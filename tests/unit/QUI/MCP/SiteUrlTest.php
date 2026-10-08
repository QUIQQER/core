<?php

declare(strict_types=1);

namespace QUI\MCP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site;
use ReflectionMethod;

class SiteUrlTest extends TestCase
{
    #[DataProvider('unpublishedSites')]
    public function testUnpublishedSitesDoNotGeneratePublicUrls(int $active, int $deleted): void
    {
        $Site = $this->createSite($active, $deleted);
        $Site->expects(self::never())->method('getLocation');
        $Site->expects(self::never())->method('getUrlRewritten');
        $Site->expects(self::never())->method('getUrlRewrittenWithHost');

        $this->assertUnavailableUrls($this->parseSite($Site));
    }

    public static function unpublishedSites(): array
    {
        return [
            'inactive' => [0, 0],
            'trashed' => [-1, 1],
            'deleted' => [1, 1]
        ];
    }

    public function testUnresolvableParentDoesNotReachTheLoggingUrlRenderer(): void
    {
        $Site = $this->createSite();
        $Site->method('getLocation')->willThrowException(new QUI\Exception('Site not found', 705));
        $Site->expects(self::never())->method('getUrlRewritten');
        $Site->expects(self::never())->method('getUrlRewrittenWithHost');

        $result = $this->parseSite($Site);

        $this->assertUnavailableUrls($result);
        self::assertTrue($result['active']);
        self::assertTrue($result['languageLinks']['de']['exists']);
        self::assertTrue($result['languageLinks']['de']['active']);
        self::assertSame(378, $result['languageLinks']['de']['id']);
        self::assertFalse($result['languageLinks']['en']['exists']);
    }

    #[DataProvider('publicUrls')]
    public function testReachableSitesKeepTheirRewrittenUrls(string $location, string $url): void
    {
        $Site = $this->createSite();
        $Site->method('getLocation')->willReturn($location);
        $Site->method('getUrlRewritten')->willReturn($url);
        $Site->method('getUrlRewrittenWithHost')->willReturn('https://example.org' . $url);

        $result = $this->parseSite($Site);

        self::assertSame($url, $result['url']);
        self::assertSame('https://example.org' . $url, $result['urlWithHost']);
        self::assertSame($url, $result['languageLinks']['de']['url']);
        self::assertSame('https://example.org' . $url, $result['languageLinks']['de']['urlWithHost']);
    }

    public static function publicUrls(): array
    {
        return [
            'page' => ['products/example', '/products/example'],
            'homepage with empty location' => ['', '/']
        ];
    }

    public function testFailedRewriteDoesNotBecomeAnUnrelatedHomepageUrl(): void
    {
        $Site = $this->createSite();
        $Site->method('getLocation')->willReturn('products/example');
        $Site->method('getUrlRewritten')->willReturn('');
        $Site->expects(self::never())->method('getUrlRewrittenWithHost');

        $this->assertUnavailableUrls($this->parseSite($Site));
    }

    public function testUnexpectedLocationErrorsAreNotSilenced(): void
    {
        $Site = $this->createSite();
        $Exception = new QUI\Exception('Unexpected failure', 500);
        $Site->method('getLocation')->willThrowException($Exception);
        $Site->expects(self::never())->method('getUrlRewritten');

        $this->expectExceptionObject($Exception);
        $this->parseSite($Site);
    }

    private function createSite(int $active = 1, int $deleted = 0): Site & MockObject
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('mcp-site-url-test');
        $Project->method('getLang')->willReturn('de');
        $Project->method('getLanguages')->willReturn(['de', 'en']);

        $Site = $this->createMock(Site::class);
        $Site->method('getProject')->willReturn($Project);
        $Site->method('getId')->willReturn(378);
        $Site->method('getLangIds')->willReturn([]);
        $Site->method('getAttribute')->willReturnMap([
            ['active', $active],
            ['deleted', $deleted],
            ['name', 'premium abonnements'],
            ['title', 'Premium subscriptions']
        ]);

        return $Site;
    }

    private function parseSite(Site $Site): array
    {
        return (new ReflectionMethod(AbstractTool::class, 'parseSite'))->invoke(null, $Site);
    }

    private function assertUnavailableUrls(array $result): void
    {
        self::assertSame(378, $result['id']);
        self::assertSame('Premium subscriptions', $result['title']);
        self::assertNull($result['url']);
        self::assertNull($result['urlWithHost']);
        self::assertNull($result['languageLinks']['de']['url']);
        self::assertNull($result['languageLinks']['de']['urlWithHost']);
    }
}
