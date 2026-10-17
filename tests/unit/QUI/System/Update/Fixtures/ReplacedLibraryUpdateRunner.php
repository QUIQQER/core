<?php

// This worker uses the real runner and lock without touching a QUIQQER database.
require dirname(__DIR__, 8) . '/autoload.php';
define('CMS_DIR', getenv('QUIQQER_CORE_TEST_RUNTIME') ?: dirname(__DIR__, 9) . '/');
define('VAR_DIR', CMS_DIR . 'var/');

use QUI\System\Update\Action\PhaseTransitionAction;
use QUI\System\Update\RunActionInterface;
use QUI\System\Update\RunActionResult;
use QUI\System\Update\RunEntrypoint;
use QUI\System\Update\RunState;

$directory = dirname($argv[0]);
require $directory . '/library.php';

$replaceLibrary = new class ($directory) implements RunActionInterface {
    public function __construct(private readonly string $directory)
    {
    }

    public function execute(RunState $state): RunActionResult
    {
        file_put_contents($this->directory . '/events.jsonl', json_encode(['update', getmypid()]) . PHP_EOL, FILE_APPEND);
        file_put_contents(
            $this->directory . '/library.php',
            '<?php namespace UpdateRunnerLibrary; function existing() {} function addedByUpdate() { return true; }'
        );

        return RunActionResult::restartAt(RunState::PHASE_CLEANUP);
    }
};

$completeUpdate = new class ($directory) implements RunActionInterface {
    public function __construct(private readonly string $directory)
    {
    }

    public function execute(RunState $state): RunActionResult
    {
        // Like MongoDB's new helper, this function is unavailable in the process that replaced the file.
        \UpdateRunnerLibrary\addedByUpdate();
        file_put_contents($this->directory . '/events.jsonl', json_encode(['updateEnd', getmypid()]) . PHP_EOL, FILE_APPEND);

        if ($state->getMetadata()['failCompletion'] ?? false) {
            throw new RuntimeException('completion listener failed');
        }

        return RunActionResult::finished();
    }
};

$actions = [
    RunState::PHASE_CREATED => new PhaseTransitionAction(RunState::PHASE_PREPARED),
    RunState::PHASE_PREPARED => new PhaseTransitionAction(RunState::PHASE_SYSTEM_UPDATE),
    RunState::PHASE_SYSTEM_UPDATE => $replaceLibrary,
    RunState::PHASE_CLEANUP => $completeUpdate
];

exit((new RunEntrypoint())->execute(basename($directory), dirname($directory), $actions));
