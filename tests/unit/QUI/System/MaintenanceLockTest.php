<?php

namespace QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\System\Update\RunExecutor;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;
use QUI\System\Update\Fixtures\RecordingUpdateRunAction;
use QUI\System\Update\RunActionResult;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/Update/Fixtures/RecordingUpdateRunAction.php';

class MaintenanceLockTest extends TestCase
{
    public function testNestedCallsAndChildrenJoinWhileUnrelatedProcessesAreBlocked(): void
    {
        $previous = getenv('QUIQQER_MAINTENANCE_TOKEN');

        MaintenanceLock::run(function (): void {
            self::assertSame('nested', MaintenanceLock::run(static fn(): string => 'nested'));
            self::assertSame('entered', $this->probe());

            $operations = [
                'probe',
                'setup',
                'security',
                'update',
                'package-setup',
                'package-install',
                'local-install',
                'composer-json'
            ];

            foreach ($operations as $operation) {
                self::assertSame('busy', $this->probe($operation, false));
            }
        });

        self::assertSame($previous, getenv('QUIQQER_MAINTENANCE_TOKEN'));
        self::assertSame('entered', $this->probe('probe', false));
    }

    public function testFailureReleasesTheGuardAndRestoresTheEnvironment(): void
    {
        $previous = getenv('QUIQQER_MAINTENANCE_TOKEN');

        try {
            MaintenanceLock::run(static function (): void {
                throw new RuntimeException('operation failed');
            });
            self::fail('Expected the operation to fail.');
        } catch (RuntimeException $Exception) {
            self::assertSame('operation failed', $Exception->getMessage());
        }

        self::assertSame($previous, getenv('QUIQQER_MAINTENANCE_TOKEN'));
        self::assertSame('entered', $this->probe('probe', false));
    }

    public function testChildRetainsTheGuardAfterTheOwnerReleasesIt(): void
    {
        $Input = new InputStream();
        $Child = null;

        try {
            MaintenanceLock::run(function () use ($Input, &$Child): void {
                $Child = $this->worker('hold');
                $Child->setInput($Input);
                $Child->start();
                self::assertTrue($Child->waitUntil(static fn(string $type, string $output): bool =>
                    str_contains($output, 'ready')));
            });

            self::assertTrue(MaintenanceLock::isBusy());
            self::assertSame('busy', $this->probe('probe', false));
            $Input->write(PHP_EOL);
            $Input->close();
            $Child->wait();
            self::assertSame(0, $Child->getExitCode(), $Child->getErrorOutput());
            self::assertFalse(MaintenanceLock::isBusy());
        } finally {
            $Input->close();
            $Child?->stop();
        }
    }

    public function testReservedRunnerBlocksOtherOperationsBetweenProcesses(): void
    {
        $Repository = new RunRepository(VAR_DIR . 'update/runs/');
        $Run = $Repository->create();
        $id = $Run->getState()->getId();

        try {
            self::assertTrue(MaintenanceLock::isBusy());
            self::assertSame('busy', $this->probe('security', false));
            $Action = new RecordingUpdateRunAction(RunActionResult::finished());
            $Executor = new RunExecutor($Repository, [RunState::PHASE_CREATED => $Action]);
            $State = $Executor->execute($id, $Run->getToken());

            self::assertSame(RunState::STATUS_FINISHED, $State->getStatus());
            self::assertSame([$id], $Action->executedRunIds);
            self::assertFalse(MaintenanceLock::isBusy());
        } finally {
            $Repository->delete($id);
        }
    }

    public function testInvalidInheritedTokenCannotBypassTheGuard(): void
    {
        MaintenanceLock::run(function (): void {
            $Process = $this->worker('probe');
            $Process->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => str_repeat('0', 64)]);
            $Process->mustRun();

            self::assertSame('busy', $Process->getOutput());
        });
    }

    #[DataProvider('legacyRunnerCases')]
    public function testLegacyRunnerChildrenJoinButUnrelatedProcessesRemainBlocked(
        string $mode,
        string $expected
    ): void {
        if (!is_readable('/proc/self/stat')) {
            self::markTestSkipped('The legacy runner bridge requires the Linux process tree.');
        }

        $Input = new InputStream();
        $Runner = $this->worker('legacy-runner', mode: $mode);
        $Runner->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
        $Runner->setInput($Input);

        try {
            $Runner->start();
            self::assertTrue($Runner->waitUntil(static fn(string $type, string $output): bool =>
                str_contains($output, 'ready')));
            self::assertSame($expected . PHP_EOL . 'ready' . PHP_EOL, $Runner->getOutput());
            self::assertSame('busy', $this->probe('probe', false));
            self::assertSame('busy', $this->probe('security', false));

            $Input->close();
            $Runner->wait();
            self::assertSame(0, $Runner->getExitCode(), $Runner->getErrorOutput());
            self::assertSame('entered', $this->probe('probe', false));
        } finally {
            $Input->close();
            $Runner->stop();
        }
    }

    /** @return array<string, array{string, string}> */
    public static function legacyRunnerCases(): array
    {
        return [
            'active legacy runner and its descendants' => ['active', 'entered'],
            'invalid tokens cannot use the legacy bridge' => ['invalid-token', 'busy'],
            'paused runners cannot authorize children' => ['paused', 'busy'],
            'another reserved run still blocks setup' => ['competing', 'busy']
        ];
    }

    public function testLegacyRunnerMustStillOwnItsRunLock(): void
    {
        $Repository = new RunRepository(VAR_DIR . 'update/runs/');
        $Run = $Repository->create();
        $State = $Run->getState();
        $State->transitionTo(RunState::PHASE_PREPARED);
        $State->transitionTo(RunState::PHASE_SYSTEM_UPDATE);
        $State->markRunning(time());
        $State->setProcess((int)getmypid(), '', time(), 'runner');
        $Repository->save($State);
        $lock = $Repository->acquireLock($State->getId());
        $Repository->releaseLock($lock);

        try {
            self::assertSame('busy', $this->probe('probe', false));
        } finally {
            $Repository->delete($State->getId());
        }
    }

    public function testSeparateInstallationsDoNotShareTheirMaintenanceLock(): void
    {
        $directory = sys_get_temp_dir() . '/quiqqer-maintenance-isolation-' . bin2hex(random_bytes(8)) . '/';
        mkdir($directory, 0700);
        $Input = new InputStream();
        $Owner = $this->worker('hold', $directory . 'first/');
        $Owner->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
        $Owner->setInput($Input);

        try {
            $Owner->start();
            self::assertTrue($Owner->waitUntil(static fn(string $type, string $output): bool =>
                str_contains($output, 'ready')));

            foreach (['first/' => 'busy', 'second/' => 'entered'] as $path => $expected) {
                $Probe = $this->worker('probe', $directory . $path);
                $Probe->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
                $Probe->mustRun();

                self::assertSame($expected, $Probe->getOutput());
            }
        } finally {
            $Input->close();
            $Owner->stop();
            \QUI\Utils\System\File::deleteDir($directory);
        }
    }

    private function probe(string $operation = 'probe', bool $join = true): string
    {
        $Process = $this->worker($operation);

        if (!$join) {
            $Process->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
        }

        $Process->mustRun();

        return $Process->getOutput();
    }

    private function worker(string $operation, ?string $varDirectory = null, string $mode = 'active'): Process
    {
        return new Process(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/maintenance-lock-worker.php',
                $operation,
                CMS_DIR,
                $varDirectory ?? VAR_DIR,
                $mode
            ],
            timeout: 30
        );
    }
}
