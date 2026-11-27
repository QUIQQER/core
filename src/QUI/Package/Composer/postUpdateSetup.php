<?php

define('QUIQQER_UPDATE_IN_PROGRESS', true);

global $Output;
require "header.php";

use QUI\Setup;
use QUI\System\Log;

Setup::deferJavaScriptLocalePublication();

// quiqqer setup
$Output->writeLn('> Execute QUIQQER setup');

try {
    QUI::getPackage('quiqqer/core')->setup([
        'executePackagesSetup' => false
    ]);
} catch (QUI\Exception $exception) {
    Log::addError($exception->getMessage());
    $Output->writeLn($exception->getMessage(), 'red');
}

// clear cache
$Output->writeLn('> Execute package setups');

try {
    Setup::all($Output);
} catch (Throwable $exception) {
    Log::addError($exception->getMessage());
    $Output->writeLn($exception->getMessage(), 'red');
}

QUI\Cache\Manager::clear('quiqqer');
QUI\Cache\Manager::clearPackagesCache();
QUI\Cache\Manager::clearSettingsCache();
QUI\Cache\Manager::clearCompleteQuiqqerCache();
QUI\Cache\LongTermCache::clear('quiqqer');
