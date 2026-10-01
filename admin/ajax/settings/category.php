<?php

/**
 * Return a xml category
 *
 * @param array $file - list of xml files
 * @param $category
 * @param $windowName
 * @return String
 */

QUI::getAjax()->registerFunction(
    'ajax_settings_category',
    static function ($file, $category, $windowName) {
        if (file_exists($file)) {
            $files = [$file];
        } else {
            $files = json_decode($file, true);
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $k => $file) {
            if (file_exists(OPT_DIR . $file)) {
                $files[$k] = OPT_DIR . $file;
                continue;
            }

            if (file_exists(CMS_DIR . $file)) {
                $files[$k] = CMS_DIR . $file;
            }
        }


        $cacheName = 'quiqqer/package/quiqqer/core/menu/categories/global/'
            . md5((string)json_encode([$files, $windowName])) . '/' . $category;

        try {
            $result = QUI\Cache\Manager::get($cacheName);
        } catch (QUI\Exception) {
            $Settings = QUI\Utils\XML\Settings::getInstance();

            if (!empty($windowName) && $windowName !== 'qui-desktop-panel') {
                $Settings->setXMLPath('//quiqqer/settings/window[@name="' . $windowName . '"]');

                // if window name exists, load the packages with a settings.xml
                $packages = QUI::getPackageManager()->searchInstalledPackages([
                    'type' => 'quiqqer-module'
                ]);

                foreach ($packages as $package) {
                    if (isset($package['_settings'])) {
                        $settingXml = OPT_DIR . $package['name'] . '/settings.xml';

                        if (file_exists($settingXml)) {
                            $files[] = $settingXml;
                        }
                    }
                }

                $files = array_unique($files);
            } else {
                $Settings->setXMLPath('//quiqqer/settings/window');
            }

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
    ['file', 'category', 'windowName'],
    [
        'Permission::checkAdminUser',
        'quiqqer.settings'
    ]
);
