<?php

declare(strict_types=1);

namespace QUI\Projects;

/**
 * Prepare project loader defaults for the frontend header.
 */
final class LoaderSettings
{
    /**
     * @return array{type: string, color: string, background: string}
     */
    public static function fromProject(Project $Project): array
    {
        $type = $Project->getConfig('quiqqer.frontend.loader.type');
        $color = self::normalizeColor($Project->getConfig('quiqqer.frontend.loader.color'));
        $background = self::normalizeColor($Project->getConfig('quiqqer.frontend.loader.background'));

        if (!is_string($type) || !preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $type)) {
            $type = '';
        }

        if ($background !== '') {
            $background = sprintf(
                'rgba(%d, %d, %d, 0.7)',
                hexdec(substr($background, 1, 2)),
                hexdec(substr($background, 3, 2)),
                hexdec(substr($background, 5, 2))
            );
        }

        return [
            'type' => $type,
            'color' => $color,
            'background' => $background
        ];
    }

    private static function normalizeColor(mixed $color): string
    {
        if (!is_string($color) || !preg_match('/\A#[a-f0-9]{6}\z/i', $color)) {
            return '';
        }

        return $color;
    }
}
