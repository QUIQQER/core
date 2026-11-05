<?php

namespace QUI\System\Update;

use PHPUnit\Framework\TestCase;
use QUI\System\Update\Fixtures\RecordingUpdateRunAction;

require_once __DIR__ . '/Fixtures/RecordingUpdateRunAction.php';

class RunEntrypointTest extends TestCase
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

    public function testExecuteReadsWebTokenAndReturnsJsonSuccess(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::next(RunState::PHASE_PREPARED)),
                RunState::PHASE_PREPARED => new RecordingUpdateRunAction(RunActionResult::finished())
            ],
            [
                'token' => $run->getToken(),
                'foreground' => '1'
            ],
            [],
            'cgi-fcgi',
            1001
        );
        $output = (string)ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'success' => true,
            'id' => $run->getState()->getId(),
            'phase' => RunState::PHASE_FINISHED,
            'status' => RunState::STATUS_FINISHED
        ], json_decode($output, true));
    }

    public function testExecuteAppliesWebYesArgumentToRunMetadata(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();
        $action = new RecordingUpdateRunAction(RunActionResult::finished());

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => $action
            ],
            [
                'token' => $run->getToken(),
                'foreground' => '1',
                'yes' => '1'
            ],
            [],
            'cgi-fcgi',
            1001
        );
        ob_end_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame(['yes' => true], $action->metadata[0]['arguments'] ?? []);
    }

    public function testExecuteReadsCliTokenFromFirstArgument(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
            ],
            [],
            ['execute.php', $run->getToken()],
            'cli',
            1001
        );
        $output = (string)ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame('  [OK] Update finished.' . PHP_EOL . PHP_EOL, $output);
    }

    public function testExecuteReturnsCliRestartMessage(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::next(RunState::PHASE_PREPARED)),
                RunState::PHASE_PREPARED => new RecordingUpdateRunAction(
                    RunActionResult::next(RunState::PHASE_COMPOSER_UPDATE)
                ),
                RunState::PHASE_COMPOSER_UPDATE => new RecordingUpdateRunAction(RunActionResult::restartRequired())
            ],
            [],
            ['execute.php', $run->getToken()],
            'cli',
            1001
        );
        $output = (string)ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            '[2/6] Composer tool' . PHP_EOL
            . '  [OK] Composer updated' . PHP_EOL
            . '  [..] Continuing with system update' . PHP_EOL,
            $output
        );
    }

    public function testCompletionRestartMessageStaysUnindented(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::next(RunState::PHASE_PREPARED)),
                RunState::PHASE_PREPARED => new RecordingUpdateRunAction(
                    RunActionResult::next(RunState::PHASE_SYSTEM_UPDATE)
                ),
                RunState::PHASE_SYSTEM_UPDATE => new RecordingUpdateRunAction(
                    RunActionResult::restartAt(RunState::PHASE_CLEANUP)
                )
            ],
            [],
            ['execute.php', $run->getToken()],
            'cli',
            1001
        );
        $output = (string)ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertSame('[..] Continuing update completion in a fresh process' . PHP_EOL, $output);
    }

    public function testExecuteReturnsCliFailureMessage(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
            ],
            [],
            [],
            'cli',
            1001
        );
        $output = (string)ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Update failed', $output);
        $this->assertStringContainsString('Invalid update run token.', $output);
        $this->assertStringContainsString("\033[41;37m", $output);
    }

    public function testExecuteReturnsFailureForMissingTokenWithoutChangingState(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $exitCode = $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
            ],
            [],
            [],
            'cgi-fcgi',
            1001
        );
        $output = (string)ob_get_clean();
        $state = $repository->load($run->getState()->getId());

        $this->assertSame(1, $exitCode);
        $this->assertFalse(json_decode($output, true)['success']);
        $this->assertSame(RunState::PHASE_CREATED, $state->getPhase());
        $this->assertSame(RunState::STATUS_CREATED, $state->getStatus());
    }

    public function testWebSessionFailureDoesNotExposeCliCommand(): void
    {
        $repository = new RunRepository($this->root, 600);
        $run = $repository->create(1000);
        $entrypoint = new RunEntrypoint();

        ob_start();
        $entrypoint->execute(
            $run->getState()->getId(),
            $this->root,
            [
                RunState::PHASE_CREATED => new RecordingUpdateRunAction(RunActionResult::finished())
            ],
            ['token' => 'web-session-secret'],
            [],
            'cgi-fcgi',
            1001,
            false
        );
        $payload = json_decode((string)ob_get_clean(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertArrayNotHasKey('cliCommand', $payload);
        $this->assertStringNotContainsString('web-session-secret', (string)json_encode($payload));
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
