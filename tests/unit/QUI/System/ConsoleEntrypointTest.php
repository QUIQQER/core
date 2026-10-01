<?php

namespace QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ConsoleEntrypointTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-console-entrypoint-' . bin2hex(random_bytes(8));
        $core = $this->directory . '/packages/quiqqer/core';
        $composer = $this->directory . '/packages/composer/composer/bin';
        mkdir($core . '/src', 0700, true);
        mkdir($composer, 0700, true);
        self::assertTrue(copy(dirname(__DIR__, 4) . '/quiqqer.php', $core . '/quiqqer.php'));
        file_put_contents(
            $this->directory . '/console',
            '<?php define("CMS_DIR", __DIR__ . "/"); require __DIR__ . "/packages/quiqqer/core/quiqqer.php";'
        );

        // Exercise dispatch without running repairs, downloads, or Composer mutations.
        foreach ([$core . '/src/repair.php', $core . '/src/pathMigration.php', $composer . '/composer'] as $file) {
            file_put_contents($file, '<?php echo json_encode(array_values($_SERVER["argv"]));');
        }
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
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
    }

    #[DataProvider('bootstrapCommandsProvider')]
    public function testNoHeaderPreservesBootstrapCommandArguments(string $command): void
    {
        $normal = $this->runConsole([$command, '--help']);

        self::assertSame($normal, $this->runConsole(['--no-header', $command, '--help']));
        self::assertSame($normal, $this->runConsole([$command, '--help', '--no-header']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bootstrapCommandsProvider(): iterable
    {
        foreach (['repair', 'system-migration', 'composer'] as $command) {
            yield $command => [$command];
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function runConsole(array $arguments): string
    {
        $Process = proc_open(
            [PHP_BINARY, $this->directory . '/console', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory
        );

        self::assertIsResource($Process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($Process), (string)$errors);
        self::assertSame('', $errors);
        self::assertIsString($output);

        return $output;
    }
}
