<?php

/**
 * This file contains QUI\System\Console\Tools\Update
 */

namespace QUI\System\Console\Tools;

use Exception;
use QUI;
use QUI\System\Console\UpdateConsoleOutput;
use QUI\System\Console\UpdatePackageOutput;

use function array_merge;
use function count;
use function date;
use function error_log;
use function explode;
use function function_exists;
use function implode;
use function in_array;
use function is_dir;
use function is_resource;
use function method_exists;
use function ob_get_clean;
use function ob_get_level;
use function ob_flush;
use function ob_start;
use function preg_replace;
use function preg_split;
use function proc_close;
use function proc_open;
use function rtrim;
use function flush;
use function str_pad;
use function str_replace;
use function strip_tags;
use function strlen;
use function strtolower;
use function system;
use function trim;
use function unlink;

use const CMS_DIR;
use const PHP_EOL;
use const VAR_DIR;

/**
 * Update command for the console
 */
class Update extends QUI\System\Console\Tool
{
    private ?UpdateConsoleOutput $updateOutput = null;

    private int $setupPackageCount = 0;

    private bool $composerUpdateHeaderWritten = false;

    /**
     * @var array<string, bool>
     */
    private array $composerChangesWritten = [];

    private int $updateOutputSectionOffset = 0;

    /**
     * constructor
     */
    public function __construct()
    {
        $this->systemTool = true;

        $this->setName('quiqqer:update')
            ->setDescription('Update the quiqqer system and the quiqqer packages')
            ->addArgument(
                'clearCache',
                QUI::getLocale()->get('quiqqer/core', 'console.update.clearCache'),
                false,
                true
            )
            ->addArgument(
                'setDevelopment',
                QUI::getLocale()->get('quiqqer/core', 'console.update.setDevelopment'),
                false,
                true
            )->addArgument(
                'check',
                QUI::getLocale()->get('quiqqer/core', 'console.update.check'),
                false,
                true
            )->addArgument(
                'set-date',
                QUI::getLocale()->get('quiqqer/core', 'console.update.set-date'),
                false,
                true
            )->addArgument(
                'package',
                QUI::getLocale()->get('quiqqer/core', 'console.update.package.update.check'),
                false,
                true
            )->addArgument(
                'skip-filesystem-check',
                'Skip the composer filesystem status check before the update.',
                false,
                true
            )->addArgument(
                'verbose',
                'Show verbose update progress output. Supports -v, -vv and -vvv.',
                'v',
                true
            )->addArgument(
                'cancel',
                'Cancel an active update run by id. Usage: --cancel=<run-id>',
                false,
                true
            )->addArgument(
                'yes',
                'Automatically answer update confirmation questions with yes.',
                'y',
                true
            );

        $this->addExample('./console update --check');
        $this->addExample('./console update --check -vvv');
        $this->addExample('./console update --skip-filesystem-check');
        $this->addExample('./console update --yes');
        $this->addExample('./console update --cancel=<run-id>');
    }

    /**
     * (non-PHPdoc)
     *
     * @see \QUI\System\Console\Tool::execute()
     */
    public function execute(): void
    {
        if ($this->getArgument('cancel')) {
            $this->cancelUpdateRun((string)$this->getArgument('cancel'));
            exit(0);
        }

        if ($this->getArgument('set-date')) {
            $this->executeSystemUpdate();
            return;
        }

        $this->launchUpdateRun();
    }

    public function setUpdateOutputSectionOffset(int $offset): void
    {
        $this->updateOutputSectionOffset = $offset;

        if ($this->updateOutput !== null) {
            $this->updateOutput->setCurrentSection($offset);
        }
    }

