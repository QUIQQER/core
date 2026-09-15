<?php

declare(strict_types=1);

namespace QUITests\Projects\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Manager;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Hreflang;

class HreflangTest extends TestCase
{
    private array $previousProjects;
    private mixed $previousManager;

    protected function setUp(): void
    {
        $this->previousProjects = Manager::$projects;
        $this->previousManager = QUI::$ProjectManager;
        QUI::$ProjectManager = new Manager();
    }

    protected function tearDown(): void
    {
        Manager::$projects = $this->previousProjects;
        QUI::$ProjectManager = $this->previousManager;
    }

    private function source(array $languageIds): Site
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('hreflang-test');
        $Project->method('getLang')->willReturn('de');
        $Project->method('getLanguages')->willReturn(['de', 'en']);
        $Project->method('getDefaultLang')->willReturn('en');

        $Site = $this->createMock(Site::class);
        $Site->method('getProject')->willReturn($Project);
        $Site->method('getId')->willReturn(57);
        $Site->method('getLangIds')->willReturn($languageIds);
        // Reproduce the old fallback: an unrelated English site has ID 57.
        $Site->method('existLang')->willReturn(true);
        $Site->method('getUrlRewrittenWithHost')->willReturn('https://example.de/api');
        $Project->method('get')->with(57)->willReturn($Site);
        Manager::$projects['hreflang-test']['de'] = $Project;

        return $Site;
    }

    public static function missingLinks(): array
    {
        return [
            'removed link' => [['en' => 0]],
            'missing translation' => [['en' => false]],
            'missing column' => [[]]
        ];
    }

    #[DataProvider('missingLinks')]
    public function testMissingTranslationDoesNotFallBackToSameSiteId(array $languageIds): void
    {
        $Site = $this->source($languageIds);
        $EnglishProject = $this->createMock(Project::class);
        $EnglishProject->expects(self::never())->method('get');
        Manager::$projects['hreflang-test']['en'] = $EnglishProject;

        self::assertSame(
            '<link rel="alternate" hreflang="de" href="https://example.de/api" />',
            (new Hreflang($Site))->output()
        );
    }

    public function testLinkedTranslationUsesItsExplicitIdAndSuppliesXDefault(): void
    {
        $Site = $this->source(['en' => 49]);
        $EnglishSite = $this->createMock(Site::class);
        $EnglishSite->method('getUrlRewrittenWithHost')->willReturn('https://example.eu/api');
        $EnglishProject = $this->createMock(Project::class);
        $EnglishProject->expects(self::exactly(2))->method('get')->with(49)->willReturn($EnglishSite);
        Manager::$projects['hreflang-test']['en'] = $EnglishProject;

        self::assertSame(
            '<link rel="alternate" hreflang="de" href="https://example.de/api" />' . "\n" .
            '<link rel="alternate" hreflang="en" href="https://example.eu/api" />' . "\n" .
            '<link rel="alternate" hreflang="x-default" href="https://example.eu/api" />',
            (new Hreflang($Site))->output()
        );
    }

    public function testMissingLinkedSiteIsOmitted(): void
    {
        $Site = $this->source(['en' => 49]);
        $EnglishProject = $this->createMock(Project::class);
        $EnglishProject->method('get')->with(49)->willThrowException(new QUI\Exception('Not found', 404));
        Manager::$projects['hreflang-test']['en'] = $EnglishProject;

        self::assertSame(
            '<link rel="alternate" hreflang="de" href="https://example.de/api" />',
            (new Hreflang($Site))->output()
        );
    }

    public function testExplicitLanguageUrlOverrideIsPreserved(): void
    {
        $Site = $this->source([]);
        $Site->method('existsAttribute')->willReturnCallback(static fn($name) => $name === 'en-link');
        $Site->method('getAttribute')->with('en-link')->willReturn('https://example.org/api?a=1&b=2');

        $output = (new Hreflang($Site))->output();
        self::assertStringContainsString(
            'hreflang="en" href="https://example.org/api?a=1&amp;b=2"',
            $output
        );
        self::assertStringContainsString('hreflang="x-default"', $output);
    }
}
