<?php

/**
 * QUIQQER Frontend Ajax API
 */

define('QUIQQER_AJAX', true);
define('QUIQQER_FRONTEND', true);

header("Content-Type: text/plain");
header('X-Robots-Tag: noindex');
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header('Expires: ' . gmdate('D, d M Y H:i:s', time() - 60) . ' GMT');

require 'bootstrap.php';
require 'src/ajax.php';
