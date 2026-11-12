<?php

declare(strict_types=1);

namespace QUITests\Export;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use QUI;

final class PrintTemplateSecurityTest extends TestCase
{
    private const ATTACK_GROUP_NAME = '<img src=x onerror="alert(1)">Malicious group';
    private const ATTACK_HEADER = '<script>alert(2)</script>Group';
    private const ATTACK_CSS_FILE = 'https://example.com/export.css" onload="alert(3)';

    private ?QUI\Rewrite $PreviousRewrite;
    private ?QUI\Template $PreviousTemplate;

    protected function setUp(): void
    {
        $this->PreviousRewrite = QUI::$Rewrite;
        $this->PreviousTemplate = QUI::$Template;

        // The export has no page or theme and must not resolve the installation's start page.
        $Project = $this->createMock(QUI\Projects\Project::class);
        $Project->method('getName')->willReturn('print-template-test');
        $Project->method('getAttribute')->willReturn(false);

        $Rewrite = $this->createMock(QUI\Rewrite::class);
        $Rewrite->method('getProject')->willReturn($Project);
        $Rewrite->method('getSite')->willReturn(null);

        QUI::$Rewrite = $Rewrite;
        QUI::$Template = new QUI\Template();
    }

    protected function tearDown(): void
    {
        QUI::$Rewrite = $this->PreviousRewrite;
        QUI::$Template = $this->PreviousTemplate;
    }

    public function testGridValuesAreRenderedAsText(): void
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $Engine->assign([
            'cssFile' => self::ATTACK_CSS_FILE,
            'header' => [self::ATTACK_HEADER],
            'data' => [[self::ATTACK_GROUP_NAME]]
        ]);

        $html = $Engine->fetch(
            dirname(__DIR__, 4) . '/src/QUI/Export/bin/exportPrint.html'
        );

        $Document = new DOMDocument('1.0', 'UTF-8');
        $Document->loadHTML($html);

        self::assertSame(0, $Document->getElementsByTagName('script')->length);
        self::assertSame(0, $Document->getElementsByTagName('img')->length);
        self::assertSame(
            self::ATTACK_HEADER,
            $Document->getElementsByTagName('th')->item(0)?->textContent
        );
        self::assertSame(
            self::ATTACK_GROUP_NAME,
            $Document->getElementsByTagName('td')->item(0)?->textContent
        );

        $StyleSheet = $Document->getElementsByTagName('link')->item(0);

        self::assertSame(self::ATTACK_CSS_FILE, $StyleSheet?->getAttribute('href'));
        self::assertFalse($StyleSheet?->hasAttribute('onload'));
    }
}
