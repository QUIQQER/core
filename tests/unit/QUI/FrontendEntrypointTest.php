<?php

namespace QUITests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FrontendEntrypointTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function errors(): iterable
    {
        yield 'page exception' => ['page', 'RuntimeException', true];
        yield 'page type error' => ['page', 'TypeError', true];
        yield 'page error' => ['page', 'Error', true];
        yield 'page parse error from included code' => ['page', 'ParseError', true];
        yield 'routing type error stays in global handler' => ['routing', 'TypeError', false];
        yield 'routing error stays in global handler' => ['routing', 'Error', false];
        yield 'routing exception keeps existing error page' => ['routing', 'RuntimeException', true];
    }

    #[DataProvider('errors')]
    public function testErrorResponse(string $phase, string $class, bool $expectHtml): void
    {
        $root = dirname(__DIR__, 3);
        $Process = $this->runEntrypoint($root . '/index.php', [
            'QUIQQER_TEST_ERROR_PHASE' => $phase,
            'QUIQQER_TEST_ERROR_CLASS' => $class
        ]);
        $output = $Process->getOutput();
        $errorOutput = $Process->getErrorOutput();

        self::assertSame($class . ': private error details', $errorOutput);
        self::assertIsString($output);
        self::assertStringNotContainsString('private error details', $output);

        if ($expectHtml) {
            self::assertSame(file_get_contents($root . '/src/templates/error.html'), $output);
        } else {
            self::assertSame(['error' => true, 'message' => 'global handler'], json_decode($output, true));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'reported invalid host' => ['2a01:4f8:c2c:6abc:'];
        yield 'unbracketed IPv6 address' => ['2a01:4f8:c2c:6abc::1'];
        yield 'hostname containing whitespace' => ['invalid host.example'];
        yield 'hostname containing HTML' => ['<script>alert(1)</script>.example'];
    }

    #[DataProvider('invalidHosts')]
    public function testInvalidHostReturnsBadRequestWithoutLoggingServerError(string $host): void
    {
        $Process = $this->runEntrypoint(__DIR__ . '/Fixtures/FrontendEntrypoint/request.php', [
            'QUIQQER_TEST_ERROR_PHASE' => 'host',
            'QUIQQER_TEST_REQUEST_HOST' => $host
        ]);
        $response = json_decode($Process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $errorPage = file_get_contents(dirname(__DIR__, 3) . '/src/templates/error.html');

        self::assertSame(400, $response['status']);
        self::assertSame($errorPage, $response['body']);
        self::assertSame('', $Process->getErrorOutput());
        self::assertCount(1, $response['infoLogs']);
        self::assertSame('Invalid HTTP request rejected.', $response['infoLogs'][0]['message']);
        self::assertStringContainsString('Invalid Host', $response['infoLogs'][0]['context']['reason']);
    }

    /**
     * @param array<string, string> $environment
     */
    private function runEntrypoint(string $file, array $environment): Process
    {
        $Process = new Process(
            [PHP_BINARY, $file],
            cwd: __DIR__ . '/Fixtures/FrontendEntrypoint',
            env: $environment,
            timeout: 10
        );
        $Process->mustRun();

        return $Process;
    }
}
