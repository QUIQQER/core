<?php

declare(strict_types=1);

namespace QUI\Projects;

use QUI;

/**
 * Language-independent project colors.
 *
 * @see https://dev.quiqqer.com/quiqqer/core/-/wikis/project-colors
 */
final class Colors
{
    public const CONFIG_KEY = 'quiqqer.colors.palette';

    /** @return array{provider: string, palette: string} */
    public static function defaultSource(): array
    {
        return ['provider' => 'quiqqer/core', 'palette' => 'standard'];
    }

    /** @return array<string, array{value?: string, light?: string, dark?: string}> */
    public static function defaults(): array
    {
        return [
            'primary' => ['value' => '#2f8fc6'],
            'secondary' => ['value' => '#6c757d'],
            'tertiary' => ['value' => '#6f42c1'],
            'success' => ['value' => '#28a745'],
            'danger' => ['value' => '#dc4139'],
            'warning' => ['value' => '#ffd147'],
            'info' => ['value' => '#4ca5ce'],
            'light' => ['value' => '#e5e5e5'],
            'dark' => ['value' => '#2c3136'],
            'text' => ['light' => '#3b3b3a', 'dark' => '#e5e5e5'],
            'background' => ['light' => '#ffffff', 'dark' => '#2c3136'],
            'heading' => ['light' => '#3b3b3a', 'dark' => '#e5e5e5'],
            'link' => ['light' => '#2f8fc6', 'dark' => '#9bb9ed'],
            'link-hover' => ['light' => '#26729e', 'dark' => '#c3d5f5']
        ];
    }

    /**
     * Display metadata; IDs and translation groups remain independent.
     *
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'primary' => 'brand',
            'secondary' => 'brand',
            'tertiary' => 'brand',
            'success' => 'status',
            'danger' => 'status',
            'warning' => 'status',
            'info' => 'status',
            'light' => 'neutral',
            'dark' => 'neutral',
            'text' => 'content',
            'background' => 'content',
            'heading' => 'content',
            'link' => 'content',
            'link-hover' => 'content'
        ];
    }

    public static function isColor(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})\z/i', $value) === 1;
    }

    /** Stable namespaces: Core roles, custom IDs and explicitly declared package roles. */
    public static function isId(mixed $id): bool
    {
        return is_string($id) && (
            isset(self::defaults()[$id])
            || preg_match('/\Acustom-[a-z0-9-]{1,80}\z/', $id) === 1
            || preg_match('/\Atemplate:[a-z0-9-]+\/[a-z0-9-]+:[a-z][a-z0-9-]*\z/', $id) === 1
        );
    }

    /** @return array{value?: string, light?: string, dark?: string} */
    public static function parseColor(mixed $color): array
    {
        if (!is_array($color)) {
            throw new QUI\Exception('Invalid project color.', 400);
        }

        if (count($color) === 1 && self::isColor($color['value'] ?? null)) {
            return ['value' => $color['value']];
        }

        if (count($color) === 2 && self::isColor($color['light'] ?? null) && self::isColor($color['dark'] ?? null)) {
            return ['light' => $color['light'], 'dark' => $color['dark']];
        }

        throw new QUI\Exception('Invalid project color.', 400);
    }

