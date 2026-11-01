<?php

QUI::getAjax()->registerFunction(
    'ajax_system_systemInfo',
    static function (): array {
        $connection = QUI::getDataBaseConnection();
        $dbVersion = $connection->getServerVersion();
        $dbType = $connection->getParams()['driver'] ?? null;
        $package = QUI::getPackage('quiqqer/core');

        return [
            'version' => QUI::getPackageManager()->getVersion(),
            'hash' => QUI::getPackageManager()->getHash(),
            'lock' => $package->getLock(),
            'php_version' => phpversion(),
            'php_update_warning' => QUI\System\PhpVersionSupport::getUpdateWarning(),
            'database' => [
                'type' => $dbType,
                'version' => $dbVersion
            ]
        ];
    },
    false,
    [
        'Permission::checkAdminUser',
        'quiqqer.system.update'
    ]
);
