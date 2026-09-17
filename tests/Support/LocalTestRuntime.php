<?php

namespace QUITests\Support;

use Doctrine\DBAL\DriverManager;
use QUI;
use ReflectionProperty;
use RuntimeException;

/** Keeps local tests away from the installation's database and writable files. */
final class LocalTestRuntime
{
    private static ?string $directory = null;
    private static ?int $owner = null;

    public static function prepare(): void
    {
        if (DatabaseEnvironment::usesCiDatabase()) {
            return;
        }

        foreach (['CMS_DIR', 'ETC_DIR', 'VAR_DIR', 'USR_DIR'] as $constant) {
            if (defined($constant)) {
                throw new RuntimeException('Local tests must initialize their runtime before QUIQQER.');
            }
        }

        $source = dirname(__DIR__, 5) . '/';
        $config = parse_ini_file($source . 'etc/conf.ini.php', true);
        if ($config === false) {
            throw new RuntimeException('Cannot read the QUIQQER configuration for local tests.');
        }

        $inherited = getenv('QUIQQER_CORE_TEST_RUNTIME');
        $directory = $inherited ?: sys_get_temp_dir() . '/quiqqer-core-runtime-' . bin2hex(random_bytes(16)) . '/';
        self::$directory = $directory;
        if ($inherited === false) {
            if (!mkdir($directory, 0700)) {
                throw new RuntimeException('Cannot create the local test runtime.');
            }
            $owner = getmypid();
            if ($owner === false) {
                throw new RuntimeException('Cannot determine the test runtime owner.');
            }
            self::$owner = $owner;
            register_shutdown_function(static function (): void {
                self::registerCleanup();
            });
            self::copyDirectory($source . 'etc/', $directory . 'etc/');
            self::copyDirectory($config['globals']['var_dir'] . 'locale/', $directory . 'var/locale/');
            foreach (['var/composer', 'usr', 'media'] as $path) {
                mkdir($directory . $path, 0700, true);
            }
            foreach (['composer.json', 'composer.lock', 'composer.phar'] as $file) {
                copy($config['globals']['var_dir'] . 'composer/' . $file, $directory . 'var/composer/' . $file);
            }
            if (!symlink($config['globals']['opt_dir'], $directory . 'packages')) {
                throw new RuntimeException('Cannot expose installed packages to the local test runtime.');
            }
            file_put_contents($directory . 'etc/projects.ini.php', ";<?php exit; ?>\n");
            $bootstrap = var_export(dirname(__DIR__) . '/runtime-bootstrap.php', true);
            file_put_contents($directory . 'bootstrap.php', "<?php require " . $bootstrap . ";\n");
            file_put_contents($directory . 'console', "<?php\ndefine('QUIQQER_SYSTEM', true);\nrequire " . $bootstrap
                . ";\ndefine('QUIQQER_CONSOLE', true);\n(new \\QUI\\System\\Console())->start();\n");
            putenv('QUIQQER_CORE_TEST_RUNTIME=' . $directory);
        } elseif (!is_file($directory . 'etc/conf.ini.php') || !is_file($directory . 'var/bootstrap.sqlite')) {
            throw new RuntimeException('The parent test runtime is unavailable.');
        }

        define('CMS_DIR', $directory);
        define('ETC_DIR', $directory . 'etc/');
        define('VAR_DIR', $directory . 'var/');
        define('USR_DIR', $directory . 'usr/');
        define('OPT_DIR', $directory . 'packages/');
        define('LIB_DIR', OPT_DIR . 'quiqqer/core/src/');


        ini_set('error_log', VAR_DIR . 'php-errors.log');
        require_once dirname(__DIR__, 2) . '/src/autoload.php';
        // Direct Watcher calls also bypass the event isolation; its tables are not part of the Core fixtures.
        if (class_exists(\QUI\Watcher::class)) {
            \QUI\Watcher::$globalWatcherDisable = true;
        }

        if (self::$owner !== null) {
            $Config = new QUI\Config(ETC_DIR . 'conf.ini.php');
            foreach (
                ['cms_dir' => CMS_DIR, 'var_dir' => VAR_DIR, 'usr_dir' => USR_DIR, 'opt_dir' => OPT_DIR,
                'system_changed' => 0, 'root' => '00000000-0000-4000-8000-000000000002',
                'rootuser' => '00000000-0000-4000-8000-000000000011'] as $key => $value
            ) {
                $Config->setValue('globals', $key, $value);
            }
            $Config->set('db', [
                'driver' => 'pdo_sqlite', 'path' => VAR_DIR . 'bootstrap.sqlite', 'prfx' => 'test_'
            ]);
            $Config->save();
            file_put_contents(ETC_DIR . 'cache.ini.php', ";<?php exit; ?>\n[handlers]\nfilesystem = 1\n");
        }
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => VAR_DIR . 'bootstrap.sqlite']);
        $Connection->executeStatement('PRAGMA busy_timeout = 10000');
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
        require_once __DIR__ . '/LocalPackageManager.php';
        QUI::$PackageManager = new LocalPackageManager();
        // Boot the framework without starting installed applications against empty fixtures.
        QUI::$Events = new class extends QUI\Events\Manager {
            public function __construct()
            {
                $this->Events = new QUI\Events\Event();
            }

            public function fireEvent(string $event, false|array $args = false, bool $force = false): array
            {
                return [];
            }
        };
    }

    public static function finishBootstrap(): void
    {
        if (self::$directory !== null) {
            if (self::$owner !== null) {
                self::createFixtures();
            }
            QUI\Utils\System\File::mkdir(VAR_DIR . 'log/');
            QUI\Log\ErrorHandler::attach();
            require_once __DIR__ . '/LocalEvents.php';
            QUI::$Events = new LocalEvents();
            $Events = QUI::getEvents();

            foreach ($Events->getList() as $name => $listeners) {
                foreach ($listeners as $listener) {
                    if (!in_array($listener['package'], ['quiqqer/core'], true)) {
                        $Events->removeEvent($name, $listener['callable']);
                    }
                }
            }
        }
    }

    private static function createFixtures(): void
    {
        QUI\Update::importDatabase(OPT_DIR . 'quiqqer/core/database.xml');
        QUI\Update::importDatabase(OPT_DIR . 'quiqqer/translator/database.xml');
        QUI\Update::importPermissions(OPT_DIR . 'quiqqer/core/permissions.xml', 'quiqqer/core');
        $Connection = QUI::getDataBaseConnection();
        foreach ([0 => 'Nobody', 5 => 'System', 11 => 'Root'] as $id => $name) {
            $Connection->insert(QUI\Users\Manager::table(), [
                'id' => $id, 'uuid' => $id === 11 ? (string)QUI::conf('globals', 'rootuser') : (string)$id, 'username' => $name, 'active' => 1,
                'su' => $id === 0 ? 0 : 1, 'usergroup' => $id === 11 ? ',' . QUI::conf('globals', 'root') . ',' : '', 'lang' => 'de'
            ]);
        }
        foreach ([0 => 'Guest', 1 => 'Everyone', 2 => 'Root'] as $id => $name) {
            $Connection->insert(QUI\Groups\Manager::table(), [
                'id' => $id, 'uuid' => $id === 2 ? (string)QUI::conf('globals', 'root') : (string)$id, 'name' => $name, 'active' => 1, 'parent' => '0'
            ]);
        }
        QUI\Projects\ProjectTestHelper::runAsSystemUser(static function (): void {
            QUI\Projects\Manager::createProject('localtest', 'de', ['de', 'en']);
        });
        $Connection->transactional(static function (): void {
            QUI\Translator::batchImportFromPackage(QUI::getPackage('quiqqer/core'));
        });
        QUI\Translator::publish('quiqqer/core');
    }

    private static function registerCleanup(): void
    {
        if (self::$directory === null || self::$owner !== getmypid()) {
            return;
        }

        $directory = self::$directory;
        register_shutdown_function(static function () use ($directory): void {
            self::removeDirectory($directory);
        });
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
            throw new RuntimeException('Cannot create a test fixture directory.');
        }
        foreach (new \DirectoryIterator($source) as $Entry) {
            if ($Entry->isDot()) {
                continue;
            }
            // Never copy databases, sockets or links into the local runtime.
            if ($Entry->isLink() || in_array($Entry->getFilename(), ['database', 'localefiles'], true)) {
                continue;
            }
            if ($Entry->isDir()) {
                self::copyDirectory($Entry->getPathname(), $destination . $Entry->getFilename() . '/');
            } elseif ($Entry->isFile() && !copy($Entry->getPathname(), $destination . $Entry->getFilename())) {
                throw new RuntimeException('Cannot copy a local test fixture.');
            }
        }
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $Entry) {
            if ($Entry->isDot()) {
                continue;
            }
            if ($Entry->isDir() && !$Entry->isLink()) {
                self::removeDirectory($Entry->getPathname());
            } else {
                unlink($Entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
