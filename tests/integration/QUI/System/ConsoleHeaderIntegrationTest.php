<?php

namespace QUITests\QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConsoleHeaderIntegrationTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[DataProvider('headerArgumentsProvider')]
    public function testNoHeaderPreservesConsoleOutput(array $arguments, bool $compact): void
    {
        $normal = $this->runConsole($arguments);
        $suppressed = $this->runConsole([...$arguments, '--no-header']);
        $flagFirst = $this->runConsole(['--no-header', ...$arguments]);

        self::assertSame($suppressed, $flagFirst);
        self::assertNotSame('', $suppressed);
        self::assertStringEndsWith($suppressed, $normal);
        self::assertStringNotContainsString('Last update:', $suppressed);
        self::assertStringNotContainsString('Welcome to QUIQQER', $suppressed);

        $header = substr($normal, 0, strlen($normal) - strlen($suppressed));

        if ($compact) {
            self::assertStringContainsString('Last update:', $header);
            self::assertSame(1, substr_count($header, PHP_EOL));
        } else {
            self::assertStringContainsString('Welcome to QUIQQER', $header);
            self::assertStringContainsString('ABSOLUTELY NO WARRANTY', $header);
            self::assertStringContainsString('--no-header', $suppressed);
        }
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function headerArgumentsProvider(): iterable
    {
        yield 'default help' => [[], false];
        yield 'explicit help' => [['--help'], false];
        yield 'system tool help' => [['update', '--help'], true];
        yield 'system tool output' => [['licence'], true];
        yield 'registered tool output' => [['quiqqer:licence'], true];
    }

    public function testCompletionRemainsHeaderFree(): void
    {
        $arguments = ['_complete', '--word=--no-'];

        self::assertSame('--no-header' . PHP_EOL, $this->runConsole($arguments));
        self::assertSame('--no-header' . PHP_EOL, $this->runConsole([...$arguments, '--no-header']));
    }

    /**
     * @param list<string> $arguments
     */
    private function runConsole(array $arguments): string
    {
        $Process = proc_open(
            [PHP_BINARY, CMS_DIR . 'console', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            CMS_DIR
        );

        self::assertIsResource($Process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($Process);

        self::assertSame(0, $status, (string)$errors);
        self::assertSame('', $errors);
        self::assertIsString($output);

        return $output;
    }
}
