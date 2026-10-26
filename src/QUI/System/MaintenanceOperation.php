<?php

namespace QUI\System;

/** Diagnostic metadata only; MaintenanceLock remains responsible for admission. */
final class MaintenanceOperation
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct(private readonly string $path, $handle)
    {
        $this->handle = $handle;
    }

    public static function start(string $directory, string $operation, ?string $runId): ?self
    {
        $pid = getmypid();

        if ($pid === false) {
            return null;
        }

        $path = $directory . 'locks/maintenance-operation-' . $pid . '-' . bin2hex(random_bytes(8)) . '.json';
        $handle = @fopen($path, 'x+e');

        if ($handle === false) {
            return null;
        }

        $Operation = new self($path, $handle);
        $data = [
            'operation' => $operation,
            'pid' => $pid,
            'startedAt' => time(),
            'runId' => $runId
        ];
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);

        if (
            !flock($handle, LOCK_EX | LOCK_NB)
            || $json === false
            || fwrite($handle, $json) !== strlen($json)
            || !fflush($handle)
        ) {
            $Operation->close();
            return null;
        }

        return $Operation;
    }

    /** @return list<array{operation: string, pid: int, startedAt: int, runId?: string}> */
    public static function active(string $directory): array
    {
        $operations = [];

        foreach (glob($directory . 'locks/maintenance-operation-*.json') ?: [] as $path) {
            $handle = @fopen($path, 're');

            if ($handle === false) {
                continue;
            }

            try {
                // A stale file cannot claim a process is still holding the maintenance guard.
                if (flock($handle, LOCK_SH | LOCK_NB, $wouldBlock) || $wouldBlock !== 1) {
                    continue;
                }

                $json = stream_get_contents($handle, 8192);
                $data = is_string($json) ? json_decode($json, true) : null;

                if (
                    !is_array($data)
                    || !is_string($data['operation'] ?? null)
                    || !is_int($data['pid'] ?? null)
                    || $data['pid'] <= 0
                    || !is_int($data['startedAt'] ?? null)
                    || $data['startedAt'] <= 0
                ) {
                    continue;
                }

                $operation = [
                    'operation' => $data['operation'],
                    'pid' => $data['pid'],
                    'startedAt' => $data['startedAt']
                ];

                if (is_string($data['runId'] ?? null) && preg_match('/^[a-f0-9]{32,64}$/D', $data['runId'])) {
                    $operation['runId'] = $data['runId'];
                }

                $operations[] = $operation;
            } finally {
                fclose($handle);
            }
        }

        return $operations;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
