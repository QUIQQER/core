<?php

namespace QUI\System\Update;

use RuntimeException;

class RunRepository
{
    private const STATE_FILE = 'state.json';
    private const EXECUTE_FILE = 'execute.php';
    private const LOCK_FILE = 'run.lock';

    public function __construct(
        private readonly string $root,
        private readonly int $ttl = 3600
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(?int $now = null, array $metadata = []): Run
    {
        $now ??= time();
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $directory = $this->getRunDirectory($id);

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create update run directory.');
        }

        $state = RunState::create($id, hash('sha256', $token), $now, $this->ttl, $metadata);
        $this->save($state);

        $executeFile = $directory . self::EXECUTE_FILE;
        file_put_contents($executeFile, $this->createExecuteFileContent($id));

        return new Run($state, $token, $directory, $executeFile);
    }

    public function load(string $id): RunState
    {
        RunState::assertValidIdentifier($id);

        $file = $this->getStateFile($id);

        if (!is_file($file)) {
            throw new RuntimeException('Update run state not found.');
        }

        $data = json_decode((string)file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException('Update run state is invalid.');
        }

        return RunState::fromArray($data);
    }

    /**
     * @return array<int, RunState>
     */
    public function list(int $limit = 20): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $states = [];
        $items = new \FilesystemIterator(
            $this->root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if (!$item->isDir()) {
                continue;
            }

            try {
                $states[] = $this->load($item->getFilename());
            } catch (\Throwable) {
                continue;
            }
        }

        usort($states, static function (RunState $A, RunState $B): int {
            return $B->getCreatedAt() <=> $A->getCreatedAt();
        });

