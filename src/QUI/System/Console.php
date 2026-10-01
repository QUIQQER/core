<?php

/**
 * This file contains System_Console
 */

namespace QUI\System;

use Exception;
use League\CLImate\CLImate;
use QUI;
use QUI\ExceptionStack;
use QUI\Utils\Security\Orthos;
use Ramsey\Uuid\Uuid;
use Throwable;

use function array_flip;
use function array_keys;
use function array_map;
use function array_merge;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function chr;
use function class_exists;
use function count;
use function date;
use function define;
use function explode;
use function fgets;
use function file_exists;
use function fileowner;
use function implode;
use function in_array;
use function is_array;
use function is_null;
use function is_object;
use function key;
use function ksort;
use function max;
use function ob_end_clean;
use function php_sapi_name;
use function phpversion;
use function posix_geteuid;
use function posix_getpwuid;
use function realpath;
use function rtrim;
use function sort;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strlen;
use function time;
use function trim;

use const PHP_EOL;

/**
 * The QUIQQER Console
 *
 * With the console you can start tools in the shell
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @author  www.pcsg.de (Moritz Scholz)
 * @licence For copyright and license information, please view the /README.md
 */
class Console
{
    public const PASSWORD_RESET_EXIT_SUCCESS = 0;
    public const PASSWORD_RESET_EXIT_RUNTIME_FAILURE = 1;
    public const PASSWORD_RESET_EXIT_USER_NOT_FOUND = 2;
    public const PASSWORD_RESET_EXIT_CANCELLED = 3;

    /**
     * The current text color
     */
    protected string | bool $current_color = false;

    /**
     * the current background color
     */
    protected string | bool $current_bg = false;

    /**
     * All available text colors
     *
     * @var array<string, string>
     */
    protected array $colors = [
        'black' => '0;30',
        'dark_gray' => '1;30',
        'blue' => '0;34',
        'light_blue' => '1;34',
        'green' => '0;32',
        'light_green' => '1;32',
        'cyan' => '0;36',
        'light_cyan' => '1;36',
        'red' => '1;31',
        'light_red' => '2;31',
        'purple' => '0;35',
        'light_purple' => '1;35',
        'brown' => '0;33',
        'yellow' => '1;33',
        'light_gray' => '0;37',
        'white' => '1;37',
        'black_u' => '4;30',
        'red_u' => '4;31',
        'green_u' => '4;32',
        'yellow_u' => '4;33',
        'blue_u' => '4;34',
        'purple_u' => '4;35',
        'cyan_u' => '4;36',
        'white_u' => '4;37'
    ];

    /**
     * All available background colors
     *
     * @var array<string, string>
     */
    protected array $bg = [
        'black' => '40',
        'red' => '41',
        'green' => '42',
        'yellow' => '43',
        'blue' => '44',
        'magenta' => '45',
        'cyan' => '46',
        'light_gray' => '47'
    ];

    /**
     * CLI arguments
     *
     * @var array<string, mixed>
     */
    protected array $arguments = [];

    protected ?QUI\Interfaces\Users\User $User = null;

    /**
     * All available console tools
     *
     * @var array<string, Console\Tool>
     */
    private array $tools = [];

    /**
     * @var array<string, array<string, Console\Tool>> All available tools, but grouped
     */
    private array $groupedTools = [];

    /**
     * List of system tools
     * - Tools which are called with the SystemUser
     *
     * @var array<int, string>
     */
    private array $systemTools = [
        'clear-all-quiqqer-cache',
        'clear-cache',
        'clear-cache-path',
        'clear-tmp',
        'clear-sessions',
        'clear-lock',
        'cron',
        'password-reset',
        'setup',
        'system-migration',
        'update',
        'package',
        'licence',
        'htaccess',
        'composer'
    ];

    /**
     * Console parameter
     *
     * @var array<string, mixed>
     */
    private readonly array $argv;

    private int $systemToolExitCode = 0;

    /**
     * constructor
     * @throws QUI\Exception
     */
    public function __construct()
    {
        // check locale
        $languages = QUI::availableLanguages();
        $languages = array_flip($languages);

        $locale = QUI::getLocale()->getCurrent();

        if (!isset($languages[$locale])) {
            if (isset($languages['en'])) {
                QUI::getLocale()->setCurrent('en');
            } elseif (isset($languages['de'])) {
                QUI::getLocale()->setCurrent('de');
            } else {
                $defaultLanguage = key($languages);

                if ($defaultLanguage !== null) {
                    QUI::getLocale()->setCurrent($defaultLanguage);
                }
            }
        }

        $this->title();


        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = '';
        }

        if (!isset($_SERVER['argv'])) {
            $this->writeLn("Cannot use Consoletools");
            exit;
        }

