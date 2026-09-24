<?php

namespace QUI\Projects;

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use QUI\Projects\Media\Image;
use ReflectionClass;

class ProjectMediaImageCacheTest extends ProjectIntegrationTestCase
{
    /**
     * @param array<string, int> $attributes
     */
    #[DataProvider('srcsetSizes')]
    public function testSrcsetWidthsMatchGeneratedImages(
        int $sourceWidth,
        int $sourceHeight,
        array $attributes,
        int $rounding,
        int $cacheLimit
    ): void {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $Root = $Media->firstChild();
        $folderName = 'phpunit-srcset-' . uniqid();
        $sourceFile = sys_get_temp_dir() . '/' . $folderName . '.png';
        $originalConfig = $Project->getConfig();
        $fileId = null;
        $folderId = null;

        self::createPng($sourceFile, $sourceWidth, $sourceHeight);

        try {
            self::setProjectConfig($Project, array_merge($originalConfig, [
                'media_imageCacheSizeRounding' => $rounding,
                'media_imageCacheExactSizeThreshold' => 100,
                'media_maxImageCacheSize' => $cacheLimit,
                'media_useImageScale' => 2
            ]));

            $folderId = ProjectTestHelper::runAsSystemUser(
                static fn (): int => $Root->createFolder($folderName)->getId()
            );
            $fileId = ProjectTestHelper::runAsSystemUser(
                static function () use ($Media, $folderId, $sourceFile): int {
                    $Image = $Media->get($folderId)->uploadFile($sourceFile);
                    $Image->activate();

                    return $Image->getId();
                }
            );

            $Image = $Media->get($fileId);
            self::assertInstanceOf(Image::class, $Image);
            $html = ProjectTestHelper::runAsSystemUser(
                static fn (): string => Media\Utils::getImageHTML($Image->getUrl(), $attributes)
            );
            $Document = new DOMDocument();
            self::assertTrue($Document->loadHTML($html));
            $Img = $Document->getElementsByTagName('img')->item(0);
            self::assertNotNull($Img);
            $srcset = $Img->getAttribute('srcset');
            self::assertNotSame('', $srcset);
            $widths = [];

            foreach (explode(', ', $srcset) as $candidate) {
                self::assertSame(1, preg_match('/^(.*) ([0-9]+)w$/', $candidate, $parts));
                $url = $parts[1];
                $width = (int)$parts[2];
                self::assertSame(1, preg_match('/__([0-9]+)x([0-9]+)\.png$/', $url, $size));
                $cachePath = ProjectTestHelper::runAsSystemUser(
                    static fn (): false | string => $Image->createSizeCache((int)$size[1], (int)$size[2])
                );
                self::assertIsString($cachePath);
                self::assertSame(basename($url), basename($cachePath));
                $actualSize = getimagesize($cachePath);
                self::assertIsArray($actualSize);
                self::assertSame($actualSize[0], $width, $candidate);
                self::assertNotContains($width, $widths, 'Each srcset width must be unique.');
                $widths[] = $width;
            }

            $sortedWidths = $widths;
            sort($sortedWidths);
            self::assertSame($sortedWidths, $widths);
        } finally {
            self::setProjectConfig($Project, $originalConfig);
            self::deleteMediaItems($Media, $fileId, $folderId);

            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
        }
    }

    /**
     * @return iterable<string, array{int, int, array<string, int>, int, int}>
     */
    public static function srcsetSizes(): iterable
    {
        yield 'rounded landscape' => [1600, 900, ['width' => 333], 1, 4000];
        yield 'rounding disabled' => [1600, 900, ['width' => 333], 0, 4000];
        yield 'height constrained' => [1600, 900, ['width' => 500, 'height' => 150], 1, 4000];
        yield 'portrait' => [900, 1600, ['width' => 333], 1, 4000];
        yield 'small logo' => [380, 80, ['width' => 285, 'height' => 60], 1, 4000];
        yield 'original size limit' => [201, 113, ['width' => 1000], 1, 4000];
        yield 'cache size limit' => [1600, 900, ['width' => 1000], 1, 333];
        yield 'wide banner' => [1600, 60, ['width' => 333], 1, 4000];
        yield 'duplicate physical widths' => [1600, 60, ['width' => 28], 1, 4000];
    }

    public function testSmallCacheImageUsesExactTargetDimensions(): void
    {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $Root = $Media->firstChild();
        $folderName = 'phpunit-image-cache-' . uniqid();
        $sourceFile = sys_get_temp_dir() . '/quiqqer-phpunit-image-cache-' . uniqid() . '.png';
        $originalConfig = $Project->getConfig();
        $fileId = null;
        $folderId = null;

        self::createPng($sourceFile, 380, 80);

        try {
            self::setProjectConfig($Project, array_merge($originalConfig, [
                'media_imageCacheSizeRounding' => 1,
                'media_imageCacheExactSizeThreshold' => 100
            ]));

            $folderId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $folderName): int {
                return $Root->createFolder($folderName)->getId();
            });

