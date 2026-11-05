<?php

/**
 * Set the config of an project
 *
 * @param string $project - project name
 * @param string $params - JSON Array
 */

QUI::getAjax()->registerFunction(
    'ajax_project_set_config',
    static function ($project, $params): void {
        $Project = QUI\Projects\Manager::getProject($project);
        $params = json_decode($params, true);

        $customCss = $params['project-custom-css'] ?? null;
        $customJavaScript = $params['project-custom-javascript'] ?? null;
        unset($params['project-custom-css'], $params['project-custom-javascript']);

        QUI\Projects\Manager::setConfigForProject($project, $params);

        if ($customCss !== null) {
            $Project->setCustomCSS($customCss);
        }

        if ($customJavaScript !== null) {
            $Project->setCustomJavaScript($customJavaScript);
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/core',
                'message.project.config.save.success'
            )
        );
    },
    ['project', 'params'],
    'Permission::checkAdminUser'
);
