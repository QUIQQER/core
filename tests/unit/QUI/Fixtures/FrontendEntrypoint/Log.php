<?php

namespace QUI\System;

final class Log
{
    /**
     * @param array<string, mixed> $context
     */
    public static function addInfo(string $message, array $context = []): void
    {
        $GLOBALS['frontendEntrypointInfoLogs'][] = [
            'message' => $message,
            'context' => $context
        ];
    }
}
