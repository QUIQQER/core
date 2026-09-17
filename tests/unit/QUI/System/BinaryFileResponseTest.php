<?php

declare(strict_types=1);

namespace QUITests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\System\BinaryFileResponse;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\BinaryFileResponse as SymfonyBinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class BinaryFileResponseTest extends TestCase
{
    private string $directory;

    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-sendfile-' . bin2hex(random_bytes(8)) . ' "quoted"/';
        mkdir($this->directory, 0700);
        $this->file = $this->directory . 'test ü #%.txt';
        file_put_contents($this->file, '0123456789');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        rmdir($this->directory);
    }

    public function testClientHeadersCannotEnableOrRedirectDelivery(): void
    {
        $Request = Request::create('/file', 'GET', [], [], [], [
            'HTTP_X_SENDFILE_TYPE' => 'X-Sendfile',
            'HTTP_X_ACCEL_MAPPING' => '/=/attacker/',
            'HTTP_QUIQQER_SENDFILE_TYPE' => 'X-Sendfile',
            'HTTP_QUIQQER_ACCEL_MAPPING' => '/=/attacker/',
            'HTTP_RANGE' => 'bytes=2-5'
        ]);
        $Response = $this->response()->prepare($Request);

        self::assertSame(206, $Response->getStatusCode());
        self::assertSame('bytes 2-5/10', $Response->headers->get('Content-Range'));
        self::assertFalse($Response->headers->has('X-Sendfile'));
        self::assertFalse($Response->headers->has('X-Accel-Redirect'));
        self::assertSame('2345', $this->body($Response));
        self::assertSame('X-Sendfile', $Request->headers->get('X-Sendfile-Type'));
    }

    #[DataProvider('methods')]
    public function testServerConfiguredSendfileHasNoPhpBody(string $method): void
    {
        $Request = Request::create('/file', $method, [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => 'X-Sendfile',
            'HTTP_X_SENDFILE_TYPE' => 'X-Attacker'
        ]);
        $Response = $this->response()->prepare($Request);

        self::assertSame(200, $Response->getStatusCode());
        self::assertSame($this->file, $Response->headers->get('X-Sendfile'));
        self::assertSame('10', $Response->headers->get('Content-Length'));
        self::assertSame('application/octet-stream', $Response->headers->get('Content-Type'));
        self::assertSame('', $this->body($Response));
    }

    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    public function testAccelMappingUsesServerParametersAndLeavesRangeHandlingToServer(): void
    {
        $Request = Request::create('/file', 'GET', [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => 'X-Accel-Redirect',
            'QUIQQER_ACCEL_MAPPING' => '/unrelated/=/other/, ' . $this->directory . '=/private/',
            'HTTP_X_ACCEL_MAPPING' => '/=/attacker/',
            'HTTP_RANGE' => 'bytes=2-5'
        ]);
        $Response = $this->response()->prepare($Request);

        self::assertSame(200, $Response->getStatusCode());
        self::assertSame(
            rawurlencode('/private/' . basename($this->file)),
            $Response->headers->get('X-Accel-Redirect')
        );
        self::assertFalse($Response->headers->has('Content-Range'));
        self::assertSame('', $this->body($Response));
    }

    #[DataProvider('invalidConfiguration')]
    public function testMissingOrInvalidConfigurationRetainsRangeStreaming(string $type, mixed $mapping): void
    {
        $Request = Request::create('/file', 'GET', [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => $type,
            'QUIQQER_ACCEL_MAPPING' => $mapping,
            'HTTP_RANGE' => 'bytes=2-5'
        ]);
        $Response = $this->response()->prepare($Request);

        self::assertSame(206, $Response->getStatusCode());
        self::assertSame('2345', $this->body($Response));
        self::assertFalse($Response->headers->has('X-Accel-Redirect'));
        self::assertFalse($Response->headers->has('X-Sendfile'));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'not configured' => ['', null];
        yield 'unsupported header' => ['X-Attacker', null];
        yield 'missing mapping' => ['X-Accel-Redirect', null];
        yield 'array mapping' => ['X-Accel-Redirect', ['/=/private/']];
        yield 'malformed mapping' => ['X-Accel-Redirect', 'invalid'];
        yield 'unmatched mapping' => ['X-Accel-Redirect', '/unrelated/=/private/'];
        yield 'missing directory boundary' => ['X-Accel-Redirect', '/tmp=/private/'];
        yield 'relative URI' => ['X-Accel-Redirect', '/=private/'];
        yield 'external URI' => ['X-Accel-Redirect', '/=//attacker.invalid/'];
        yield 'query in URI' => ['X-Accel-Redirect', '/=/private/?path=/'];
        yield 'header injection' => ['X-Accel-Redirect', "/=/private/\r\nX-Injected: yes"];
    }

    public function testHeadFallbackHasNoBody(): void
    {
        $Response = $this->response()->prepare(Request::create('/file', 'HEAD'));

        self::assertSame('10', $Response->headers->get('Content-Length'));
        self::assertSame('', $this->body($Response));
    }

    public function testHeadAccelRequestRetainsOffloadHeaderWithoutBody(): void
    {
        $Response = $this->response()->prepare(Request::create('/file', 'HEAD', [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => 'X-Accel-Redirect',
            'QUIQQER_ACCEL_MAPPING' => $this->directory . '=/private/'
        ]));

        self::assertTrue($Response->headers->has('X-Accel-Redirect'));
        self::assertSame('10', $Response->headers->get('Content-Length'));
        self::assertSame('', $this->body($Response));
    }

    public function testPreparingResponseAgainRemovesPreviousOffloadHeader(): void
    {
        $Response = $this->response()->prepare(Request::create('/file', 'GET', [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => 'X-Sendfile'
        ]));
        $Response->prepare(Request::create('/file'));

        self::assertFalse($Response->headers->has('X-Sendfile'));
        self::assertSame('0123456789', $this->body($Response));
    }

    public function testActivationDoesNotLeakIntoNextResponse(): void
    {
        $Trust = new ReflectionProperty(SymfonyBinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $previous = $Trust->getValue();
        $this->response()->prepare(Request::create('/file', 'GET', [], [], [], [
            'QUIQQER_SENDFILE_TYPE' => 'X-Sendfile'
        ]));

        self::assertSame($previous, $Trust->getValue());
        $Response = $this->response()->prepare(Request::create('/file'));
        self::assertFalse($Response->headers->has('X-Sendfile'));
        self::assertSame('0123456789', $this->body($Response));
    }

    public function testOtherPackagesCannotEnableClientHeaderTrustForCoreResponses(): void
    {
        $Trust = new ReflectionProperty(SymfonyBinaryFileResponse::class, 'trustXSendfileTypeHeader');
        $previous = $Trust->getValue();
        SymfonyBinaryFileResponse::trustXSendfileTypeHeader();

        try {
            $Response = $this->response()->prepare(Request::create('/file', 'GET', [], [], [], [
                'HTTP_X_SENDFILE_TYPE' => 'X-Sendfile'
            ]));
            self::assertTrue($Trust->getValue());
            self::assertFalse($Response->headers->has('X-Sendfile'));
            self::assertSame('0123456789', $this->body($Response));
        } finally {
            $Trust->setValue(null, $previous);
        }
    }

    public function testTemporaryDownloadIsStreamedAndDeleted(): void
    {
        $Response = $this->response()->deleteFileAfterSend()->prepare(
            Request::create('/file', 'GET', [], [], [], ['QUIQQER_SENDFILE_TYPE' => 'X-Sendfile'])
        );

        self::assertFalse($Response->headers->has('X-Sendfile'));
        self::assertSame('0123456789', $this->body($Response));
        self::assertFileDoesNotExist($this->file);
    }

    private function response(): BinaryFileResponse
    {
        return new BinaryFileResponse($this->file, 200, ['Content-Type' => 'application/octet-stream']);
    }

    private function body(BinaryFileResponse $Response): string
    {
        ob_start();

        try {
            $Response->sendContent();
            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
