<?php

namespace QUI\System;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Lock\Locker;
use QUI\System\Update\RunExecutor;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;
use QUI\System\Update\Fixtures\RecordingUpdateRunAction;
use QUI\System\Update\RunActionResult;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

require_once __DIR__ . '/Update/Fixtures/RecordingUpdateRunAction.php';

class MaintenanceLockTest extends TestCase
{
    public function testBusyResponseIdentifiesTheActualOwnerWithoutExposingItsToken(): void
    {
        MaintenanceLock::run(function (): void {
            $details = json_decode($this->probe('details', false), true, 512, JSON_THROW_ON_ERROR);

            self::assertStringContainsString('Operation: System setup', $details['message']);
            self::assertStringContainsString('PID:       ' . getmypid(), $details['message']);
            self::assertSame('System setup', $details['operations'][0]['operation']);
            self::assertSame(getmypid(), $details['operations'][0]['pid']);
            self::assertGreaterThan(0, $details['operations'][0]['startedAt']);
            self::assertStringNotContainsString((string)getenv('QUIQQER_MAINTENANCE_TOKEN'), $details['message']);

            foreach (glob(VAR_DIR . 'locks/maintenance-operation-*.json') ?: [] as $path) {
                self::assertStringNotContainsString(
                    (string)getenv('QUIQQER_MAINTENANCE_TOKEN'),
                    (string)file_get_contents($path)
                );
            }
        }, operation: 'System setup');

        self::assertSame([], MaintenanceOperation::active(VAR_DIR));
    }

