<?php

namespace QUI\REST\Core\Project\Media;

use QUI;
use QUI\REST\Core\ApiException;

final class Effects
{
    /** @param array<string, mixed> $current
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    public static function merge(string $project, array $current, array $updates): array
    {
        if ($updates === []) {
            throw new ApiException('invalid_input', 'Provide at least one image effect.');
        }

        foreach ($updates as $name => $value) {
            $valid = match ($name) {
                'blur' => self::integer($value, 0, 100),
                'brightness', 'contrast' => self::integer($value, -100, 100),
                'watermark_ratio' => self::integer($value, 1, 100),
                'greyscale' => $value === null || is_bool($value),
                'watermark' => $value === null || $value === '' || $value === 'default'
                    || (is_int($value) && $value > 0),
                'watermark_position' => $value === null || in_array($value, [
                    '', 'top-left', 'top', 'top-right', 'left', 'center', 'right',
                    'bottom-left', 'bottom', 'bottom-right'
                ], true),
                default => false
            };

            if (!$valid) {
                throw new ApiException('invalid_input', 'Invalid image effect: ' . $name);
            }

            if ($value === null) {
                unset($current[$name]);
                continue;
            }

            if ($name === 'watermark' && is_int($value)) {
                $Image = QUI::getProject($project)->getMedia()->get($value);

                if (!$Image instanceof QUI\Projects\Media\Image || $Image->getAttribute('deleted')) {
                    throw new ApiException('invalid_input', 'The watermark must be an existing media image.');
                }

                $Image->checkPermission('quiqqer.projects.media.view', QUI::getUserBySession());
                $value = $Image->getUrl();
            }

            $current[$name] = is_bool($value) ? (int)$value : $value;
        }

        return $current;
    }

    private static function integer(mixed $value, int $min, int $max): bool
    {
        return $value === null || (is_int($value) && $value >= $min && $value <= $max);
    }
}
