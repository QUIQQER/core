<?php

/**
 * This file contains QUI\System\Console\Tools\SecurityUpdate
 */

namespace QUI\System\Console\Tools;

use Exception;
use QUI;
use RuntimeException;

use function date;
use function explode;
use function implode;
use function is_file;
use function rtrim;
use function str_replace;
use function trim;

use const PHP_EOL;
use const VAR_DIR;

/**
 * Update command for the console
 */
class SecurityUpdate extends QUI\System\Console\Tool
{
    protected bool $dryRun;

    /**
     * constructor
     */
    public function __construct()
    {
        $this->systemTool = true;

        $this->setName('quiqqer:security-update')
            ->setDescription('Update the quiqqer system and the quiqqer packages only with security Updates')
            ->addArgument('--mail', 'Which should receive the update log (--mail=info@quiqqer.com)', 'm', true);
    }

    /**
     * (non-PHPdoc)
     *
     * @throws QUI\Exception
     * @throws Exception
     * @see \QUI\System\Console\Tool::execute()
     */
    public function execute(): void
    {
        Cleanup::clearComposer();

        $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update'));
        $this->writeLn('========================');
        $this->writeLn();

        $Packages = QUI::getPackageManager();
        $this->dryRun = true;
        $dryRunOutput = '';

        $Composer = $Packages->getComposer();
        $Composer->unmute();

        // output events

        // start update routines
        $CLIOutput = new QUI\System\Console\Output();
        $CLIOutput->Events->addEvent('onWrite', function ($message) use (&$dryRunOutput): void {
            if ($this->dryRun) {
                $dryRunOutput .= $message . PHP_EOL;
                return;
            }

            Update::onCliOutput($message, $this);
        });

        $Composer->setOutput($CLIOutput);
        $Packages->refreshServerList();

        $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update.start'));

        $workingDir = rtrim($Composer->getWorkingDir(), '/') . '/';

        try {
            if (!is_file($workingDir . 'composer.json')) {
                throw new RuntimeException("Couldn't find the composer.json file.");
            }

            if (!is_file($workingDir . 'composer.lock')) {
                throw new RuntimeException('Patch updates require an existing composer.lock file.');
            }

            $help = $Composer->executeComposer('update', ['--help' => true]);

            if (!str_contains(implode(PHP_EOL, $help), '--patch-only')) {
                throw new RuntimeException('Patch updates require Composer 2.8 or newer (--patch-only).');
            }

            $dryRunOutput = '';
            $Composer->update([
                '--dry-run' => true,
                '--patch-only' => true
            ]);

            $dryRunOutput = explode(PHP_EOL, $dryRunOutput);

            $isUpdateAvailable = false;

            foreach ($dryRunOutput as $line) {
                if (!str_contains($line, 'Lock file operations:')) {
                    continue;
                }

                $line = str_replace('Lock file operations:', '', $line);
                $lines = explode(',', $line);

                foreach ($lines as $l) {
                    // line formed like: "0 updates"
                    if (str_starts_with(trim($l), '0')) {
                        continue;
                    }

                    // line does not start with "0", therefore something should be installed, updated or removed
                    $isUpdateAvailable = true;
                    break;
                }

                break;
            }

            if (!$isUpdateAvailable) {
                $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update.no.updates.found'));
                return;
            }

            // Apply the same patch restriction used by the dry run.
            $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update.updates.found'));
            $this->writeLn();
            $this->writeLn();
            $this->dryRun = false;

            // if update exist, activate maintenance
            $this->setMaintenance(true);
            $maintenanceEnabled = true;

            $Composer->update(['--patch-only' => true]);

            $wasExecuted = QUI::getLocale()->get('quiqqer/core', 'update.message.execute');
            $webserver = QUI::getLocale()->get('quiqqer/core', 'update.message.webserver');

            $this->writeLn($wasExecuted);
            $this->writeLn($webserver);

            $Htaccess = new Htaccess();
            $Htaccess->execute();

            $NGINX = new Nginx();
            $NGINX->execute();

            $Frankenphp = new Frankenphp();
            $Frankenphp->execute();

            // setup set the last update date
            QUI::getPackageManager()->setLastUpdateDate();

            QUI\Cache\Manager::clearCompleteQuiqqerCache();
            QUI\Cache\Manager::longTimeCacheClearCompleteQuiqqer();
        } catch (Exception $Exception) {
            $this->write(' [error]', 'red');
            $this->writeLn();
            $this->writeLn(
                QUI::getLocale()->get('quiqqer/core', 'update.message.error.1') . '::' . $Exception->getMessage(),
                'red'
            );

            $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'update.message.error'), 'red');
            $this->writeLn();
            $this->writeLn('./console repair', 'red');
            $this->resetColor();
            $this->writeLn();
        } finally {
            if (isset($maintenanceEnabled)) {
                $this->setMaintenance(false);
            }
        }

        // mail
        $mail = $this->getArgument('mail');

        if ($this->getArgument('m')) {
            $mail = $this->getArgument('m');
        }

        if (!empty($mail)) {
            try {
                $logFile = VAR_DIR . 'log/update-' . date('Y-m-d') . '.log';

                $Mailer = QUI::getMailManager()->getMailer();
                $Mailer->addAttachment($logFile);

                $Mailer->setSubject(
                    QUI::getLocale()->get('quiqqer/core', 'security.update.console.mail.subject', [
                        'host' => HOST
                    ])
                );

                $Mailer->setBody(
                    QUI::getLocale()->get('quiqqer/core', 'security.update.console.mail.body', [
                        'host' => HOST
                    ])
                );

                $Mailer->send();
            } catch (\PHPMailer\PHPMailer\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }
    }

    protected function setMaintenance(bool $enabled): void
    {
        $Maintenance = new Maintenance();
        $Maintenance->setArgument('status', $enabled ? 'on' : 'off');
        $Maintenance->execute();
    }
}
