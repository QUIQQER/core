<?php

QUI::getAjax()->registerFunction(
    'ajax_project_colors_get',
    static function ($project): array {
        $Project = QUI\Projects\Manager::getProject($project);
        $raw = QUI\Projects\Colors::snapshot($Project);

        try {
            $palette = QUI\Projects\Colors::parse($raw);
            $palette['damaged'] = false;
        } catch (QUI\Exception) {
            $palette = ['version' => 2, 'colors' => []];
            $palette['damaged'] = true;
        }

        $palette['groups'] = QUI\Projects\Colors::groups();
        $palette['defaults'] = QUI\Projects\Colors::defaults();
        $palette['defaultSource'] = QUI\Projects\Colors::defaultSource();

        if (isset($palette['source'])) {
            $provider = $palette['source']['provider'];
            $palette['sourceTitle'] = $provider;

            try {
                $palette['sourceTitle'] = QUI::getPackageManager()->getInstalledPackage($provider)->getTitle();
            } catch (QUI\Exception) {
                // Keep the package ID readable if its provider is no longer installed.
            }
        }

        return $palette;
    },
    ['project'],
    'Permission::checkAdminUser'
);
