<?php

namespace QUI\System\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunCompletionProcessTest extends TestCase
{
    public static function completionModes(): array
    {
        return [
            'background runner' => [true, false],
            'CLI supervisor' => [false, false],
            'background listener failure' => [true, true],
            'CLI listener failure' => [false, true]
        ];
    }

    #[DataProvider('completionModes')]
    public function testCompletionUsesFreshDefinitionsAndPropagatesFailure(bool $loop, bool $fail): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('This regression test requires subprocesses.');
        }

        $root = sys_get_temp_dir() . '/quiqqer_update_completion_' . bin2hex(random_bytes(8));
        $repository = new RunRepository($root);
        $run = $repository->create(null, ['failCompletion' => $fail]);
        $id = $run->getState()->getId();
        $directory = $repository->getRunDirectory($id);
        file_put_contents($directory . 'library.php', '<?php namespace UpdateRunnerLibrary; function existing() {}');
        file_put_contents(
            $run->getExecuteFile(),
            '<?php require ' . var_export(__DIR__ . '/Fixtures/ReplacedLibraryUpdateRunner.php', true) . ';'
        );
        $command = [PHP_BINARY, '-d', 'xdebug.mode=off', $run->getExecuteFile(), $run->getToken()];

        if ($loop) {
            $command[] = '--loop=5';
        }

        try {
            [$exitCode, $output] = $this->runProcess($command);

            if (!$loop) {
                $this->assertSame(0, $exitCode, $output);
                $this->assertSame(RunState::STATUS_RESTART_REQUIRED, $repository->load($id)->getStatus());
                $this->assertCount(1, file($directory . 'events.jsonl'));
                [$exitCode, $output] = $this->runProcess($command);
            }

            $this->assertSame($fail ? 1 : 0, $exitCode, $output);
            $state = $repository->load($id);
            $this->assertSame($fail ? RunState::STATUS_FAILED : RunState::STATUS_FINISHED, $state->getStatus());
            $events = array_map(static fn ($line) => json_decode($line, true), file($directory . 'events.jsonl'));
            $this->assertSame(['update', 'updateEnd'], array_column($events, 0));
            $this->assertNotSame($events[0][1], $events[1][1]);

            if ($fail) {
                $this->assertSame('completion listener failed', $state->toArray()['errorMessage']);
                $this->assertStringContainsString('completion listener failed', $output);
            } else {
                [$exitCode, $output] = $this->runProcess($command);
                $this->assertSame(0, $exitCode, $output);
                $this->assertCount(2, file($directory . 'events.jsonl'), 'Completion must not run twice.');
            }
        } finally {
            $repository->delete($id);
            rmdir($root);
        }
    }

    private function runProcess(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $errors];
    }
}
