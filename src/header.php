<?php

use QUI\ExceptionStack;

require 'minimalHeader.php';

// Datenbankverbindung aufbauen
try {
    QUI::getDataBaseConnection()->getServerVersion();
} catch (Throwable $Exception) {
    if (php_sapi_name() === 'cli') {
        echo "\033[1;31m";

        echo 'Database Error: ';
        echo $Exception->getMessage();
        echo "\033[0m";
        echo PHP_EOL;
        exit;
    }

    header('HTTP/1.1 503 Service Temporarily Unavailable');
    header('Status: 503 Service Temporarily Unavailable');

    $Template = QUI::getTemplateManager()->getEngine();
    $file = LIB_DIR . 'templates/db_error.html';

    if (
        QUI::conf('db', 'error_html')
        && file_exists(QUI::conf('db', 'error_html'))
    ) {
        $file = QUI::conf('db', 'error_html');
    }

    try {
        echo $Template->fetch($file);
    } catch (\Exception $Exception) {
        echo $Template->fetch(LIB_DIR . 'templates/db_error.html');
    }

    QUI\System\Log::writeException($Exception);
    exit;
}


QUI::getSession()->start();

if ((int)QUI::conf('session', 'regenerate')) {
    QUI::getSession()->refresh();
}

$User = QUI::getUserBySession();

// Logout
if (isset($_GET['logout'])) {
    $User->logout();
    $User = QUI::getUsers()->getNobody();

    QUI::getSession()->destroy();

    if (
        isset($_SERVER['REQUEST_URI'])
        && str_contains($_SERVER['REQUEST_URI'], 'logout=1')
    ) {
        header('Location: ' . str_replace('logout=1', '', $_SERVER['REQUEST_URI']));
        exit;
    }
}

$memoryLimit = QUI\Utils\System::getMemoryLimit();
QUI\Utils\System::$memory_limit = $memoryLimit > 0 ? $memoryLimit : 0;

try {
    QUI::getEvents()->fireEvent('headerLoaded');
} catch (QUI\Exception $Exception) {
    if ($Exception instanceof ExceptionStack) {
        $list = $Exception->getExceptionList();

        if (count($list) === 1) {
            $Exception = $list[0];
        }
    }

    if (defined('QUIQQER_AJAX') && QUIQQER_AJAX) {
        $response = QUI::getAjax()->getHeaderExceptionResponse($Exception, isset($_REQUEST['quiqqerBundle']));

        if ($response !== null) {
            // AJAX authentication errors use the normal result protocol so request
            // callbacks can open login, including when the header rejected a bundle.
            http_response_code(200);
            header('Cache-Control: no-store');
            echo $response;
            exit;
        }
    }

    throw $Exception;
}

QUI\Utils\System\Debug::marker('header end');
