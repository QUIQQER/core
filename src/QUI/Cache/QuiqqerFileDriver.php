<?php

/**
 * This file contains QUI\Cache\FileDriver
 */

namespace QUI\Cache;

use QUI;
use Stash\Driver\FileSystem;

use function is_dir;
use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function strlen;
use function strpos;
use function substr;
use function unlink;

use const E_WARNING;

/**
 * Class QuiqqerFileDriver
 */
class QuiqqerFileDriver extends FileSystem
{
    /**
     * @param array<array-key, string> $key
     * @param mixed $data
     * @param int $expiration
     */
    public function storeData($key, $data, $expiration): bool
    {
        $previousErrorHandler = null;
        $previousErrorHandler = set_error_handler(
            static function (
                int $errorLevel,
                string $message,
                string $file,
                int $line
            ) use (&$previousErrorHandler): bool {
                if (
                    $errorLevel === E_WARNING
                    && $message === 'file_put_contents(): Exclusive locks are not supported for this stream'
                ) {
                    // Stash returns false for this optional cache write.
                    return true;
                }

                if ($previousErrorHandler !== null) {
                    return $previousErrorHandler($errorLevel, $message, $file, $line) !== false;
                }

                return false;
            }
        );

        try {
            return parent::storeData($key, $data, $expiration);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param null $key
     * @return bool
     */
    public function clear($key = null)
    {
        $path = $this->makePath($key);

        if (is_file($path)) {
            $return = true;
            unlink($path);
        }

        $extension = $this->getEncoder()->getExtension();

        if (str_contains($path, $extension)) {
            $path = substr($path, 0, -(strlen($extension)));
        }

        if (is_dir($path)) {
            try {
                QUI::getTemp()->moveToTemp($path);
            } catch (QUI\Exception) {
                return false;
            }

            return true;
        }

        return isset($return);
    }
}
