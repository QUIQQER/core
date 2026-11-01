<?php

namespace QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;

class ConsoleTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSystemSetupWarningFollowsTheFinalSuccessMessage(): void
    {
        $Tool = $this->getMockBuilder(Console\Tools\Setup::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $Tool->expects(self::once())->method('execute');
        $Console = $this->getMockBuilder(Console::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getArgument', 'writeLn', 'writePhpVersionWarning'])
            ->getMock();
        (new ReflectionProperty(Console::class, 'argv'))->setValue($Console, []);
        $Console->method('get')->willReturn($Tool);
        $Console->method('getArgument')->willReturnMap([
            ['#system-tool', 'setup'],
            ['help', false]
        ]);
        $Console->method('writeLn')->willReturnCallback(static function (string $message = ''): void {
            echo $message . PHP_EOL;
        });
        $Console->expects(self::once())->method('writePhpVersionWarning')->willReturnCallback(static function (): void {
            echo '[PHP warning]' . PHP_EOL;
        });
        ob_start();

        try {
            (new ReflectionMethod(Console::class, 'executeSystemTool'))->invoke($Console);
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertStringContainsString('Everything is done.', $output);
        self::assertStringEndsWith('[PHP warning]' . PHP_EOL, $output);
    }

    /** @param class-string<Console\Tool> $toolClass */
    #[DataProvider('completionTools')]
    public function testRegisteredToolWarningFollowsExecution(string $toolClass, bool $warn): void
    {
        $Tool = $this->getMockBuilder($toolClass)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $Tool->expects(self::once())->method('execute')->willReturnCallback(static function (): void {
            echo 'Tool completed' . PHP_EOL;
        });
        $Console = $this->getMockBuilder(Console::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getArgument', 'writePhpVersionWarning'])
            ->getMock();
        $Console->method('get')->willReturn($Tool);
        $Console->method('getArgument')->willReturnMap([
            ['tool', 'test'],
            ['help', false]
        ]);
        $Console->expects($warn ? self::once() : self::never())
            ->method('writePhpVersionWarning')
            ->willReturnCallback(static function (): void {
                echo '[PHP warning]' . PHP_EOL;
            });
        ob_start();

        try {
            $Console->start();
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $expected = 'Tool completed' . PHP_EOL;

        if ($warn) {
            $expected .= '[PHP warning]' . PHP_EOL;
        }

        self::assertSame($expected, $output);
    }

    /** @return iterable<string, array{class-string<Console\Tool>, bool}> */
    public static function completionTools(): iterable
    {
        yield 'setup' => [Console\Tools\Setup::class, true];
        yield 'update' => [Console\Tools\Update::class, true];
        yield 'security update' => [Console\Tools\SecurityUpdate::class, true];
        yield 'unrelated tool' => [Console\Tools\Licence::class, false];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('phpWarningHeaderArguments')]
    public function testHeadersShowPhpWarningExceptWhenSuppressed(array $arguments, bool $visible): void
    {
        $Console = $this->getMockBuilder(Console::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['readArgv', 'writePhpVersionWarning'])
            ->getMock();
        $Console->method('readArgv')->willReturn($arguments);
        $Console->expects($visible ? self::once() : self::never())->method('writePhpVersionWarning');
        ob_start();

        try {
            $Console->title();
        } finally {
            ob_end_clean();
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function phpWarningHeaderArguments(): iterable
    {
        yield 'compact header' => [['update' => true], true];
        yield 'help header' => [[], true];
        yield 'explicit help' => [['--help' => true], true];
        yield 'suppressed header' => [['update' => true, '--no-header' => true], false];
        yield 'shell completion' => [['_complete' => true], false];
    }

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
