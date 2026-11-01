<?php

namespace QUITests\QUI\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\System\SystemOutput;
use QUI\Locale;
use QUI\LocaleRuntimeCache;
use QUI\System\Console;
use QUI\System\Console\UpdateConsoleOutput;
use QUI\System\PhpVersionSupport;
use ReflectionClass;
use ReflectionProperty;

class PhpVersionSupportTest extends TestCase
{
    private ?Locale $previousLocale;

    /** @var array<string, mixed> */
    private array $previousTranslations;

    protected function setUp(): void
    {
        $this->previousLocale = QUI::$Locale;
        $this->previousTranslations = (new ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->getValue();
        QUI::$Locale = new Locale();
        QUI::$Locale->setCurrent('en');

        $catalog = simplexml_load_file(dirname(__DIR__, 4) . '/locale/en.console.xml');
        self::assertNotFalse($catalog);
        $entries = $catalog->xpath('//locale[@name="system.phpVersion.outdated"]/en');
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        LocaleRuntimeCache::set('en', 'quiqqer/core', [
            'system.phpVersion.outdated' => (string)$entries[0]
        ]);
    }

    protected function tearDown(): void
    {
        QUI::$Locale = $this->previousLocale;
        (new ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->setValue(null, $this->previousTranslations);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function phpVersions(): iterable
    {
        yield 'PHP 7' => ['7.4.33', true];
        yield 'PHP 8.0' => ['8.0.30', true];
        yield 'PHP 8.1' => ['8.1.34', true];
        yield 'PHP 8.2 initial' => ['8.2.0', true];
        yield 'PHP 8.2 patched' => ['8.2.99', true];
        yield 'PHP 8.2 distribution build' => ['8.2.29-1ubuntu1', true];
        yield 'PHP 8.3' => ['8.3.0', false];
        yield 'PHP 8.3 patched' => ['8.3.36', false];
        yield 'PHP 8.4' => ['8.4.0', false];
        yield 'PHP 8.5' => ['8.5.12', false];
        yield 'PHP 8.10' => ['8.10.0', false];
        yield 'PHP 9' => ['9.0.0', false];
    }

    #[DataProvider('phpVersions')]
    public function testWarningMatchesPhpVersion(string $version, bool $outdated): void
    {
        $warning = PhpVersionSupport::getUpdateWarning($version);

        if (!$outdated) {
            self::assertNull($warning);
            return;
        }

        self::assertNotNull($warning);
        self::assertStringContainsString($version, $warning);
        self::assertStringContainsString('PHP 8.3 or higher', $warning);
        self::assertStringContainsString('Future QUIQQER updates', $warning);
        self::assertStringNotContainsString('[version]', $warning);
    }

    #[DataProvider('phpVersions')]
    public function testConsoleWarningIsRedAndResetsTheColor(string $version, bool $outdated): void
    {
        $Console = (new ReflectionClass(Console::class))->newInstanceWithoutConstructor();
        ob_start();

        try {
            $Console->writePhpVersionWarning($version);
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (!$outdated) {
            self::assertSame('', $output);
            return;
        }

        self::assertStringStartsWith("\033[1;31m[!!] ", $output);
        self::assertStringContainsString($version, $output);
        self::assertStringEndsWith("\033[0m", $output);
    }

    #[DataProvider('phpVersions')]
    public function testUpdateOutputShowsTheWarningInRed(string $version, bool $outdated): void
    {
        $Output = $this->createMock(SystemOutput::class);

        if ($outdated) {
            $Output->expects(self::once())->method('writeLn')->with(
                '  [!!] ' . PhpVersionSupport::getUpdateWarning($version),
                'red'
            );
        } else {
            $Output->expects(self::never())->method('writeLn');
        }

        (new UpdateConsoleOutput($Output))->phpVersionNotice($version);
    }

    public function testWarningExistsInEveryExistingLanguage(): void
    {
        foreach (['de', 'en', 'es', 'fr', 'it', 'pl', 'pt'] as $language) {
            $catalog = simplexml_load_file(dirname(__DIR__, 4) . '/locale/' . $language . '.console.xml');
            self::assertNotFalse($catalog);
            $entries = $catalog->xpath('//locale[@name="system.phpVersion.outdated"]/' . $language);
            self::assertIsArray($entries);
            self::assertCount(1, $entries, $language);
            self::assertStringContainsString('[version]', (string)$entries[0]);
            self::assertStringContainsString('8.3', (string)$entries[0]);
        }
    }
}
