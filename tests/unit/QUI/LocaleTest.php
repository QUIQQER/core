<?php

namespace QUI;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

# Tests have to run in separate processes since some properties of Locale are static and thus leak into other tests
#[RunTestsInSeparateProcesses]
class LocaleTest extends TestCase
{
    private array $previousTranslations;
    private ?string $translationDirectory = null;
    private array $fixtureFiles = [];
    private array $fixtureDirectories = [];

    protected function setUp(): void
    {
        $this->previousTranslations = (new \ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->setValue(null, $this->previousTranslations);

        foreach ($this->fixtureFiles as $file) {
            unlink($file);
        }

        foreach (array_reverse($this->fixtureDirectories) as $directory) {
            rmdir($directory);
        }
    }

    public function testSetCurrent(): void
    {
        $sut = new Locale();

        $testLanguage = 'fr';
        $sut->setCurrent($testLanguage);

        $this->assertEquals($testLanguage, $sut->getCurrent());
    }

    public function testResetCurrent(): void
    {
        $sut = new Locale();
        $testLanguage = 'fr';

        $sut->setCurrent($testLanguage);
        $sut->setTemporaryCurrent('it');
        $sut->resetCurrent();

        $this->assertEquals($testLanguage, $sut->getCurrent());
    }

    public function testSetTemporaryCurrent(): void
    {
        $sut = new Locale();

        $testLanguage = 'fr';
        $sut->setTemporaryCurrent($testLanguage);

        $this->assertEquals($testLanguage, $sut->getCurrent());
    }

    public static function formatNumberDataProvider(): array
    {
        return [
            ['de', 1_234.567, '1.234,567'],
            ['de', 123_456.789, '123.456,789'],
            ['de', 123_456_789.1, '123.456.789,1'],
            ['de', 1.1, '1,1'],
            ['de', 1.987654321, '1,988'],
            ['de', 2, '2'],
            ['en', 1_234.567, '1,234.567'],
            ['en', 123_456.789, '123,456.789'],
            ['en', 123_456_789.1, '123,456,789.1'],
            ['en', 1.1, '1.1'],
            ['en', 1.987654321, '1.988'],
            ['en', 2, '2'],
        ];
    }

    #[DataProvider('formatNumberDataProvider')]
    public function testFormatNumber(string $language, float $numberToFormat, string $expectedFormat): void
    {
        $locale = new Locale();

        // TODO: remove logic from test
        if (!$locale->existsLang($language)) {
            $this->markTestSkipped("Language '$language' is not available in this QUIQQER system");
        }

        $locale->setCurrent($language);

        $sut = $locale->formatNumber($numberToFormat, \NumberFormatter::SCIENTIFIC);

        $this->assertEquals($expectedFormat, $sut);
    }

    public function testGetDateFormatterContainsCurrentLanguage(): void
    {
        $locale = new Locale();
        $expectedLanguage = 'fr';
        $locale->setCurrent($expectedLanguage);

        $sut = $locale->getDateFormatter();

        $this->assertEquals($expectedLanguage, $sut->getLocale());
    }

    public function testExistsReturnsFalseOnRandomLocaleVariable(): void
    {
        $sut = new Locale();

        $this->assertFalse($sut->exists('abc', '123'));
    }

    public function testExistsLangReturnsFalseOnNonExistingLanguage(): void
    {
        $sut = new Locale();

        $this->assertFalse($sut->existsLang('abcdefg'));
    }

    public function testRefreshClearsRuntimeCache(): void
    {
        LocaleRuntimeCache::set('de', 'quiqqer/core', [
            'projects.defaultstructure.search.title' => 'Stale title'
        ]);

        $this->assertTrue(LocaleRuntimeCache::isCached('de', 'quiqqer/core'));

        $locale = new Locale();
        $locale->refresh();

        $this->assertFalse(LocaleRuntimeCache::isCached('de', 'quiqqer/core'));
    }

    public static function isLocaleStringDataProvider(): array
    {
        return [
            ['[quiqqer/core] this.is.a.test', true],
            ['[quiqqer/core] hello', true],
            ['[quiqqer/core] 123', true],
            ['[pcsg/ai-influencer] this.is.a-test', true],
            ['this.is.a.test', false],
            ['this.is.a.test [quiqqer/core]', false],
            ['', false],
            ['[ ]', false],
            ['[quiqqer/core]', false],
            ['[quiqqer] this.is.a.test', false],
        ];
    }

    #[DataProvider('isLocaleStringDataProvider')]
    public function testIsLocaleString(string $localeString, bool $expectedResult): void
    {
        $sut = new Locale();

        $this->assertEquals($expectedResult, $sut->isLocaleString($localeString));
    }

    public static function getPartsOfLocaleStringProvider(): array
    {
        return [
            ['[quiqqer/core] this.is.a.test', 'quiqqer/core', 'this.is.a.test'],
            ['[quiqqer/core] hello', 'quiqqer/core', 'hello'],
            ['[quiqqer/core] 123', 'quiqqer/core', '123'],
            ['[pcsg/ai-influencer] this.is.a-test', 'pcsg/ai-influencer', 'this.is.a-test'],
            ['this.is.a.test', null, null],
            ['this.is.a.test [quiqqer/core]', null, null],
            ['', null, null],
            ['[ ]', null, null],
            ['[quiqqer/core]', null, null],
            ['[quiqqer] this.is.a.test', null, null],
        ];
    }

    #[DataProvider('getPartsOfLocaleStringProvider')]
    public function testGetPartsOfLocaleString(
        string $localeStringToTest,
        ?string $expectedGroup,
        ?string $expectedVariable
    ): void {
        $locale = new Locale();

        $sut = $locale->getPartsOfLocaleString($localeStringToTest);

        $this->assertEquals($expectedGroup, $sut[0]);
        $this->assertEquals($expectedVariable, $sut[1]);
    }

    public function testGetUsesCurrentLanguageAndKeepsGroupsSeparate(): void
    {
        $Locale = $this->createTranslationLocale();
        $this->writeTranslation('de', 'locale-test/other', 'greeting = "Andere Gruppe"');

        self::assertSame('Hallo [name]', $Locale->get('locale-test/messages', 'greeting'));
        self::assertSame('Andere Gruppe', $Locale->get('locale-test/other', 'greeting'));

        $Locale->setCurrent('en');
        self::assertSame('Hello [name]', $Locale->get('locale-test/messages', 'greeting'));
        $Locale->setCurrent('de');
        self::assertSame('Hallo [name]', $Locale->get('locale-test/messages', 'greeting'));
    }

    public static function translationReaders(): array
    {
        return ['current language' => [false], 'explicit language' => [true]];
    }

    #[DataProvider('translationReaders')]
    public function testTranslationsReplaceScalarValuesAndLineBreaks(bool $explicitLanguage): void
    {
        $Locale = $this->createTranslationLocale();
        $replace = ['name' => 'Ada', 'count' => 0, 'array' => ['ignored'], 'object' => new \stdClass()];
        $actual = $explicitLanguage
            ? $Locale->getByLang('de', 'locale-test/messages', 'details', $replace)
            : $Locale->get('locale-test/messages', 'details', $replace);

        self::assertSame('Hallo Ada: 0' . PHP_EOL . '[array] [object] [unknown]', $actual);
    }

    #[DataProvider('translationReaders')]
    public function testTranslationsReturnWholeGroupsAndConvertLineBreaks(bool $explicitLanguage): void
    {
        $Locale = $this->createTranslationLocale();
        $actual = $explicitLanguage
            ? $Locale->getByLang('de', 'locale-test/messages')
            : $Locale->get('locale-test/messages');

        self::assertSame([
            'greeting' => 'Hallo [name]',
            'details' => 'Hallo [name]: [count]' . PHP_EOL . '[array] [object] [unknown]',
            'empty' => ''
        ], $actual);
    }

    public function testMissingTranslationsReturnTheirReference(): void
    {
        $Locale = $this->createTranslationLocale();

        self::assertSame('[locale-test/messages] absent', $Locale->get('locale-test/messages', 'absent'));
        self::assertSame('[locale-test/missing] greeting', $Locale->get('locale-test/missing', 'greeting'));
        self::assertSame(
            '[locale-test/messages] greeting',
            $Locale->getByLang('fr', 'locale-test/messages', 'greeting')
        );
        self::assertSame('', $Locale->get('locale-test/messages', 'empty'));
    }

    public function testDisabledTranslationReturnsReferencesEvenForCachedValues(): void
    {
        $Locale = $this->createTranslationLocale();
        $Locale->initConfig('locale-test/messages');
        $Locale->no_translation = true;

        self::assertSame('[locale-test/messages] greeting', $Locale->get('locale-test/messages', 'greeting'));
        self::assertSame(
            '[locale-test/messages] greeting',
            $Locale->getByLang('en', 'locale-test/messages', 'greeting')
        );
    }

    public function testInitConfigLoadsCurrentOrExplicitLanguage(): void
    {
        $Locale = $this->createTranslationLocale();
        self::assertFalse(LocaleRuntimeCache::isCached('de', 'locale-test/messages'));
        self::assertFalse(LocaleRuntimeCache::isCached('en', 'locale-test/messages'));

        $Locale->initConfig('locale-test/messages');
        self::assertSame('Hallo [name]', LocaleRuntimeCache::get('de', 'locale-test/messages', 'greeting'));
        self::assertFalse(LocaleRuntimeCache::isCached('en', 'locale-test/messages'));

        $Locale->initConfig('locale-test/messages', 'en');
        self::assertSame('Hello [name]', LocaleRuntimeCache::get('en', 'locale-test/messages', 'greeting'));
        self::assertSame('de', $Locale->getCurrent());
    }

    public function testInitConfigReusesCachedTranslationsUntilRefresh(): void
    {
        $Locale = $this->createTranslationLocale();
        $Locale->initConfig('locale-test/messages');
        $this->writeTranslation('de', 'locale-test/messages', 'greeting = "Geändert"');
        $Locale->initConfig('locale-test/messages');

        self::assertSame('Hallo [name]', $Locale->get('locale-test/messages', 'greeting'));
        $Locale->refresh();
        self::assertSame('Geändert', $Locale->get('locale-test/messages', 'greeting'));
    }

    public function testInitConfigCanLoadAFileCreatedAfterAnEarlierMiss(): void
    {
        $Locale = $this->createTranslationLocale();
        $Locale->initConfig('locale-test/later');
        self::assertFalse(LocaleRuntimeCache::isCached('de', 'locale-test/later'));

        $this->writeTranslation('de', 'locale-test/later', 'greeting = "Jetzt vorhanden"');
        $Locale->initConfig('locale-test/later');
        self::assertSame('Jetzt vorhanden', $Locale->get('locale-test/later', 'greeting'));
    }

    public static function translationPaths(): array
    {
        return [
            'package name' => ['de', 'vendor/package', 'de/LC_MESSAGES/vendor_package.ini.php'],
            'uppercase language' => ['EN', 'vendor/package', 'en/LC_MESSAGES/vendor_package.ini.php'],
            'language normalization' => ['../D3E!', 'vendor/package', 'de/LC_MESSAGES/vendor_package.ini.php']
        ];
    }

    #[DataProvider('translationPaths')]
    public function testGetTranslationFileNormalizesLanguageAndGroup(string $lang, string $group, string $path): void
    {
        $Locale = new Locale();

        self::assertSame($Locale->dir() . '/' . $path, $Locale->getTranslationFile($lang, $group));
    }

    public static function systemLocales(): array
    {
        return [
            'German locales' => [true, 'de', ['de_DE.utf8', 'de_AT.utf8', 'de_CH.utf8']],
            'British English first' => [true, 'en', ['en_GB.utf8', 'en_US.utf8']],
            'no matching locale' => [true, 'fr', []],
            'without shell' => [false, 'de', ['de_DE', 'de_DE.utf8', 'de_DE.UTF-8', 'de_DE@euro']]
        ];
    }

    #[DataProvider('systemLocales')]
    public function testGetLocalesByLangFiltersSortsAndCachesSystemLocales(bool $shell, string $lang, array $expected): void
    {
        $Process = proc_open([
            PHP_BINARY, __DIR__ . '/Fixtures/locale-list.php', $shell ? '1' : '0', $lang
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($Process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($Process), $errors . $output);
        self::assertSame([$expected, $expected], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testGetByLangDoesNotChangeCurrentOrTemporaryLanguage(): void
    {
        $Locale = $this->createTranslationLocale();
        $Locale->setTemporaryCurrent('fr');

        self::assertSame('Hello Ada', $Locale->getByLang('en', 'locale-test/messages', 'greeting', ['name' => 'Ada']));
        self::assertSame('fr', $Locale->getCurrent());
        $Locale->resetCurrent();
        self::assertSame('de', $Locale->getCurrent());
    }

    public static function localeStrings(): array
    {
        return [
            'translation reference' => ['[locale-test/messages] greeting', 'Hallo [name]'],
            'plain text' => ['Hallo Welt', 'Hallo Welt'],
            'empty text' => ['', ''],
            'incomplete reference' => ['[locale-test/messages]', '[locale-test/messages]'],
            'embedded reference' => ['Text [locale-test/messages] greeting', 'Text [locale-test/messages] greeting'],
            'missing translation' => ['[locale-test/messages] absent', '[locale-test/messages] absent'],
            'array with replacements' => [['locale-test/messages', 'greeting', ['name' => 'Ada']], 'Hallo Ada']
        ];
    }

    #[DataProvider('localeStrings')]
    public function testParseLocaleStringTranslatesOnlyReferences(array|string $input, string $expected): void
    {
        self::assertSame($expected, $this->createTranslationLocale()->parseLocaleString($input));
    }

    public static function localeArrays(): array
    {
        return [
            'translation' => [['locale-test/messages', 'greeting'], 'Hallo [name]'],
            'replacements' => [['locale-test/messages', 'greeting', ['name' => 'Ada']], 'Hallo Ada'],
            'empty array' => [[], ''],
            'missing key' => [['locale-test/messages'], ''],
            'missing group' => [[1 => 'greeting'], ''],
            'null key' => [['locale-test/messages', null], ''],
            'missing translation' => [['locale-test/messages', 'absent'], '[locale-test/messages] absent']
        ];
    }

    #[DataProvider('localeArrays')]
    public function testParseLocaleArrayHandlesTranslationsAndIncompleteReferences(array $input, string $expected): void
    {
        self::assertSame($expected, $this->createTranslationLocale()->parseLocaleArray($input));
    }

    private function createTranslationLocale(): Locale
    {
        $this->translationDirectory = sys_get_temp_dir() . '/quiqqer-locale-test-' . bin2hex(random_bytes(12));
        mkdir($this->translationDirectory, 0700);
        $this->fixtureDirectories[] = $this->translationDirectory;
        $this->writeTranslation('de', 'locale-test/messages', <<<'INI'
greeting = "Hallo [name]"
details = "Hallo [name]: [count]{\n}[array] [object] [unknown]"
empty = ""
INI);
        $this->writeTranslation('en', 'locale-test/messages', 'greeting = "Hello [name]"');

        $Locale = $this->getMockBuilder(Locale::class)->onlyMethods(['dir'])->getMock();
        $Locale->method('dir')->willReturn($this->translationDirectory);
        $Locale->setCurrent('de');

        return $Locale;
    }

    private function writeTranslation(string $language, string $group, string $content): void
    {
        $directory = $this->translationDirectory;

        foreach ([$language, 'LC_MESSAGES'] as $part) {
            $directory .= '/' . $part;

            if (!is_dir($directory)) {
                mkdir($directory, 0700);
                $this->fixtureDirectories[] = $directory;
            }
        }

        $file = $directory . '/' . str_replace('/', '_', $group) . '.ini.php';
        if (!in_array($file, $this->fixtureFiles, true)) {
            $this->fixtureFiles[] = $file;
        }

        file_put_contents($file, ";<?php exit; ?>\n" . $content . "\n");
    }
}
