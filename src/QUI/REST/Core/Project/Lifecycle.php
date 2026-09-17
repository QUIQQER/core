<?php

namespace QUI\REST\Core\Project;

use QUI;
use QUI\Projects\Manager;
use QUI\REST\Core\ApiException;

final class Lifecycle
{
    public static function availableName(string $name): void
    {
        try {
            QUI\Utils\Project::validateProjectName($name);
        } catch (QUI\Exception) {
            throw new ApiException('invalid_input', 'Invalid project name.');
        }

        if (Manager::existsProject($name)) {
            throw new ApiException('conflict', 'The project name is already in use.', 409);
        }
    }

    public static function template(string $name): void
    {
        if ($name === '') {
            return;
        }

        foreach (QUI::getPackageManager()->searchInstalledPackages(['type' => 'quiqqer-template']) as $package) {
            if (($package['name'] ?? null) === $name) {
                return;
            }
        }

        throw new ApiException('invalid_input', 'The template is not installed.');
    }

    public static function permissions(string $project, ?string $newName): void
    {
        $Connection = QUI::getDataBaseConnection();

        foreach (['projects', 'sites', 'media'] as $area) {
            $table = QUI\Permissions\Manager::table() . '2' . $area;

            if (!QUI::getSchemaManager()->tablesExist([$table])) {
                continue;
            }

            if ($newName === null) {
                $Connection->delete($table, ['project' => $project]);
            } else {
                $Connection->update($table, ['project' => $newName], ['project' => $project]);
            }
        }

        Manager::cleanup();
        Manager::$Config = null;
        Manager::$Standard = null;
        unset(QUI::$Configs['etc/projects.ini'], QUI::$Configs['etc/projects.ini.php']);
    }
}
