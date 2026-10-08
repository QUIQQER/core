<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

final class DeviceCookie
{
    public const NAME = 'quiqqer_mobile_upload_device';

    public static function get(bool $create = false): string
    {
        $token = $_COOKIE[self::NAME] ?? '';

        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return $token;
        }

        if (!$create) {
            throw new \QUI\Exception('The device cookie is required.', 428);
        }

        $token = bin2hex(random_bytes(32));

        $options = [
            'expires' => time() + 365 * 86400,
            'path' => URL_DIR ?: '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ];

        if (!setcookie(self::NAME, $token, $options)) {
            throw new \RuntimeException('Cannot set device cookie.');
        }

        return $token;
    }
}
