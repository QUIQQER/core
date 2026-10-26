<?php

namespace QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

class ConsoleTest extends TestCase
{
    public function testBusyMaintenanceProducesAnInformativeCliExitWithoutErrorLogging(): void
    {
        $log = sys_get_temp_dir() . '/quiqqer-busy-console-' . bin2hex(random_bytes(8)) . '.log';
        $command = [
            PHP_BINARY,
            __DIR__ . '/Fixtures/maintenance-busy-console.php',
            CMS_DIR,
            VAR_DIR,
            $log
        ];
        $Process = new Process($command, timeout: 10);

        try {
            $Process->run();

            self::assertSame(75, $Process->getExitCode(), $Process->getErrorOutput());
            self::assertStringContainsString('Operation: System setup', $Process->getOutput());
            self::assertStringContainsString('PID:       12345', $Process->getOutput());
            self::assertSame('', $Process->getErrorOutput());
            self::assertFileDoesNotExist($log);
        } finally {
            if (is_file($log)) {
                unlink($log);
            }
        }
    }

    public function testUnexpectedExceptionsStillReachTheNormalErrorHandler(): void
    {
        $Console = (new ReflectionClass(Console::class))->newInstanceWithoutConstructor();
        $Method = new ReflectionMethod(Console::class, 'executeWithTerminalProgress');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected failure');

        $Method->invoke($Console, static function (): void {
            throw new RuntimeException('Unexpected failure');
        });
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('titleVisibilityProvider')]
    public function testTitleVisibility(array $arguments, bool $expected): void
    {
        $Console = new class extends Console {
            public function __construct()
            {
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function titleIsVisible(array $arguments): bool
            {
                return $this->needsHelpHeader($arguments);
            }
        };

        self::assertSame($expected, $Console->titleIsVisible($arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function titleVisibilityProvider(): iterable
    {
        yield 'no arguments' => [[], true];
        yield 'global help' => [['--help' => true], true];
        yield 'system tool' => [['update' => true], false];
        yield 'system tool help' => [['update' => true, '--help' => true], false];
        yield 'registered tool' => [['quiqqer:test' => true], false];
        yield 'list tools' => [['--listtools' => true], false];
        yield 'explicit no logo' => [['--noLogo' => true], false];
        yield 'completion' => [['_complete' => true], false];
    }
}
