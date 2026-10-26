<?php

/**
 * This file contains QUI\System\Console\UpdatePackageOutput
 */

namespace QUI\System\Console;

use Closure;
use QUI\Interfaces\System\SystemOutput;

use function explode;
use function preg_replace;
use function rtrim;
use function str_replace;
use function str_contains;
use function str_starts_with;
use function strip_tags;
use function trim;

/**
 * Formats legacy package update output for the modern update command output.
 */
class UpdatePackageOutput implements SystemOutput
{
    private bool $requirementsHeadlineWritten = false;

    private bool $packageChangesHeadlineWritten = false;

    /**
     * @var array<string, bool>
     */
    private array $writtenComposerChanges = [];

    public function __construct(
        private UpdateConsoleOutput $Output,
        private int $verbosity = 0,
        private ?Closure $hasComposerChangesAlreadyWritten = null
    ) {
    }

    public function writeLn(string $msg = '', bool|string $color = false, bool|string $bg = false): void
    {
        $message = $this->sanitize($msg);

        if ($message === '') {
            return;
        }

        if ($message === 'Cleanup database') {
            $this->Output->info('Cleanup database');
            return;
        }

        if ($this->writeComposerChange($message)) {
            return;
        }

        if (str_contains($message, 'run setup for package')) {
            $this->Output->listItem($message);
            return;
        }

        if (str_contains($message, 'Your quiqqer system is missing some requirements')) {
            if (!$this->requirementsHeadlineWritten) {
                $this->Output->warning('Your quiqqer system is missing some requirements');
                $this->requirementsHeadlineWritten = true;
            }

            return;
        }

        if (str_starts_with($message, '#')) {
            return;
        }

        if (str_contains($message, 'Cron did not run')) {
            $message = (string)preg_replace('/^[^A-Za-z0-9-]+/', '', $message);
            $this->Output->warning($message);
            return;
        }

        if (str_starts_with($message, '- ')) {
            $message = trim($message, " \t\n\r\0\x0B-");
        }

        if (str_contains($message, 'Aktualisierung wurde')) {
            $this->Output->success('Update executed');
            return;
        }

        if ($this->verbosity > 0) {
            $this->Output->info($message);
        }
    }

    public function write(string $msg, bool|string $color = false, bool|string $bg = false): void
    {
        $this->writeLn($msg, $color, $bg);
    }

    public function writeExternalLine(string $msg): void
    {
        $message = $this->sanitize($msg);

        if ($message === '') {
            return;
        }

        if ($message === 'Cleanup database' || $message === '[..] Cleanup database') {
            $this->Output->info('Cleanup database');
            return;
        }

        if ($this->writeComposerChange($message)) {
            return;
        }

        $this->Output->quote($message);
    }

    private function writeComposerChange(string $message): bool
    {
        if ($message === 'Nothing to install, update or remove') {
            if ($this->hasComposerChangesAlreadyWritten()) {
                return true;
            }

            if (!$this->packageChangesHeadlineWritten) {
                $this->Output->info('No package changes');
                $this->packageChangesHeadlineWritten = true;
            }

            return true;
        }

        $update = str_contains($message, 'Update: ');
        $updates = str_contains($message, 'Updates: ');
        $upgrade = str_starts_with($message, '- Upgrading ') || str_contains($message, ' - Upgrading ');
        $remove = str_starts_with($message, '- Removing ') || str_contains($message, ' - Removing ');
        $removals = str_contains($message, 'Removals: ');

        $install = str_contains($message, 'Install: ');
        $installs = str_contains($message, 'Installs: ');
        $installing = str_starts_with($message, '- Installing ') || str_contains($message, ' - Installing ');

        if (!$update && !$updates && !$install && !$installs && !$installing && !$upgrade && !$remove && !$removals) {
            return false;
        }

        if ($this->hasComposerChangesAlreadyWritten()) {
            return true;
        }

        $message = str_replace(['Updates: ', 'Update: '], '', $message);
        $message = str_replace(['Installs: ', 'Install: '], '', $message);
        $message = str_replace(['Removals: '], '', $message);
        $message = str_replace([' - Upgrading ', '- Upgrading '], '', $message);
        $message = str_replace([' - Installing ', '- Installing '], '', $message);
        $message = str_replace([' - Removing ', '- Removing '], '', $message);
        $changedPackages = explode(',', $message);

        if (!$this->packageChangesHeadlineWritten) {
            $this->Output->info('Package changes');
            $this->packageChangesHeadlineWritten = true;
        }

        foreach ($changedPackages as $package) {
            $package = $this->normalizeComposerChange($package);

            if ($package === '') {
                continue;
            }

            if ($this->writtenComposerChanges[$package] ?? false) {
                continue;
            }

            $this->Output->listItem($package);
            $this->writtenComposerChanges[$package] = true;
        }

        return true;
    }

    private function normalizeComposerChange(string $package): string
    {
        $package = trim(strip_tags($package));
        $package = (string)preg_replace('/:\s.*$/', '', $package);

        return rtrim(trim($package), ':');
    }

    private function sanitize(string $message): string
    {
        $message = (string)preg_replace('/\033\[[0-9;]*m/', '', $message);

        return trim($message);
    }

    private function hasComposerChangesAlreadyWritten(): bool
    {
        if ($this->hasComposerChangesAlreadyWritten === null) {
            return false;
        }

        return (bool)($this->hasComposerChangesAlreadyWritten)();
    }
}
