<?php

namespace QUI\System\Console;

use Throwable;

use function basename;
use function extension_loaded;
use function fwrite;
use function getcwd;
use function getenv;
use function is_resource;
use function pcntl_async_signals;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function pcntl_waitpid;
use function posix_kill;
use function preg_replace;
use function register_shutdown_function;
use function stream_isatty;
use function str_starts_with;
use function usleep;

use const STDOUT;

/**
 * Reports command activity to supported terminal emulators.
 */
class TerminalProgress
{
    private const INDETERMINATE = "\033]9;4;3;0\033\\";
    private const HIDDEN = "\033]9;4;0;0\033\\";
    private const SAVE_TITLE = "\033[22;0t";
    private const RESTORE_TITLE = "\033[23;0t";
    private const TITLE_FRAMES = [
        '⠋',
        '⠙',
        '⠹',
        '⠸',
        '⠼',
        '⠴',
        '⠦',
        '⠧',
        '⠇',
        '⠏'
    ];

    /**
     * @var resource
     */
    private mixed $output;

    private bool $enabled;
    private bool $animateTitle;
    private string $title;
    private ?int $spinnerPid = null;
    private bool $cleanedUp = true;
    private bool $previousAsyncSignals = false;

    /**
     * @var array<int, callable|int>
     */
    private array $previousSignalHandlers = [];

    /**
     * @param resource|null $output
     */
    public function __construct(
        mixed $output = null,
        ?bool $enabled = null,
        ?string $title = null,
        ?bool $animateTitle = null
    ) {
        $this->output = $output ?? STDOUT;
        $this->enabled = $enabled ?? $this->isInteractiveOutput();
        $this->title = $this->sanitizeTitle($title ?? $this->getDefaultTitle());
        $this->animateTitle = $animateTitle ?? extension_loaded('pcntl');
    }

    /**
     * @throws Throwable
     */
    public function run(callable $callback): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $this->cleanedUp = false;
        fwrite($this->output, self::SAVE_TITLE . self::INDETERMINATE);
        $this->spinnerPid = $this->startTitleSpinner();

        register_shutdown_function(function (): void {
            $this->cleanup();
        });

        $this->installSignalHandlers();

        try {
            return $callback();
        } finally {
            $this->cleanup();
            $this->restoreSignalHandlers();
        }
    }

    private function cleanup(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;

        if ($this->spinnerPid !== null) {
            posix_kill($this->spinnerPid, SIGTERM);
            pcntl_waitpid($this->spinnerPid, $status);
            $this->spinnerPid = null;
        }

        fwrite($this->output, self::HIDDEN . self::RESTORE_TITLE);
    }

    private function installSignalHandlers(): void
    {
        if (!$this->animateTitle) {
            return;
        }

        $this->previousAsyncSignals = pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT] as $signal) {
            $this->previousSignalHandlers[$signal] = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, function (int $receivedSignal): never {
                $this->cleanup();
                $this->restoreSignalHandlers();

                exit(128 + $receivedSignal);
            });
        }
    }

    private function restoreSignalHandlers(): void
    {
        foreach ($this->previousSignalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        $this->previousSignalHandlers = [];

        if ($this->animateTitle) {
            pcntl_async_signals($this->previousAsyncSignals);
        }
    }

    private function startTitleSpinner(): ?int
    {
        $this->writeTitle($this->getActivityTitle(self::TITLE_FRAMES[0]));

        if (!$this->animateTitle) {
            return null;
        }

        $childPid = pcntl_fork();

        if ($childPid === -1) {
            return null;
        }

        if ($childPid > 0) {
            return $childPid;
        }

        $frame = 1;

        /* @phpstan-ignore-next-line */
        while (true) {
            $this->writeTitle($this->getActivityTitle(self::TITLE_FRAMES[$frame]));
            $frame = ($frame + 1) % count(self::TITLE_FRAMES);
            usleep(100000);
        }
    }

    private function getActivityTitle(string $frame): string
    {
        return $frame . ' QUIQQER (' . $this->title . ')';
    }

    private function writeTitle(string $title): void
    {
        fwrite($this->output, "\033]0;" . $title . "\007");
    }

    private function getDefaultTitle(): string
    {
        $workingDirectory = getcwd();

        if ($workingDirectory === false) {
            return 'QUIQQER';
        }

        return basename($workingDirectory);
    }

    private function sanitizeTitle(string $title): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $title) ?: 'QUIQQER';
    }

    private function isInteractiveOutput(): bool
    {
        if (!is_resource($this->output) || !stream_isatty($this->output)) {
            return false;
        }

        $terminal = getenv('TERM');
        $screenSession = getenv('STY');

        // A TTY alone does not guarantee support for the title/progress sequences.
        // Screen can display their payload as ordinary output on every animation frame.
        return $terminal !== false
            && $terminal !== ''
            && $terminal !== 'dumb'
            && !str_starts_with($terminal, 'screen')
            && ($screenSession === false || $screenSession === '');
    }
}
