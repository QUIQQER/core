<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI;
use RuntimeException;

/**
 * Private package storage. All web workers must share this directory.
 */
final class Store
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? QUI::getPackage('quiqqer/core')->getVarDir() . 'upload/mobileUpload';
    }

    public function root(): string
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Invalid mobile upload storage.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Mobile upload storage is unavailable.');
        }

        return $this->directory;
    }

    public function path(string $id): string
    {
        self::assertId($id);

        $path = $this->root() . '/' . $id;

        if (is_link($path)) {
            throw new RuntimeException('Invalid mobile upload file.');
        }

        return $path;
    }

    public static function assertId(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
            throw new QUI\Exception('Invalid upload access.', 410);
        }
    }

    /**
     * @template T
     * @param callable(?Session): array{Session, T} $callback
     * @return T
     */
    public function locked(string $id, callable $callback, bool $create = false): mixed
    {
        $path = $this->path($id);

        if (!$create && !is_file($path . '.json')) {
            throw new QUI\Exception('Invalid upload access.', 410);
        }

        if (is_link($path . '.lock') || is_link($path . '.json')) {
            throw new RuntimeException('Invalid mobile upload storage.');
        }

        $Lock = fopen($path . '.lock', 'c');

        if ($Lock === false) {
            throw new RuntimeException('Cannot lock mobile upload.');
        }

        try {
            if (!flock($Lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock mobile upload.');
            }

            $Session = null;

            if (is_file($path . '.json')) {
                $Session = Session::decode((string)file_get_contents($path . '.json'));
            }

            [$Session, $result] = $callback($Session);

            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

            try {
                $json = json_encode($Session, JSON_THROW_ON_ERROR);

                if (
                    file_put_contents($temporary, $json) !== strlen($json) || !chmod($temporary, 0600)
                    || !rename($temporary, $path . '.json')
                ) {
                    throw new RuntimeException('Cannot save mobile upload.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return $result;
        } finally {
            flock($Lock, LOCK_UN);
            fclose($Lock);
        }
    }
}