        return array_slice($states, 0, max(1, $limit));
    }

    public function save(RunState $state): void
    {
        $directory = $this->getRunDirectory($state->getId());

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create update run directory.');
        }

        $data = $state->toArray();
        $stateFile = $this->getStateFile($state->getId());

        if (($data['process'] ?? null) === null && is_file($stateFile)) {
            $existing = json_decode((string)file_get_contents($stateFile), true);

            if (is_array($existing) && is_array($existing['process'] ?? null)) {
                $data['process'] = $existing['process'];
            }
        }

        if (is_array($data['process'] ?? null)) {
            unset($data['process']['command']);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($stateFile, $json) === false) {
            throw new RuntimeException('Could not write update run state.');
        }
    }

    /**
     * @return resource
     */
    public function acquireLock(string $id)
    {
        RunState::assertValidIdentifier($id);

        $lockFile = $this->getRunDirectory($id) . self::LOCK_FILE;
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open update run lock.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Update run is already locked.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function delete(string $id): void
    {
        RunState::assertValidIdentifier($id);
        $directory = $this->getRunDirectory($id);

        if (!is_dir($directory)) {
            return;
        }

        $this->deleteDirectory($directory);
    }

    public function cancel(string $id, ?int $now = null): RunState
    {
        $now ??= time();
        $state = $this->load($id);

        if (
            $state->getStatus() === RunState::STATUS_FINISHED
            || $state->getStatus() === RunState::STATUS_FAILED
            || $state->getStatus() === RunState::STATUS_CANCELLED
        ) {
            return $state;
        }

        $state->markCancelled('Cancelled by console command.', $now);
        $this->save($state);

        return $state;
    }

    /**
     * @return array<int, string>
     */
    public function deleteExpired(int $now): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $deleted = [];
        $items = new \FilesystemIterator(
            $this->root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if (!$item->isDir()) {
                continue;
            }

            $id = $item->getFilename();

            try {
                $state = $this->load($id);
            } catch (\Throwable) {
                continue;
            }

            if (!$state->isExpired($now)) {
                continue;
            }

            $this->delete($id);
            $deleted[] = $id;
        }

        return $deleted;
    }

    /**
     * @return array{deleted: array<int, string>, active: array<int, RunState>}
     */
    public function cleanupAndFindActive(int $now, int $maxAge): array
    {
        if (!is_dir($this->root)) {
            return [
                'deleted' => [],
                'active' => []
            ];
        }

        $deleted = [];
        $active = [];
        $items = new \FilesystemIterator(
            $this->root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if (!$item->isDir()) {
                continue;
            }

            $id = $item->getFilename();

            try {
                $state = $this->load($id);
            } catch (\Throwable) {
                continue;
            }

            if (
                ($state->getStatus() === RunState::STATUS_RUNNING && $this->isProcessRunning($state) !== false)
                || (
                    in_array($state->getStatus(), [RunState::STATUS_CREATED, RunState::STATUS_RESTART_REQUIRED], true)
                    && $state->getCreatedAt() > $now - $maxAge
                )
            ) {
                // Do not contend with the runner's non-blocking lock between actions.
                $active[] = $state;
                continue;
            }

            try {
                $lock = $this->acquireLock($id);
            } catch (RuntimeException) {
                // A runner (or an inherited child process) still owns the lock.
                // Never remove its directory or allow another update to start.
                $active[] = $state;
                continue;
            }

            try {
                // The action may have completed while we were acquiring the lock.
                $state = $this->load($id);

                if (
                    $state->getStatus() === RunState::STATUS_FAILED
                    || $state->getStatus() === RunState::STATUS_CANCELLED
                ) {
                    continue;
                }

                if ($state->getStatus() === RunState::STATUS_RUNNING) {
                    if ($this->isProcessRunning($state) === false) {
                        $state->markFailed('Update process ended before the run completed.', $now);
                        $this->save($state);
                    } else {
                        // Unknown process status must not unlock a possibly active update.
                        $active[] = $state;
                    }

                    continue;
                }

                if ($state->getCreatedAt() <= $now - $maxAge) {
                    $this->delete($id);
                    $deleted[] = $id;
                    continue;
                }

                if ($state->getStatus() !== RunState::STATUS_FINISHED) {
                    $active[] = $state;
                }
            } finally {
                $this->releaseLock($lock);
            }
        }

        return [
            'deleted' => $deleted,
            'active' => $active
        ];
    }

    public function getRunDirectory(string $id): string
    {
        RunState::assertValidIdentifier($id);

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR;
    }

    private function isProcessRunning(RunState $state): ?bool
    {
        $pid = (int)($state->getProcess()['pid'] ?? 0);

        if ($pid <= 0 || !function_exists('posix_kill') || !function_exists('posix_get_last_error')) {
            return null;
        }

        if (!posix_kill($pid, 0)) {
            // ESRCH means no such process; EPERM and other failures are inconclusive.
            return posix_get_last_error() === 3 ? false : null;
        }

        // Container PID 1 may leave exited runners as zombies until they are reaped.
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        if (is_string($stat) && preg_match('/^\d+ \(.*\) [ZX] /s', $stat)) {
            return false;
        }

        return true;
    }

    private function getStateFile(string $id): string
    {
        return $this->getRunDirectory($id) . self::STATE_FILE;
    }

    private function createExecuteFileContent(string $id): string
    {
        return "<?php\n\n"
            . "define('QUIQQER_UPDATE_RUN_ID', '" . $id . "');\n"
            . "\$cmsDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;\n"
            . "define('CMS_DIR', \$cmsDir);\n"
            . "define('ETC_DIR', \$cmsDir . 'etc/');\n"
            . "define('QUIQQER_SYSTEM', true);\n"
            . "require \$cmsDir . 'bootstrap.php';\n"
            . "\$entrypoint = new QUI\\System\\Update\\RunEntrypoint();\n"
            . "exit(\$entrypoint->execute("
            . "QUIQQER_UPDATE_RUN_ID, "
            . "VAR_DIR . 'update/runs/', "
            . "QUI\\System\\Update\\DefaultRunActions::create()"
            . "));\n";
    }

    private function deleteDirectory(string $directory): void
    {
        $items = new \FilesystemIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
