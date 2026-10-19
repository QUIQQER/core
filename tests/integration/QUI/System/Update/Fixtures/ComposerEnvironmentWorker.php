<?php

namespace QUI\System\Update\Fixtures;

use QUI\System\Console\Tools\Update;
use QUI\System\Update\Action\SystemUpdateAction;
use QUI\System\Update\RunState;
use RuntimeException;
use Symfony\Component\Process\Process;

require $argv[1];

$composer = $argv[2];
$directory = $argv[3];
define('VAR_DIR', $directory . '/');

// Ensure Symfony's $_SERVER filtering applies as it does in web requests.
$_SERVER['PATH'] = (string)getenv('PATH');

if ($argv[4] === 'prepare') {
    $Action = new class extends SystemUpdateAction {
        protected function createUpdate(): Update
        {
            // Stop after the real preflight, before any installation update routines.
            throw new ComposerEnvironmentPrepared();
        }
    };
    $state = RunState::create(str_repeat('a', 32), hash('sha256', 'token'), time(), 600);

    try {
        $Action->execute($state);
    } catch (ComposerEnvironmentPrepared) {
        // Exercise real Composer commands below with the prepared environment.
    }
} else {
    // The Composer runner used to set only the process environment.
    putenv('COMPOSER_HOME=' . $directory . '/composer/');
}

class ComposerEnvironmentPrepared extends RuntimeException
{
}

foreach (['status', 'update'] as $command) {
    $Process = new Process([
        PHP_BINARY,
        $composer,
        $command,
        '--no-plugins',
        '--no-scripts',
        '--no-interaction',
        '--no-ansi'
    ], $directory, [
        'COMPOSER' => 'composer.json',
        'COMPOSER_DISABLE_NETWORK' => '1'
    ]);
    $Process->run();
    echo $Process->getOutput();
    echo $Process->getErrorOutput();

    if (!$Process->isSuccessful()) {
        exit($Process->getExitCode() ?? 1);
    }
}
