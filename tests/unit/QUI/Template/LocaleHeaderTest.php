<?php

declare(strict_types=1);

namespace QUITests\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Template\EngineInterface;
use QUI\Projects\Project;
use QUI\Template;

final class LocaleHeaderTest extends TestCase
{
    private string $directory;
    private array $originalGlobals = [];
    private array $assigned = [];
    private Project&MockObject $Project;
    private Template $Template;
    private EngineInterface&MockObject $Engine;
    private array $manifest = [
        'version' => 'first-publication',
        'contexts' => ['frontend', 'administration', 'dashboard'],
        'languages' => ['de', 'en'],
        'bundledContexts' => ['frontend', 'administration']
    ];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-header-locale-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory . 'bin/_contexts', 0700, true);
        $this->writeManifest($this->manifest);

        foreach (['Locale', 'Users', 'Events', 'PackageManager'] as $property) {
            $this->originalGlobals[$property] = QUI::${$property};
        }

        QUI::$Locale = $this->createMock(QUI\Locale::class);
        QUI::$Locale->method('dir')->willReturn($this->directory);
        QUI::$Locale->method('getCurrent')->willReturn('en');
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        QUI::$PackageManager = $this->createMock(QUI\Package\Manager::class);
        QUI::$PackageManager->method('getLastUpdateDate')->willReturn(123);
        $User = $this->createMock(QUI\Users\User::class);
        $User->method('getLang')->willReturn('en');
        QUI::$Users = $this->createMock(QUI\Users\Manager::class);
        QUI::$Users->method('getUserBySession')->willReturn($User);

        $this->Project = $this->createMock(Project::class);
        $this->Project->method('getName')->willReturn(basename($this->directory));
        $this->Project->method('getLanguages')->willReturn(['de', 'en']);
        $Site = $this->createMock(QUI\Interfaces\Projects\Site::class);
        $Site->method('getAttribute')->willReturn('');
        $this->Engine = $this->createMock(EngineInterface::class);
        $this->Engine->method('assign')->willReturnCallback(function (array|string $name, mixed $value = false): void {
            if (is_array($name)) {
                $this->assigned = array_replace($this->assigned, $name);
            } else {
                $this->assigned[$name] = $value;
            }
        });
        $this->Template = new Template();
        $this->Template->getJsonLd()->set('type', 'WebPage');
        $this->Template->setAttributes([
            'Project' => $this->Project,
            'Site' => $Site,
            'Engine' => $this->Engine
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalGlobals as $property => $value) {
            QUI::${$property} = $value;
        }

        unlink($this->directory . 'bin/_contexts/manifest.json');
        rmdir($this->directory . 'bin/_contexts');
        rmdir($this->directory . 'bin');
        rmdir($this->directory);
    }

    public static function headerModes(): iterable
    {
        yield 'standard header' => [false, 'header.html'];
        yield 'no-conflict header' => [true, 'headerNoConflict.html'];
    }

    #[DataProvider('headerModes')]
    public function testHeaderSelectsOnlyFrontendBundleInProjectLanguage(bool $noConflict, string $header): void
    {
        $this->Project->method('getLang')->willReturn('de');
        $this->Template->setAttribute('noConflict', $noConflict);
        $this->Engine->expects(self::once())->method('fetch')->with(LIB_DIR . 'templates/' . $header);

        $this->Template->getHeader();

        self::assertSame(
            ['locale/_contexts/first-publication/b' . bin2hex('frontend') . '/de'],
            $this->assigned['localeFiles']
        );
        self::assertSame($this->manifest, $this->assigned['localeContextManifest']);
        self::assertSame($this->manifest['version'], $this->assigned['localePublishVersion']);
    }

    #[DataProvider('headerModes')]
    public function testPublicationDuringHeaderGenerationDoesNotMixVersions(bool $noConflict, string $header): void
    {
        $newManifest = array_replace($this->manifest, ['version' => 'second-publication']);
        $this->Project->method('getLang')->willReturnCallback(function () use ($newManifest): string {
            // The project language is requested after the publication snapshot has been read.
            $this->writeManifest($newManifest);
            return 'de';
        });
        $this->Template->setAttribute('noConflict', $noConflict);
        $this->Engine->expects(self::exactly(2))->method('fetch')->with(LIB_DIR . 'templates/' . $header);

        $this->Template->getHeader();

        self::assertSame(
            ['locale/_contexts/first-publication/b' . bin2hex('frontend') . '/de'],
            $this->assigned['localeFiles']
        );
        self::assertSame($this->manifest, $this->assigned['localeContextManifest']);
        self::assertSame($this->manifest['version'], $this->assigned['localePublishVersion']);

        $this->Template->getHeader();

        self::assertSame(
            ['locale/_contexts/second-publication/b' . bin2hex('frontend') . '/de'],
            $this->assigned['localeFiles']
        );
        self::assertSame($newManifest, $this->assigned['localeContextManifest']);
        self::assertSame($newManifest['version'], $this->assigned['localePublishVersion']);
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->directory . 'bin/_contexts/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
    }
}
