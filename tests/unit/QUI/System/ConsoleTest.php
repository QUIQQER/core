<?php

namespace QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('titleVisibilityProvider')]
    public function testTitleVisibility(array $arguments, bool $expected): void
    {
        $Console = new class extends Console {
            public function __construct()
            {
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function titleIsVisible(array $arguments): bool
            {
                return $this->needsHelpHeader($arguments);
            }
        };

        self::assertSame($expected, $Console->titleIsVisible($arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function titleVisibilityProvider(): iterable
    {
        yield 'no arguments' => [[], true];
        yield 'global help' => [['--help' => true], true];
        yield 'system tool' => [['update' => true], false];
        yield 'system tool help' => [['update' => true, '--help' => true], false];
        yield 'registered tool' => [['quiqqer:test' => true], false];
        yield 'list tools' => [['--listtools' => true], false];
        yield 'explicit no logo' => [['--noLogo' => true], false];
        yield 'completion' => [['_complete' => true], false];
    }
}