    /**
     * Normalize v1 prototypes on read; no implicit write or template lookup.
     *
     * @return array{version: int, colors: array<string, array<string, mixed>>, source?: array{provider: string, palette: string}}
     */
    public static function parse(mixed $serialized): array
    {
        $palette = ['version' => 2, 'colors' => self::defaults()];

        if ($serialized === '' || $serialized === false) {
            $palette['source'] = self::defaultSource();
            return $palette;
        }

        $data = is_string($serialized) ? json_decode($serialized, true) : null;

        if (
            !is_array($data)
            || !in_array($data['version'] ?? null, [1, 2], true)
            || !is_array($data['colors'] ?? null)
            || count($data['colors']) > 512
        ) {
            throw new QUI\Exception('Invalid project color palette.', 400);
        }

        $legacy = $data['version'] === 1;
        $keys = $legacy ? ['version', 'colors', 'favorites'] : ['version', 'colors', 'source'];

        if (array_diff(array_keys($data), $keys)) {
            throw new QUI\Exception('Invalid project palette properties.', 400);
        }

        if (array_key_exists('source', $data)) {
            $source = $data['source'];

            if (
                !is_array($source)
                || count($source) !== 2
                || !is_string($source['provider'] ?? null)
                || !is_string($source['palette'] ?? null)
                || preg_match('/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $source['provider']) !== 1
                || preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/', $source['palette']) !== 1
            ) {
                throw new QUI\Exception('Invalid project palette source.', 400);
            }

            $palette['source'] = ['provider' => $source['provider'], 'palette' => $source['palette']];
        }

        foreach ($data['colors'] as $id => $entry) {
            if (!self::isId($id) || !is_array($entry)) {
                throw new QUI\Exception('Invalid project color ID.', 400);
            }

            $builtin = isset(self::defaults()[$id]);

            if ($legacy) {
                if (!$builtin || array_diff(array_keys($entry), ['light', 'dark'])) {
                    throw new QUI\Exception('Invalid prototype color.', 400);
                }

                $entry = array_key_exists('dark', $entry) ? $entry : ['value' => $entry['light'] ?? null];
            }

            $metadata = [];

            if (array_key_exists('displayGroup', $entry)) {
                if (
                    !str_starts_with($id, 'template:')
                    || !in_array($entry['displayGroup'], ['brand', 'status', 'neutral', 'content', 'other'], true)
                ) {
                    throw new QUI\Exception('Invalid project color display group.', 400);
                }

                $metadata['displayGroup'] = $entry['displayGroup'];
                unset($entry['displayGroup']);
            }

            if (!$builtin) {
                $name = $entry['name'] ?? null;

                if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100) {
                    throw new QUI\Exception('A project color requires a name of 1 to 100 characters.', 400);
                }

                $metadata['name'] = trim($name);
                unset($entry['name']);

                if (array_key_exists('archived', $entry)) {
                    if (!is_bool($entry['archived'])) {
                        throw new QUI\Exception('Invalid archived color flag.', 400);
                    }

                    $metadata['archived'] = $entry['archived'];
                    unset($entry['archived']);
                }
            }

            $palette['colors'][$id] = array_merge(self::parseColor($entry), $metadata);
        }

        if ($legacy) {
            if (!is_array($data['favorites'] ?? null) || !array_is_list($data['favorites']) || count($data['favorites']) > 64) {
                throw new QUI\Exception('Invalid prototype favorites.', 400);
            }

            foreach ($data['favorites'] as $favorite) {
                if (!self::isColor($favorite)) {
                    throw new QUI\Exception('Invalid prototype favorite.', 400);
                }

                // Deterministic IDs also make repeated reads/migration idempotent.
                $id = 'custom-legacy-' . strtolower(substr($favorite, 1));

                if (!isset($palette['colors'][$id])) {
                    $palette['colors'][$id] = ['value' => $favorite, 'name' => $favorite];
                }
            }
        }

        return $palette;
    }

    /**
     * Omitted custom/package entries are archived, never silently destroyed.
     *
     * @return array{version: int, colors: array<string, array<string, mixed>>, source?: array{provider: string, palette: string}}
     */
    public static function prepareSave(mixed $serialized, mixed $previous): array
    {
        $palette = self::parse($serialized);
        $old = self::parse($previous);

        if ($serialized === '' || $serialized === false) {
            unset($palette['source']);
        }

        if (!isset($palette['source']) && isset($old['source'])) {
            $palette['source'] = $old['source'];
        }

        foreach ($old['colors'] as $id => $color) {
            if (!isset($palette['colors'][$id])) {
                $palette['colors'][$id] = array_merge($color, ['archived' => true]);
            }
        }

        // Validate the complete result, including retained entries and the size limit.
        return self::parse(json_encode($palette, JSON_THROW_ON_ERROR));
    }