        // read argv params
        $params = $this->getArguments();
        unset($params['no-header']);
        $this->argv = $params;

        $this->read();

        // read system tools
        $tools = $this->get(true);
        $systemTools = $this->systemTools;

        foreach ($tools as $tool => $Tool) {
            if ($Tool->isSystemTool()) {
                $systemTools[] = $tool;
            }
        }

        $systemTools = array_unique($systemTools);

        sort($systemTools);
        $this->systemTools = $systemTools;

        if ($this->getArgument('_complete')) {
            $this->outputCompletionSuggestions();
        }

        $args = $this->readArgv();
        unset($args['--no-header']);
        $isSystemTool = key($args);

        if (
            is_string($isSystemTool)
            && in_array($isSystemTool, $this->systemTools, true)
        ) {
            $this->setArgument('#system-tool', $isSystemTool);
        }

        // check execute permissions with process user
        $ignorePermCheck = $this->getArgument('ignore-file-permissions');
        $processUserId = posix_geteuid();
        $processUserData = posix_getpwuid($processUserId);
        $processUser = $processUserData === false
            ? (string)$processUserId
            : $processUserData['name'];

        $ownerId = (int)fileowner(__FILE__);
        $ownerData = posix_getpwuid($ownerId);
        $owner = $ownerData === false
            ? (string)$ownerId
            : $ownerData['name'];

        if (!$ignorePermCheck && $owner !== $processUser) {
            $this->write(
                QUI::getLocale()->get('quiqqer/core', 'exception.console.execute.user', [
                    'user' => $processUser,
                    'owner' => $owner,
                ]),
                'red'
            );

            $this->clearMsg();
            $this->writeLn();
            $this->writeLn();
            $this->write(QUI::getLocale()->get('quiqqer/core', 'console.execute-user.question'));

            $input = $this->readInput();

            if ($input !== 'yes') {
                exit;
            }
        }

        if ($this->getArgument('#system-tool')) {
            if ($this->getArgument('help')) {
                $this->executeSystemTool();
            } else {
                $this->executeWithTerminalProgress(function (): void {
                    $this->executeSystemTool();
                });
            }

            exit($this->systemToolExitCode);
        }


        if (isset($params['help']) && !isset($params['tool'])) {
            $this->help();
        }


        // system tools
        if (empty($params)) {
            $this->help();
        }

        if (
            !$this->getArgument('--login')
            && !$this->getArgument('--username')
            && !$this->getArgument('--listtools')
        ) {
            if (!empty($args)) {
                $this->displayToolsForGroups(key($args));
            }

            return;
        }

        try {
            $this->authenticate();
        } catch (QUI\Exception $Exception) {
            QUI::getEvents()->fireEvent('userCliLoginError', [$this->getArgument('username'), $Exception]);

            $this->writeLn($Exception->getMessage() . "\n\n", 'red');
            exit;
        }

        if (is_null($this->User) || !$this->User->getUUID()) {
            QUI::getEvents()->fireEvent('userCliLoginError', [$this->getArgument('username')]);

            $this->writeLn("Login incorrect\n\n", 'red');
            exit;
        }

        QUI\Permissions\Permission::setUser($this->User);

        QUI::getEvents()->fireEvent('userCliLogin', [$this->User]);

        if (!QUI\Permissions\Permission::hasPermission('quiqqer.system.console')) {
            $this->writeLn("Missing rights to use the console\n\n", 'red');
            $this->clearMsg();
            exit;
        }

        if (isset($params['listtools'])) {
            $this->help();
        }

