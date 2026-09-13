<?php

namespace QUI\REST\Core\Project;

use QUI;
use QUI\Projects\Project;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Endpoint;

abstract class ProjectEndpoint extends Endpoint
{
    protected static function project(string $name, ?string $lang = null): Project
    {
        $Project = QUI::getProject($name);

        if ($lang !== null && !in_array($lang, $Project->getLanguages(), true)) {
            throw new ApiException('not_found', 'The requested project language does not exist.', 404);
        }

        return $lang === null ? $Project : QUI::getProject($name, $lang);
    }

    /** @return array<string, mixed> */
    protected static function representation(Project $Project): array
    {
        return [
            'name' => $Project->getName(),
            'title' => $Project->getTitle(),
            'defaultLanguage' => $Project->getDefaultLang(),
            'languages' => $Project->getLanguages(),
            'template' => $Project->getTemplate() ?: null,
            'host' => $Project->getHost()
        ];
    }

    protected static function settingType(string $type): string
    {
        return match (strtolower($type)) {
            'bool', 'boolean' => 'boolean',
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            default => 'string'
        };
    }

    /** @return list<array<string, mixed>> */
    protected static function settings(Project $Project): array
    {
        $definitions = QUI\Projects\Manager::getProjectConfigDefinitions($Project);
        $config = $Project->getConfig();
        $settings = [];
        ksort($definitions);

        foreach ($definitions as $key => $definition) {
            $value = $config[$key] ?? $definition['default'];
            $type = self::settingType($definition['type']);
            $settings[] = [
                'key' => $key,
                'value' => match ($type) {
                    'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    'integer' => (int)$value,
                    'number' => (float)$value,
                    default => (string)$value
                },
                'default' => $definition['default'],
                'type' => $type,
                'source' => $definition['source']
            ];
        }

        return $settings;
    }

    /** @param list<mixed> $languages
     * @return list<string>
     */
    protected static function languages(string $default, array $languages): array
    {
        $available = QUI::availableLanguages();
        $result = [];

        foreach ([$default, ...$languages] as $lang) {
            if (!is_string($lang) || !preg_match('/^[a-z]{2}$/', $lang) || !in_array($lang, $available, true)) {
                throw new ApiException('invalid_input', 'Every project language must be an installed language code.');
            }

            $result[$lang] = $lang;
        }

        return array_values($result);
    }
}
