<?php

declare(strict_types=1);

namespace QUITests\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Projects\LoaderSettings;
use QUI\Projects\Project;

final class LoaderSettingsTest extends TestCase
{
    public function testBothFrontendHeadersCompileWithLoaderConfiguration(): void
    {
        require_once OPT_DIR . 'smarty/smarty/libs/bootstrap.php';
        $Engine = new \Smarty();
        $Engine->setCompileDir(VAR_DIR . 'cache/loader-template-tests');

        foreach (['locale', 'url'] as $plugin) {
            $Engine->registerPlugin('function', $plugin, static fn(): string => '');
        }

        foreach (['md5', 'json_encode', 'defined', 'is_string'] as $modifier) {
            $Engine->registerPlugin('modifier', $modifier, $modifier);
        }

        foreach (['header.html', 'headerNoConflict.html'] as $file) {
            $Template = $Engine->createTemplate(LIB_DIR . 'templates/' . $file);
            $Template->loadCompiled();
            $Template->compileTemplateSource();
            self::assertStringContainsString(
                'window.whenQuiLoaded',
                file_get_contents($Template->compiled->filepath)
            );
        }
    }

    public function testHeaderIncludeRendersOnlyConfiguredColors(): void
    {
        require_once OPT_DIR . 'smarty/smarty/libs/bootstrap.php';
        $Engine = new \Smarty();
        $Engine->setCompileDir(VAR_DIR . 'cache/loader-template-tests');
        $Engine->assign('loaderSettings', [
            'type' => 'line-scale',
            'color' => '#123456',
            'background' => 'rgba(0, 0, 0, 0.7)'
        ]);

        $output = $Engine->fetch('string:{include file="`$smarty.const.LIB_DIR`templates/loaderSettings.html"}');
        self::assertStringContainsString('--_q-loaderConf-color: #123456;', $output);
        self::assertStringContainsString('--_q-loaderConf-background: rgba(0, 0, 0, 0.7);', $output);

        $Engine->assign('loaderSettings', ['type' => '', 'color' => '', 'background' => '']);
        self::assertSame('', trim($Engine->fetch(LIB_DIR . 'templates/loaderSettings.html')));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array{type: string, color: string, background: string} $expected
     */
    #[DataProvider('settingsProvider')]
    public function testFrontendConfiguration(array $settings, array $expected): void
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getConfig')->willReturnCallback(
            static fn(string $name): mixed => $settings[$name] ?? false
        );

        self::assertSame($expected, LoaderSettings::fromProject($Project));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{type: string, color: string, background: string}}>
     */
    public static function settingsProvider(): iterable
    {
        $automatic = ['type' => '', 'color' => '', 'background' => ''];

        yield 'existing project without settings' => [[], $automatic];
        yield 'cleared settings restore automatic colors' => [[
            'quiqqer.frontend.loader.type' => '',
            'quiqqer.frontend.loader.color' => '',
            'quiqqer.frontend.loader.background' => ''
        ], $automatic];
        yield 'configured color and translucent background' => [[
            'quiqqer.frontend.loader.type' => 'line-scale',
            'quiqqer.frontend.loader.color' => '#12ABef',
            'quiqqer.frontend.loader.background' => '#123456'
        ], ['type' => 'line-scale', 'color' => '#12ABef', 'background' => 'rgba(18, 52, 86, 0.7)']];
        yield 'animation alone preserves automatic background' => [[
            'quiqqer.frontend.loader.color' => '#000000'
        ], ['type' => '', 'color' => '#000000', 'background' => '']];
        yield 'new type does not require a duplicated PHP registry' => [[
            'quiqqer.frontend.loader.type' => 'future-loader'
        ], ['type' => 'future-loader', 'color' => '', 'background' => '']];
        yield 'script and style injection are discarded' => [[
            'quiqqer.frontend.loader.type' => '</script><script>alert(1)</script>',
            'quiqqer.frontend.loader.color' => '</style><script>alert(1)</script>',
            'quiqqer.frontend.loader.background' => 'red; background: url(https://example.org)'
        ], $automatic];
        yield 'malformed persisted values are ignored' => [[
            'quiqqer.frontend.loader.type' => ['standard'],
            'quiqqer.frontend.loader.color' => 123,
            'quiqqer.frontend.loader.background' => '#ffffff00'
        ], $automatic];
    }
}
