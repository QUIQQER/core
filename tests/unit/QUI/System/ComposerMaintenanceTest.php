<?php

namespace QUI\System;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ComposerMaintenanceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-composer-command-' . bin2hex(random_bytes(8)) . '/';

        foreach (['etc', 'packages/quiqqer/core', 'var/composer'] as $path) {
            mkdir($this->directory . $path, 0700, true);
        }

        copy(dirname(__DIR__, 4) . '/quiqqer.php', $this->directory . 'packages/quiqqer/core/quiqqer.php');
        copy(__DIR__ . '/Fixtures/maintenance-composer.php', $this->directory . 'var/composer/composer.phar');
        $autoload = var_export(dirname(__DIR__, 6) . '/autoload.php', true);
        file_put_contents($this->directory . 'packages/autoload.php', '<?php require_once ' . $autoload . ';');
        file_put_contents(
            $this->directory . 'etc/conf.ini.php',
            '[globals]' . PHP_EOL . 'var_dir="' . addcslashes(VAR_DIR, '\\"') . '"' . PHP_EOL
        );
        file_put_contents(
            $this->directory . 'console',
            "<?php\ndefine('CMS_DIR', __DIR__ . '/');\nrequire __DIR__ . '/packages/quiqqer/core/quiqqer.php';"
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);
    }

    public function testComposerPreservesArgumentsAndItsFailureStatus(): void
    {
        $Process = $this->command(['require', 'test/package:dev-main as 2.0.x-dev', '--no-update']);
        $Process->setEnv(['QUIQQER_TEST_COMPOSER_STATUS' => '23']);
        $Process->run();

        self::assertSame(23, $Process->getExitCode(), $Process->getErrorOutput());
        self::assertSame([
            'require',
            'test/package:dev-main as 2.0.x-dev',
            '--no-update',
            '--working-dir=' . rtrim($this->directory, '/') . '/var/composer'
        ], json_decode($Process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testComposerJoinsItsOwningUpdateButRejectsAnUnrelatedCaller(): void
    {
        MaintenanceLock::run(function (): void {
            $Child = $this->command(['--version']);
            $Child->mustRun();
            self::assertStringContainsString('--version', $Child->getOutput());

            $Other = $this->command(['--version']);
            $Other->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
            $Other->run();

            self::assertSame(1, $Other->getExitCode());
            self::assertSame('', $Other->getOutput());
            self::assertStringContainsString('already active', $Other->getErrorOutput());
        });
    }

    public function testLegacyComposerEntryPointCanInferTheInstallationDirectory(): void
    {
        $Process = new Process(
            [PHP_BINARY, 'quiqqer.php', 'composer', '--version'],
            cwd: $this->directory . 'packages/quiqqer/core',
            timeout: 30
        );
        $Process->mustRun();

        self::assertStringContainsString('--version', $Process->getOutput());
    }

    public function testComposerCanBootstrapBeforeTheInstallationConfigurationExists(): void
    {
        unlink($this->directory . 'etc/conf.ini.php');
        $Process = $this->command(['--version']);
        $Process->mustRun();

        self::assertSame('', $Process->getErrorOutput());
        self::assertStringContainsString('--version', $Process->getOutput());
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): Process
    {
        return new Process([PHP_BINARY, $this->directory . 'console', 'composer', ...$arguments], timeout: 30);
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $Entry) {
            if ($Entry->isDir()) {
                $this->deleteDirectory($Entry->getPathname());
            } else {
                unlink($Entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
