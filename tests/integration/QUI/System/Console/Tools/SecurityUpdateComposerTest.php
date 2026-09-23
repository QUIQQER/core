<?php

declare(strict_types=1);

namespace QUI\System\Console\Tools;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Process\Process;

class SecurityUpdateComposerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-patch-composer-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    public function testNativePatchUpdatePreservesConstraintsAndUpdatesDevelopmentBranch(): void
    {
        $this->writeProject(str_repeat('a', 40));
        $this->runComposer(['--with=test/patch:1.2.3']);
        $previousLock = file_get_contents($this->directory . '/composer.lock');
        $this->writeProject(str_repeat('b', 40));
        $previousJson = file_get_contents($this->directory . '/composer.json');

        $preview = $this->runComposer(['--patch-only', '--dry-run']);

        self::assertStringContainsString('Upgrading test/patch (1.2.3 => 1.2.4)', $preview);
        self::assertSame($previousLock, file_get_contents($this->directory . '/composer.lock'));
        self::assertSame($previousJson, file_get_contents($this->directory . '/composer.json'));

        $this->runComposer(['--patch-only']);
        $lock = json_decode(file_get_contents($this->directory . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($lock['packages'], null, 'name');

        self::assertSame('1.2.4', $packages['test/patch']['version']);
        self::assertSame('1.0.0', $packages['test/pinned']['version']);
        self::assertSame('dev-main', $packages['test/development']['version']);
        self::assertSame(str_repeat('b', 40), $packages['test/development']['source']['reference']);
        self::assertSame($previousJson, file_get_contents($this->directory . '/composer.json'));
        self::assertFileDoesNotExist($this->directory . '/composer-security-update-backup.json');
        self::assertStringContainsString('Nothing to modify in lock file', $this->runComposer(['--patch-only', '--dry-run']));
    }

    private function writeProject(string $reference): void
    {
        $packages = [];

        foreach (['1.2.3', '1.2.4', '1.3.0', '2.0.0'] as $version) {
            $packages[] = ['name' => 'test/patch', 'version' => $version, 'type' => 'metapackage'];
        }

        foreach (['1.0.0', '1.0.1', '1.1.0'] as $version) {
            $packages[] = ['name' => 'test/pinned', 'version' => $version, 'type' => 'metapackage'];
        }

        $packages[] = [
            'name' => 'test/development', 'version' => 'dev-main', 'type' => 'metapackage',
            'source' => ['type' => 'git', 'url' => 'https://example.invalid/development.git', 'reference' => $reference]
        ];
        file_put_contents($this->directory . '/composer.json', json_encode([
            'name' => 'test/security-update',
            'require' => ['test/patch' => '^1.0 || ^2.0', 'test/pinned' => '1.0.0', 'test/development' => 'dev-main'],
            'repositories' => [['type' => 'package', 'package' => $packages], ['packagist.org' => false]]
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function runComposer(array $options): string
    {
        $binary = dirname((new ReflectionClass(Application::class))->getFileName(), 4) . '/bin/composer';
        $Process = new Process([
            PHP_BINARY, $binary, 'update', '--no-install', '--no-plugins', '--no-scripts',
            '--no-audit', '--no-interaction', '--no-ansi', ...$options
        ], $this->directory, [
            'COMPOSER_HOME' => $this->directory . '/home',
            'COMPOSER_CACHE_DIR' => $this->directory . '/cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER' => 'composer.json'
        ]);
        $Process->run();
        $output = $Process->getOutput() . $Process->getErrorOutput();
        self::assertSame(0, $Process->getExitCode(), $output);

        return $output;
    }
}
