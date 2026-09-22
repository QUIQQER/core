<?php

namespace QUI\System\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function fopen;
use function rewind;
use function stream_get_contents;

class TerminalProgressTest extends TestCase
{
    #[DataProvider('terminalEnvironments')]
    public function testAutomaticDetectionOnInteractiveOutput(
        ?string $terminal,
        string $screenSession,
        bool $expectedProgress
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PTY descriptors require a Unix terminal.');
        }

        $classFile = (new \ReflectionClass(TerminalProgress::class))->getFileName();
        $code = 'require ' . var_export($classFile, true) . ';' . <<<'PHP'
            if (!stream_isatty(STDOUT)) {
                exit(2);
            }

            $Progress = new \QUI\System\Console\TerminalProgress(null, null, 'core', false);
            $result = $Progress->run(static function (): string {
                fwrite(STDOUT, 'command-output');
                return 'done';
            });
            fwrite(STDOUT, $result);
            PHP;
        $environment = ['STY' => $screenSession];

        if ($terminal !== null) {
            $environment['TERM'] = $terminal;
        }

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pty'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);

        // Linux reports EIO when the child closes the PTY slave, rather than normal EOF.
        $output = @stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $errors);
        $this->assertSame('', $errors);

        if ($expectedProgress) {
            $this->assertSame(
                "\033[22;0t\033]9;4;3;0\033\\\033]0;⠋ QUIQQER (core)\007"
                . "command-output\033]9;4;0;0\033\\\033[23;0tdone",
                $output
            );
        } else {
            $this->assertSame('command-outputdone', $output);
        }
    }

    public static function terminalEnvironments(): array
    {
        return [
            'screen' => ['screen', '', false],
            'screen with colors' => ['screen-256color', '', false],
            'screen with capabilities suffix' => ['screen.xterm-256color', '', false],
            'screen with overridden TERM' => ['xterm-256color', '1234.session', false],
            'dumb terminal' => ['dumb', '', false],
            'missing TERM' => [null, '', false],
            'empty TERM' => ['', '', false],
            'ordinary terminal' => ['xterm-256color', '', true]
        ];
    }

    public function testRunReportsIndeterminateProgressAndHidesItAfterwards(): void
    {
        $output = fopen('php://memory', 'w+');
        $Progress = new TerminalProgress($output, true, 'core', false);

        $result = $Progress->run(static fn(): string => 'done');

        rewind($output);

        $this->assertSame('done', $result);
        $this->assertSame(
            "\033[22;0t\033]9;4;3;0\033\\\033]0;⠋ QUIQQER (core)\007\033]9;4;0;0\033\\\033[23;0t",
            stream_get_contents($output)
        );

        fclose($output);
    }

    public function testRunHidesProgressWhenCallbackThrows(): void
    {
        $output = fopen('php://memory', 'w+');
        $Progress = new TerminalProgress($output, true, 'core', false);

        try {
            $Progress->run(static function (): void {
                throw new RuntimeException('Test exception');
            });

            $this->fail('Expected callback exception was not thrown.');
        } catch (RuntimeException $Exception) {
            $this->assertSame('Test exception', $Exception->getMessage());
        }

        rewind($output);

        $this->assertSame(
            "\033[22;0t\033]9;4;3;0\033\\\033]0;⠋ QUIQQER (core)\007\033]9;4;0;0\033\\\033[23;0t",
            stream_get_contents($output)
        );

        fclose($output);
    }

    public function testNonInteractiveOutputDoesNotReceiveControlSequences(): void
    {
        $output = fopen('php://memory', 'w+');
        $Progress = new TerminalProgress($output);

        $Progress->run(static fn(): string => 'done');

        rewind($output);

        $this->assertSame('', stream_get_contents($output));

        fclose($output);
    }
}
