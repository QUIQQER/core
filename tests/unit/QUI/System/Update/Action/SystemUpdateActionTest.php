<?php

namespace QUI\System\Update\Action;

use PHPUnit\Framework\TestCase;
use QUI\System\Console\Tools\Update;
use QUI\System\Update\RunState;
use RuntimeException;

class SystemUpdateActionTest extends TestCase
{
    private string|false $composerHome;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        $this->composerHome = getenv('COMPOSER_HOME');
        $this->server = $_SERVER;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        putenv($this->composerHome === false ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $this->composerHome);
        $_SERVER = $this->server;
        $_ENV = $this->env;
    }

    public function testComposerEnvironmentIsPreparedBeforeUpdateAndCompletion(): void
    {
        foreach ([false, true] as $complete) {
            putenv('COMPOSER_HOME');
            unset($_SERVER['COMPOSER_HOME'], $_ENV['COMPOSER_HOME']);
            $Update = $this->createMock(Update::class);
            $Update->method('executeSystemUpdate')->willReturn(true);
            $Update->method('completeSystemUpdate')->willReturn(true);
            $state = $this->state();
            $state->setMetadataValue('systemUpdateCompletion', [
                'backupFolder' => '/tmp/quiqqer-etc-backup',
                'finalizePackages' => true
            ]);
            $Action = $this->getMockBuilder(SystemUpdateAction::class)
                ->setConstructorArgs([$complete])
                ->onlyMethods(['createUpdate'])
                ->getMock();
            $Action->expects($this->once())->method('createUpdate')->willReturnCallback(function () use ($Update): Update {
                $directory = VAR_DIR . 'composer/';
                $this->assertFalse(getenv('COMPOSER_HOME'));
                $this->assertSame($directory, $_SERVER['COMPOSER_HOME']);
                $this->assertSame($directory, $_ENV['COMPOSER_HOME']);

                return $Update;
            });

            $this->assertTrue($Action->execute($state)->isFinished());
        }
    }

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
