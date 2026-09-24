<?php

namespace QUI\Projects\Media;

use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Lock\Locker;
use QUI\Projects\Media;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;

class ImageCacheStampedeTest extends TestCase
{
    private string $directory;
    private mixed $previousEvents;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-image-stampede-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        Locker::setProcessLockStore(new FlockStore($this->directory . '/locks'));
        $this->previousEvents = QUI::$Events;
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        $Source = imagecreatetruecolor(64, 64);
        imagepng($Source, $this->directory . '/original.png');
    }

    protected function tearDown(): void
    {
        Locker::setProcessLockStore(null);
        QUI::$Events = $this->previousEvents;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    private function image(string $mime = 'image/png'): Image
    {
        $Media = $this->createMock(Media::class);
        $Media->method('getImageManager')->willReturn(ImageManager::gd());
        $Image = $this->getMockBuilder(Image::class)->disableOriginalConstructor()->onlyMethods([
            'getAttribute', 'checkPermission', 'getFullPath', 'getSizeCachePath', 'getEffects', 'getWatermark', 'isAnimated'
        ])->getMock();
        (new \ReflectionProperty(Item::class, 'Media'))->setValue($Image, $Media);
        $Image->method('getAttribute')->willReturnCallback(
            static fn(string $key): mixed => ['active' => 1, 'mime_type' => $mime][$key] ?? null
        );
        $Image->method('getFullPath')->willReturn($this->directory . '/original.png');
        $Image->method('getSizeCachePath')->willReturn($this->directory . '/cache.png');
        return $Image;
    }

    public function testWarmRasterCacheDoesNotAcquireALockOrRender(): void
    {
        copy($this->directory . '/original.png', $this->directory . '/cache.png');
        $Store = $this->createMock(PersistingStoreInterface::class);
        $Store->expects(self::never())->method('save');
        Locker::setProcessLockStore($Store);
        $Image = $this->image();
        $Image->expects(self::never())->method('getEffects');
        self::assertSame($this->directory . '/cache.png', $Image->createSizeCache());
    }

    public function testCacheIsCheckedAgainAfterAcquiringTheLock(): void
    {
        $Store = $this->createMock(PersistingStoreInterface::class);
        $Store->expects(self::once())->method('save')->willReturnCallback(function (): void {
            copy($this->directory . '/original.png', $this->directory . '/cache.png');
        });
        $held = true;
        $Store->method('exists')->willReturnCallback(static function () use (&$held): bool {
            return $held;
        });
        $Store->method('delete')->willReturnCallback(static function () use (&$held): void {
            $held = false;
        });
        Locker::setProcessLockStore($Store);
        $Image = $this->image();
        $Image->expects(self::never())->method('getEffects');
        self::assertSame($this->directory . '/cache.png', $Image->createSizeCache());
    }

    #[DataProvider('copyBranches')]
    public function testUnmodifiedAndAnimatedImagesAreCopied(string $mime, bool $animated): void
    {
        $Image = $this->image($mime);
        $Image->method('isAnimated')->willReturn($animated);
        $Image->method('getEffects')->willReturn([]);
        self::assertSame($this->directory . '/cache.png', $Image->createSizeCache());
        self::assertFileEquals($this->directory . '/original.png', $this->directory . '/cache.png');
        self::assertSame([], glob($this->directory . '/.quiqqer-image-*'));
    }

    public static function copyBranches(): array
    {
        return [['image/png', false], ['image/gif', true]];
    }

    #[DataProvider('unscaledImageDimensions')]
    public function testUnscaledCacheDimensionsUseOriginalImageWidth(string $mime, bool $resize): void
    {
        $Image = $this->image($mime);
        $Image->method('isAnimated')->willReturn($mime === 'image/gif');

        self::assertSame(
            ['width' => 64, 'height' => 64],
            $Image->getSizeCacheImageDimensions($resize ? 16 : false)
        );
    }

    public static function unscaledImageDimensions(): array
    {
        return [
            'original raster' => ['image/png', false],
            'vector' => ['image/svg+xml', true],
            'animated gif' => ['image/gif', true]
        ];
    }

    public function testFailedRenderingCleansUpAndTheNextRequestCanRetry(): void
    {
        $Image = $this->image();
        $Image->method('getEffects')->willReturn(['brightness' => 1]);
        $attempts = 0;
        $Image->method('getWatermark')->willReturnCallback(static function () use (&$attempts): false {
            if (++$attempts === 1) {
                throw new \RuntimeException('Rendering failed');
            }

            return false;
        });
        $limit = ini_get('max_execution_time');

        try {
            $Image->createSizeCache();
            self::fail('Rendering should fail.');
        } catch (\RuntimeException $Exception) {
            self::assertSame('Rendering failed', $Exception->getMessage());
        }

        self::assertSame($limit, ini_get('max_execution_time'));
        self::assertFileDoesNotExist($this->directory . '/cache.png');
        self::assertSame([], glob($this->directory . '/.quiqqer-image-*'));
        QUI::$Events->expects(self::once())->method('fireEvent')->with(
            'mediaCreateSizeCache',
            self::callback(function (array $args) use ($Image): bool {
                self::assertFileExists($this->directory . '/cache.png');
                return $args[0] === $Image;
            })
        );
        self::assertSame($this->directory . '/cache.png', $Image->createSizeCache());
        self::assertSame(IMAGETYPE_PNG, getimagesize($this->directory . '/cache.png')[2]);
    }

    public function testExpiredOwnerDoesNotPublishItsResult(): void
    {
        $Store = $this->createMock(PersistingStoreInterface::class);
        // Symfony refreshes once during acquisition and again before publication.
        $refreshes = 0;
        $Store->method('putOffExpiration')->willReturnCallback(static function () use (&$refreshes): void {
            if (++$refreshes > 1) {
                throw new LockConflictedException('Owner expired');
            }
        });
        Locker::setProcessLockStore($Store);
        $Image = $this->image();
        $Image->method('getEffects')->willReturn([]);

        try {
            $Image->createSizeCache();
            self::fail('An expired owner must not publish its result.');
        } catch (LockConflictedException) {
            self::assertFileDoesNotExist($this->directory . '/cache.png');
            self::assertSame([], glob($this->directory . '/.quiqqer-image-*'));
        }
    }

    public function testExistingSvgCacheIsStillSanitized(): void
    {
        file_put_contents(
            $this->directory . '/cache.png',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>'
        );
        $Image = $this->image('image/svg+xml');
        $Image->expects(self::never())->method('getEffects');
        $Image->createSizeCache();
        self::assertStringNotContainsString('<script', file_get_contents($this->directory . '/cache.png'));
        self::assertSame([], glob($this->directory . '/.quiqqer-image-*'));
    }

    public function testParallelRequestsRenderOnceAndDifferentVariantsRemainIndependent(): void
    {
        $processes = [];

        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/Fixtures/image-cache-worker.php', $this->directory, (string)$i],
                    [
                        1 => ['file', $this->directory . '/output-' . $i, 'w'],
                        2 => ['file', $this->directory . '/error-' . $i, 'w']
                    ],
                    $pipes
                );
                self::assertIsResource($process);
                $processes[] = $process;
            }

            $this->waitFor(static fn(string $dir): bool => count(glob($dir . '/ready-*') ?: []) === 4);
            file_put_contents($this->directory . '/go', 'go');
            $this->waitFor(static fn(string $dir): bool =>
                file_exists($dir . '/started-shared') && file_exists($dir . '/done-3')
                && count(glob($dir . '/waiting-*') ?: []) === 2);

            // The independent variant finishes while the shared variant is still rendering.
            self::assertFileExists($this->directory . '/independent.png');
            self::assertFileDoesNotExist($this->directory . '/shared.png');
            self::assertSame([], glob($this->directory . '/done-[012]'));
            file_put_contents($this->directory . '/release', 'release');
            $this->waitFor(static fn(string $dir): bool => count(glob($dir . '/done-*') ?: []) === 4);

            foreach ($processes as $process) {
                self::assertSame(0, proc_close($process), $this->workerOutput());
            }

            $processes = [];
            $generations = file($this->directory . '/generations', FILE_IGNORE_NEW_LINES);
            sort($generations);
            self::assertSame(['independent', 'shared'], $generations);
            self::assertSame([], glob($this->directory . '/.quiqqer-image-*'));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 20;

        while (!$condition($this->directory)) {
            if (microtime(true) >= $deadline) {
                self::fail('Image cache workers timed out: ' . $this->workerOutput());
            }

            usleep(10000);
        }
    }

    private function workerOutput(): string
    {
        $output = '';

        foreach (array_merge(glob($this->directory . '/output-*') ?: [], glob($this->directory . '/error-*') ?: []) as $file) {
            $output .= file_get_contents($file);
        }

        return $output;
    }
}