            $fileId = ProjectTestHelper::runAsSystemUser(
                static function () use ($Media, $folderId, $sourceFile): int {
                    $Image = $Media->get($folderId)->uploadFile($sourceFile);
                    $Image->activate();

                    return $Image->getId();
                }
            );

            $Image = $Media->get($fileId);
            $this->assertInstanceOf(Image::class, $Image);

            $cachePath = ProjectTestHelper::runAsSystemUser(
                static fn (): string => $Image->getSizeCachePath(false, 60)
            );
            $createdCachePath = ProjectTestHelper::runAsSystemUser(
                static fn (): false | string => $Image->createSizeCache(false, 60)
            );

            $this->assertMatchesRegularExpression('/__285x60\.png$/', $cachePath);
            $this->assertSame($cachePath, $createdCachePath);
            $this->assertFileExists($cachePath);

            $imageSize = getimagesize($cachePath);
            $this->assertIsArray($imageSize);
            $this->assertSame(285, $imageSize[0]);
            $this->assertSame(60, $imageSize[1]);

            self::setProjectConfig($Project, array_merge($originalConfig, [
                'media_imageCacheSizeRounding' => 1,
                'media_imageCacheExactSizeThreshold' => 0
            ]));

            $roundedPath = ProjectTestHelper::runAsSystemUser(
                static fn (): string => $Image->getSizeCachePath(false, 60)
            );

            $this->assertMatchesRegularExpression('/__288x61\.png$/', $roundedPath);
        } finally {
            self::setProjectConfig($Project, $originalConfig);
            self::deleteMediaItems($Media, $fileId, $folderId);

            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
        }
    }

    public function testCacheSizeRoundingCanBeConfigured(): void
    {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $Root = $Media->firstChild();
        $folderName = 'phpunit-image-cache-config-' . uniqid();
        $sourceFile = sys_get_temp_dir() . '/quiqqer-phpunit-image-cache-config-' . uniqid() . '.png';
        $originalConfig = $Project->getConfig();
        $fileId = null;
        $folderId = null;

        self::createPng($sourceFile, 1600, 900);

        try {
            $folderId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $folderName): int {
                return $Root->createFolder($folderName)->getId();
            });

            $fileId = ProjectTestHelper::runAsSystemUser(
                static function () use ($Media, $folderId, $sourceFile): int {
                    $Image = $Media->get($folderId)->uploadFile($sourceFile);
                    $Image->activate();

                    return $Image->getId();
                }
            );

            $Image = $Media->get($fileId);
            $this->assertInstanceOf(Image::class, $Image);

            self::setProjectConfig($Project, array_merge($originalConfig, [
                'media_imageCacheSizeRounding' => 1,
                'media_imageCacheExactSizeThreshold' => 100
            ]));

            $roundedPath = ProjectTestHelper::runAsSystemUser(
                static fn (): string => $Image->getSizeCachePath(333)
            );

            self::setProjectConfig($Project, array_merge($originalConfig, [
                'media_imageCacheSizeRounding' => 0,
                'media_imageCacheExactSizeThreshold' => 100
            ]));

            $exactPath = ProjectTestHelper::runAsSystemUser(
                static fn (): string => $Image->getSizeCachePath(333)
            );

            $this->assertMatchesRegularExpression('/__336x189\.png$/', $roundedPath);
            $this->assertMatchesRegularExpression('/__333x187\.png$/', $exactPath);
        } finally {
            self::setProjectConfig($Project, $originalConfig);
            self::deleteMediaItems($Media, $fileId, $folderId);

            if (file_exists($sourceFile)) {
                unlink($sourceFile);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function setProjectConfig(Project $Project, array $config): void
    {
        $Reflection = new ReflectionClass($Project);
        $Property = $Reflection->getProperty('config');
        $Property->setValue($Project, $config);
    }

    private static function deleteMediaItems(
        Media $Media,
        ?int $fileId,
        ?int $folderId
    ): void {
        if ($fileId) {
            ProjectTestHelper::runAsSystemUser(static function () use ($Media, $fileId): void {
                $File = $Media->get($fileId);
                $File->delete();
                $File->destroy();
            });
        }

        if ($folderId) {
            ProjectTestHelper::runAsSystemUser(static function () use ($Media, $folderId): void {
                $Folder = $Media->get($folderId);
                $Folder->delete();
                $Folder->destroy();
            });
        }
    }

    private static function createPng(string $file, int $width, int $height): void
    {
        $Image = imagecreatetruecolor($width, $height);
        $Color = imagecolorallocate($Image, 255, 0, 0);
        imagefilledrectangle($Image, 0, 0, $width - 1, $height - 1, $Color);
        imagepng($Image, $file);
        imagedestroy($Image);
    }
}