    public function testReservedUpdateReportsItsRunIdStatusAndPhase(): void
    {
        $Repository = new RunRepository(VAR_DIR . 'update/runs/');
        $Run = $Repository->create();
        $State = $Run->getState();

        try {
            $details = json_decode($this->probe('details', false), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame($State->getId(), $details['operations'][0]['runId']);
            self::assertSame(RunState::STATUS_CREATED, $details['operations'][0]['status']);
            self::assertSame(RunState::PHASE_CREATED, $details['operations'][0]['phase']);
            self::assertStringContainsString('System update', $details['message']);
            self::assertStringNotContainsString($Run->getToken(), $details['message']);
        } finally {
            $Repository->delete($State->getId());
        }
    }

    public function testRunningUpdateDiagnosticsUseTheCurrentRunPhase(): void
    {
        $Repository = new RunRepository(VAR_DIR . 'update/runs/');
        $Run = $Repository->create();
        $State = $Run->getState();
        $State->transitionTo(RunState::PHASE_PREPARED);
        $State->transitionTo(RunState::PHASE_COMPOSER_UPDATE);
        $State->markRunning(time());
        $State->setProcess((int)getmypid(), '', time());
        $Repository->save($State);

        try {
            MaintenanceLock::run(function () use ($State): void {
                $details = json_decode($this->probe('details', false), true, 512, JSON_THROW_ON_ERROR);

                self::assertSame($State->getId(), $details['operations'][0]['runId']);
                self::assertSame(RunState::STATUS_RUNNING, $details['operations'][0]['status']);
                self::assertSame(RunState::PHASE_COMPOSER_UPDATE, $details['operations'][0]['phase']);
            }, $State->getId(), operation: 'System update');
        } finally {
            $Repository->delete($State->getId());
        }
    }

    public function testOlderOwnersWithoutMetadataKeepTheHelpfulFallbackMessage(): void
    {
        $Lock = Locker::createFilesystemLock('system-maintenance', VAR_DIR . 'locks/');
        self::assertTrue($Lock->acquire());

        try {
            $details = json_decode($this->probe('details', false), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame([], $details['operations']);
            self::assertStringContainsString('Details about the blocking process are not available.', $details['message']);
        } finally {
            $Lock->release();
        }
    }

    public function testDefaultCronLabelDoesNotPersistCommandArguments(): void
    {
        $argv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['/example/console', 'cron', '--password=do-not-persist'];

        try {
            MaintenanceLock::run(static function (): void {
                $operations = MaintenanceOperation::active(VAR_DIR);
                self::assertSame('Cron execution', $operations[0]['operation']);

                foreach (glob(VAR_DIR . 'locks/maintenance-operation-*.json') ?: [] as $path) {
                    self::assertStringNotContainsString('do-not-persist', (string)file_get_contents($path));
                }
            });
        } finally {
            unset($_SERVER['argv']);

            if ($argv !== null) {
                $_SERVER['argv'] = $argv;
            }
        }
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutCannotRunTheCallback(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        MaintenanceLock::runWhenAvailable(static function (): void {
            self::fail('Invalid timeouts must be rejected before entering the callback.');
        }, timeout: $timeout);
    }

    /** @return array<string, array{float}> */
    public static function invalidTimeouts(): array
    {
        return [
            'negative' => [-1.0],
            'infinite' => [INF],
            'not a number' => [NAN]
        ];
    }

    public function testWaitingForAdmissionTimesOutWithoutHoldingTheOwnerLock(): void
    {
        $Admission = Locker::createFilesystemLock('system-maintenance-admission', VAR_DIR . 'locks/');
        self::assertTrue($Admission->acquire());

        try {
            $Waiter = $this->worker('wait', mode: '0.1');
            $Waiter->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
            $Waiter->mustRun();
            self::assertSame('busy', $Waiter->getOutput());
            self::assertTrue(MaintenanceLock::isBusy());
        } finally {
            $Admission->release();
        }

        self::assertSame('entered', $this->probe('probe', false));
    }

    public function testNestedCallsAndChildrenJoinWhileUnrelatedProcessesAreBlocked(): void
    {
        $previous = getenv('QUIQQER_MAINTENANCE_TOKEN');

        MaintenanceLock::run(function (): void {
            self::assertSame('nested', MaintenanceLock::run(static fn(): string => 'nested'));
            self::assertSame('entered', $this->probe());

            self::assertSame('busy', $this->probe('probe', false));
        });

        self::assertSame($previous, getenv('QUIQQER_MAINTENANCE_TOKEN'));
        self::assertSame('entered', $this->probe('probe', false));
    }

    #[DataProvider('maintenanceOperations')]
    public function testMaintenanceEntryPointsWaitForAnUnrelatedOwner(string $operation): void
    {
        MaintenanceLock::run(function () use ($operation): void {
            $Process = $this->worker($operation);
            $Process->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
            $Process->setTimeout(0.3);

            try {
                $Process->run();
                self::fail('Maintenance must wait instead of failing or entering while the owner is active.');
            } catch (ProcessTimedOutException) {
                self::assertSame('', $Process->getOutput());
                self::assertSame('', $Process->getErrorOutput());
            } finally {
                $Process->stop();
            }
        });
    }

    /** @return iterable<string, array{string}> */
    public static function maintenanceOperations(): iterable
    {
        $operations = [
            'setup',
            'security',
            'update',
            'package-setup',
            'package-install',
            'local-install',
            'composer-json'
        ];

        foreach ($operations as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testWaitingMaintenanceStopsNewJobsAndLetsTheOwnersChildFinish(): void
    {
        $OwnerInput = new InputStream();
        $WaiterInput = new InputStream();
        $Owner = $this->worker('hold');
        $Owner->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
        $Owner->setInput($OwnerInput);
        $Waiter = $this->worker('wait-hold', mode: '5');
        $Waiter->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
        $Waiter->setInput($WaiterInput);
        $Admission = Locker::createFilesystemLock('system-maintenance-admission', VAR_DIR . 'locks/');

        try {
            $Owner->start();
            self::assertTrue($Owner->waitUntil(static fn(string $type, string $output): bool =>
                str_contains($output, 'ready')));
            $Waiter->start();
            $deadline = microtime(true) + 5;

            while ($Admission->acquire()) {
                $Admission->release();
                self::assertLessThan($deadline, microtime(true), 'Maintenance did not reserve admission.');
                usleep(10000);
            }

            self::assertSame('', $Waiter->getOutput());
            self::assertSame('busy', $this->probe('probe', false));
            $OwnerInput->write('child' . PHP_EOL);
            $OwnerInput->close();
            $Owner->wait();
            self::assertSame(0, $Owner->getExitCode(), $Owner->getErrorOutput());
            self::assertSame('ready' . PHP_EOL . 'entered', $Owner->getOutput());
            self::assertTrue($Waiter->waitUntil(static fn(string $type, string $output): bool =>
                str_contains($output, 'ready')));
            self::assertSame('busy', $this->probe('probe', false));
            $WaiterInput->close();
            $Waiter->wait();
            self::assertSame(0, $Waiter->getExitCode(), $Waiter->getErrorOutput());
            self::assertSame('entered', $this->probe('probe', false));
        } finally {
            $OwnerInput->close();
            $WaiterInput->close();
            $Owner->stop();
            $Waiter->stop();
            $Admission->release();
        }
    }

    public function testWaitingMaintenanceTimesOutAndReleasesAdmission(): void
    {
        MaintenanceLock::run(function (): void {
            $Waiter = $this->worker('wait', mode: '0.1');
            $Waiter->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);
            $started = microtime(true);
            $Waiter->mustRun();

            self::assertSame('busy', $Waiter->getOutput());
            self::assertGreaterThanOrEqual(0.1, microtime(true) - $started);
            $Admission = Locker::createFilesystemLock('system-maintenance-admission', VAR_DIR . 'locks/');

            try {
                self::assertTrue($Admission->acquire());
            } finally {
                $Admission->release();
            }
        });

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
        $Waiter = $this->worker('wait', mode: '5');
        $Waiter->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => false]);

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
            self::assertSame($Child->getPid(), MaintenanceOperation::active(VAR_DIR)[0]['pid']);
            $Waiter->start();
            $Admission = Locker::createFilesystemLock('system-maintenance-admission', VAR_DIR . 'locks/');
            $deadline = microtime(true) + 5;

            while ($Admission->acquire()) {
                $Admission->release();
                self::assertLessThan($deadline, microtime(true));
                usleep(10000);
            }

            self::assertSame('', $Waiter->getOutput());
            $Input->write(PHP_EOL);
            $Input->close();
            $Child->wait();
            self::assertSame(0, $Child->getExitCode(), $Child->getErrorOutput());
            $Waiter->wait();
            self::assertSame('entered', $Waiter->getOutput());
            self::assertSame(0, $Waiter->getExitCode(), $Waiter->getErrorOutput());
            self::assertFalse(MaintenanceLock::isBusy());
        } finally {
            $Input->close();
            $Child?->stop();
            $Waiter->stop();
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
