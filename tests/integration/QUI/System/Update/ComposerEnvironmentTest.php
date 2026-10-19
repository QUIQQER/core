<?php

namespace QUI\System\Update;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Process\Process;

class ComposerEnvironmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer composer environment ' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/composer', 0700, true);
        $project = [
            'name' => 'test/composer-environment',
            'require' => [
                'test/update-fixture' => '1.0.1'
            ],
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'test/update-fixture',
                        'version' => '1.0.1',
                        'type' => 'metapackage'
                    ]
                ],
                ['packagist.org' => false]
            ]
        ];
        file_put_contents(
            $this->directory . '/composer.json',
            json_encode($project, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory . '/composer')) {
            chmod($this->directory . '/composer', 0700);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    public static function composerEnvironments(): array
    {
        return [
            'missing HOME and COMPOSER_HOME' => [false, false, ''],
            'empty HOME and COMPOSER_HOME' => ['', '', ''],
            'existing user HOME' => ['/existing/user/home', false, ''],
            'stale Composer environment' => [false, '/obsolete/composer/home', ''],
            'putenv disabled in the parent process' => [false, false, 'putenv']
        ];
    }

    #[DataProvider('composerEnvironments')]
    public function testComposerStatusAndUpdate(
        string|false $home,
        string|false $composerHome,
        string $disabledFunctions
    ): void {
        $composer = dirname((new ReflectionClass(Application::class))->getFileName(), 4) . '/bin/composer';
        $command = [
            PHP_BINARY,
            __DIR__ . '/Fixtures/ComposerEnvironmentWorker.php',
            dirname(__DIR__, 7) . '/autoload.php',
            $composer,
            $this->directory
        ];
        $environment = [
            'HOME' => false,
            'COMPOSER_HOME' => false
        ];
        $Before = new Process([...$command, 'putenv-only'], $this->directory, $environment);
        $Before->run();

        $this->assertFalse($Before->isSuccessful());
        $this->assertStringContainsString(
            'The HOME or COMPOSER_HOME environment variable must be set',
            $Before->getOutput() . $Before->getErrorOutput()
        );
        $this->assertFileDoesNotExist($this->directory . '/composer.lock');

        array_splice($command, 1, 0, ['-d', 'disable_functions=' . $disabledFunctions]);
        $environment['HOME'] = $home;
        $environment['COMPOSER_HOME'] = $composerHome;
        $After = new Process([...$command, 'prepare'], $this->directory, $environment);
        $After->run();

        $this->assertSame(0, $After->getExitCode(), $After->getOutput() . $After->getErrorOutput());
        $lock = json_decode(
            file_get_contents($this->directory . '/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('1.0.1', $lock['packages'][0]['version']);
        $this->assertFileExists($this->directory . '/vendor/autoload.php');
    }

    public function testMissingComposerDirectoryFailsPreflight(): void
    {
        rmdir($this->directory . '/composer');
        $this->assertPreflightFails();
    }

    public function testReadOnlyComposerDirectoryFailsPreflight(): void
    {
        $composerHome = $this->directory . '/composer';
        chmod($composerHome, 0500);
        clearstatcache(true, $composerHome);

        if (is_writable($composerHome)) {
            $this->markTestSkipped('The current user can write to read-only directories.');
        }

        $this->assertPreflightFails();
    }

    private function assertPreflightFails(): void
    {
        $Process = new Process([
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            __DIR__ . '/Fixtures/ComposerEnvironmentWorker.php',
            dirname(__DIR__, 7) . '/autoload.php',
            'unused-composer-command',
            $this->directory,
            'prepare'
        ]);
        $Process->run();
        $output = $Process->getOutput() . $Process->getErrorOutput();

        $this->assertFalse($Process->isSuccessful());
        $this->assertStringContainsString('COMPOSER_HOME', $output);
        $this->assertStringContainsString('writable', $output);
        $this->assertFileDoesNotExist($this->directory . '/composer.lock');
    }
}
