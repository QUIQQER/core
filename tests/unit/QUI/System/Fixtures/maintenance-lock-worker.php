<?php

define('CMS_DIR', $argv[2]);
define('VAR_DIR', $argv[3]);
define('OPT_DIR', CMS_DIR . 'packages/');
require dirname(__DIR__, 5) . '/src/autoload.php';

use QUI\System\MaintenanceBusyException;
use QUI\System\MaintenanceLock;

try {
    switch ($argv[1]) {
        case 'hold':
            MaintenanceLock::run(static function (): void {
                echo 'ready' . PHP_EOL;
                fgets(STDIN);
            });
            break;
        case 'setup':
            QUI\Setup::all();
            break;
        case 'security':
            (new QUI\System\Console\Tools\SecurityUpdate())->executeSecurityUpdate();
            break;
        case 'update':
            $Update = (new ReflectionClass(QUI\System\Console\Tools\Update::class))->newInstanceWithoutConstructor();
            $Update->executeSystemUpdate();
            break;
        case 'package-setup':
            (new ReflectionClass(QUI\Package\Package::class))->newInstanceWithoutConstructor()->setup();
            break;
        case 'package-install':
            QUI::getPackageManager()->install('test/package');
            break;
        case 'local-install':
            QUI::getPackageManager()->installLocalPackage('test/package');
            break;
        case 'composer-json':
            QUI::getPackageManager()->refreshServerList();
            break;
        default:
            MaintenanceLock::run(static function (): void {
                echo 'entered';
            });
    }
} catch (MaintenanceBusyException) {
    echo 'busy';
}
