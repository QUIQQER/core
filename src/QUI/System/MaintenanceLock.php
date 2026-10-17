<?php

namespace QUI\System;

use QUI\Lock\Locker;
use QUI\System\Update\RunRepository;
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
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback, ?string $runId = null): mixed
    {
        if (self::$current !== null) {
            return $callback();
        }

        $Guard = new self(self::getVarDirectory());
        $previous = getenv(self::CONTEXT);
        $previousServer = $_SERVER[self::CONTEXT] ?? null;
        $previousEnvironment = $_ENV[self::CONTEXT] ?? null;

        try {
            $Guard->acquire($previous, $runId);
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

    public static function isBusy(): bool
    {
        try {
            return self::run(static fn(): bool => false);
        } catch (MaintenanceBusyException) {
            return true;
        }
    }

    private function acquire(string|false $token, ?string $runId): void
    {
        $lockDirectory = $this->directory . 'locks/';

        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0777, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Cannot create the maintenance lock directory.');
        }

        $path = $lockDirectory . 'maintenance-children.lock';

        if (is_string($token) && $token !== '') {
            $handle = @fopen($path, 'r');

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

        $this->Lock = Locker::createFilesystemLock('system-maintenance', $lockDirectory);

        if (!$this->Lock->acquire()) {
            throw new MaintenanceBusyException();
        }

        $handle = @fopen($path, 'x+');

        if ($handle !== false) {
            chmod($path, 0666);
        } else {
            $handle = @fopen($path, 'r+');
        }

        if ($handle === false) {
            throw new RuntimeException('Cannot open the maintenance child guard.');
        }

        $this->handle = $handle;

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new MaintenanceBusyException();
        }

        $Repository = new RunRepository($this->directory . 'update/runs/');
        $runs = $Repository->cleanupAndFindActive(time(), 86400);

        foreach ($runs['active'] as $State) {
            if ($State->getId() !== $runId) {
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
