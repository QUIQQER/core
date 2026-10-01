<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI;
use QUI\Interfaces\Users\User;

final class Files
{
    /**
     * Use the same permission and media settings as the existing Core upload manager.
     * Zero means no application-level limit.
     *
     * @return array{
     *     maxBytes: int,
     *     maxFiles: int
     * }
     */
    public static function limits(User $Issuer): array
    {
        $maxBytes = (int)$Issuer->getPermission('quiqqer.upload.maxFileUploadSize', 'maxInteger');
        $mediaMaxBytes = (int)QUI\Projects\Manager::get()->getConfig('media_maxUploadFileSize');

        if ($mediaMaxBytes > 0) {
            $maxBytes = $mediaMaxBytes;
        }

        return [
            'maxBytes' => max(0, $maxBytes),
            'maxFiles' => max(0, (int)$Issuer->getPermission('quiqqer.upload.maxUploadCount', 'maxInteger'))
        ];
    }

    /**
     * File types belong to the provider. Core only checks the received file against that declaration.
     *
     * @param list<string> $allowedTypes MIME types, including optional wildcards; empty permits all types.
     * @return array{
     *     size: int,
     *     mime: string,
     *     checksum: string
     * }
     */
    public static function validate(string $path, int $maxBytes, array $allowedTypes): array
    {
        $size = is_file($path) && !is_link($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            throw new QUI\Exception('Invalid upload file.', 400);
        }

        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new QUI\Exception('Upload exceeds the configured file size.', 413);
        }

        $FileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $FileInfo->file($path);

        if (!is_string($mime)) {
            throw new QUI\Exception('Cannot determine upload type.', 415);
        }

        $allowed = $allowedTypes === [];

        foreach ($allowedTypes as $type) {
            if (fnmatch($type, $mime)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new QUI\Exception('This file type is not accepted by the upload provider.', 415);
        }

        return [
            'size' => $size,
            'mime' => $mime,
            'checksum' => (string)hash_file('sha256', $path)
        ];
    }
}
