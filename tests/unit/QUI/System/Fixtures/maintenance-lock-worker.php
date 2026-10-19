<?php

define('CMS_DIR', $argv[2]);
define('VAR_DIR', $argv[3]);
define('OPT_DIR', CMS_DIR . 'packages/');
require dirname(__DIR__, 5) . '/src/autoload.php';

use QUI\System\MaintenanceBusyException;
use QUI\System\MaintenanceLock;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;
use Symfony\Component\Process\Process;

try {
    switch ($argv[1]) {
        case 'wait':
        case 'wait-hold':
            MaintenanceLock::runWhenAvailable(static function () use ($argv): void {
                if ($argv[1] === 'wait-hold') {
                    echo 'ready' . PHP_EOL;
                    fgets(STDIN);
                } else {
                    echo 'entered';
                }
            }, timeout: (float)$argv[4]);
            break;
        case 'legacy-runner':
            // An updater loaded before the upgrade owns only run.lock, without a maintenance token.
            $Repository = new RunRepository(VAR_DIR . 'update/runs/');
            $Run = $Repository->create();
            $State = $Run->getState();
            $lock = $Repository->acquireLock($State->getId());
            $CompetingRun = null;

            try {
                $State->transitionTo(RunState::PHASE_PREPARED);
                $State->transitionTo(RunState::PHASE_SYSTEM_UPDATE);
                $State->markRunning(time());
                $State->setProcess((int)getmypid(), '', time(), 'runner');

                if ($argv[4] === 'paused') {
                    $State->markRestartRequired(RunState::PHASE_CLEANUP);
                }

                if ($argv[4] === 'competing') {
                    $CompetingRun = $Repository->create();
                }

                $Repository->save($State);
                $Child = new Process(
                    [PHP_BINARY, __FILE__, 'probe-child', CMS_DIR, VAR_DIR],
                    timeout: 30
                );

                if ($argv[4] === 'invalid-token') {
                    $Child->setEnv(['QUIQQER_MAINTENANCE_TOKEN' => str_repeat('0', 64)]);
                }

                $Child->mustRun();
                echo $Child->getOutput() . PHP_EOL . 'ready' . PHP_EOL;
                fgets(STDIN);
            } finally {
                $Repository->releaseLock($lock);
                $Repository->delete($State->getId());

                if ($CompetingRun !== null) {
                    $Repository->delete($CompetingRun->getState()->getId());
                }
            }

            break;
        case 'probe-child':
            MaintenanceLock::run(static function (): void {
                $Child = new Process(
                    [PHP_BINARY, __FILE__, 'probe', CMS_DIR, VAR_DIR],
                    timeout: 30
                );
                $Child->mustRun();
                echo $Child->getOutput();
            });
            break;
        case 'hold':
            MaintenanceLock::run(static function (): void {
                echo 'ready' . PHP_EOL;

                if (trim((string)fgets(STDIN)) === 'child') {
                    $Child = new Process(
                        [PHP_BINARY, __FILE__, 'probe', CMS_DIR, VAR_DIR],
                        timeout: 30
                    );
                    $Child->mustRun();
                    echo $Child->getOutput();
                }
            });
            break;
        case 'setup':
            QUI\Setup::all();
            break;
        case 'security':
            (new QUI\System\Console\Tools\SecurityUpdate())->executeSecurityUpdate();
            break;
        case 'update':
            $Update = (new ReflectionClass(QUI\System\Console\Tools\Update::class))->newInstanceWithoutConstructor();
            $Update->executeSystemUpdate();
            break;
        case 'package-setup':
            (new ReflectionClass(QUI\Package\Package::class))->newInstanceWithoutConstructor()->setup();
            break;
        case 'package-install':
            QUI::getPackageManager()->install('test/package');
            break;
        case 'local-install':
            QUI::getPackageManager()->installLocalPackage('test/package');
            break;
        case 'composer-json':
            QUI::getPackageManager()->refreshServerList();
            break;
        default:
            MaintenanceLock::run(static function (): void {
                echo 'entered';
            });
    }
} catch (MaintenanceBusyException) {
    echo 'busy';
}