        if (!isset($params['tool']) && !isset($params['listtools'])) {
            $this->writeLn("\n");
            $this->readToolFromShell();
        }
    }

    /**
     * QUIQQER Console title
     * output the main quiqqer console info
     */
    public function title(): void
    {
        $params = $this->readArgv();

        if (isset($params['--no-header'])) {
            return;
        }

        if (!$this->needsHelpHeader($params)) {
            // Completion output is consumed by the shell and must contain only suggestions.
            foreach (array_keys($params) as $argument) {
                if (trim($argument, '-') === '_complete') {
                    return;
                }
            }

            $PackageManager = QUI::getPackageManager();
            $lastUpdate = date('d.m.Y H:i:s', $PackageManager->getLastUpdateDate());

            echo "\033[1mQUIQQER\033[22m " . $PackageManager->getVersion()
                . ' | PHP ' . phpversion()
                . ' | Last update: ' . $lastUpdate . PHP_EOL;

            return;
        }

        $version = QUI::getPackageManager()->getVersion();
        $year = date('Y');

        $lastUpdate = QUI::getPackageManager()->getLastUpdateDate();
        $lastUpdate = date('d.m.Y H:i:s', $lastUpdate);

        $str = '
  _______          _________ _______  _______  _______  _______
 (  ___  )|\     /|\__   __/(  ___  )(  ___  )(  ____ \(  ____ )
 | (   ) || )   ( |   ) (   | (   ) || (   ) || (    \/| (    )|
 | |   | || |   | |   | |   | |   | || |   | || (__    | (____)|
 | |   | || |   | |   | |   | |   | || |   | ||  __)   |     __)
 | | /\| || |   | |   | |   | | /\| || | /\| || (      | (\ (
 | (_\ \ || (___) |___) (___| (_\ \ || (_\ \ || (____/\| ) \ \__
 (____\/_)(_______)\_______/(____\/_)(____\/_)(_______/|/   \__/


 Welcome to QUIQQER Version ' . $version . ' - Last Update: ' . $lastUpdate . ' - PHP Version: ' . phpversion() . '
';

        $this->message($str, 'green', 'white');
        $this->clearMsg();

        $licenceText = '
 QUIQQER Copyright(C) ' . $year . '  PCSG - Computer & Internet Service OHG - www.pcsg.de
 This program comes with ABSOLUTELY NO WARRANTY; for details type `./console licence`.
 This is free software, and you are welcome to redistribute it under certain conditions;
 visit www.quiqqer.com for details.

                               ';

        $this->message($licenceText, 'cyan', 'white');
        $this->clearMsg();
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function needsHelpHeader(array $params): bool
    {
        return empty($params)
            || (count($params) === 1 && isset($params['--help']));
    }

    /**
     * Read the argv params
     *
     * @return array<string, mixed>
     */
    protected function readArgv(): array
    {
        // Vars löschen die Probleme bereiten können
        $_REQUEST = [];
        $_POST = [];
        $_GET = [];

        if (isset($_SERVER['argv'][0])) {
            unset($_SERVER['argv'][0]);
        }

        $params = [];

        // Parameter auslesen
        foreach ($_SERVER['argv'] as $argv) {
            if (str_contains($argv, '=')) {
                [$key, $value] = explode('=', $argv, 2);
                $params[$key] = $value;
            } else {
                $params[$argv] = true;
            }
        }

        return $params;
    }

    /**
     * Output a message
     *
     * @param string $msg - Message to output
     * @param boolean|string $color - (optional) text color
     * @param boolean|string $bg - (optional) background color
     */
    public function message(string $msg, bool | string $color = false, bool | string $bg = false): void
    {
        if ($color) {
            $this->current_color = $color;
        }

        if ($bg) {
            $this->current_bg = $bg;
        }

        if (isset($this->colors[$this->current_color])) {
            echo "\033[" . $this->colors[$this->current_color] . "m";
        }

        if (isset($this->bg[$this->current_bg])) {
            echo "\033[" . $this->bg[$this->current_bg] . "m";
        }

        echo $msg;

        $this->resetMsg();
    }

    /**
     * reset the message color
     */
    public function resetMsg(): void
    {
        echo "\033[0m";
    }

    /**
     * reset the message and background color and reset the color settings
     */
    public function clearMsg(): void
    {
        $this->current_color = false;
        $this->current_bg = false;

        echo "\033[0m";
    }

    /**
     * Write a new line
     *
     * @param string $msg - (optional) the printed message
     * @param boolean|string $color - (optional) text color
     * @param boolean|string $bg - (optional) background color
     */
    public function writeLn(string $msg = '', bool | string $color = false, bool | string $bg = false): void
    {
        $this->message(PHP_EOL . $msg, $color, $bg);

        if (php_sapi_name() === 'cli') {
            @flush();
            @ob_flush();
        }
    }

    /**
     * Return the CLI arguments
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        if (!empty($this->arguments)) {
            return $this->arguments;
        }

        $args = $this->readArgv();

        foreach ($args as $arg => $value) {
            $this->setArgument($arg, $value);
        }

        return $this->arguments;
    }

    /**
     * Set CLI arguments
     */
    public function setArgument(string $argument, string $value): void
    {
        $argument = trim($argument, '-');

        $this->arguments[$argument] = $value;
    }

    /**
     * Read all tools and include it
     *
     * @throws QUI\Exception
     */
    private function read(): void
    {
        if (!empty($this->tools)) {
            return;
        }

        // Standard Konsoletools
        $path = LIB_DIR . 'QUI/System/Console/Tools/';
        $files = QUI\Utils\System\File::readDir($path, true);

        for ($i = 0, $len = count($files); $i < $len; $i++) {
            if (!file_exists($path . $files[$i])) {
                continue;
            }

            $this->includeClasses($files[$i], $path);
        }

        // look at console tools at plugins
        $PackageManager = QUI::getPackageManager();
        $plugins = $PackageManager->getInstalled();

        $tools = [];

        foreach ($plugins as $plugin) {
            $dir = OPT_DIR . $plugin['name'];

            if (!file_exists($dir . '/console.xml')) {
                continue;
            }

            $tools = array_merge(
                $tools,
                QUI\Utils\Text\XML::getConsoleToolsFromXml($dir . '/console.xml')
            );
        }

        // look at console tools at projects
        $ProjectManager = QUI::getProjectManager();
        $projects = $ProjectManager->getProjects();

        foreach ($projects as $project) {
            $dir = USR_DIR . $project;

            if (!file_exists($dir . '/console.xml')) {
                continue;
            }

            $tools = array_merge(
                $tools,
                QUI\Utils\Text\XML::getConsoleToolsFromXml($dir . '/console.xml')
            );
        }


        // init tools
        foreach ($tools as $cls) {
            if (
                !class_exists($cls)
                || !is_a($cls, Console\Tool::class, true)
            ) {
                continue;
            }

            $Tool = new $cls();
            $Tool->setAttribute('parent', $this);

            if ($this->argv) {
                foreach ($this->argv as $key => $value) {
                    $Tool->setArgument($key, $value);
                }
            }

            $name = $Tool->getName();

            if (!is_string($name)) {
                continue;
            }

            $this->tools[$name] = $Tool;
        }

        // grouping
        $groups = [];

        foreach ($this->tools as $name => $Tool) {
            if (!str_contains($name, ':')) {
                continue;
            }

            $name = explode(':', $name);

            $groups[$name[0]][$name[1]] = $Tool;
        }

        $this->groupedTools = $groups;
    }

    /**
     * Include the tool class
     *
     * @throws QUI\Exception
     */
    protected function includeClasses(string $file, string $dir): void
    {
        $file = Orthos::clearPath((string)realpath($dir . $file));

        if (!file_exists($file)) {
            throw new QUI\Exception('console tool not exists');
        }

        require_once $file;

        $class = str_replace('.php', '', $file);
        $class = explode(LIB_DIR, $class);
        $class = str_replace('/', '\\', $class[1]);

        if (
            !class_exists($class)
            || !is_a($class, Console\Tool::class, true)
        ) {
            return;
        }

        $Tool = new $class();
        $Tool->setAttribute('parent', $this);

        if ($this->argv) {
            foreach ($this->argv as $key => $value) {
                $Tool->setArgument($key, $value);
            }
        }

        $name = $Tool->getName();

        if (!is_string($name)) {
            return;
        }

        $this->tools[$name] = $Tool;
    }

    /**
     * Return a tool
     *
     * @param boolean|string $tool - boolean true = all Tools | string = specific tool
     *
     * @return ($tool is true ? array<string, Console\Tool> : Console\Tool|false)
     */
    public function get(bool | string $tool): false | array | Console\Tool
    {
        if ($tool === true) {
            return $this->tools;
        }

        if ($tool === false) {
            return false;
        }

        return $this->tools[$tool] ?? false;
    }

    private function outputCompletionSuggestions(): never
    {
        $command = $this->getArgument('command');
        $currentWord = $this->getArgument('word');
        $Provider = new Console\CompletionProvider($this->systemTools, $this->tools);
        $suggestions = $Provider->getSuggestions(
            is_string($command) ? $command : '',
            is_string($currentWord) ? $currentWord : ''
        );

        if ($suggestions) {
            echo implode(PHP_EOL, $suggestions) . PHP_EOL;
        }

        exit;
    }

    /**
     * Return the CLI argument
     *
     * @return mixed|null
     */
    public function getArgument(string $argument): mixed
    {
        $argument = trim($argument, '-');

        return $this->arguments[$argument] ?? null;
    }

    /**
     * alternative for message()
     *
     * @param string $msg - Message to output
     * @param boolean|string $color - (optional) text color
     * @param boolean|string $bg - (optional) background color
     */
    public function write(string $msg, bool | string $color = false, bool | string $bg = false): void
    {
        $this->message($msg, $color, $bg);
    }

    /**
     * Read the input from the user -> STDIN
     */
    public function readInput(): string
    {
        return trim((string)fgets(STDIN));
    }

    /**
     * Execute the system tool
     * @throws QUI\Exception
     */
    protected function executeSystemTool(): void
    {
        if (php_sapi_name() != 'cli') {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.console.execute.only.in.cli'
            ]);
        }

        define('SYSTEM_INTERN', true);

        QUI\Permissions\Permission::setUser(
            QUI::getUsers()->getSystemUser()
        );

        $help = $this->getArgument('help');

        $displaySystemToolHelp = function ($tool): void {
            $self = $this;
            $description = QUI::getLocale()->get(
                'quiqqer/core',
                'console.systemtool.' . $tool
            );

            $self->writeLn($tool . ':');
            $self->writeLn($description);
        };

        switch ($this->getArgument('#system-tool')) {
            case 'clear-all-quiqqer-cache':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI\Cache\Manager::clearAll();
                QUI::getTemp()->moveToTemp(VAR_DIR . 'cache');
                QUI::getTemp()->moveToTemp(VAR_DIR . 'sessions');
                QUI\Cache\Manager::clearCompleteQuiqqerCache();
                break;

            case 'clear-cache':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI\Cache\Manager::clearCompleteQuiqqerCache();
                QUI::getTemp()->moveToTemp(VAR_DIR . 'cache');
                break;

            case 'clear-cache-path':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                $path = $this->getArgument('path');

                if (empty($path)) {
                    $this->writeLn('Missing --path. Pleas use --path="my/cache/path"', 'red');
                    $this->writeLn();
                    $this->resetMsg();

                    return;
                }

                QUI\Cache\Manager::clear($path);
                break;

            case 'clear-tmp':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI::getTemp()->clear();
                break;

            case 'clear-sessions':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI::getTemp()->moveToTemp(VAR_DIR . 'sessions');
                break;

            case 'clear-lock':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI::getTemp()->moveToTemp(VAR_DIR . 'lock');
                break;

            case 'cron':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                QUI::getPackage('quiqqer/cron');

                // locking
                $lockKey = 'cron-execution';
                $Package = QUI::getPackage('quiqqer/cron');

                if (QUI\Lock\Locker::isLocked($Package, $lockKey, null, false)) {
                    $time = QUI\Lock\Locker::getLockTime($Package, $lockKey);

                    if ($time < 0) {
                        $this->writeLn(
                            'Crons cannot be executed because another instance is already executing crons.',
                            'red'
                        );

                        $this->resetMsg();
                        $this->writeLn();
                        exit(1);
                    }
                }

                $CronManager = new QUI\Cron\Manager();
                $CronManager->execute();

                break;

            case 'password-reset':
                if ($help) {
                    $displaySystemToolHelp($this->getArgument('#system-tool'));

                    return;
                }

                $this->systemToolExitCode = $this->passwordReset(
                    $this->getPasswordResetIdentifierArgument(),
                    (bool)$this->getArgument('no-interaction'),
                    (bool)$this->getArgument('password-stdin')
                );
                return;

            case 'setup':
                $this->setArgument('#system-tool', 'quiqqer:setup');
                break;

            case 'update':
                $this->setArgument('#system-tool', 'quiqqer:update');
                break;

            case 'package':
                $this->setArgument('#system-tool', 'quiqqer:package');
                break;

            case 'htaccess':
                $this->setArgument('#system-tool', 'quiqqer:htaccess');
                break;

            case 'licence':
                $this->setArgument('#system-tool', 'quiqqer:licence');
                break;
        }

        $Tool = $this->get($this->getArgument('#system-tool'));

        if ($Tool && !is_array($Tool)) {
            if ($help) {
                $Tool->outputHelp();

                return;
            }

            $Tool->setAttribute('parent', $this);

            if ($this->argv) {
                foreach ($this->argv as $key => $value) {
                    $Tool->setArgument($key, $value);
                }
            }

            $Tool->execute();
        }

        $this->writeLn('Everything is done. Thank you for using QUIQQER', 'green');
        $this->resetMsg();
        $this->writeLn();
    }

    /**
     * Execute a console tool while reporting its activity to the terminal.
     */
    private function executeWithTerminalProgress(callable $callback): mixed
    {
        $Progress = new Console\TerminalProgress();

        return $Progress->run($callback);
    }

    /**
     * clear the console (all colors)
     */
    public function clear(): void
    {
        array_map(static function ($a): void {
            print chr($a);
        }, [27, 91, 72, 27, 91, 50, 74]);
    }

    /**
     * Initiates a password reset
     *
     * @param string|null $identifier Optional username or UUID; requested interactively if omitted
     * @param bool $noInteraction Skip confirmation prompts; requires an identifier
     * @param bool $passwordStdin Read the new password from STDIN instead of generating one
     *
     * @throws QUI\Database\Exception
     * @throws ExceptionStack
     * @throws QUI\Permissions\Exception
     * @throws QUI\Users\Exception
     */
    protected function passwordReset(
        ?string $identifier = null,
        bool $noInteraction = false,
        bool $passwordStdin = false
    ): int {
        $this->writeLn(
            QUI::getLocale()->get(
                "quiqqer/core",
                "console.tool.passwordreset.header"
            )
        );

        $this->writeLn(
            QUI::getLocale()->get(
                "quiqqer/core",
                "console.tool.passwordreset.warning"
            ),
            "yellow"
        );
        $this->clearMsg();

        if ($identifier === null && $noInteraction) {
            $this->writePasswordResetMessage('console.tool.passwordreset.identifier.required', 'red');

            return self::PASSWORD_RESET_EXIT_CANCELLED;
        }

        if ($identifier === null) {
            $this->writeLn(
                QUI::getLocale()->get(
                    "quiqqer/core",
                    "console.tool.passwordreset.prompt.identifier"
                ) . ' '
            );

            $identifier = $this->readInput();
        }

        $identifier = trim($identifier);

        if ($identifier === '') {
            $this->writePasswordResetMessage('console.tool.passwordreset.cancelled', 'red');

            return self::PASSWORD_RESET_EXIT_CANCELLED;
        }

        try {
            $User = $this->getPasswordResetUser($identifier);
            $username = $User->getUsername();
            $uuid = (string)$User->getUUID();
        } catch (QUI\Exception $Exception) {
            if ((int)$Exception->getCode() === 404) {
                $this->writePasswordResetMessage('console.tool.passwordreset.user.not.found', 'red');

                return self::PASSWORD_RESET_EXIT_USER_NOT_FOUND;
            }

            $this->writePasswordResetMessage('console.tool.passwordreset.error', 'red');

            return self::PASSWORD_RESET_EXIT_RUNTIME_FAILURE;
        } catch (Throwable) {
            $this->writePasswordResetMessage('console.tool.passwordreset.error', 'red');

            return self::PASSWORD_RESET_EXIT_RUNTIME_FAILURE;
        }

        if (!$noInteraction) {
            $this->writeLn(
                QUI::getLocale()->get(
                    "quiqqer/core",
                    "console.tool.passwordreset.prompt.confirm",
                    [
                        "username" => $username,
                        "uuid" => $uuid
                    ]
                ) . ' '
            );

            $confirm = strtolower(trim($this->readInput()));

            if ($confirm !== "y") {
                $this->writePasswordResetMessage('console.tool.passwordreset.cancelled', 'red');

                return self::PASSWORD_RESET_EXIT_CANCELLED;
            }

            $this->writeLn(
                QUI::getLocale()->get(
                    "quiqqer/core",
                    "console.tool.passwordreset.prompt.confirm2",
                    [
                        "username" => $username
                    ]
                ) . ' ',
                "yellow"
            );

            $confirm = strtolower(trim($this->readInput()));

            if ($confirm !== "y") {
                $this->writePasswordResetMessage('console.tool.passwordreset.cancelled', 'red');

                return self::PASSWORD_RESET_EXIT_CANCELLED;
            }
        }

        try {
            if ($passwordStdin) {
                $password = $this->readPasswordResetPasswordFromStdin();

                if ($password === null || $password === '') {
                    $this->writePasswordResetMessage('console.tool.passwordreset.password.stdin.required', 'red');

                    return self::PASSWORD_RESET_EXIT_CANCELLED;
                }
            } else {
                $password = $this->createPasswordResetPassword();
            }

            $User->setPassword($password, QUI::getUsers()->getSystemUser());
        } catch (Throwable) {
            $this->writePasswordResetMessage('console.tool.passwordreset.error', 'red');

            return self::PASSWORD_RESET_EXIT_RUNTIME_FAILURE;
        }

        $this->writeLn(
            QUI::getLocale()->get(
                "quiqqer/core",
                $passwordStdin
                    ? "console.tool.passwordreset.success.custom"
                    : "console.tool.passwordreset.success"
            ),
            "green"
        );

        if (!$passwordStdin) {
            $this->writeLn($password, "green");
        }

        $this->writeLn();

        return self::PASSWORD_RESET_EXIT_SUCCESS;
    }

    private function getPasswordResetIdentifierArgument(): ?string
    {
        $arguments = array_values($_SERVER['argv']);
        $toolIndex = array_search('password-reset', $arguments, true);

        if ($toolIndex === false) {
            return null;
        }

        foreach (array_slice($arguments, $toolIndex + 1) as $argument) {
            if (str_starts_with($argument, '-')) {
                continue;
            }

            return $argument;
        }

        return null;
    }

    /**
     * Resolve a password-reset target by UUID or, for backwards compatibility, username.
     *
     * @throws QUI\Exception
     * @throws ExceptionStack
     */
    protected function getPasswordResetUser(string $identifier): QUI\Interfaces\Users\User
    {
        if (Uuid::isValid($identifier)) {
            return QUI::getUsers()->get($identifier);
        }

        return QUI::getUsers()->getUserByName($identifier);
    }

    protected function createPasswordResetPassword(): string
    {
        return Orthos::getPassword(random_int(8, 14));
    }

    protected function readPasswordResetPasswordFromStdin(): ?string
    {
        $password = fgets(STDIN);

        if ($password === false) {
            return null;
        }

        return rtrim($password, "\r\n");
    }

    private function writePasswordResetMessage(string $localeKey, string $color): void
    {
        $this->writeLn(QUI::getLocale()->get('quiqqer/core', $localeKey), $color);
        $this->write("\n");
    }

    /**
     * Output the help
     *
     * @param string $msg - [optional] extra text
     */
    public function help(string $msg = ''): never
    {
        $this->clearMsg();
        $this->getArguments();

        $this->writeLn(" Call");
        $this->writeLn(" ./console [--PARAMS]", 'red');
        $this->writeLn(" ./console [group:tool]", 'orange');
        $this->writeLn(" ./console [group] [tool]", 'orange');

        $this->clearMsg();
        $this->writeLn();
        $this->writeLn(" Optional arguments");
        $this->writeLn(" --help			This help text");
        $this->writeLn(" --no-header             Suppress the QUIQQER header, keeping tool output unchanged");

        $this->writeLn(" --username		Username", 'red');
        $this->writeLn(" --password		Password to login", 'red');

        $this->writeLn(" --listtools		Lists all available tools, including those that require a login");

        $this->writeLn();
        $this->writeLn();
        $this->writeLn();

        $this->displaySystemTools();

        $Climate = new CLImate();
        $Climate->white()->out("Command Groups");
        $Climate->white()->out("-------------");
        $Climate->white()->out('');

        $groups = array_keys($this->groupedTools);
        $Climate->white()->out(implode(', ', $groups));

        $this->writeLn($msg);
        exit;
    }

    /**
     * Display the list of the system tool
     */
    public function displaySystemTools(): void
    {
        $systemTools = $this->systemTools;

        $Climate = new CLImate();
        $Climate->blue()->out("Available System-Tools");
        $Climate->blue()->out("=============================================================");

        $data = [
            ['           Command', 'Description'],
            ['           -------', '-----------'],
            ['', '']
        ];

        foreach ($systemTools as $tool) {
            $Tool = $this->get($tool);

            $name = $tool;
            $description = QUI::getLocale()->get('quiqqer/core', 'console.systemtool.' . $tool);

            if ($Tool instanceof QUI\System\Console\Tool) {
                $name = $Tool->getName();
                $description = $Tool->getDescription();
            }

            /* @var $Tool Console\Tool */
            $data[] = [
                "\033[" . $this->colors['green'] . "m" . $name . "\033[0m",
                $description
            ];
        }

        $Climate->out('');
        $Climate->columns($data);
        $Climate->out('');
    }

    /**
     * @param string $group
     */
    protected function displayToolsForGroups($group): void
    {
        $group = rtrim((string)$group, ':');

        if (!empty($this->groupedTools[$group])) {
            $tools = $this->groupedTools[$group];
            ksort($tools);

            $commands = array_values(array_map(
                static fn(Console\Tool $Tool): string => (string)$Tool->getName(),
                $tools
            ));

            $this->displayCommandTable('Available Commands for ' . $group, $commands);
            return;
        }

        $Provider = new Console\CompletionProvider($this->systemTools, $this->tools);
        $commands = $Provider->getSuggestions('', $group);

        if ($commands) {
            $this->displayCommandTable('Did you mean?', $commands);
            return;
        }

        $this->writeLn('No command found!', 'red');
        $this->writeLn();
        $this->resetMsg();

        exit(2);
    }

    /**
     * @param array<int, string> $commands
     */
    private function displayCommandTable(string $title, array $commands): void
    {
        $commandWidth = strlen('Command');
        $rows = [];

        $this->writeLn();

        $Climate = new CLImate();
        $Climate->blue()->out($title);
        $Climate->blue()->out(str_repeat('=', strlen($title)));

        foreach ($commands as $name) {
            $Tool = $this->get($name);
            $description = $Tool instanceof Console\Tool
                ? $Tool->getDescription()
                : QUI::getLocale()->get('quiqqer/core', 'console.systemtool.' . $name);

            $parts = explode(':', $name, 2);

            if (isset($parts[1])) {
                $shortCommand = ':' . $parts[1];
                $coloredCommand = $parts[0]
                    . "\033[" . $this->colors['green'] . "m"
                    . $shortCommand
                    . "\033[0m";
            } else {
                $coloredCommand = "\033[" . $this->colors['green'] . "m" . $name . "\033[0m";
            }

            $commandWidth = max($commandWidth, strlen($name));
            $rows[] = [
                'plainCommand' => $name,
                'coloredCommand' => $coloredCommand,
                'description' => trim((string)$description)
            ];
        }

        $commandWidth += 4;

        $this->writeLn(
            'Command'
            . str_repeat(' ', $commandWidth - strlen('Command'))
            . 'Description'
        );
        $this->writeLn(
            '-------'
            . str_repeat(' ', $commandWidth - strlen('-------'))
            . '-----------'
        );
        $this->writeLn();

        foreach ($rows as $row) {
            $this->writeLn(
                $row['coloredCommand']
                . str_repeat(' ', $commandWidth - strlen($row['plainCommand']))
                . $row['description']
            );
        }

        $this->writeLn();
    }

    /**
     * Execute the authentication
     * @throws QUI\Users\Exception|QUI\Database\Exception
     */
    protected function authenticate(): void
    {
        $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalAuthenticators();

        if ($this->getArgument('u')) {
            $this->setArgument('username', $this->getArgument('u'));
        }

        if ($this->getArgument('p')) {
            $this->setArgument('password', $this->getArgument('p'));
        }

        foreach ($authenticators as $authenticator) {
            /* @var $Authenticator QUI\Users\AbstractAuthenticator */
            $Authenticator = new $authenticator('');

            if (!$Authenticator->isCLICompatible()) {
                continue;
            }

            $Authenticator->cliAuthentication($this);

            if (is_null($this->User)) {
                $this->setArgument('username', $Authenticator->getUser()->getName());
                $this->User = $Authenticator->getUser();
            }
        }

        if (
            $this->User === null
            || !QUI::getUsers()->isUser($this->User)
        ) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail'],
                401
            );
        }

        if (QUI::getUsers()->isNobodyUser($this->User)) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail'],
                401
            );
        }

        $User = $this->User;
        $authenticators = $User->getAuthenticators();

        foreach ($authenticators as $Authenticator) {
            if ($Authenticator->isCLICompatible()) {
                $Authenticator->cliAuthentication($this);
            }
        }

        // login
        $Users = QUI::getUsers();
        $userAgent = '';

        $Session = QUI::getSession();
        $Session->set('auth', 1);
        $Session->set('secHash', $Users->getSecHash());
        $Session->set('uid', $User->getUUID());
        $Session->set('inAuthentication', true);

        QUI::getUsers()->login();

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        QUI::getDataBaseConnection()->update(
            $Users->table(),
            [
                'lastvisit' => time(),
                'user_agent' => $userAgent,
                'secHash' => $Users->getSecHash()
            ],
            ['uuid' => $User->getUUID()]
        );
    }

    /**
     * List all tools in the shell for selection
     */
    public function readToolFromShell(): void
    {
        $this->clearMsg();

        $Climate = new CLImate();
        $Climate->cyan()->out("Available Tools");
        $Climate->cyan()->out("===============");

        // build tools
        $tools = $this->get(true);
        ksort($tools);

        $data = [
            ['           Command', 'Description'],
            ['           -------', '-----------'],
            ['', '']
        ];

        foreach ($tools as $Tool) {
            /* @var $Tool Console\Tool */
            $data[] = [
                "\033[" . $this->colors['green'] . "m" . $Tool->getName() . "\033[0m",
                $Tool->getDescription()
            ];
        }

        $Climate->out('');
        $Climate->columns($data);

        $Climate->out('');
        $Climate->out('Please select a tool from the list');
        $Climate->inline("Tool: ");

        $tool = $this->readInput();
        $Exec = false;

        if ($tool === 'exit' || !$tool) {
            $this->writeLn();

            return;
        }

        if (isset($this->tools[$tool])) {
            $Exec = $this->tools[$tool];
        }

        if ($Exec) {
            /* @var $Exec Console\Tool */

            try {
                $this->executeWithTerminalProgress(static function () use ($Exec): void {
                    $Exec->execute();
                });
            } catch (QUI\Exception $Exception) {
                Log::addAlert($Exception->getMessage(), [
                    'type' => 'cron',
                    'tool' => $tool
                ]);

                $this->writeLn($Exception->getMessage(), 'red');
                $this->writeLn();

                return;
            }
        } else {
            $this->writeLn('Command not found!', 'red');
            $this->clearMsg();
        }

        @ob_end_clean();

        $this->writeLn('Would you like any other steps to do?');

        $this->readToolFromShell();
    }

    /**
     * Start the console, if a tool is selected, execute the tool
     */
    public function start(): void
    {
        if (!$this->getArgument('tool')) {
            return;
        }

        if ($Tool = $this->get($this->getArgument('tool'))) {
            try {
                if (is_array($Tool)) {
                    throw new QUI\Exception('Command not found', 404);
                }

                if ($this->getArgument('help')) {
                    $Tool->outputHelp();

                    return;
                }

                $this->executeWithTerminalProgress(static function () use ($Tool): void {
                    $Tool->execute();
                });
            } catch (QUI\Exception $Exception) {
                $this->writeLn($Exception->getMessage(), 'red');
                $this->writeLn();
            }

            return;
        }

        $this->writeLn('Command not found', 'red');
        $this->writeLn();
    }
}
