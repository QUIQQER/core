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

    if (!defined('CMS_DIR')) {
        define('CMS_DIR', $cmsDir . '/');
    }

    $composerPhar = $cmsDir . '/var/composer/composer.phar';
    $composerPackageBin = $packagesDir . '/composer/composer/bin/composer';

    $argv = array_values($_SERVER['argv']);

    // Prefer the PHAR so composer/composer can be updated without executing itself.
    $_SERVER['argv'] = array_merge([$composerPhar], $argv);

    $_SERVER['argv'][] = '--working-dir=' . $cmsDir . '/var/composer';

    require_once $packagesDir . '/autoload.php';

    try {
        $exitCode = QUI\System\MaintenanceLock::runWhenAvailable(static function () use ($composerPhar, $composerPackageBin): int {
            if (file_exists($composerPhar)) {
                $arguments = array_slice($_SERVER['argv'], 1);
                $commands = [
                    [PHP_BINARY, $composerPhar, 'self-update'],
                    [PHP_BINARY, $composerPhar, ...$arguments]
                ];

                foreach ($commands as $index => $command) {
                    $Process = new Symfony\Component\Process\Process(
                        $command,
                        input: $index === 0 ? null : STDIN,
                        timeout: null
                    );

                    if (stream_isatty(STDIN) && Symfony\Component\Process\Process::isTtySupported()) {
                        $Process->setTty(true);
                    }

                    $Process->disableOutput();
                    $status = $Process->run(static function (string $type, string $output): void {
                        fwrite($type === Symfony\Component\Process\Process::ERR ? STDERR : STDOUT, $output);
                    });

                    // A failed automatic self-update must not prevent the requested Composer command.
                    if ($status !== 0 && $index > 0) {
                        return $status;
                    }
                }

                return 0;
            }

            $_SERVER['argv'][0] = $composerPackageBin;

            if (file_exists($composerPackageBin)) {
                require $composerPackageBin;
                return 0;
            }

            throw new RuntimeException('Composer is not installed.');
        });
    } catch (Throwable $Exception) {
        fwrite(STDERR, $Exception->getMessage() . PHP_EOL);
        $exitCode = 1;
    }

    exit($exitCode);
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
