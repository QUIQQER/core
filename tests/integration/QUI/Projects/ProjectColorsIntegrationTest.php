<?php

declare(strict_types=1);

namespace QUI\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use QUI;
use QUI\Cache\Manager as CacheManager;

final class ProjectColorsIntegrationTest extends ProjectIntegrationTestCase
{
    protected function tearDown(): void
    {
        ProjectTestHelper::cleanup();
    }

    public function testSettingsAjaxKeepsItsEmptyResponseAndExposesTheSavedPaletteThroughGetConfig(): void
    {
        $Project = self::getTestProject();
        $palette = Colors::get($Project);
        $palette['colors']['primary'] = ['value' => '#123456'];

        require OPT_DIR . 'quiqqer/core/admin/ajax/project/set/config.php';
        require OPT_DIR . 'quiqqer/core/admin/ajax/project/get/config.php';

        $callables = QUI\Ajax::getRegisteredCallables();
        $response = ProjectTestHelper::runAsSystemUser(static function () use ($Project, $palette, $callables) {
            return $callables['ajax_project_set_config']['callable'](
                $Project->getName(),
                json_encode([Colors::CONFIG_KEY => self::request($palette)], JSON_THROW_ON_ERROR)
            );
        });

        self::assertNull($response);
        $config = $callables['ajax_project_get_config']['callable']($Project->getName(), '');
        self::assertSame($palette, Colors::parse($config[Colors::CONFIG_KEY]));
    }

