<?php

declare(strict_types=1);

namespace QUI\Cache\Fixtures;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP stream wrapper method names are fixed.

/** Simulates a file stream which cannot acquire a lock. */
final class DeniedLockStream
{
    public mixed $context;

    /** @var array<int|string, int> */
    public static array $stat = [];

    public static int $writes = 0;

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return self::$stat;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return self::$stat;
    }

    public function stream_write(string $data): int
    {
        self::$writes++;

        return strlen($data);
    }

    public function stream_close(): void
    {
    }
}

// phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
