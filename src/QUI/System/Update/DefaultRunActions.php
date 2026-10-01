<?php

namespace QUI\System\Update;

use QUI\System\Update\Action\PhaseTransitionAction;
use QUI\System\Update\Action\ComposerToolUpdateAction;
use QUI\System\Update\Action\SystemUpdateAction;

class DefaultRunActions
{
    /**
     * @return array<string, RunActionInterface>
     */
    public static function create(): array
    {
        return [
            RunState::PHASE_CREATED => new PhaseTransitionAction(RunState::PHASE_PREPARED),
            RunState::PHASE_PREPARED => new PhaseTransitionAction(RunState::PHASE_COMPOSER_UPDATE),
            RunState::PHASE_COMPOSER_UPDATE => new ComposerToolUpdateAction(),
            RunState::PHASE_RESTART_REQUIRED => new PhaseTransitionAction(RunState::PHASE_SYSTEM_UPDATE),
            RunState::PHASE_SYSTEM_UPDATE => new SystemUpdateAction(),
            RunState::PHASE_CLEANUP => new SystemUpdateAction(true)
        ];
    }
}
