<?php

require __DIR__ . '/QUI.php';
require __DIR__ . '/License.php';
require __DIR__ . '/Events.php';

class_alias(\QUITests\Fixtures\Setup\QUI::class, 'QUI');

define('CMS_DIR', $argv[1] . '/');
define('OPT_DIR', $argv[2] . '/');
define('USR_DIR', $argv[3] . '/');
define('SYS_DIR', OPT_DIR . 'quiqqer/core/admin/');

require dirname(__DIR__, 5) . '/src/QUI/Setup.php';

\QUI\Setup::generateFileLinks();
\QUI\Setup::makeHeaderFiles();