    public function executeSystemUpdate(): bool
    {
        $Output = $this->getUpdateOutput();

        $this->writeUpdateLog('====== EXECUTE UPDATE ======');
        $this->writeUpdateLog(QUI::getLocale()->get('quiqqer/core', 'update.log.message.execute.console'));

        Cleanup::clearComposer();

        $Output->section('System update');
        $Output->info('Start update');

        // check license
        try {
            $licenceData = QUI\System\License::getLicenseData();

            if ($licenceData) {
                $status = QUI\System\License::getStatus();

                if ($status && isset($status['active']) && $status['active'] === false) {
                    $message = QUI::getLocale()->get('quiqqer/core', 'update.log.message.licenseActivation');
                    $message = preg_replace('#([ ]){2,}#', "$1", $message) ?? $message;
                    $message = str_replace(PHP_EOL . " ", PHP_EOL, $message);
                    $message = trim($message);

                    $this->writeLn();
                    $this->writeLn($message, 'red');
                    $this->writeLn();
                    $this->writeLn();
                    $this->resetColor();
                    return false;
                }
            }
        } catch (Exception $e) {
            $this->writeLn($e->getMessage(), 'red');
            $this->resetColor();
        }

        $Packages = QUI::getPackageManager();

        // output events
        $Packages->getComposer()->addEvent('onOutput', function ($Composer, $output, $type): void {
            if ($this->getArgument('check') && $this->getVerbosityLevel() > 0) {
                $this->write($output);
                self::writeToLog($output);
                return;
            }

            $lines = preg_split('/\r\n|\r|\n/', (string)$output);

            if (!is_array($lines)) {
                self::onCliOutput((string)$output, $this);
                return;
            }

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                self::onCliOutput($line, $this);
                $this->flushOutputBuffer();
            }
        });

        if ($this->getArgument('set-date')) {
            try {
                QUI::getPackageManager()->setLastUpdateDate();
            } catch (QUI\Exception $Exception) {
                self::writeToLog('====== ERROR ======');
                self::writeToLog($Exception->getMessage());

                return false;
            }

            return true;
        }

        if ($this->getArgument('clearCache')) {
            try {
                $Packages->clearComposerCache();
            } catch (QUI\Exception $Exception) {
                self::writeToLog('====== ERROR ======');
                self::writeToLog($Exception->getMessage());

                return false;
            }
        }

        if ($this->getArgument('check')) {
            $Output->section('Update check');
            $Output->info(QUI::getLocale()->get('quiqqer/core', 'update.log.message.update.via.console'));

            if ($this->getVerbosityLevel() > 0) {
                $Packages->getComposer()->unmute();
            }

            try {
                $Output->info('Refreshing composer package sources');
                $Packages->refreshServerList();
                $Output->info('Resolving available package updates');
                $packages = $Packages->getOutdated(true, $this->getComposerVerbosityOptions());
            } catch (Exception $Exception) {
                self::writeToLog('====== ERROR ======');
                self::writeToLog($Exception->getMessage());

                return false;
            }

            $nameLength = 0;
            $versionLength = 0;

            // #locale
            if (empty($packages)) {
                $Output->success(QUI::getLocale()->get('quiqqer/core', 'update.message.no.updates.available'));

                return true;
            }

            $Output->warning(count($packages) . ' package update(s) available');

            foreach ($packages as $package) {
                if (strlen($package['package']) > $nameLength) {
                    $nameLength = strlen($package['package']);
                }

                if (strlen($package['oldVersion']) > $versionLength) {
                    $versionLength = strlen($package['oldVersion']);
                }
            }

            foreach ($packages as $package) {
                $this->write(
                    str_pad($package['package'], $nameLength + 2),
                    'green'
                );

                $this->resetColor();
                $this->write(
                    str_pad($package['oldVersion'], $versionLength + 2) . ' -> '
                );

                $this->write($package['version'], 'cyan');
                $this->writeLn();
            }

            return true;
        }

        $executeFileSystemCheck = true;
        if ($this->getArgument('skip-filesystem-check')) {
            $executeFileSystemCheck = false;
        }

        if ($executeFileSystemCheck) {
            try {
                $Output->section('Filesystem check');
                $Output->info('Checking package working trees');
                $changes = $this->checkFileSystemChanges();

                if (!$changes) {
                    $Output->success('No filesystem inconsistencies found');
                }
            } catch (Exception $Exception) {
                $Output->errorBox([
                    'Filesystem check failed',
                    'The update has received inconsistencies during the file system check.',
                    'Error: ' . $Exception->getMessage()
                ]);

                if ($this->executedAnywayQuestion() === false) {
                    return false;
                }

                $changes = false;
            }

            if ($changes) {
                $Output->warning('The update has found inconsistencies in the system.');

                if ($this->executedAnywayQuestion() === false) {
                    return false;
                }
            }
        }

        // init backup
        $etcBackupFolder = QUI\System\Backup::createEtcBackup();

