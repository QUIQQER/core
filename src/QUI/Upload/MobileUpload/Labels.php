<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI;

/**
 * Collect native locale entries for the standalone page and its backend window.
 */
final class Labels
{
    private const KEYS = [
        'title',
        'intro',
        'qr',
        'copy',
        'copied',
        'copyError',
        'open',
        'revoke',
        'endUpload',
        'keepOpen',
        'closeError',
        'expires',
        'inactive',
        'mobileIntro',
        'documents',
        'camera',
        'cameraHint',
        'files',
        'filesHint',
        'images',
        'types',
        'maxSize',
        'remove',
        'send',
        'uploading',
        'saved',
        'arrived',
        'done',
        'finished',
        'empty',
        'loading',
        'error',
        'limit',
        'invalid',
        'rate',
        'discard',
        'codeLabel',
        'codeChanges',
        'freshCode',
        'codeInvalid',
        'registerTitle',
        'registerHint',
        'registerDevice',
        'unlockTitle',
        'unlockHint',
        'unlockUpload',
        'deviceName',
        'cookiesRequired',
    ];

    /**
     * @return array<string, string>
     */
    public static function get(string $language): array
    {
        $Locale = QUI::getLocale();
        $labels = [];

        foreach (self::KEYS as $key) {
            $labels[$key] = $Locale->getByLang(
                $language,
                'quiqqer/core',
                'upload.mobileUpload.' . $key
            );
        }

        return $labels;
    }
}
