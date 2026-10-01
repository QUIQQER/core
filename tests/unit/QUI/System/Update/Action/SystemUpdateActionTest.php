<?php

namespace QUI\System\Update\Action;

use PHPUnit\Framework\TestCase;
use QUI\System\Console\Tools\Update;
use QUI\System\Update\RunState;
use RuntimeException;

class SystemUpdateActionTest extends TestCase
{
    public function testPackageUpdateDefersCompletionAndPersistsItsArguments(): void
    {
        $completion = ['backupFolder' => '/tmp/quiqqer-etc-backup', 'finalizePackages' => true];
        $Update = $this->createMock(Update::class);
        $Update->expects($this->once())->method('executeSystemUpdate')->with(true)->willReturn(true);
        $Update->expects($this->never())->method('completeSystemUpdate');
        $Update->method('getPendingCompletion')->willReturn($completion);
        $state = $this->state();

        $result = $this->action($Update)->execute($state);

        $this->assertTrue($result->isRestartRequired());
        $this->assertSame(RunState::PHASE_CLEANUP, $result->getNextPhase());
        $this->assertSame($completion, $state->getMetadata()['systemUpdateCompletion']);
    }

    public function testCompletionDoesNotRepeatComposerAndKeepsPackageUpdateSemantics(): void
    {
        foreach ([true, false] as $finalizePackages) {
            $Update = $this->createMock(Update::class);
            $Update->expects($this->never())->method('executeSystemUpdate');
            $Update->expects($this->once())->method('completeSystemUpdate')
                ->with('/tmp/quiqqer-etc-backup', $finalizePackages)->willReturn(true);
            $state = $this->state();
            $state->setMetadataValue('systemUpdateCompletion', [
                'backupFolder' => '/tmp/quiqqer-etc-backup',
                'finalizePackages' => $finalizePackages
            ]);

            $this->assertTrue($this->action($Update, true)->execute($state)->isFinished());
        }
    }

    public function testCompletionFailureDoesNotMarkRunFinished(): void
    {
        $Update = $this->createMock(Update::class);
        $Update->method('completeSystemUpdate')->willReturn(false);
        $state = $this->state();
        $state->setMetadataValue('systemUpdateCompletion', [
            'backupFolder' => '/tmp/quiqqer-etc-backup',
            'finalizePackages' => true
        ]);

        $this->expectException(RuntimeException::class);
        $this->action($Update, true)->execute($state);
    }

    public function testCheckOnlyAndLegacyCompletedRunsNeedNoCompletion(): void
    {
        foreach ([false, true] as $complete) {
            $Update = $this->createMock(Update::class);
            $Update->method('executeSystemUpdate')->willReturn(true);
            $Update->method('getPendingCompletion')->willReturn(null);
            $Update->expects($this->never())->method('completeSystemUpdate');

            $this->assertTrue($this->action($Update, $complete)->execute($this->state())->isFinished());
        }
    }

    private function action(Update $Update, bool $complete = false): SystemUpdateAction
    {
        $Action = $this->getMockBuilder(SystemUpdateAction::class)
            ->setConstructorArgs([$complete])->onlyMethods(['createUpdate'])->getMock();
        $Action->method('createUpdate')->willReturn($Update);

        return $Action;
    }

    private function state(): RunState
    {
        return RunState::create(str_repeat('a', 32), hash('sha256', 'token'), time(), 600);
    }
}