        $Maintenance = new Maintenance();
        $Maintenance->setArgument('status', 'on');
        $Maintenance->execute();

        // start update routines
        $CLIOutput = new QUI\System\Console\Output();
        $CLIOutput->Events->addEvent('onWrite', function ($message): void {
            self::onCliOutput($message, $this);
        });

        try {
            $Packages->refreshServerList();

            $Composer = $Packages->getComposer();
            $Composer->unmute();
            $Composer->setOutput($CLIOutput);

            if ($this->getArgument('package')) {
                $Output->section('Composer update');
                $Output->info('Updating package ' . $this->getArgument('package'));

                $Composer->update([
                    'packages' => [
                        $this->getArgument('package')
                    ],
                    '--with-dependencies' => false,
                    '--no-autoloader' => false,
                    '--optimize-autoloader' => true
                ]);
            } else {
                $localeDir = VAR_DIR . 'locale/';
                $localeFiles = $localeDir . 'localefiles';
                $entries = QUI\Utils\System\File::readDir($localeDir);
                $oldDirsAvailable = false;

                // cleanup
                foreach ($entries as $entry) {
                    if ($entry === 'localefiles' || $entry === 'bin') {
                        continue;
                    }

                    // delete old dirs
                    if (is_dir($localeDir . $entry) && str_contains($entry, '_')) {
                        QUI\Utils\System\File::deleteDir($localeDir . $entry);
                        $oldDirsAvailable = true;
                    }
                }

                if ($oldDirsAvailable) {
                    unlink($localeFiles);
                }

                $Output->section('Composer update');
                $Output->info('Updating QUIQQER packages');
                $Packages->getComposer()->setOutput($CLIOutput);
                $this->setupPackageCount = 0;
                $this->composerUpdateHeaderWritten = false;
                $this->composerChangesWritten = [];
                $PackageOutput = new UpdatePackageOutput(
                    $Output,
                    $this->getVerbosityLevel(),
                    fn(): bool => $this->composerUpdateHeaderWritten
                );

                ob_start();

                try {
                    $Packages->update(false, false, $PackageOutput);
                } finally {
                    $this->writeBufferedPackageOutput((string)ob_get_clean(), $PackageOutput);
                }

                $Output->success('Composer update completed');
            }

            $wasExecuted = 'Update executed';
            $webserver = 'Generate server files (.htaccess and NGINX)';

            $Output->success($wasExecuted);
            self::writeToLog($wasExecuted . PHP_EOL);

            $Output->section('Server files');
            $Output->info($webserver);
            self::writeToLog($webserver . PHP_EOL);

            $Htaccess = new Htaccess();
            $Htaccess->execute();

            $NGINX = new Nginx();
            $NGINX->execute();

            $Frankenphp = new Frankenphp();
            $Frankenphp->execute();

            self::writeToLog(PHP_EOL);
            self::writeToLog('✔️' . PHP_EOL);
            self::writeToLog(PHP_EOL);

            // setup set the last update date
            QUI::getPackageManager()->setLastUpdateDate();

            QUI\Cache\Manager::clearCompleteQuiqqerCache();
            QUI\Cache\Manager::longTimeCacheClearCompleteQuiqqer();

            // check init backup, with current inits
            $diff = QUI\System\Backup::diff((string)$etcBackupFolder);

            if (!empty($diff)) {
                if ($this->getVerbosityLevel() > 0) {
                    $this->write($diff);
                }

                $Output->warning('There have been changes to the ini files.');
                $Output->question('Should the etc backup be deleted anyway? [Y,n]');
                $input = $this->readUpdateConfirmationInput('y');

                if (strtolower($input) === 'y') {
                    QUI\System\Backup::deleteEtcBackup((string)$etcBackupFolder);
                }
            } else {
                QUI\System\Backup::deleteEtcBackup((string)$etcBackupFolder);
            }
        } catch (Exception $Exception) {
            $this->write(' [error]', 'red');
            $this->writeLn();
            $this->writeLn(
                QUI::getLocale()->get('quiqqer/core', 'update.message.error.1') . '::' . $Exception->getMessage(),
                'red'
            );

            if ($Exception instanceof QUI\Exception) {
                QUI\System\Log::addError($Exception->getMessage(), $Exception->getContext());
            }

            $this->writeLn(
                QUI::getLocale()->get('quiqqer/core', 'update.message.error'),
                'red'
            );

            $this->writeLn();
            $this->writeLn('./console repair', 'red');
            $this->resetColor();
            $this->writeLn();

            $Maintenance->setArgument('status', 'off');
            $Maintenance->execute();

            return false;
        }