    public function testSaveRoundTripsNamesVariantsAndSourceAndInvalidatesRenderedCaches(): void
    {
        $Project = self::getTestProject();
        $palette = Colors::get($Project);
        $palette['colors']['primary'] = ['light' => '#123456', 'dark' => '#abcdef80'];
        $palette['colors']['custom-name'] = ['value' => '#1234', 'name' => 'Akzent / <Text> & "Zitat" \\ Pfad'];
        $projectCache = $Project->getCachePath() . '/colors-test';
        $templateCache = 'quiqqer/template/colors-test';
        CacheManager::set($projectCache, 'old');
        CacheManager::set($templateCache, 'old');

        self::save($Project, $palette);
        self::assertSame($palette, self::reload($Project));
        self::assertSame('#abcdef80', Colors::resolve($Project, '{"type":"palette","id":"primary"}', 'dark'));
        $variables = Colors::getCssVariables($Project);
        self::assertSame('light-dark(#123456, #abcdef80)', $variables['--qui-project-color-primary']);
        self::assertSame('#1234', $variables['--qui-project-color-custom-name']);

        foreach ([$projectCache, $templateCache] as $cache) {
            try {
                CacheManager::get($cache);
                self::fail('Saving a palette must invalidate previously rendered colors.');
            } catch (QUI\Cache\Exception) {
                // A cache miss is the expected public cache API result.
            }
        }

        // Saving unrelated settings must not send the stored color JSON through HTML/path filters.
        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            Manager::setConfigForProject($Project->getName(), ['langs' => 'de']);
        });
        self::assertSame($palette, self::reload($Project));
    }

    public function testPermissionDenialLeavesTheSettingsFileUntouched(): void
    {
        $Project = self::getTestProject();
        $palette = Colors::get($Project);
        $palette['colors']['primary'] = ['value' => '#123456'];
        $before = file_get_contents(ETC_DIR . 'projects.ini.php');

        try {
            QUI\Permissions\Permission::withUser(QUI::getUsers()->getNobody(), static function () use ($Project, $palette): void {
                Manager::setConfigForProject($Project->getName(), [
                    Colors::CONFIG_KEY => self::request($palette)
                ]);
            });
            self::fail('An unauthorized user must not be allowed to save a valid palette.');
        } catch (QUI\Permissions\Exception $Exception) {
            self::assertSame(403, $Exception->getCode());
        }

        self::assertSame($before, file_get_contents(ETC_DIR . 'projects.ini.php'));
    }

    #[DataProvider('invalidPalettes')]
    public function testInvalidPaletteRejectsTheEntireSettingsSave(string $palette): void
    {
        $Project = self::getTestProject();
        $before = file_get_contents(ETC_DIR . 'projects.ini.php');

        try {
            ProjectTestHelper::runAsSystemUser(static function () use ($Project, $palette): void {
                Manager::setConfigForProject($Project->getName(), [
                    'langs' => 'en',
                    Colors::CONFIG_KEY => json_encode(['palette' => $palette], JSON_THROW_ON_ERROR)
                ]);
            });
            self::fail('Invalid colors must abort the save before any project setting is written.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(400, $Exception->getCode());
        }

        self::assertSame($before, file_get_contents(ETC_DIR . 'projects.ini.php'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPalettes(): iterable
    {
        yield 'CSS injection' => ['{"version":2,"colors":{"primary":{"value":"red;display:none"}}}'];
        yield 'incomplete dark variant' => ['{"version":2,"colors":{"primary":{"light":"#123456","dark":""}}}'];
        yield 'unauthorized provider change' => ['{"version":2,"colors":{},"source":{"provider":"vendor/template","palette":"nature"}}'];
    }

    public function testStoredReferencesSurviveRenamingAndRemovalFromThePalette(): void
    {
        $Project = self::getTestProject();
        $reference = '{"type":"palette","id":"custom-accent"}';
        $palette = Colors::get($Project);
        $palette['colors']['custom-accent'] = ['name' => 'Accent', 'light' => '#1234', 'dark' => '#abcdef80'];
        self::save($Project, $palette);

        $palette = self::reload($Project);
        $palette['colors']['custom-accent']['name'] = 'Renamed';
        self::save($Project, $palette);
        self::reload($Project);
        self::assertSame('#abcdef80', Colors::resolve($Project, $reference, 'dark'));

        unset($palette['colors']['custom-accent']);
        self::save($Project, $palette);
        $stored = self::reload($Project);
        self::assertTrue($stored['colors']['custom-accent']['archived']);
        self::assertSame('Renamed', $stored['colors']['custom-accent']['name']);
        self::assertSame('#1234', Colors::resolve($Project, $reference, 'light'));
        self::assertSame('#abcdef80', Colors::resolve($Project, $reference, 'dark'));
    }

    public function testArchivedCssDefinitionsKeepTheirStableNames(): void
    {
        $Project = self::getTestProject();
        $palette = Colors::get($Project);
        $id = 'template:vendor/example:accent';
        $palette['colors'][$id] = ['name' => 'Accent', 'light' => '#1234', 'dark' => '#abcdef80'];
        self::save($Project, $palette);
        $before = Colors::getCssVariables($Project);

        unset($palette['colors'][$id]);
        self::save($Project, $palette);
        self::assertTrue(self::reload($Project)['colors'][$id]['archived']);
        self::assertSame($before, Colors::getCssVariables($Project));
        self::assertSame(
            'light-dark(#1234, #abcdef80)',
            $before['--qui-project-color-template-vendor__example__accent']
        );
    }

    public function testEditorAndPreviewSettingsRefreshColorsWithoutCachingThem(): void
    {
        $Project = self::getTestProject();
        $palette = Colors::get($Project);

        foreach (['#123456', '#abcdef'] as $color) {
            // Update only storage: the warmed editor-settings cache deliberately remains intact.
            $palette['colors']['primary'] = ['value' => $color];
            Manager::getConfig()->setValue($Project->getName(), Colors::CONFIG_KEY, Colors::encode($palette));

            $fresh = QUI\Editor\Manager::getSettings($Project);
            self::assertSame($fresh, QUI\Editor\Manager::getSettings($Project));
            self::assertStringStartsWith('data:text/css;base64,', $fresh['cssFiles'][0]);
            $css = base64_decode(substr($fresh['cssFiles'][0], strlen('data:text/css;base64,')));
            self::assertStringContainsString('--qui-project-color-primary:' . $color . ';', $css);

            $cached = CacheManager::get($Project->getCachePath() . '/wysiwyg-settings');
            self::assertNotContains($fresh['cssFiles'][0], $cached['cssFiles']);
        }

        Manager::getConfig()->setValue($Project->getName(), Colors::CONFIG_KEY, 'broken');
        $settings = QUI\Editor\Manager::getSettings($Project);
        self::assertSame($cached['cssFiles'], $settings['cssFiles']);
    }

    public function testProjectCssDefinitionsRemainIsolated(): void
    {
        $Project = self::getTestProject();
        $OtherProject = $this->createMock(Project::class);
        $OtherProject->method('getName')->willReturn($Project->getName() . '-other');

        self::assertSame(Colors::defaults()['primary']['value'], Colors::getCssVariables($OtherProject)['--qui-project-color-primary']);
        $palette = Colors::get($Project);
        $palette['colors']['primary'] = ['value' => '#123456'];
        self::save($Project, $palette);

        self::assertSame('#123456', Colors::getCssVariables($Project)['--qui-project-color-primary']);
        self::assertSame(Colors::defaults()['primary']['value'], Colors::getCssVariables($OtherProject)['--qui-project-color-primary']);
    }

    public function testLaterSaveReplacesAnEarlierPaletteDraft(): void
    {
        $Project = self::getTestProject();
        $first = Colors::get($Project);
        $second = $first;
        $first['colors']['primary'] = ['value' => '#123456'];
        $second['colors']['secondary'] = ['value' => '#abcdef'];
        self::save($Project, $first);
        self::save($Project, $second);

        self::assertSame($second, self::reload($Project));
    }

    public function testDamagedStorageRequiresExplicitRepairAndRemainsUntouchedOnRead(): void
    {
        $Project = self::getTestProject();
        $Config = Manager::getConfig();
        $Config->setValue($Project->getName(), Colors::CONFIG_KEY, 'broken');
        $Config->save();
        $before = file_get_contents(ETC_DIR . 'projects.ini.php');
        self::assertSame('#abc', Colors::resolve($Project, '{"type":"palette","id":"primary"}', 'light', '#abc'));
        self::assertSame([], Colors::getCssVariables($Project));
        self::assertSame('#1234', Colors::toCss('#1234'));

        try {
            self::save($Project, Colors::parse(''));
            self::fail('A normal save must not silently replace damaged palette data.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(400, $Exception->getCode());
        }

        self::assertSame($before, file_get_contents(ETC_DIR . 'projects.ini.php'));
        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            Manager::setConfigForProject($Project->getName(), [Colors::CONFIG_KEY => json_encode([
                'palette' => '',
                'repair' => true
            ], JSON_THROW_ON_ERROR)]);
        });
        self::assertSame(Colors::parse(''), self::reload($Project));
    }

    /** @param array<string, mixed> $palette */
    private static function save(Project $Project, array $palette): void
    {
        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $palette): void {
            Manager::setConfigForProject($Project->getName(), [Colors::CONFIG_KEY => self::request($palette)]);
        });
    }

    /** @param array<string, mixed> $palette */
    private static function request(array $palette): string
    {
        return json_encode(['palette' => json_encode($palette, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function reload(Project $Project): array
    {
        Manager::getConfig()->reload();
        Manager::cleanup();
        return Colors::get(Manager::getProject($Project->getName()));
    }
}
