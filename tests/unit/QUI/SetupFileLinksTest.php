<?php

namespace QUITests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SetupFileLinksTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-setup-paths-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
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

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function layouts(): iterable
    {
        yield 'standard directories' => ['app/packages', 'app/usr', false];
        yield 'nested directories' => ['app/dependencies/vendor', 'app/data/projects', false];
        yield 'sibling directories' => ['shared/packages', 'content/projects', false];
        yield 'quoted directories' => ["app/vendor's packages", "app/project's files", false];
        yield 'symlinked packages' => ['app/packages', 'app/usr', true];
    }

    #[DataProvider('layouts')]
    public function testGeneratedFilesSurviveRelocation(
        string $packages,
        string $projects,
        bool $symlinkPackages
    ): void {
        $original = $this->directory . '/original';
        $root = $original . '/app';
        $core = $original . '/' . $packages . '/quiqqer/core';

        if ($symlinkPackages) {
            mkdir($root, 0700, true);
            mkdir($original . '/shared/packages', 0700, true);
            symlink('../shared/packages', $original . '/' . $packages);
        }

        foreach ([$root . '/etc', $core . '/admin', $core . '/src', $original . '/' . $projects] as $directory) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($root . '/etc/conf.ini.php', "<?php // fixture\n");

        foreach (['bootstrap.php', 'ajax.php', 'index.php', 'image.php', 'quiqqer.php', 'admin/ajaxBundler.php'] as $file) {
            file_put_contents($core . '/' . $file, '<?php $GLOBALS["setupLoadedFiles"][] = __FILE__;');
        }

        $this->runPhp([
            __DIR__ . '/Fixtures/Setup/generate.php', $root, $original . '/' . $packages, $original . '/' . $projects
        ]);

        $entrypoints = [
            'app/bootstrap.php' => ['bootstrap.php'],
            'app/index.php' => ['bootstrap.php', 'index.php'],
            'app/image.php' => ['bootstrap.php', 'image.php'],
            'app/ajax.php' => ['ajax.php'],
            'app/ajaxBundler.php' => ['admin/ajaxBundler.php'],
            'app/quiqqer.php' => ['quiqqer.php'],
            'app/console' => ['quiqqer.php'],
            $projects . '/header.php' => ['bootstrap.php'],
            $packages . '/header.php' => ['bootstrap.php']
        ];

        foreach (array_keys($entrypoints) as $file) {
            $content = file_get_contents($original . '/' . $file);
            self::assertIsString($content);
            self::assertStringNotContainsString($original, $content);
            $this->runPhp(['-l', $original . '/' . $file]);
        }

        self::assertTrue(is_executable($root . '/console'));
        $moved = $this->directory . '/moved';
        rename($original, $moved);
        $root = $moved . '/app';
        $core = realpath($moved . '/' . $packages . '/quiqqer/core');
        self::assertIsString($core);

        foreach ($entrypoints as $file => $expectedFiles) {
            $result = $this->execute($moved . '/' . $file);
            self::assertSame(array_map(static fn ($path) => $core . '/' . $path, $expectedFiles), $result['files']);
            self::assertSame('', $result['body']);
            self::assertSame(200, $result['status']);

            if ($file === 'app/console' || $file === 'app/quiqqer.php') {
                self::assertSame($root . '/', $result['cmsDir']);
            }
        }

        // Missing configuration must load the local CLI entrypoint, regardless of the working directory.
        unlink($root . '/etc/conf.ini.php');
        self::assertSame([$core . '/quiqqer.php'], $this->execute($root . '/bootstrap.php')['files']);
        file_put_contents($root . '/etc/conf.ini.php', "<?php // fixture\n");

        // Maintenance gates and their existing exceptions must survive regeneration.
        file_put_contents($root . '/maintenance.html', 'maintenance');

        foreach (['index.php', 'ajax.php', 'ajaxBundler.php'] as $file) {
            $result = $this->execute($root . '/' . $file);
            self::assertSame([], $result['files']);
            self::assertSame(503, $result['status']);
        }

        foreach (['ajax.php', 'ajaxBundler.php'] as $file) {
            $result = $this->execute($root . '/' . $file, ['_FRONTEND' => '0']);
            self::assertNotSame([], $result['files']);
            self::assertSame(200, $result['status']);
        }

        self::assertSame(200, $this->execute($root . '/index.php', [], '/mcp')['status']);
        self::assertSame(200, $this->execute($root . '/index.php', [
            'systemId' => 'setup-path-test', 'ignoreMaintenance' => 1
        ])['status']);

        // Exercise the real CLI dispatch, but never download or execute a live migration.
        copy(dirname(__DIR__, 3) . '/quiqqer.php', $core . '/quiqqer.php');
        file_put_contents($core . '/src/pathMigration.php', '<?php $GLOBALS["setupLoadedFiles"][] = __FILE__;');
        $result = $this->execute($root . '/console', [], '/', 'system-migration', $root);
        self::assertSame([$core . '/src/pathMigration.php'], $result['files']);
    }

    /**
     * @param array<string, int|string> $request
     * @return array{files: list<string>, body: string, status: int, cmsDir: ?string}
     */
    private function execute(
        string $file,
        array $request = [],
        string $uri = '/',
        string $command = '',
        ?string $cwd = null
    ): array {
        return json_decode($this->runPhp([
            __DIR__ . '/Fixtures/Setup/run.php', $file, json_encode($request, JSON_THROW_ON_ERROR), $uri, $command
        ], $cwd), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $arguments
     */
    private function runPhp(array $arguments, ?string $cwd = null): string
    {
        $process = proc_open(
            array_merge([PHP_BINARY], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $this->directory
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$errors);
        self::assertSame('', $errors);
        self::assertIsString($output);

        return $output;
    }
}
