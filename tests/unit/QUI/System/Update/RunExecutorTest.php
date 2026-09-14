<?php

namespace QUI\System\Update;

use PHPUnit\Framework\TestCase;
use QUI\System\Update\Fixtures\FailingUpdateRunAction;
use QUI\System\Update\Fixtures\RecordingUpdateRunAction;
use RuntimeException;

require_once __DIR__ . '/Fixtures/RecordingUpdateRunAction.php';
require_once __DIR__ . '/Fixtures/FailingUpdateRunAction.php';

class RunExecutorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = '/tmp/quiqqer_update_runner_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->deleteDirectory($this->root);
        }
    }

    public function testExecuteRunsActionAndPersistsNextPhase(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $action = new RecordingUpdateRunAction(RunActionResult::next(RunState::PHASE_PREPARED));
        $executor = new RunExecutor($repository, [
            RunState::PHASE_CREATED => $action
        ]);

        $state = $executor->execute($run->getState()->getId(), $run->getToken(), 1001);
        $loaded = $repository->load($run->getState()->getId());

        $this->assertSame([$run->getState()->getId()], $action->executedRunIds);
        $this->assertSame(RunState::PHASE_PREPARED, $state->getPhase());
        $this->assertSame(RunState::PHASE_PREPARED, $loaded->getPhase());
        $this->assertSame(getmypid(), $loaded->getProcess()['pid']);
        $this->assertSame('runner', $loaded->getProcess()['method']);
    }

    public function testExecuteCanMarkRestartRequired(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $state = $run->getState();
        $state->transitionTo(RunState::PHASE_PREPARED);
        $state->transitionTo(RunState::PHASE_COMPOSER_UPDATE);
        $repository->save($state);

        $executor = new RunExecutor($repository, [
            RunState::PHASE_COMPOSER_UPDATE => new RecordingUpdateRunAction(RunActionResult::restartRequired())
        ]);

        $result = $executor->execute($state->getId(), $run->getToken(), 1001);

        $this->assertSame(RunState::PHASE_RESTART_REQUIRED, $result->getPhase());
        $this->assertSame(RunState::STATUS_RESTART_REQUIRED, $result->getStatus());
    }

    public function testExecuteCanMarkFinished(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $executor = new RunExecutor($repository, [
            RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
        ]);

        $result = $executor->execute($run->getState()->getId(), $run->getToken(), 1001);

        $this->assertSame(RunState::PHASE_FINISHED, $result->getPhase());
        $this->assertSame(RunState::STATUS_FINISHED, $result->getStatus());
    }

    public function testExecuteRejectsWrongTokenWithoutChangingState(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $executor = new RunExecutor($repository, [
            RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
        ]);

        try {
            $executor->execute($run->getState()->getId(), 'wrong-token', 1001);
            $this->fail('Expected exception was not thrown.');
        } catch (\InvalidArgumentException) {
        }

        $state = $repository->load($run->getState()->getId());

        $this->assertSame(RunState::PHASE_CREATED, $state->getPhase());
        $this->assertSame(RunState::STATUS_CREATED, $state->getStatus());
    }

    public function testExecuteRejectsExpiredRunWithoutChangingState(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $executor = new RunExecutor($repository, [
            RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
        ]);

        try {
            $executor->execute($run->getState()->getId(), $run->getToken(), 1601);
            $this->fail('Expected exception was not thrown.');
        } catch (\InvalidArgumentException) {
        }

        $state = $repository->load($run->getState()->getId());

        $this->assertSame(RunState::PHASE_CREATED, $state->getPhase());
        $this->assertSame(RunState::STATUS_CREATED, $state->getStatus());
    }

    public function testExecutePersistsActionFailure(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $executor = new RunExecutor($repository, [
            RunState::PHASE_CREATED => new FailingUpdateRunAction()
        ]);

        try {
            $executor->execute($run->getState()->getId(), $run->getToken(), 1001);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
        }

        $state = $repository->load($run->getState()->getId());

        $this->assertSame(RunState::PHASE_FAILED, $state->getPhase());
        $this->assertSame('action failed', $state->toArray()['errorMessage']);
    }

    public function testExecuteRestoresSignalHandlersAfterSuccessAndFailure(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('Signal handler restoration requires pcntl.');
        }

        $previousInt = pcntl_signal_get_handler(SIGINT);
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousAsync = pcntl_async_signals();
        $handler = static function (): void {
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
        pcntl_async_signals(false);

        try {
            foreach ([new RecordingUpdateRunAction(RunActionResult::finished()), new FailingUpdateRunAction()] as $action) {
                $repository = new RunRepository($this->root);
                $run = $repository->create();

                try {
                    (new RunExecutor($repository, [RunState::PHASE_CREATED => $action]))->execute(
                        $run->getState()->getId(),
                        $run->getToken()
                    );
                } catch (RuntimeException $exception) {
                    $this->assertSame('action failed', $exception->getMessage());
                }

                $this->assertSame($handler, pcntl_signal_get_handler(SIGINT));
                $this->assertSame($handler, pcntl_signal_get_handler(SIGTERM));
                $this->assertFalse(pcntl_async_signals());
            }
        } finally {
            pcntl_signal(SIGINT, $previousInt);
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_async_signals($previousAsync);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $items = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
