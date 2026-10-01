<?php

/**
 * Return the project panel categories / tabs
 *
 * @param string $project - name of the project
 * @return array
 */

QUI::getAjax()->registerFunction(
    'ajax_project_panel_categories_category',
    static function ($file, $category, $project = '') {
        if (!is_string($file)) {
            throw new QUI\Exception('Invalid project settings XML file.', 400);
        }

        if (file_exists($file)) {
            $files = [$file];
        } else {
            $files = \json_decode($file, true);
        }

        if (!is_array($files)) {
            throw new QUI\Exception('Invalid project settings XML file.', 400);
        }

        $allowedFiles = [];
        $projects = [];

        if (is_string($project) && $project !== '') {
            $projects[] = QUI::getProjectManager()->decode($project);
        } else {
            $projects = QUI::getProjectManager()->getProjects(true);
        }

        foreach ($projects as $Project) {
            foreach (QUI::getProjectManager()->getRelatedSettingsXML($Project) as $settingsFile) {
                $settingsFile = realpath($settingsFile);

                if ($settingsFile !== false) {
                    $allowedFiles[$settingsFile] = true;
                }
            }
        }

        foreach ($files as $key => $settingsFile) {
            if (!is_string($settingsFile)) {
                throw new QUI\Exception('Invalid project settings XML file.', 400);
            }

            $settingsFile = realpath($settingsFile);

            if ($settingsFile === false || !isset($allowedFiles[$settingsFile])) {
                throw new QUI\Exception('Invalid project settings XML file.', 400);
            }

            $files[$key] = $settingsFile;
        }

        $cacheName = 'quiqqer/package/quiqqer/core/menu/categories/project/'
            . md5((string)json_encode($files)) . '/' . $category;
        $Settings = QUI\Utils\XML\Settings::getInstance();
        $Settings->setXMLPath('//quiqqer/project/settings/window');

        try {
            $result = QUI\Cache\Manager::get($cacheName);
        } catch (QUI\Exception $Exception) {
            try {
                $result = $Settings->getCategoriesHtml($files, $category);
                QUI\Cache\Manager::set($cacheName, $result);
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
                throw $Exception;
            }
        }

        return $result;
    },
    ['file', 'category', 'project'],
    'Permission::checkAdminUser'
);
