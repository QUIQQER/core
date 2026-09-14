<?php

namespace QUI\System\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunInterruptionTest extends TestCase
{
    /**
     * @return array<string, array{int, string, string, int, bool}>
     */
    public static function interruptions(): array
    {
        return [
            'Ctrl+C' => [2, '', RunState::STATUS_CANCELLED, 130, false],
            'termination' => [15, '', RunState::STATUS_CANCELLED, 143, false],
            'hard kill' => [9, '', RunState::STATUS_RUNNING, -1, false],
            'unhandled Ctrl+C' => [2, 'unhandled', RunState::STATUS_RUNNING, -1, false],
            'unhandled termination' => [15, 'unhandled', RunState::STATUS_RUNNING, -1, false],
            'unreaped container process' => [9, '', RunState::STATUS_RUNNING, -1, true]
        ];
    }

    #[DataProvider('interruptions')]
    public function testInterruptedRunnerDoesNotBlockNextUpdate(
        int $signal,
        string $mode,
        string $statusBeforeRecovery,
        int $expectedExitCode,
        bool $leaveZombie
    ): void {
        if (!function_exists('proc_open') || !extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('The signal regression test requires proc_open, pcntl and posix.');
        }

        if ($leaveZombie && !is_readable('/proc/self/stat')) {
            $this->markTestSkipped('Zombie process detection requires Linux procfs.');
        }

        $root = sys_get_temp_dir() . '/quiqqer_update_interruption_' . bin2hex(random_bytes(8));
        $repository = new RunRepository($root);
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            __DIR__ . '/Fixtures/InterruptedUpdateRunner.php',
            $root,
            $mode
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        try {
            stream_set_timeout($pipes[1], 5);
            $id = trim((string)fgets($pipes[1]));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
            $processStatus = proc_get_status($process);
            $state = $repository->load($id);
            $this->assertSame($processStatus['pid'], $state->getProcess()['pid']);
            $this->assertCount(1, $repository->cleanupAndFindActive(time(), 86400)['active']);

            $this->assertTrue(proc_terminate($process, $signal));
            $deadline = microtime(true) + 5;

            do {
                usleep(10000);

                if ($leaveZombie) {
                    // proc_get_status() would reap the process and hide the container case.
                    $stat = (string)file_get_contents('/proc/' . $processStatus['pid'] . '/stat');
                    $running = preg_match('/^\d+ \(.*\) Z /s', $stat) !== 1;
                    continue;
                }

                $processStatus = proc_get_status($process);
                $running = $processStatus['running'];
            } while ($running && microtime(true) < $deadline);

            $this->assertFalse($running, 'The interrupted runner must exit.');

            if (!$leaveZombie) {
                $this->assertSame($expectedExitCode, $processStatus['exitcode']);
            }
            $this->assertSame($statusBeforeRecovery, $repository->load($id)->getStatus());

            $result = $repository->cleanupAndFindActive(time(), 86400);
            $this->assertSame([], $result['active']);
            $this->assertSame([], $result['deleted']);
            $this->assertSame(
                $statusBeforeRecovery === RunState::STATUS_RUNNING ? RunState::STATUS_FAILED : RunState::STATUS_CANCELLED,
                $repository->load($id)->getStatus()
            );
            $this->assertFileExists($repository->getRunDirectory($id) . 'state.json');
            $this->assertSame([], $repository->cleanupAndFindActive(time(), 86400)['active']);
        } finally {
            $processStatus = proc_get_status($process);

            if ($processStatus['running']) {
                proc_terminate($process, 9);
            }

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);

            foreach ($repository->list() as $state) {
                $repository->delete($state->getId());
            }

            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
}
