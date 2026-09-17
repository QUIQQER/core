<?php

declare(strict_types=1);

namespace QUI\Projects;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use QUI;
use QUI\Ajax;
use QUI\Exception;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProjectSettingsCategoryEndpointSecurityTest extends ProjectIntegrationTestCase
{
    public function testOnlyRendersXmlFromProjectSettingsAllowlist(): void
    {
        $Project = self::getTestProject();

        QUI::$Ajax = new Ajax();
        $coreDirectory = OPT_DIR . 'quiqqer/core';
        $settingsFixture = $coreDirectory . '/tests/integration/QUI/Projects/Fixtures/project-settings.xml';
        $projectSettingsXml = USR_DIR . $Project->getName() . '/settings.xml';
        $projectData = json_encode($Project->toArray(), JSON_THROW_ON_ERROR);

        self::assertTrue(copy($settingsFixture, $projectSettingsXml));
        QUI\Cache\Manager::clear($Project->getCachePath() . '/relatedSettingsXml');

        require $coreDirectory . '/admin/ajax/project/panel/categories/category.php';

        $callable = Ajax::getRegisteredCallables()['ajax_project_panel_categories_category']['callable'];
        $unrelatedXml = $settingsFixture;

        self::assertNotSame(
            '',
            $callable($projectSettingsXml, 'templateQUI', $projectData)
        );
        self::assertFileExists($unrelatedXml);

        $Settings = QUI\Utils\XML\Settings::getInstance();
        $Settings->setXMLPath('//quiqqer/project/settings/window');

        self::assertNotSame(
            '',
            $Settings->getCategoriesHtml([$unrelatedXml], 'templateQUI')
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid project settings XML file.');

        $callable(
            $unrelatedXml,
            'templateQUI',
            $projectData
        );
    }
}
