<?php

// Keep the worker independent of the installation and PHPUnit's signal handlers.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'QUI\\System\\Update\\')) {
        require dirname(__DIR__, 6) . '/src/' . str_replace('\\', '/', $class) . '.php';
    }
});

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