    /**
     * Validate a form draft. The last saved palette replaces the previous values.
     *
     * @return array{version: int, colors: array<string, array<string, mixed>>, source?: array{provider: string, palette: string}}
     */
    public static function prepareSaveRequest(mixed $request, string $current): array
    {
        $data = is_string($request) ? json_decode($request, true) : null;

        if (
            !is_array($data)
            || !is_string($data['palette'] ?? null)
            || array_diff(array_keys($data), ['palette', 'repair'])
            || (array_key_exists('repair', $data) && $data['repair'] !== true)
        ) {
            throw new QUI\Exception('Invalid project palette save request.', 400);
        }

        if (($data['repair'] ?? false) === true) {
            $palette = self::parse($data['palette']);

            try {
                self::parse($current);
            } catch (QUI\Exception) {
                return $palette;
            }

            throw new QUI\Exception('Only damaged palettes can be repaired.', 400);
        }

        $palette = self::prepareSave($data['palette'], $current);
        $previous = self::parse($current);

        if (($palette['source'] ?? null) !== ($previous['source'] ?? null)) {
            throw new QUI\Exception('Changing palette source requires an explicit palette-set application.', 400);
        }

        return $palette;
    }

    /** @return array{version: int, colors: array<string, array<string, mixed>>, source?: array{provider: string, palette: string}} */
    public static function get(Project $Project): array
    {
        return self::parse(self::snapshot($Project));
    }

    /**
     * Encode JSON for QUI's quoted INI storage without ambiguous backslash/quote sequences.
     *
     * @param array{version: int, colors: array<string, array<string, mixed>>, source?: array{provider: string, palette: string}} $palette
     */
    public static function encode(array $palette): string
    {
        $serialized = json_encode($palette, JSON_THROW_ON_ERROR | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        return str_replace('\\\\', '\u005c', $serialized);
    }

    /** Read the stored palette through the existing project configuration. */
    public static function snapshot(Project $Project): string
    {
        $projects = Manager::getConfig()->toArray();
        return $projects[$Project->getName()][self::CONFIG_KEY] ?? '';
    }

    /**
     * Pass the project palette when validating a new reference at a write boundary.
     * Without a palette, only syntax is checked (also permits resolving archived references).
     *
     * @param array{colors: array<string, array<string, mixed>>}|null $palette
     * @return string|array{type: 'palette', id: string}|array{type: 'color', light: string, dark: string}
     */
    public static function parseValue(mixed $value, ?array $palette = null): string|array
    {
        if ($value === '' || $value === 'transparent' || self::isColor($value)) {
            return $value;
        }

        $data = is_string($value) ? json_decode($value, true) : null;

        if (is_array($data)) {
            if (
                ($data['type'] ?? '') === 'palette'
                && self::isId($data['id'] ?? null)
                && count($data) === 2
                && ($palette === null || isset($palette['colors'][$data['id']]))
            ) {
                return ['type' => 'palette', 'id' => $data['id']];
            }

            if (
                ($data['type'] ?? '') === 'color'
                && self::isColor($data['light'] ?? null)
                && self::isColor($data['dark'] ?? null)
                && count($data) === 3
            ) {
                return ['type' => 'color', 'light' => $data['light'], 'dark' => $data['dark']];
            }
        }

        throw new QUI\Exception('Invalid color value or unknown palette reference.', 400);
    }

    /**
     * Resolve at render time; invalid or missing references use the explicit safe fallback.
     *
     * @param array{colors: array<string, array<string, mixed>>} $palette
     */
    public static function resolveValue(mixed $value, array $palette, string $mode = 'light', string $fallback = ''): string
    {
        if (!in_array($mode, ['light', 'dark'], true) || ($fallback !== '' && !self::isColor($fallback))) {
            throw new QUI\Exception('Invalid color resolution options.', 400);
        }

        try {
            $color = self::parseValue($value, $palette);
        } catch (QUI\Exception) {
            return $fallback;
        }

        if (is_string($color)) {
            return $color;
        }

        if ($color['type'] === 'palette') {
            $color = $palette['colors'][$color['id']];
        }

        $resolved = $color['value'] ?? $color[$mode] ?? $color['light'] ?? '';
        return self::isColor($resolved) ? $resolved : $fallback;
    }

    public static function resolve(Project $Project, mixed $value, string $mode = 'light', string $fallback = ''): string
    {
        try {
            $palette = self::get($Project);
        } catch (QUI\Exception) {
            // Concrete field values remain usable even when the central palette is damaged.
            $palette = ['colors' => []];
        }

        return self::resolveValue($value, $palette, $mode, $fallback);
    }
}
