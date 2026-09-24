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
$result = $Rewrite->showErrorHeader(305, 'https://target.example.test');
$errors = [];

foreach ($Handler->getRecords() as $Record) {
    if ($Record->level->value === 400) {
        $errors[] = [
            'message' => $Record->message,
            'httpCode' => $Record->context['httpCode'],
            'trace' => $Record->context['trace']
        ];
    }
}

fwrite(STDERR, json_encode([
    'result' => $result,
    'status' => http_response_code(),
    'rewriteStatus' => $Rewrite->getHeaderCode(),
    'events' => $events,
    'errors' => $errors
]));
