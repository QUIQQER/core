<?php

// Load the runner and its lock dependencies without PHPUnit's signal handlers.
require dirname(__DIR__, 8) . '/autoload.php';
define('CMS_DIR', getenv('QUIQQER_CORE_TEST_RUNTIME') ?: dirname(__DIR__, 9) . '/');
define('VAR_DIR', CMS_DIR . 'var/');

use QUI\System\Update\RunActionInterface;
use QUI\System\Update\RunActionResult;
use QUI\System\Update\RunExecutor;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;

$repository = new RunRepository($argv[1]);
$run = $repository->create(null, ['type' => 'cli']);
$action = new class ($argv[2] ?? '') implements RunActionInterface {
    public function __construct(private readonly string $mode)
    {
    }

    public function execute(RunState $state): RunActionResult
    {
        if ($this->mode === 'unhandled') {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        fwrite(STDOUT, $state->getId() . PHP_EOL);
        sleep(30);

        return RunActionResult::finished();
    }
};

(new RunExecutor($repository, [RunState::PHASE_CREATED => $action]))->execute(
    $run->getState()->getId(),
    $run->getToken()
);
