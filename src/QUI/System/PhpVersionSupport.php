<?php

namespace QUI\System;

use QUI;

use function version_compare;

use const PHP_VERSION;

final class PhpVersionSupport
{
    public static function getUpdateWarning(string $version = PHP_VERSION): ?string
    {
        if (version_compare($version, '8.3.0', '>=')) {
            return null;
        }

        return QUI::getLocale()->get('quiqqer/core', 'system.phpVersion.outdated', [
            'version' => $version
        ]);
    }
}
