<?php

/**
 * This file contains QUI\System\Console\Tools\SecurityUpdate
 */

namespace QUI\System\Console\Tools;

use QUI;
use QUI\Composer\Composer;
use RuntimeException;
use Throwable;

use const PHP_EOL;
use const VAR_DIR;

/**
 * Update command for the console
 */
class SecurityUpdate extends QUI\System\Console\Tool
{
    public function __construct()
    {
        $this->systemTool = true;

        $this->setName('quiqqer:security-update')
            ->setDescription('Update the quiqqer system and the quiqqer packages only with security Updates')
            ->addArgument('--mail', 'Which should receive the update log (--mail=info@quiqqer.com)', 'm', true);
    }

    public function execute(): void
    {
        if (!$this->executeSecurityUpdate()) {
            exit(1);
        }
    }

    /**
     * Run the update without terminating the caller; execute() exposes failure to cron.
     */
    public function executeSecurityUpdate(): bool
    {
        $phase = 'preflight';
        $maintenanceEnabled = false;

        try {
            $this->writeUpdateLog($phase, 'Starting automatic patch update');
            Cleanup::clearComposer();
            $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update'));

            $Packages = QUI::getPackageManager();
            $Composer = $Packages->getComposer();
            $workingDir = rtrim($Composer->getWorkingDir(), '/') . '/';

            if (!is_file($workingDir . 'composer.json')) {
                throw new RuntimeException("Couldn't find the composer.json file.");
            }

            if (!is_file($workingDir . 'composer.lock')) {
                throw new RuntimeException('Patch updates require an existing composer.lock file.');
            }

            $Packages->refreshServerList();
            $help = $this->runComposer($Composer, ['--help' => true], $phase);

            if (!str_contains(implode(PHP_EOL, $help), '--patch-only')) {
                throw new RuntimeException('Patch updates require Composer 2.8 or newer (--patch-only).');
            }

            $phase = 'dry-run';
            $this->writeUpdateLog($phase, 'Checking patch updates before installation');
            $preview = $this->runComposer($Composer, ['--dry-run' => true, '--patch-only' => true], $phase);

            if (!$this->hasUpdates($preview)) {
                $this->writeUpdateLog($phase, 'Preflight succeeded; no updates available');
                $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update.no.updates.found'));
                return true;
            }

            $this->writeUpdateLog($phase, 'Preflight succeeded; updates available');
            $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'security.update.updates.found'));

            $phase = 'maintenance';
            $maintenanceWasEnabled = $this->isMaintenanceEnabled();
            if (!$maintenanceWasEnabled) {
                $this->setMaintenance(true);
            }
            $maintenanceEnabled = true;

            $phase = 'installation';
            $this->writeUpdateLog($phase, 'Installing patch updates');
            $this->runComposer($Composer, ['--patch-only' => true], $phase);

            $phase = 'completion';
            $this->completeUpdate();

            if (!$maintenanceWasEnabled) {
                $this->setMaintenance(false);
            }

