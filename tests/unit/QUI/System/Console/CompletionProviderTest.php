<?php

namespace QUI\System\Console;

use PHPUnit\Framework\TestCase;

class CompletionProviderTest extends TestCase
{
    public function testCommandSuggestionsIncludeSystemAndRegisteredTools(): void
    {
        $Provider = new CompletionProvider(
            ['update', 'clear-cache'],
            ['quiqqer:update' => $this->createTool()]
        );

        self::assertSame(
            ['quiqqer:update'],
            $Provider->getSuggestions('', 'quiqqer:')
        );
        self::assertSame(
            ['clear-cache', 'quiqqer:update', 'update'],
            $Provider->getSuggestions('', '')
        );
    }

    public function testGlobalOptionsAreSuggestedInsteadOfCommands(): void
    {
        $Provider = new CompletionProvider([], []);

        self::assertSame(
            ['--help'],
            $Provider->getSuggestions('', '--h')
        );
    }

    public function testToolArgumentsAndShortOptionsAreSuggested(): void
    {
        $Provider = new CompletionProvider([], ['quiqqer:test' => $this->createTool()]);

        self::assertSame(
            ['--help', '--no-header', '--project'],
            $Provider->getSuggestions('quiqqer:test', '--')
        );
        self::assertSame(
            ['-p'],
            $Provider->getSuggestions('quiqqer:test', '-p')
        );
    }

    public function testValuesAreNotCompleted(): void
    {
        $Provider = new CompletionProvider([], ['quiqqer:test' => $this->createTool()]);

        self::assertSame([], $Provider->getSuggestions('quiqqer:test', 'project-name'));
    }

    public function testNoHeaderIsSuggestedBeforeAndAfterCommands(): void
    {
        $Provider = new CompletionProvider(['update'], []);

        self::assertSame(['--no-header'], $Provider->getSuggestions('', '--no-'));
        self::assertSame(['--no-header'], $Provider->getSuggestions('update', '--no-'));
    }

    private function createTool(): Tool
    {
        return new class extends Tool {
            public function __construct()
            {
                $this->addArgument('project', 'Project name', 'p', true);
            }
        };
    }
}
