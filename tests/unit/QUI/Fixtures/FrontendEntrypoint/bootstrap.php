<?php

require_once __DIR__ . '/QUI.php';
require_once __DIR__ . '/ProjectManager.php';
require_once __DIR__ . '/Rewrite.php';
require_once __DIR__ . '/Debug.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/Log.php';

class_alias(QUITests\Fixtures\FrontendEntrypoint\QUI::class, 'QUI');

set_exception_handler(static function (Throwable $Exception): void {
    \QUI\Log\ErrorHandler::logUncaughtException($Exception);
    echo json_encode(['error' => true, 'message' => 'global handler']);
});
