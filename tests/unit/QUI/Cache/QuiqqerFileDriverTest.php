<?php

declare(strict_types=1);

namespace QUI\Cache;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use QUI\Cache\Fixtures\DeniedLockStream;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Stash\Driver\FileSystem;
use Stash\Driver\FileSystem\NativeEncoder;

require_once __DIR__ . '/Fixtures/DeniedLockStream.php';

final class QuiqqerFileDriverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/quiqqer-file-driver-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function testWritingAndReadingTheFileCacheStillWorks(): void
    {
        $Driver = new QuiqqerFileDriver(['path' => $this->directory]);
        $key = ['normal-write'];
        $expiration = time() + 300;

        self::assertTrue($Driver->storeData($key, 'cached data', $expiration));
        self::assertSame([
            'data' => 'cached data',
            'expiration' => $expiration
        ], $Driver->getData($key));
    }

    public function testLockFailureReturnsFalseWithoutWarningAndPreservesThePreviousCache(): void
    {
        $Driver = new QuiqqerFileDriver(['path' => $this->directory]);
        $key = ['failed-lock'];
        $expiration = time() + 300;
        self::assertTrue($Driver->storeData($key, 'previous data', $expiration));

        $path = (new ReflectionMethod(FileSystem::class, 'makePath'))->invoke($Driver, $key);
        DeniedLockStream::$stat = stat($path);
        DeniedLockStream::$writes = 0;
        $warnings = [];
        $Handler = static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        };
        set_error_handler($Handler);

        try {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', DeniedLockStream::class);

            try {
                $result = $Driver->storeData($key, 'replacement data', $expiration);
            } finally {
                stream_wrapper_restore('file');
            }

            $restoredHandler = $this->getCurrentErrorHandler();
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
        self::assertSame([], $warnings);
        self::assertSame(0, DeniedLockStream::$writes);
        self::assertSame($Handler, $restoredHandler);
        self::assertSame('previous data', $Driver->getData($key)['data']);
        self::assertTrue($Driver->storeData($key, 'later data', $expiration));
        self::assertSame('later data', $Driver->getData($key)['data']);
    }

    public function testUnrelatedWarningsReachThePreviousErrorHandler(): void
    {
        $Driver = new QuiqqerFileDriver(['path' => $this->directory]);
        $OriginalEncoder = new NativeEncoder();
        $Encoder = $this->createMock(NativeEncoder::class);
        $Encoder->method('getExtension')->willReturn('.php');
        $missingFile = $this->directory . '/missing-file';
        $Encoder->method('serialize')->willReturnCallback(
            static function ($key, $data, $expiration) use ($OriginalEncoder, $missingFile): string {
                file_get_contents($missingFile);
                trigger_error('Unrelated encoder warning', E_USER_WARNING);

                return $OriginalEncoder->serialize($key, $data, $expiration);
            }
        );
        (new ReflectionProperty(FileSystem::class, 'encoder'))->setValue($Driver, $Encoder);

        $warnings = [];
        $Handler = static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [
                'level' => $level,
                'message' => $message
            ];

            return true;
        };
        set_error_handler($Handler);

        try {
            $result = $Driver->storeData(['unrelated-warning'], 'cached data', time() + 300);
            $restoredHandler = $this->getCurrentErrorHandler();
        } finally {
            restore_error_handler();
        }

        self::assertTrue($result);
        self::assertSame($Handler, $restoredHandler);
        self::assertCount(2, $warnings);
        self::assertSame(E_WARNING, $warnings[0]['level']);
        self::assertStringContainsString('file_get_contents(', $warnings[0]['message']);
        self::assertSame([
            'level' => E_USER_WARNING,
            'message' => 'Unrelated encoder warning'
        ], $warnings[1]);
    }

    public function testAnEncoderExceptionPropagatesAndRestoresTheErrorHandler(): void
    {
        $Driver = new QuiqqerFileDriver(['path' => $this->directory]);
        $failure = new RuntimeException('Encoder failure');
        $Encoder = $this->createMock(NativeEncoder::class);
        $Encoder->method('getExtension')->willReturn('.php');
        $Encoder->method('serialize')->willThrowException($failure);
        (new ReflectionProperty(FileSystem::class, 'encoder'))->setValue($Driver, $Encoder);
        $Handler = static fn (): bool => false;
        set_error_handler($Handler);
        $caught = null;

        try {
            $Driver->storeData(['encoder-failure'], 'cached data', time() + 300);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $restoredHandler = $this->getCurrentErrorHandler();
            restore_error_handler();
        }

        self::assertSame($failure, $caught);
        self::assertSame($Handler, $restoredHandler);
    }

    private function getCurrentErrorHandler(): ?callable
    {
        $Handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $Handler;
    }
}
