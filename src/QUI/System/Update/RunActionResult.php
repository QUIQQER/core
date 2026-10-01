<?php

namespace QUI\System\Update;

class RunActionResult
{
    private function __construct(
        private readonly ?string $nextPhase,
        private readonly bool $restartRequired,
        private readonly bool $finished
    ) {
    }

    public static function next(string $phase): self
    {
        return new self($phase, false, false);
    }

    public static function restartRequired(): self
    {
        return new self(null, true, false);
    }

    public static function restartAt(string $phase): self
    {
        return new self($phase, true, false);
    }

    public static function finished(): self
    {
        return new self(null, false, true);
    }

    public function getNextPhase(): ?string
    {
        return $this->nextPhase;
    }

    public function isRestartRequired(): bool
    {
        return $this->restartRequired;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }
}
