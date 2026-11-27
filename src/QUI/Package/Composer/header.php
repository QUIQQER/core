<?php

/**
 * executes the setup of the updated packages
 */

use QUI\System\Output\CliOutput;
use QUI\System\Output\VoidOutput;

define('QUIQQER_SYSTEM', true);
define('SYSTEM_INTERN', true);
define('ETC_DIR', dirname(__FILE__, 8) . '/etc/');

require_once dirname(__FILE__, 4) . '/autoload.php';
require_once dirname(__FILE__, 4) . '/header.php';

// postUpdateSetup.php publishes the complete collection after the package hooks.
if (method_exists(QUI\Translator::class, 'deferJavaScriptPublication')) {
    QUI\Translator::deferJavaScriptPublication();
}

QUI\Permissions\Permission::setUser(
    QUI::getUsers()->getSystemUser()
);

$Output = new VoidOutput();

if (php_sapi_name() === 'cli') {
    $Output = new CliOutput();
}
