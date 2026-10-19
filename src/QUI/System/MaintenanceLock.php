<?php

namespace QUI\System;

use InvalidArgumentException;
use QUI\Lock\Locker;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;
use RuntimeException;
use Symfony\Component\Lock\LockInterface;

/**
 * One installation-wide guard for setup and package changes, including their child processes.
 * Children join with an inherited random token and retain a shared guard if their parent dies.
 */
final class MaintenanceLock
{
    private const CONTEXT = 'QUIQQER_MAINTENANCE_TOKEN';

    private static ?self $current = null;

    /** @var resource|null */
    private $handle = null;

    private ?LockInterface $Lock = null;

    private function __construct(private readonly string $directory)
    {
    }

    /**
     * $runId allows an authorized update runner to continue its own reserved run.
     * Other callers must also respect runs paused between runner processes.
     * Background work uses the default non-blocking timeout; maintenance uses runWhenAvailable().
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback, ?string $runId = null, float $timeout = 0.0): mixed
    {
        if (!is_finite($timeout) || $timeout < 0) {
            throw new InvalidArgumentException('Maintenance lock timeout must be finite and non-negative.');
        }

        if (self::$current !== null) {
            return $callback();
        }

        $Guard = new self(self::getVarDirectory());
        $previous = getenv(self::CONTEXT);
        $previousServer = $_SERVER[self::CONTEXT] ?? null;
        $previousEnvironment = $_ENV[self::CONTEXT] ?? null;

        try {
            $Guard->acquire($previous, $runId, $timeout);
            self::$current = $Guard;

            return $callback();
        } finally {
            self::$current = null;
            $Guard->release();
            putenv($previous === false ? self::CONTEXT : self::CONTEXT . '=' . $previous);

            unset($_SERVER[self::CONTEXT], $_ENV[self::CONTEXT]);

            if ($previousServer !== null) {
                $_SERVER[self::CONTEXT] = $previousServer;
            }

            if ($previousEnvironment !== null) {
                $_ENV[self::CONTEXT] = $previousEnvironment;
            }
        }
    }

    /**
     * Let a running cron job finish while preventing new jobs from overtaking maintenance.
     * Timeout bounds contention across all guards; reserved update runs still reject unrelated callers.
     * Nested calls and authorized children join immediately, including while another caller is waiting.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWhenAvailable(callable $callback, ?string $runId = null, float $timeout = 60.0): mixed
    {
        return self::run($callback, $runId, $timeout);
    }

    public static function isBusy(): bool
    {
        try {
            return self::run(static fn(): bool => false);
        } catch (MaintenanceBusyException) {
            return true;
        }
    }

    private function acquire(string|false $token, ?string $runId, float $timeout): void
    {
        $lockDirectory = $this->directory . 'locks/';

        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0777, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Cannot create the maintenance lock directory.');
        }

        $path = $lockDirectory . 'maintenance-children.lock';

        if (is_string($token) && $token !== '') {
            $handle = @fopen($path, 're');

            if ($handle === false) {
                throw new MaintenanceBusyException();
            }

            $this->handle = $handle;

            if (
                !flock($handle, LOCK_SH | LOCK_NB)
                || !hash_equals(trim((string)stream_get_contents($handle)), $token)
            ) {
                throw new MaintenanceBusyException();
            }

            return;
        }

        // Hold admission only until acquisition completes. A waiting maintenance caller can
        // reserve admission while the current job finishes, so subsequent cron jobs must skip.
        $Admission = Locker::createFilesystemLock('system-maintenance-admission', $lockDirectory);
        $deadline = hrtime(true) / 1e9 + $timeout;

        try {
            self::acquireUntil(static fn(): bool => $Admission->acquire(), $deadline);
            $this->acquireOwner($path, $lockDirectory, $runId, $deadline);
        } finally {
            $Admission->release();
        }
    }

    private function acquireOwner(string $path, string $lockDirectory, ?string $runId, float $deadline): void
    {
        $this->Lock = Locker::createFilesystemLock('system-maintenance', $lockDirectory);
        self::acquireUntil(fn(): bool => $this->Lock->acquire(), $deadline);

        // Only token-authorized children may retain their own guard across process launches.
        $handle = @fopen($path, 'x+e');

        if ($handle !== false) {
            chmod($path, 0666);
        } else {
            $handle = @fopen($path, 'r+e');
        }

        if ($handle === false) {
            throw new RuntimeException('Cannot open the maintenance child guard.');
        }

        $this->handle = $handle;

        self::acquireUntil(static fn(): bool => flock($handle, LOCK_EX | LOCK_NB), $deadline);

        $Repository = new RunRepository($this->directory . 'update/runs/');
        $runs = $Repository->cleanupAndFindActive(time(), 86400);

        foreach ($runs['active'] as $State) {
            if (
                $State->getId() !== $runId
                && !$this->isLegacyRunnerChild($State, $Repository)
            ) {
                throw new MaintenanceBusyException();
            }
        }

        $token = bin2hex(random_bytes(32));

        if (
            !ftruncate($handle, 0)
            || fwrite($handle, $token) !== 64
            || !fflush($handle)
            || !flock($handle, LOCK_SH)
        ) {
            throw new RuntimeException('Cannot initialize the maintenance child guard.');
        }

        putenv(self::CONTEXT . '=' . $token);
        // Symfony Process reads PHP's environment snapshots as well as getenv().
        $_SERVER[self::CONTEXT] = $token;
        $_ENV[self::CONTEXT] = $token;
    }

    /** @param callable(): bool $acquire */
    private static function acquireUntil(callable $acquire, float $deadline): void
    {
        while (!$acquire()) {
            $remaining = $deadline - hrtime(true) / 1e9;

            if ($remaining <= 0) {
                throw new MaintenanceBusyException();
            }

            usleep((int)min(50000, ceil($remaining * 1e6)));

            if (hrtime(true) / 1e9 >= $deadline) {
                throw new MaintenanceBusyException();
            }
        }
    }

