<?php

namespace QUI\System;

use RuntimeException;

class MaintenanceBusyException extends RuntimeException
{
    /**
     * @param list<array{operation: string, pid?: int, startedAt?: int, runId?: string,
     *     status?: string, phase?: string}> $operations
     */
    public function __construct(private readonly array $operations = [])
    {
        $lines = ['A setup, installation or system update is already active. Try again later.'];

        foreach ($operations as $operation) {
            $lines[] = '';
            $lines[] = 'Operation: ' . self::plainText($operation['operation']);

            if (isset($operation['startedAt'])) {
                $lines[] = 'Started:   ' . date('Y-m-d H:i:s T', $operation['startedAt']);
            }

            if (isset($operation['pid']) && $operation['pid'] > 0) {
                $lines[] = 'PID:       ' . $operation['pid'];
            }

            foreach (['runId' => 'Run ID:    ', 'status' => 'Status:    ', 'phase' => 'Phase:     '] as $key => $label) {
                if (isset($operation[$key])) {
                    $lines[] = $label . self::plainText($operation[$key]);
                }
            }
        }

        if ($operations === []) {
            $lines[] = 'Details about the blocking process are not available.';
        }

        parent::__construct(implode(PHP_EOL, $lines));
    }

    /**
     * @return list<array{operation: string, pid?: int, startedAt?: int, runId?: string,
     *     status?: string, phase?: string}>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    private static function plainText(string $value): string
    {
        return substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '', 0, 256);
    }
}
