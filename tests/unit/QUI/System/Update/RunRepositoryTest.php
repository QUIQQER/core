<?php

namespace QUI\System\Update;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RunRepositoryTest extends TestCase
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

    public function testCreateWritesRunFilesAndState(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $state = $run->getState();

        $this->assertDirectoryExists($run->getDirectory());
        $this->assertFileExists($run->getDirectory() . 'state.json');
        $this->assertFileExists($run->getExecuteFile());
        $this->assertSame(RunState::PHASE_CREATED, $state->getPhase());
        $this->assertSame(RunState::STATUS_CREATED, $state->getStatus());
        $this->assertNotSame($state->getId(), $run->getToken());
        $this->assertStringContainsString($state->getId(), (string)file_get_contents($run->getExecuteFile()));
    }

    public function testLoadReturnsPersistedState(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);

        $loaded = $repository->load($run->getState()->getId());

        $this->assertSame($run->getState()->getId(), $loaded->getId());
        $this->assertSame(RunState::PHASE_CREATED, $loaded->getPhase());
    }

    public function testRejectsInvalidRunIdentifier(): void
    {
        $repository = new RunRepository($this->root, 600);

        $this->expectException(InvalidArgumentException::class);

        $repository->load('../state');
    }

    public function testMissingStateThrowsException(): void
    {
        $repository = new RunRepository($this->root, 600);

        $this->expectException(RuntimeException::class);

        $repository->load(str_repeat('a', 32));
    }

    public function testLockRejectsSecondConcurrentLock(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $lock = $repository->acquireLock($run->getState()->getId());

        try {
            $this->expectException(RuntimeException::class);
            $repository->acquireLock($run->getState()->getId());
        } finally {
            $repository->releaseLock($lock);
        }
    }

    public function testDeleteRemovesRunDirectory(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $directory = $run->getDirectory();

        $repository->delete($run->getState()->getId());

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testDeleteExpiredRemovesOnlyExpiredRuns(): void
    {
        $repository = new RunRepository($this->root, 600);
        $expiredRun = $repository->create(1000);
        $activeRun = $repository->create(2000);

        $deleted = $repository->deleteExpired(1601);

        $this->assertSame([$expiredRun->getState()->getId()], $deleted);
        $this->assertDirectoryDoesNotExist($expiredRun->getDirectory());
        $this->assertDirectoryExists($activeRun->getDirectory());
    }

    public function testCleanupAndFindActiveDeletesOldRunsButKeepsFailedRuns(): void
    {
        $repository = new RunRepository($this->root, 600);
        $oldRun = $repository->create(1000);
        $freshRun = $repository->create(2000);
        $failedRun = $repository->create(500);
        $cancelledRun = $repository->create(600);
        $failedState = $failedRun->getState();
        $failedState->markFailed('failed for debugging', 501);
        $repository->save($failedState);
        $cancelledState = $cancelledRun->getState();
        $cancelledState->markCancelled('cancelled for debugging', 601);
        $repository->save($cancelledState);

        $result = $repository->cleanupAndFindActive(2000, 864);

        $this->assertSame([$oldRun->getState()->getId()], $result['deleted']);
        $this->assertSame([$freshRun->getState()->getId()], array_map(
            static fn (RunState $state): string => $state->getId(),
            $result['active']
        ));
        $this->assertDirectoryDoesNotExist($oldRun->getDirectory());
        $this->assertDirectoryExists($freshRun->getDirectory());
        $this->assertDirectoryExists($failedRun->getDirectory());
        $this->assertDirectoryExists($cancelledRun->getDirectory());
    }

    public function testListReturnsNewestRunsFirstAndRespectsLimit(): void
    {
        $repository = new RunRepository($this->root, 600);
        $oldRun = $repository->create(1000);
        $newRun = $repository->create(3000);
        $middleRun = $repository->create(2000);

        $runs = $repository->list(2);

        $this->assertSame([
            $newRun->getState()->getId(),
            $middleRun->getState()->getId()
        ], array_map(
            static fn (RunState $state): string => $state->getId(),
            $runs
        ));
        $this->assertNotContains($oldRun->getState()->getId(), array_map(
            static fn (RunState $state): string => $state->getId(),
            $runs
        ));
    }

    public function testCleanupKeepsLiveRunEvenAfterMaximumAge(): void
    {
        $repository = new RunRepository($this->root);
        $run = $repository->create(1000);
        $state = $run->getState();
        $state->markRunning(1001);
        $state->setProcess(getmypid(), '', 1001);
        $repository->save($state);

        $result = $repository->cleanupAndFindActive(100000, 86400);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(1, $result['active']);
        $this->assertSame(RunState::STATUS_RUNNING, $repository->load($state->getId())->getStatus());
    }

    public function testCleanupKeepsRunningStateWithoutProcessInformation(): void
    {
        $repository = new RunRepository($this->root);
        $run = $repository->create();
        $state = $run->getState();
        $state->markRunning(time());
        $repository->save($state);

        $result = $repository->cleanupAndFindActive(time(), 86400);

        $this->assertCount(1, $result['active']);
        $this->assertSame(RunState::STATUS_RUNNING, $repository->load($state->getId())->getStatus());
    }

    public function testCleanupPreservesPendingRestartWithoutLiveProcess(): void
    {
        $repository = new RunRepository($this->root);
        $run = $repository->create();
        $state = $run->getState();
        $state->transitionTo(RunState::PHASE_PREPARED);
        $state->transitionTo(RunState::PHASE_COMPOSER_UPDATE);
        $state->markRestartRequired();
        $state->setProcess(2147483647, '', time());
        $repository->save($state);

        $result = $repository->cleanupAndFindActive(time(), 86400);

        $this->assertCount(1, $result['active']);
        $this->assertSame(RunState::STATUS_RESTART_REQUIRED, $repository->load($state->getId())->getStatus());
    }

    public function testCleanupNeverDeletesOrRecoversLockedRun(): void
    {
        $repository = new RunRepository($this->root);
        $run = $repository->create(1000);
        $state = $run->getState();
        $state->markRunning(1001);
        $state->setProcess(2147483647, '', 1001);
        $repository->save($state);
        $lock = $repository->acquireLock($state->getId());

        try {
            $result = $repository->cleanupAndFindActive(100000, 86400);
            $this->assertSame([], $result['deleted']);
            $this->assertCount(1, $result['active']);
            $this->assertSame(RunState::STATUS_RUNNING, $repository->load($state->getId())->getStatus());

            $state->markCancelled('Signal received; child still holds the lock.', 1002);
            $repository->save($state);
            $this->assertCount(1, $repository->cleanupAndFindActive(100000, 86400)['active']);
        } finally {
            $repository->releaseLock($lock);
        }
    }

    public function testCancelMarksActiveRunAsCancelledAndKeepsProcessData(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $state = $run->getState();
        $state->setProcess(1234, 'php execute.php token', 1001);
        $repository->save($state);

        $cancelled = $repository->cancel($run->getState()->getId(), 1002);

        $this->assertSame(RunState::STATUS_CANCELLED, $cancelled->getStatus());
        $this->assertSame(RunState::PHASE_CANCELLED, $cancelled->getPhase());
        $this->assertSame(1234, $cancelled->getProcess()['pid']);
        $this->assertArrayNotHasKey('command', $cancelled->getProcess());
        $this->assertStringNotContainsString(
            'php execute.php token',
            (string)file_get_contents($run->getDirectory() . 'state.json')
        );
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
