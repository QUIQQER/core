<?php

/**
 * This file contains QUI\System\Console\UpdateConsoleOutput
 */

namespace QUI\System\Console;

use QUI\Interfaces\System\SystemOutput;
use QUI\System\PhpVersionSupport;

use function max;
use function method_exists;
use function preg_match;
use function preg_replace;
use function str_pad;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strip_tags;
use function trim;

use const PHP_EOL;
use const PHP_VERSION;

/**
 * Small CLI output helper for the update command.
 */
class UpdateConsoleOutput
{
    private int $section = 0;

    private string $indentation = '  ';

    public function __construct(
        private SystemOutput $Output,
        private int $totalSections = 6
    ) {
    }

    public function section(string $title): void
    {
        $this->indentation = '  ';

        if ($this->section > 0) {
            $this->Output->writeLn();
        }

        $this->section++;

        $this->Output->writeLn('[' . $this->section . '/' . $this->totalSections . '] ' . $title);
    }

    public function setCurrentSection(int $section): void
    {
        $this->section = $section;
    }

    public function info(string $message): void
    {
        $this->Output->writeLn($this->indentation . '[..] ' . $message);
    }

    public function success(string $message): void
    {
        $this->Output->writeLn($this->indentation . '[OK] ' . $message, 'green');
        $this->resetColor();
    }

    public function warning(string $message): void
    {
        $this->Output->writeLn();
        $this->Output->writeLn('  [!!] ' . $message, 'yellow');
        $this->resetColor();
    }

    public function phpVersionNotice(string $version = PHP_VERSION): void
    {
        $warning = PhpVersionSupport::getUpdateWarning($version);

        if ($warning === null) {
            return;
        }

        $this->Output->writeLn('  [!!] ' . $warning, 'red');
        $this->resetColor();
    }

    public function question(string $message): void
    {
        $this->Output->write('  [?] ' . $message . ' ', 'cyan');
        $this->resetColor();
    }

    public function quote(string $message, bool|string $color = false): void
    {
        $this->Output->writeLn('  > ' . $message, $color);
        $this->resetColor();
    }

    public function listItem(string $message): void
    {
        $this->Output->writeLn('    > ' . $message);
    }

    public function startUnindentedOutput(): void
    {
        $this->indentation = '';
    }

    /**
     * Keep terminal redraw commands out of line-based progress output, and leave
     * Composer's warnings and setup transcript unindented until the next section.
     */
    public function writeComposerLine(string $message): bool
    {
        $plainMessage = (string)preg_replace('/\033\[[0-?]*[ -\/]*[@-~]|\x08/', '', $message);
        $plainMessage = trim(strip_tags($plainMessage));

        if ($plainMessage === '') {
            return true;
        }

        if (
            str_contains($plainMessage, ' is abandoned')
            || str_starts_with($plainMessage, 'Generating autoload files')
            || str_starts_with($plainMessage, 'Generating optimized autoload files')
            || str_contains($plainMessage, 'packages you are using are looking for funding')
            || str_starts_with($plainMessage, '> Execute ')
            || str_starts_with($plainMessage, '> Executing ')
            || str_contains($plainMessage, 'run setup for package')
        ) {
            $this->startUnindentedOutput();
        }

        if ($this->indentation === '') {
            $this->Output->writeLn(strip_tags($message));
            return true;
        }

        if (preg_match('/^\d+\/\d+\s+\[.*\]\s+\d+%$/', $plainMessage)) {
            $this->Output->writeLn('    ' . $plainMessage);
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $lines
     */
    public function errorBox(array $lines): void
    {
        $width = 0;

        foreach ($lines as $line) {
            $width = max($width, strlen($line));
        }

        $border = str_pad('', $width + 4);

        $this->Output->writeLn();
        $this->Output->writeLn($border, 'white', 'red');

        foreach ($lines as $line) {
            $this->Output->writeLn('  ' . str_pad($line, $width) . '  ', 'white', 'red');
        }

        $this->Output->writeLn($border, 'white', 'red');
        $this->resetColor();
        $this->Output->writeLn();
        $this->Output->writeLn();
    }

    private function resetColor(): void
    {
        if (method_exists($this->Output, 'resetColor')) {
            $this->Output->resetColor();
        }
    }
}
