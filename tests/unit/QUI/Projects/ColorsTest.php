<?php

declare(strict_types=1);

namespace QUITests\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Colors;

final class ColorsTest extends TestCase
{
    #[DataProvider('cssValues')]
    public function testCssValuesDoNotRequireAProject(mixed $value, string $expected): void
    {
        self::assertSame($expected, Colors::toCss($value));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function cssValues(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'missing' => [null, ''];
        yield 'unset config' => [false, ''];
        yield 'short hex' => ['#AbC', '#AbC'];
        yield 'short alpha' => ['#1234', '#1234'];
        yield 'hex' => ['#123456', '#123456'];
        yield 'alpha' => ['#abcdef80', '#abcdef80'];
        yield 'transparent' => ['transparent', 'transparent'];
        yield 'render keyword inherit' => ['inherit', 'inherit'];
        yield 'render keyword currentColor' => ['currentColor', 'currentColor'];
        yield 'pair' => ['{"type":"color","light":"#fff","dark":"#1118"}', 'light-dark(#fff, #1118)'];
        yield 'equal pair' => ['{"type":"color","light":"#fff","dark":"#fff"}', 'light-dark(#fff, #fff)'];
        yield 'reference' => ['{"type":"palette","id":"primary"}', 'var(--qui-project-color-primary)'];
        yield 'custom reference without lookup' => [
            '{"type":"palette","id":"custom-missing"}',
            'var(--qui-project-color-custom-missing)'
        ];
        yield 'package reference' => [
            '{"type":"palette","id":"template:quiqqer/template-presentation:hero-background"}',
            'var(--qui-project-color-template-quiqqer__template-presentation__hero-background)'
        ];
        yield 'incomplete pair' => ['{"type":"color","light":"#fff"}', ''];
        yield 'extra properties' => ['{"type":"palette","id":"primary","value":"#fff"}', ''];
        yield 'unknown role' => ['{"type":"palette","id":"unknown"}', ''];
        yield 'invalid reference' => ['{"type":"palette","id":"primary);color:red"}', ''];
        yield 'CSS injection' => ['#fff;display:none', ''];
        yield 'style injection' => ['</style><script>alert(1)</script>', ''];
        yield 'raw CSS variable' => ['var(--foreign)', ''];
        yield 'raw CSS function' => ['light-dark(#fff, #000)', ''];
        yield 'array instead of stored value' => [['type' => 'palette', 'id' => 'primary'], ''];
    }

    public function testPackageNamesWithHyphensDoNotCollide(): void
    {
        self::assertSame([
            '--qui-project-color-template-a-b__c__accent',
            '--qui-project-color-template-a__b-c__accent'
        ], [
            Colors::getCssVariableName('template:a-b/c:accent'),
            Colors::getCssVariableName('template:a/b-c:accent')
        ]);
    }

    public function testInvalidVariableNamesAreRejected(): void
    {
        $this->expectException(QUI\Exception::class);
        Colors::getCssVariableName('primary;display:none');
    }

    public function testRenderingKeywordsDoesNotExpandTheStorageFormat(): void
    {
        $this->expectException(QUI\Exception::class);
        Colors::parseValue('inherit');
    }

    public function testHeaderSnippetRendersDefinitionsAndOmitsEmptyPalettes(): void
    {
        require_once OPT_DIR . 'smarty/smarty/libs/bootstrap.php';
        $Engine = new \Smarty();
        $Engine->setCompileDir(VAR_DIR . 'cache/color-template-tests');
        $Engine->assign('projectColorVariables', [
            '--qui-project-color-primary' => 'light-dark(#1234, #abcdef80)'
        ]);

        $output = $Engine->fetch(LIB_DIR . 'templates/projectColors.html');
        self::assertStringContainsString('--qui-project-color-primary: light-dark(#1234, #abcdef80);', $output);
        self::assertSame(1, substr_count($output, '<style>'));

        $Engine->assign('projectColorVariables', []);
        self::assertSame('', trim($Engine->fetch(LIB_DIR . 'templates/projectColors.html')));
    }
}
