<?php

namespace QUI\System\Update;

/**
 * Scope signal handling to the action that owns the update run lock.
 */
class RunSignalHandler
{
    /**
     * @var array<int, callable|int>
     */
    private array $previousHandlers = [];

    private bool $previousAsyncSignals = false;

    public function __construct(
        private readonly RunRepository $repository,
        private readonly string $id
    ) {
    }

    public function install(): void
    {
        if (
            PHP_SAPI !== 'cli'
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return;
        }

        $this->previousAsyncSignals = pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (int $receivedSignal): never {
                try {
                    $state = $this->repository->load($this->id);

                    if ($state->getStatus() === RunState::STATUS_RUNNING) {
                        $state->markCancelled('Update interrupted by signal ' . $receivedSignal . '.', time());
                        $this->repository->save($state);
                    }
                } finally {
                    exit(128 + $receivedSignal);
                }
            });
        }
    }

    public function restore(): void
    {
        if ($this->previousHandlers === []) {
            return;
        }

        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        $this->previousHandlers = [];
        pcntl_async_signals($this->previousAsyncSignals);
    }
}
