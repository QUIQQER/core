<?php

declare(strict_types=1);

namespace QUITests\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Output;
use QUI\Projects\Manager;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Canonical;
use QUI\Projects\Site\Hreflang;
use ReflectionMethod;
use ReflectionProperty;

class VhostLanguageFallbackTest extends TestCase
{
    private mixed $previousVhosts;
    private array $previousProjects;

    protected function setUp(): void
    {
        $Vhosts = new ReflectionProperty(QUI::class, 'vhosts');
        $this->previousVhosts = $Vhosts->getValue();
        $Vhosts->setValue(null, []);
        $this->previousProjects = Manager::$projects;
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(QUI::class, 'vhosts'))->setValue(null, $this->previousVhosts);
        Manager::$projects = $this->previousProjects;
    }

    #[DataProvider('languageConfigurations')]
    public function testSiteAndCanonicalUrlsUseTheSameLanguagePath(
        string $language,
        array $languages,
        array $vhosts,
        string $expectedPath
    ): void {
        (new ReflectionProperty(QUI::class, 'vhosts'))->setValue(null, $vhosts);
        $Project = $this->project($language, $languages);
        $baseUrl = 'https://example.test' . URL_DIR . $expectedPath;

        self::assertSame($baseUrl, $Project->getVHostBaseUrl());

        foreach (['', 'contact'] as $location) {
            $Site = $this->site($Project, $location);
            $expectedUrl = $baseUrl . $location;

            self::assertSame($expectedUrl, $Site->getUrlRewrittenWithHost());

            // Both a custom relative canonical and an already prefixed URL must work.
            foreach ([$location, URL_DIR . $expectedPath . $location] as $canonical) {
                $CanonicalSite = $this->createMock(QUI\Interfaces\Projects\Site::class);
                $CanonicalSite->method('getProject')->willReturn($Project);
                $CanonicalSite->method('getCanonical')->willReturn($canonical);
                $CanonicalSite->method('getAttribute')->willReturn(false);

                self::assertSame(
                    $expectedUrl,
                    (new ReflectionMethod(Canonical::class, 'buildCanonicalUrl'))->invoke(
                        new Canonical($CanonicalSite)
                    )
                );
            }
        }
    }

    public static function languageConfigurations(): array
    {
        return [
            'German without VHosts' => ['de', ['de', 'en'], [], 'de/'],
            'English without VHosts' => ['en', ['de', 'en'], [], 'en/'],
            'single language without VHosts' => ['de', ['de'], [], ''],
            'legacy fallback with unrelated VHost' => [
                'de', ['de'], ['other.test' => ['project' => 'other', 'lang' => 'de']], 'de/'
            ],
            'explicit root route' => [
                'de', ['de', 'en'], ['example.test' => ['project' => 'fallback-test', 'lang' => 'de']], ''
            ],
            'explicit path route' => [
                'en', ['de', 'en'], [
                    'example.test' => [
                        'project' => 'fallback-test',
                        'lang' => 'de',
                        'path_langs' => 'en'
                    ]
                ], 'en/'
            ]
        ];
    }

    public function testLanguageLinksRemainDistinctWithoutVhosts(): void
    {
        $GermanProject = $this->project('de', ['de', 'en']);
        $EnglishProject = $this->project('en', ['de', 'en']);
        $GermanSite = $this->site($GermanProject, 'contact');
        $EnglishSite = $this->site($EnglishProject, 'contact');
        $GermanProject->method('get')->with(5)->willReturn($GermanSite);
        $EnglishProject->method('get')->with(5)->willReturn($EnglishSite);
        Manager::$projects['fallback-test'] = ['de' => $GermanProject, 'en' => $EnglishProject];

        self::assertNotSame($GermanSite->getUrlRewrittenWithHost(), $EnglishSite->getUrlRewrittenWithHost());
        self::assertSame(
            '<link rel="alternate" hreflang="de" href="https://example.test' . URL_DIR . 'de/contact" />' . "\n" .
            '<link rel="alternate" hreflang="en" href="https://example.test' . URL_DIR . 'en/contact" />' . "\n" .
            '<link rel="alternate" hreflang="x-default" href="https://example.test' . URL_DIR . 'de/contact" />',
            (new Hreflang($GermanSite))->output()
        );
    }

    private function project(string $language, array $languages): Project
    {
        $Project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName', 'getLang', 'getLanguages', 'getVHost', 'getDefaultLang', 'get'])
            ->getMock();
        $Project->method('getName')->willReturn('fallback-test');
        $Project->method('getLang')->willReturn($language);
        $Project->method('getLanguages')->willReturn($languages);
        $Project->method('getVHost')->willReturn('https://example.test');
        $Project->method('getDefaultLang')->willReturn('de');
        (new ReflectionProperty(Project::class, 'vhostRoute'))->setValue(
            $Project,
            QUI\System\VhostManager::resolveProjectLanguageRoute(QUI::vhosts(), 'fallback-test', $language) ?? false
        );

        return $Project;
    }

    private function site(Project $Project, string $location): Site
    {
        $Site = $this->getMockBuilder(Site::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProject', 'getId', 'getLangIds', 'getUrlRewritten', 'existsAttribute', 'existLang'])
            ->getMock();
        $Site->method('getProject')->willReturn($Project);
        $Site->method('getId')->willReturn(5);
        $Site->method('getLangIds')->willReturn(['de' => 5, 'en' => 5]);
        $Site->method('existsAttribute')->willReturn(false);
        $Site->method('existLang')->willReturn(true);
        $Output = new Output();
        (new ReflectionProperty(Output::class, 'rewrittenCache'))->setValue($Output, [
            'fallback-test_' . $Project->getLang() . '_5' => $location
        ]);
        $Site->method('getUrlRewritten')->willReturnCallback(
            static fn(): string => $Output->getSiteUrl(['site' => $Site])
        );

        return $Site;
    }
}