        $Maintenance->setArgument('status', 'off');
        $Maintenance->execute();

        return true;
    }

    protected function launchUpdateRun(): void
    {
        $Repository = new QUI\System\Update\RunRepository(VAR_DIR . 'update/runs/');
        $runs = $Repository->cleanupAndFindActive(time(), 86400);

        foreach ($runs['deleted'] as $id) {
            $this->writeLn('Removed stale update run: ' . $id, 'yellow');
        }

        if (!empty($runs['active'])) {
            $lines = [
                'Update already running',
                'Another update process is active.',
                'Wait until it has finished or cancel the active run.',
                ''
            ];

            foreach ($runs['active'] as $index => $State) {
                if ($index > 0) {
                    $lines[] = '';
                }

                $process = $State->getProcess();
                $pid = is_array($process) ? (int)($process['pid'] ?? 0) : 0;

                $lines[] = 'Run ID:  ' . $State->getId();
                $lines[] = 'Status:  ' . $State->getStatus();
                $lines[] = 'Started: ' . date('Y-m-d H:i:s', $State->getCreatedAt());
                $lines[] = 'PID:     ' . ($pid > 0 ? (string)$pid : 'not available');
                $lines[] = 'Cancel:  ./console update --cancel=' . $State->getId();
            }

            $this->writeErrorBox($lines);
            exit(1);
        }

        $Launcher = QUI\System\Update\RunLauncherFactory::createDefault();
        $Launch = $Launcher->create(null, [
            'type' => 'cli',
            'arguments' => $this->normalizeUpdateRunArguments($this->params)
        ]);

        $this->getUpdateOutput()->section('Preparing update');
        $this->getUpdateOutput()->info('Preparing isolated update runner');
        echo PHP_EOL . PHP_EOL;

        $exitCode = 0;
        $maxRuns = 5;

        do {
            $exitCode = $this->executeRunProcess($Launch);

            if ($exitCode !== 0) {
                exit($exitCode);
            }

            $State = $Repository->load($Launch->getRun()->getState()->getId());
            $maxRuns--;
        } while ($State->getStatus() === QUI\System\Update\RunState::STATUS_RESTART_REQUIRED && $maxRuns > 0);

        if ($State->getStatus() === QUI\System\Update\RunState::STATUS_RESTART_REQUIRED) {
            $this->getUpdateOutput()->errorBox([
                'Update run failed',
                'The runner still requires a restart after maximum attempts.'
            ]);
            exit(1);
        }
    }

    private function executeRunProcess(QUI\System\Update\RunLaunch $Launch): int
    {
        $command = $Launch->getCliCommand();

        if (!function_exists('proc_open')) {
            system($command, $exitCode);
            return (int)$exitCode;
        }

        $process = proc_open($command, [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w']
        ], $pipes);

        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }

    private function cancelUpdateRun(string $id): void
    {
        $Repository = new QUI\System\Update\RunRepository(VAR_DIR . 'update/runs/');
        $State = $Repository->cancel($id);
        $process = $State->getProcess();
        $pid = is_array($process) ? (int)($process['pid'] ?? 0) : 0;
        $signalSent = false;

        if ($pid > 0 && function_exists('posix_kill')) {
            $isRunning = posix_kill($pid, 0);

            if ($isRunning) {
                $signalSent = posix_kill($pid, 15);
            }
        }

        $lines = [
            'Update run cancelled',
            'The runner state was marked as cancelled.',
            '',
            'Run ID: ' . $State->getId(),
            'Status: ' . $State->getStatus()
        ];

        if ($pid > 0) {
            $lines[] = 'PID:    ' . $pid . ($signalSent ? ' (SIGTERM sent)' : ' (not stopped)');
        } else {
            $lines[] = 'PID:    not available';
        }

        $this->writeErrorBox($lines);
    }

    /**
     * Write a log to the update file
     */
    protected function writeUpdateLog(string $message): void
    {
        QUI\System\Log::write(
            $message,
            QUI\System\Log::LEVEL_NOTICE,
            [
                'params' => [
                    'clearCache' => $this->getArgument('clearCache'),
                    'setDevelopment' => $this->getArgument('setDevelopment'),
                    'check' => $this->getArgument('check'),
                    'set-date' => $this->getArgument('set-date')
                ]
            ],
            'update',
            true
        );
    }

    /**
     * Write buffer to the update log
     */
    public static function writeToLog(string $buffer): void
    {
        if (empty($buffer)) {
            return;
        }

        error_log($buffer, 3, VAR_DIR . 'log/update-' . date('Y-m-d') . '.log');
    }

    public static function onCliOutput(string $message, QUI\Interfaces\System\SystemOutput $Instance): void
    {
        self::writeToLog($message . PHP_EOL);
        $verbosity = $Instance instanceof self ? $Instance->getVerbosityLevel() : 0;
        $trimmedMessage = trim(strip_tags($message));

        if ($trimmedMessage === '') {
            return;
        }

        if (str_contains($trimmedMessage, 'run setup for package')) {
            if ($Instance instanceof self) {
                $Instance->setupPackageCount++;
            }

            if ($verbosity < 1) {
                return;
            }
        }

        if ($verbosity >= 3) {
            $Instance->writeLn($trimmedMessage);
            return;
        }

        if (str_contains($message, '<warning>')) {
            $Instance->writeLn(strip_tags($message), 'cyan');

            // reset color
            if (method_exists($Instance, 'resetColor')) {
                $Instance->resetColor();
            }

            return;
        }

        if ($trimmedMessage === 'Nothing to install, update or remove') {
            if ($Instance instanceof self) {
                $Instance->writeComposerChangeHeader('No package changes');
                return;
            }

            $Instance->writeLn('No package changes');
            return;
        }

        // update message
        $update = str_contains($message, 'Update: ');
        $updates = str_contains($message, 'Updates: ');
        $upgrade = str_starts_with($trimmedMessage, '- Upgrading ') || str_contains($message, ' - Upgrading ');
        $remove = str_starts_with($trimmedMessage, '- Removing ') || str_contains($message, ' - Removing ');
        $removals = str_contains($message, 'Removals: ');

        $install = str_contains($message, 'Install: ');
        $installs = str_contains($message, 'Installs: ');
        $installing = str_starts_with($trimmedMessage, '- Installing ') || str_contains($message, ' - Installing ');

        if ($update || $updates || $install || $installs || $installing || $upgrade || $remove || $removals) {
            $message = str_replace(['Updates: ', 'Update: '], '', $message);
            $message = str_replace(['Installs: ', 'Install: '], '', $message);
            $message = str_replace(['Removals: '], '', $message);
            $message = str_replace([' - Upgrading ', '- Upgrading '], '', $message);
            $message = str_replace([' - Installing ', '- Installing '], '', $message);
            $message = str_replace([' - Removing ', '- Removing '], '', $message);
            $changedPackages = explode(',', $message);

            if ($Instance instanceof self) {
                $Instance->writeComposerChangeHeader();

                if ($verbosity === 0) {
                    foreach ($changedPackages as $package) {
                        $package = $Instance->normalizeComposerChange($package);

                        if ($package === '') {
                            continue;
                        }

                        if ($Instance->composerChangesWritten[$package] ?? false) {
                            continue;
                        }

                        $Instance->getUpdateOutput()->listItem($package);
                        $Instance->composerChangesWritten[$package] = true;
                    }

                    return;
                }
            } elseif ($update || $updates || $upgrade) {
                $Instance->writeLn('Updates:', 'yellow');
            } elseif ($install || $installs) {
                $Instance->writeLn('Installs:', 'yellow');
            } elseif ($remove || $removals) {
                $Instance->writeLn('Removals:', 'yellow');
            }

            foreach ($changedPackages as $package) {
                $package = trim(strip_tags($package));

                if ($package === '') {
                    continue;
                }

                $Instance->writeLn('  - ' . $package, 'purple');
            }

            // reset color
            if (method_exists($Instance, 'resetColor')) {
                $Instance->resetColor();
            }

            return;
        }

        // pull message
        if (str_starts_with($message, '      ')) {
            return;
        }

        // ignoring
        $ignore = [
            'Downloading ',
            '- Downloading ',
            '- Upgrading ',
            '- Syncing ',
            'Cloning to cache ',
            'Executing async command ',
            'Pulling in changes',
            'Reading ',
            'Importing ',
            'Writing ',
            'Executing command ',
            '[304] ',
            'Skipped tag '
        ];


        foreach ($ignore as $ig) {
            if (str_starts_with($trimmedMessage, $ig)) {
                return;
            }
        }

        if ($verbosity === 0) {
            $normalModeIgnore = [
                'Loading composer repositories',
                'Updating dependencies',
                'Dependency resolution',
                'Analyzed ',
                'Lock file operations',
                'Package operations',
                'Installing dependencies',
                'Nothing to ',
                'Writing lock file',
                'Generating autoload files',
                'Generating optimized autoload files',
                'No security vulnerability advisories found',
                'Cleanup database'
            ];

            foreach ($normalModeIgnore as $ig) {
                if (str_starts_with($trimmedMessage, $ig)) {
                    return;
                }
            }
        }

        if ($verbosity < 2 && str_starts_with($trimmedMessage, '> ')) {
            return;
        }

        $Instance->writeLn($trimmedMessage);
    }

    protected function checkFileSystemChanges(): bool
    {
        $Packages = QUI::getPackageManager();
        $Composer = $Packages->getComposer();

        if ($this->getVerbosityLevel() > 0) {
            $Composer->unmute();
        } else {
            $Composer->mute();
        }

        $Runner = $Composer->getRunner();
        $result = [];
        $filesystemStatusOutputWritten = false;

        $CLIOutput = new QUI\System\Console\Output();
        $CLIOutput->Events->addEvent('onWrite', function ($message) use (&$result, &$filesystemStatusOutputWritten): void {
            $result[] = $message;
            self::writeToLog($message . PHP_EOL);

            if ($this->getVerbosityLevel() >= 3) {
                $this->writeLn($message);
                $filesystemStatusOutputWritten = true;
            }
        });

        $Runner->setOutput($CLIOutput);

        try {
            $Runner->executeComposer(
                'status',
                array_merge(['--no-interaction' => true], $this->getComposerVerbosityOptions())
            );
        } catch (\QUI\Exception $exception) {
            if ($this->getVerbosityLevel() >= 3 && !$filesystemStatusOutputWritten) {
                foreach ($result as $line) {
                    $this->writeLn($line);
                }
            }

            $modified = [];

            foreach ($result as $line) {
                if (
                    str_contains($line, '[400] ')
                    || str_contains($line, '[401] ')
                    || str_contains($line, '[402] ')
                    || str_contains($line, '[403] ')
                    || str_contains($line, '[404] ')
                ) {
                    $this->writeLn();
                    $this->writeLn(
                        '- The update could not check the following package, there was a problem with the package archive.',
                        'yellow'
                    );

                    $this->writeLn('>> ' . $exception->getMessage());
                }

                if (str_contains($line, "    M ")) {
                    $modified[] = $line;
                }
            }

            if (count($modified) && $this->getVerbosityLevel() > 0) {
                $this->writeLn();
                $this->writeLn('Modified files:', 'light_green');
                $this->writeLn(implode("\n", $modified));
            }


            // fetch changes
            $changes = false;
            $changesList = [];
            $path = '';

            foreach ($result as $line) {
                if (str_contains($line, 'You have changes in the following dependencies:')) {
                    $changes = true;
                    continue;
                }

                if ($changes === false) {
                    continue;
                }

                if (str_contains($line, ':')) {
                    $path = trim($line, ':');
                    $path = str_replace(CMS_DIR, '', $path);

                    $changesList[$path] = [];
                    continue;
                }

                $lines = explode(PHP_EOL, $line);

                foreach ($lines as $l) {
                    if (!empty(trim($l))) {
                        $changesList[$path][] = trim($l);
                    }
                }
            }

            if (count($changesList)) {
                if ($this->getVerbosityLevel() === 0) {
                    $this->getUpdateOutput()->warning('Changed dependencies: ' . count($changesList));
                    $this->getUpdateOutput()->info('Use --verbose (-v) to see package paths and files');
                    $this->resetColor();

                    return true;
                }

                $this->writeLn();
                $this->getUpdateOutput()->quote('You have changes in the following dependencies:');

                foreach ($changesList as $path => $files) {
                    $this->getUpdateOutput()->quote($path);

                    foreach ($files as $file) {
                        $this->getUpdateOutput()->quote($file);
                    }
                }
            }

            $this->resetColor();

            return true;
        }

        return false;
    }

    protected function executedAnywayQuestion(): bool
    {
        $this->getUpdateOutput()->question('Should the update be executed anyway? [y,N]');
        $answer = $this->readUpdateConfirmationInput('y');

        if (empty($answer)) {
            return false;
        }

        if (strtolower($answer) === 'y') {
            return true;
        }

        return false;
    }

    private function readUpdateConfirmationInput(string $autoAnswer): string
    {
        if ($this->shouldAnswerYes()) {
            $this->writeLn($autoAnswer, 'cyan');
            $this->resetColor();

            return $autoAnswer;
        }

        return $this->readInput();
    }

    private function shouldAnswerYes(): bool
    {
        return $this->getArgument('yes')
            || ($this->params['--yes'] ?? false)
            || ($this->params['-y'] ?? false)
            || ($this->params['y'] ?? false)
            || $this->hasVerbosityArgument(['--yes', '-y', 'yes', 'y']);
    }

    /**
     * @return array<string, bool>
     */
    private function getComposerVerbosityOptions(): array
    {
        $level = $this->getVerbosityLevel();

        if ($level >= 3) {
            return ['-vvv' => true];
        }

        if ($level === 2) {
            return ['-vv' => true];
        }

        if ($level === 1) {
            return ['-v' => true];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function normalizeUpdateRunArguments(array $arguments): array
    {
        if (
            ($arguments['-vvv'] ?? false)
            || ($arguments['--vvv'] ?? false)
            || ($arguments['vvv'] ?? false)
        ) {
            $arguments['-vvv'] = true;
            return $arguments;
        }

        if (
            ($arguments['-vv'] ?? false)
            || ($arguments['--vv'] ?? false)
            || ($arguments['vv'] ?? false)
        ) {
            $arguments['-vv'] = true;
            return $arguments;
        }

        if (
            ($arguments['-v'] ?? false)
            || ($arguments['--v'] ?? false)
            || ($arguments['v'] ?? false)
            || ($arguments['--verbose'] ?? false)
            || ($arguments['verbose'] ?? false)
        ) {
            $arguments['verbose'] = true;
        }

        return $arguments;
    }

    private function getVerbosityLevel(): int
    {
        if ($this->hasVerbosityArgument(['-vvv', '--vvv', 'vvv'])) {
            return 3;
        }

        if ($this->hasVerbosityArgument(['-vv', '--vv', 'vv'])) {
            return 2;
        }

        if (
            $this->getArgument('verbose')
            || $this->getArgument('v')
            || ($this->params['--verbose'] ?? false)
            || $this->hasVerbosityArgument(['-v', '--v', 'v'])
        ) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<int, string> $arguments
     */
    private function hasVerbosityArgument(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($this->params[$argument] ?? false) {
                return true;
            }
        }

        foreach ($this->params as $value) {
            if (!is_string($value)) {
                continue;
            }

            if (in_array($value, $arguments, true)) {
                return true;
            }
        }

        return false;
    }

    private function flushOutputBuffer(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function writeBufferedPackageOutput(string $buffer, UpdatePackageOutput $Output): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $buffer);

        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $Output->writeExternalLine($line);
        }
    }

    private function getUpdateOutput(): UpdateConsoleOutput
    {
        if ($this->updateOutput === null) {
            $this->updateOutput = new UpdateConsoleOutput($this);
            $this->updateOutput->setCurrentSection($this->updateOutputSectionOffset);
        }

        return $this->updateOutput;
    }

    private function writeComposerChangeHeader(string $message = 'Package changes'): void
    {
        if ($this->composerUpdateHeaderWritten) {
            return;
        }

        $this->getUpdateOutput()->info($message);
        $this->composerUpdateHeaderWritten = true;
    }

    private function normalizeComposerChange(string $package): string
    {
        $package = trim(strip_tags($package));
        $package = (string)preg_replace('/:\s.*$/', '', $package);

        return rtrim(trim($package), ':');
    }

    /**
     * @param array<int, string> $lines
     */
    private function writeErrorBox(array $lines): void
    {
        $this->getUpdateOutput()->errorBox($lines);
    }
}
