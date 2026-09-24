<?php

declare(strict_types=1);

namespace QUITests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Events\Manager;
use QUI\Rewrite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RewriteResponseTest extends TestCase
{
    private ?Response $PreviousResponse;

    private ?Manager $PreviousEvents;

    private string $previousMimeType;

    protected function setUp(): void
    {
        $this->PreviousResponse = QUI::$Response;
        $this->PreviousEvents = QUI::$Events;
        $this->previousMimeType = (string)ini_get('default_mimetype');
        QUI::$Response = new Response('existing body', 200, [
            'Location' => '/old-redirect',
            'ETag' => '"resource-version"',
            'Cache-Control' => 'public, max-age=600',
            'Vary' => 'Accept-Encoding',
            'Content-Type' => 'text/html',
            'Content-Length' => '13'
        ]);
        QUI::$Events = $this->createMock(Manager::class);
    }

    protected function tearDown(): void
    {
        QUI::$Response = $this->PreviousResponse;
        QUI::$Events = $this->PreviousEvents;
        ini_set('default_mimetype', $this->previousMimeType);
    }

    #[DataProvider('notModifiedRequests')]
    public function testNotModifiedRemainsEmptyWhenRenderedResponseIsSent(string $method, string $url): void
    {
        $Response = QUI::getGlobalResponse();
        $Rewrite = new Rewrite();
        $cacheControl = $Response->headers->get('Cache-Control');

        ob_start();

        try {
            self::assertTrue($Rewrite->showErrorHeader(304, $url));
            self::assertSame('', ob_get_contents(), 'Setting the status must not send a response.');
            self::assertSame($Response, QUI::getGlobalResponse());
            self::assertSame(304, $Rewrite->getHeaderCode());
            self::assertSame(304, $Response->getStatusCode());
            self::assertSame('', $Response->getContent());
            self::assertFalse($Response->headers->has('Location'));

            // The frontend can still render a page before its final prepare()/send() calls.
            $Response->setContent('<html>later rendered page</html>');
            $Response->prepare(Request::create('https://example.test/resource', $method));
            $Response->send(false);

            self::assertSame('', ob_get_contents());
            self::assertSame('', $Response->getContent());
            self::assertSame(304, $Response->getStatusCode());
            self::assertFalse($Response->headers->has('Location'));
            self::assertFalse($Response->headers->has('Content-Type'));
            self::assertFalse($Response->headers->has('Content-Length'));
            self::assertSame('"resource-version"', $Response->headers->get('ETag'));
            self::assertSame($cacheControl, $Response->headers->get('Cache-Control'));
            self::assertSame('Accept-Encoding', $Response->headers->get('Vary'));
        } finally {
            ob_end_clean();
        }
    }

    public function testUseProxyLogsErrorAndSendsPermanentRedirect(): void
    {
        // A fresh process lets us inspect the actual status before PHPUnit has sent any output.
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/rewrite-use-proxy.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $metadata = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, "STDOUT:\n" . $output . "\nSTDERR:\n" . $metadata);
        self::assertIsString($output);
        self::assertStringContainsString('https://target.example.test', $output);
        $data = json_decode((string)$metadata, true);
        self::assertIsArray($data);
        $trace = $data['errors'][0]['trace'];
        self::assertNotEmpty($trace);
        self::assertSame(Rewrite::class, $trace[0]['class']);
        self::assertSame('showErrorHeader', $trace[0]['function']);
        self::assertSame(__DIR__ . '/Fixtures/rewrite-use-proxy.php', $trace[0]['file']);
        self::assertArrayHasKey('line', $trace[0]);

        foreach ($trace as $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayNotHasKey('object', $frame);
        }

        unset($data['errors'][0]['trace']);

        self::assertSame([
            'result' => true,
            'status' => 301,
            'rewriteStatus' => 301,
            'events' => [301, 301],
            'errors' => [[
                'message' => 'HTTP status 305 (Use Proxy) is no longer supported; using 301.',
                'httpCode' => 305
            ]]
        ], $data);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notModifiedRequests(): iterable
    {
        yield 'GET without URL' => ['GET', ''];
        yield 'GET with ignored redirect URL' => ['GET', '/not-a-redirect'];
        yield 'HEAD without URL' => ['HEAD', ''];
        yield 'HEAD with ignored redirect URL' => ['HEAD', '/not-a-redirect'];
    }
}