    /**
     * An updater loaded before MaintenanceLock was installed cannot pass its token to Composer's new setup scripts.
     * Only its own process tree may bridge that first upgrade, while the runner still holds its per-run lock.
     */
    private function isLegacyRunnerChild(RunState $State, RunRepository $Repository): bool
    {
        $runnerPid = (int)($State->getProcess()['pid'] ?? 0);

        if (
            PHP_SAPI !== 'cli'
            || $State->getStatus() !== RunState::STATUS_RUNNING
            || $State->getPhase() !== RunState::PHASE_SYSTEM_UPDATE
            || $runnerPid <= 1
        ) {
            return false;
        }

        $pid = getmypid();
        $visited = [];

        while (
            $pid !== false
            && $pid > 1
            && !isset($visited[$pid])
        ) {
            if ($pid === $runnerPid) {
                $path = $Repository->getRunDirectory($State->getId()) . 'run.lock';
                $handle = @fopen($path, 'r+');

                if ($handle === false) {
                    return false;
                }

                try {
                    $acquired = flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);

                    return !$acquired && $wouldBlock === 1;
                } finally {
                    fclose($handle);
                }
            }

            $visited[$pid] = true;
            $stat = @file_get_contents('/proc/' . $pid . '/stat');

            // Fail closed when the OS cannot prove ancestry. No command lines or bearer tokens are read.
            if (
                !is_string($stat)
                || !preg_match('/^\d+ \(.*\) \S+ (\d+) /s', $stat, $matches)
            ) {
                return false;
            }

            $pid = (int)$matches[1];
        }

        return false;
    }

    private function release(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        $this->Lock?->release();
    }

    private static function getVarDirectory(): string
    {
        if (defined('VAR_DIR')) {
            return rtrim(VAR_DIR, '/') . '/';
        }

        // Composer's bootstrap command deliberately runs without the QUIQQER runtime.
        $file = CMS_DIR . 'etc/conf.ini.php';
        $config = is_file($file) ? @parse_ini_file($file, true) : [];

        if ($config === false) {
            throw new RuntimeException('Cannot read the installation maintenance configuration.');
        }

        $directory = $config['globals']['var_dir'] ?? CMS_DIR . 'var/';

        if (!is_string($directory) || $directory === '') {
            throw new RuntimeException('Cannot determine the installation maintenance directory.');
        }

        return rtrim($directory, '/') . '/';
    }
}
