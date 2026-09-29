<?php

// no console
if (php_sapi_name() !== 'cli') {
    exit;
}

// read params
$isComposerMode = false;
$isRepairMode = false;
$isPathMigration = false;

$scriptName = $_SERVER['argv'][0] ?? '';
$commandArguments = array_values(array_filter(
    $_SERVER['argv'],
    static fn (string $argument): bool => explode('=', $argument, 2)[0] !== '--no-header'
));
$command = $commandArguments[1] ?? '';

$validScripts = ['quiqqer.php', './console'];

if (!in_array($scriptName, $validScripts, true)) {
    if (basename($scriptName) === 'console') {
        $scriptName = './console';

        if (defined('CMS_DIR')) {
            chdir(CMS_DIR);
        }
    }
}

if (in_array($scriptName, $validScripts, true)) {
    switch ($command) {
        case 'repair':
            $isRepairMode = true;
            break;
        case 'system-migration':
            $isPathMigration = true;
            break;
        case 'composer':
            $isComposerMode = true;
            break;
    }
} else {
    echo 'Error: Please run "./console" only from the main directory of your QUIQQER installation.' . PHP_EOL;
    exit(1);
}

// execute params

// Bootstrap commands bypass Console and must not receive its global header flag.
if ($isPathMigration || $isRepairMode || $isComposerMode) {
    $_SERVER['argv'] = $commandArguments;
}

if ($isPathMigration) {
    require 'src/pathMigration.php';
    exit;
}

if ($isRepairMode) {
    unset($_SERVER['argv'][0]);
    unset($_SERVER['argv'][1]);

    require 'src/repair.php';
    exit;
}

if ($isComposerMode) {
    unset($_SERVER['argv'][0]);
    unset($_SERVER['argv'][1]);

    $packagesDir = dirname(__FILE__, 3);
    $cmsDir = dirname($packagesDir);
    $composerPhar = $cmsDir . '/var/composer/composer.phar';
    $composerPackageBin = $packagesDir . '/composer/composer/bin/composer';

    $argv = array_values($_SERVER['argv']);

    // Prefer the PHAR so composer/composer can be updated without executing itself.
    $_SERVER['argv'] = array_merge([$composerPhar], $argv);

    $_SERVER['argv'][] = '--working-dir=' . $cmsDir . '/var/composer';

    if (file_exists($composerPhar)) {
        array_shift($_SERVER['argv']);
        $argString = implode(' ', array_map('escapeshellarg', $_SERVER['argv']));

        system(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhar) . ' self-update');
        system(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhar) . ' ' . $argString);
        exit;
    }

    $_SERVER['argv'][0] = $composerPackageBin;

    if (file_exists($composerPackageBin)) {
        require $composerPackageBin;
        exit;
    }

    exit;
}


/**
 * This file contains the quiqqer access for the api, cron and console
 */
if (!defined('CMS_DIR')) {
    exit;
}

define('QUIQQER_SYSTEM', true);
require dirname(__FILE__, 3) . '/header.php';

$conf = ETC_DIR . 'conf.ini.php';

if (!file_exists($conf)) {
    exit(1);
}

// Console aufbauen
define('QUIQQER_CONSOLE', true);


if (!empty($_SERVER['argv']) && $_SERVER['argv'][0] === 'quiqqer.php') {
    /* @deprecated for quiqqer v2.0 */
    echo 'The direct call of quiqqer.php is deprecated.';
    echo PHP_EOL;
    echo 'At the latest in quiqqer v2.0 quiqqer.php will no longer exist.';
    echo PHP_EOL;
    echo 'Please use ./console';
    echo PHP_EOL;
    echo PHP_EOL;
}

$Console = new \QUI\System\Console();
$Console->start();