            $this->writeUpdateLog($phase, 'Automatic patch update completed successfully');
            $this->writeLn(QUI::getLocale()->get('quiqqer/core', 'update.message.execute'));
        } catch (Throwable $Exception) {
            $message = $Exception::class . ': ' . $Exception->getMessage();
            $this->writeUpdateLog($phase, 'FAILED: ' . $message);
            $this->writeLn('Security update failed (' . $phase . '): ' . $Exception->getMessage(), 'red');

            if ($maintenanceEnabled) {
                $this->writeUpdateLog($phase, 'Maintenance remains enabled; check and repair the installation');
                $this->writeLn('Maintenance remains enabled. Check the update log and run ./console repair.', 'red');
            }

            $this->sendNotification($phase . ': ' . $message);
            return false;
        }

        $this->sendNotification();
        return true;
    }

    /**
     * Both Composer adapters throw on a nonzero status and return stdout/stderr on success.
     * update() in unmuted CLI mode returns an empty array instead of the captured output.
     *
     * @param array<string, bool> $options
     * @return list<string>
     */
    private function runComposer(Composer $Composer, array $options, string $phase): array
    {
        if (!isset($options['--help'])) {
            $options['--prefer-dist'] = true;
        }

        // A fresh output prevents help or dry-run lines leaking into the next web-adapter call.
        $Composer->setOutput(new QUI\System\Console\Output());
        $output = $Composer->executeComposer('update', $options + [
            '--no-interaction' => true,
            '--no-ansi' => true
        ]);

        foreach ($output as $line) {
            $this->writeUpdateLog($phase, $line);
        }

        return array_values($output);
    }

    /** @param list<string> $output */
    private function hasUpdates(array $output): bool
    {
        $recognized = false;

        foreach ($output as $line) {
            if (
                preg_match(
                    '/(?:Lock file|Package) operations: (\d+) installs?, (\d+) updates?, (\d+) removals?/',
                    $line,
                    $matches
                )
            ) {
                $recognized = true;
                if ((int)$matches[1] > 0 || (int)$matches[2] > 0 || (int)$matches[3] > 0) {
                    return true;
                }
            }

            if (
                str_contains($line, 'Nothing to modify in lock file')
                || str_contains($line, 'Nothing to install, update or remove')
            ) {
                $recognized = true;
            }
        }

        if (!$recognized) {
            throw new RuntimeException('Composer dry-run did not return a recognizable update plan.');
        }

        return false;
    }

    protected function completeUpdate(): void
    {
        (new Htaccess())->execute();
        (new Nginx())->execute();
        (new Frankenphp())->execute();
        QUI::getPackageManager()->setLastUpdateDate();
        QUI\Cache\Manager::clearCompleteQuiqqerCache();
        QUI\Cache\Manager::longTimeCacheClearCompleteQuiqqer();
    }

    protected function writeUpdateLog(string $phase, string $message): void
    {
        Update::writeToLog('[' . date('Y-m-d H:i:s') . '] [security-update] [' . $phase . '] '
            . $message . PHP_EOL);
    }

    private function sendNotification(?string $failure = null): void
    {
        $mail = $this->getArgument('m') ?: $this->getArgument('mail');
        if (!is_string($mail) || trim($mail) === '') {
            return;
        }

        try {
            $Mailer = $this->createMailer();
            $Mailer->addRecipient(trim($mail));
            $logFile = VAR_DIR . 'log/update-' . date('Y-m-d') . '.log';
            if (is_file($logFile)) {
                $Mailer->addAttachment($logFile);
            }

            $prefix = 'security.update.console.mail.';
            $subject = $failure === null ? 'subject' : 'failure.subject';
            $body = $failure === null ? 'body' : 'failure.body';
            $Mailer->setSubject(QUI::getLocale()->get('quiqqer/core', $prefix . $subject, ['host' => HOST]));
            $message = QUI::getLocale()->get('quiqqer/core', $prefix . $body, ['host' => HOST]);

            if ($failure !== null) {
                $message .= '<pre>' . htmlspecialchars($failure, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }

            $Mailer->setBody($message);
            if (!$Mailer->send()) {
                throw new RuntimeException('The update notification could not be sent.');
            }
        } catch (Throwable $Exception) {
            $this->writeUpdateLog('notification', 'FAILED: ' . $Exception->getMessage());
            $this->writeLn('Update notification failed: ' . $Exception->getMessage(), 'red');
        }
    }

    protected function createMailer(): QUI\Mail\Mailer
    {
        return QUI::getMailManager()->getMailer();
    }

    protected function isMaintenanceEnabled(): bool
    {
        return (bool)QUI::getConfig('etc/conf.ini.php')->get('globals', 'maintenance');
    }

    protected function setMaintenance(bool $enabled): void
    {
        $Maintenance = new Maintenance();
        $Maintenance->setArgument('status', $enabled ? 'on' : 'off');
        $Maintenance->execute();

        // Maintenance::execute() logs exceptions itself, so also verify the persisted result.
        $Config = new QUI\Config(ETC_DIR . 'conf.ini.php');
        if ((bool)$Config->get('globals', 'maintenance') !== $enabled) {
            throw new RuntimeException('Could not change the maintenance status.');
        }
    }
}
