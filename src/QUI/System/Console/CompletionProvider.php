<?php

namespace QUI\System\Console;

use function array_keys;
use function array_merge;
use function array_unique;
use function is_string;
use function ltrim;
use function sort;
use function str_starts_with;

class CompletionProvider
{
    private const GLOBAL_OPTIONS = [
        '--help',
        '--ignore-file-permissions',
        '--listtools',
        '--no-header',
        '--noLogo',
        '--password',
        '--username'
    ];

    /**
     * @param array<int, string> $systemTools
     * @param array<string, Tool> $tools
     */
    public function __construct(
        private readonly array $systemTools,
        private readonly array $tools
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getSuggestions(string $command, string $currentWord): array
    {
        if ($command === '') {
            if (str_starts_with($currentWord, '-')) {
                return $this->filterSuggestions(self::GLOBAL_OPTIONS, $currentWord);
            }

            $commands = array_unique(array_merge($this->systemTools, array_keys($this->tools)));

            return $this->filterSuggestions($commands, $currentWord);
        }

        if ($currentWord !== '' && !str_starts_with($currentWord, '-')) {
            return [];
        }

        $options = ['--help', '--no-header'];
        $Tool = $this->tools[$command] ?? null;

        if ($Tool) {
            foreach ($Tool->getArgumentDefinitions() as $argument) {
                $options[] = '--' . ltrim($argument['param'], '-');

                if (is_string($argument['short']) && $argument['short'] !== '') {
                    $options[] = '-' . ltrim($argument['short'], '-');
                }
            }
        }

        return $this->filterSuggestions(array_unique($options), $currentWord);
    }

    /**
     * @param array<int, string> $suggestions
     * @return array<int, string>
     */
    private function filterSuggestions(array $suggestions, string $currentWord): array
    {
        $suggestions = array_values(array_filter(
            $suggestions,
            static fn(string $suggestion): bool => str_starts_with($suggestion, $currentWord)
        ));

        sort($suggestions);

        return $suggestions;
    }
}
