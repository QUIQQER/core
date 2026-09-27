<?php

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use QUI\Log\Logger;
use QUI\Rewrite;

define('QUIQQER_SYSTEM', true);
define('QUIQQER_AJAX', true);
require dirname(__DIR__, 3) . '/Support/DatabaseEnvironment.php';

if (\QUITests\Support\DatabaseEnvironment::usesCiDatabase()) {
    require dirname(__DIR__, 7) . '/bootstrap.php';
} else {
    require dirname(__DIR__, 3) . '/runtime-bootstrap.php';
}

$Handler = new TestHandler();
Logger::$Logger = new MonologLogger('rewrite-response-test', [$Handler]);
QUI\Log\Config::getPackageConfig()->setValue('log_levels', 'error', 1);

$events = [];
QUI::$Events = new QUI\Events\Manager();

foreach (['onErrorHeaderShowBefore', 'onErrorHeaderShowAfter'] as $event) {
    QUI::getEvents()->addEvent($event, static function (int $code) use (&$events): void {
        $events[] = $code;
    });
}

$Rewrite = new Rewrite();
$continued = false;

register_shutdown_function(static function () use ($Handler, $Rewrite, &$events, &$continued): void {
    $errors = [];

    foreach ($Handler->getRecords() as $Record) {
        if ($Record->level->value === 400) {
            $errors[] = [
                'message' => $Record->message,
                'httpCode' => $Record->context['httpCode'] ?? null,
                'trace' => $Record->context['trace'] ?? []
            ];
        }
    }

    fwrite(STDERR, json_encode([
        'continued' => $continued,
        'status' => http_response_code(),
        'rewriteStatus' => $Rewrite->getHeaderCode(),
        'events' => $events,
        'errors' => $errors
    ]));
});

$Rewrite->showErrorHeader((int)($argv[1] ?? 305), $argv[2] ?? 'https://target.example.test');
$continued = true;

// Simulate the frontend rendering and sending its page after rewrite.
QUI::getGlobalResponse()->setContent('UNEXPECTED_SECOND_RESPONSE');
QUI::getGlobalResponse()->send();
