<?php

declare(strict_types=1);

namespace QUI\Package;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cache\LongTermCache;
use ReflectionProperty;

final class PackageMetadataCacheTest extends TestCase
{
    private string $directory;

    private string $packageName;

    private Package $Package;

    private ?Manager $PreviousManager;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/quiqqer-package-metadata-' . $id . '/';
        $this->packageName = 'phpunit/metadata-' . $id;
        mkdir($this->directory, 0700);
        $this->PreviousManager = QUI::$PackageManager;
        $Manager = $this->createMock(Manager::class);
        $Manager->method('getPackageLock')->willReturn(['version' => '1.0.0']);
        QUI::$PackageManager = $Manager;
        $this->Package = new Package('quiqqer/core');
        (new ReflectionProperty(Package::class, 'packageDir'))->setValue($this->Package, $this->directory);
        (new ReflectionProperty(Package::class, 'name'))->setValue($this->Package, $this->packageName);
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->PreviousManager;
        LongTermCache::clear($this->Package->getCacheName());

        if (is_file($this->directory . 'composer.json')) {
            unlink($this->directory . 'composer.json');
        }

        rmdir($this->directory);
    }

    public function testIncompleteCachedMetadataIsReloadedFromRestoredManifest(): void
    {
        LongTermCache::set($this->Package->getCacheName() . '/composerData', ['version' => '0.9.0']);
        $this->restoreManifest();

        self::assertTrue($this->Package->isQuiqqerPackage());
        self::assertSame($this->metadata(), LongTermCache::get($this->Package->getCacheName() . '/composerData'));
    }

    public function testMissingManifestDoesNotPoisonCacheDuringPackageReplacement(): void
    {
        self::assertSame(['version' => '1.0.0'], $this->Package->getComposerData());

        try {
            LongTermCache::get($this->Package->getCacheName() . '/composerData');
            self::fail('Metadata without a manifest must not enter the persistent cache.');
        } catch (QUI\Cache\MissException) {
            // No cache entry should survive the temporary absence of the manifest.
        }

        $this->restoreManifest();

        self::assertTrue($this->Package->isQuiqqerPackage());
        self::assertSame($this->metadata(), $this->Package->getComposerData());
        self::assertSame($this->metadata(), LongTermCache::get($this->Package->getCacheName() . '/composerData'));
    }

    private function restoreManifest(): void
    {
        file_put_contents($this->directory . 'composer.json', json_encode([
            'name' => $this->packageName,
            'type' => 'quiqqer-module'
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{name: string, type: string, version: string} */
    private function metadata(): array
    {
        return ['name' => $this->packageName, 'type' => 'quiqqer-module', 'version' => '1.0.0'];
    }
}
