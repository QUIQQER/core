<?php

/**
 * This file contains \QUI\Session
 */

namespace QUI;

use Memcache;
use Memcached;
use PDO;
use QUI;
use QUI\System\Log;
use RedisArray;
use RedisCluster;
use RedisClusterException;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcachedSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcacheSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

use function array_flip;
use function array_rand;
use function array_unique;
use function class_exists;
use function define;
use function defined;
use function explode;
use function file_exists;
use function headers_sent;
use function implode;
use function in_array;
use function md5;
use function microtime;
use function preg_replace;
use function range;
use function str_starts_with;
use function time;

/**
 * Session handling for QUIQQER
 *
 * based at symfony session handler
 * http://symfony.com/doc/current/components/http_foundation/sessions.html
 */
class Session
{
    private const CLIENT_PROTECTED_KEYS = [
        'uid',
        'secHash',
        'inAuthentication',
        'quiqqer.verified.2fa.mail.code',
        'quiqqer.verifying.2fa.mail.code'
    ];

    private const CLIENT_PROTECTED_KEY_PREFIXES = [
        'auth',
        'quiqqer.security.',
        'session_master_',
        'session_log_'
    ];

    /**
     * Lifetime of the cookie
     */
    public int $lifetime = 1400;

    /**
     * @var array<string, mixed>
     */
    protected array $vars = [];

    private mixed $Session = null;

    private mixed $Storage = null;

    /**
     * Database table
     */
    private readonly string $table;

    /**
     * constructor
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->table = QUI::getDBTableName('sessions');

        if (defined('QUIQQER_SETUP')) {
            $this->Storage = new MockArraySessionStorage();
            $this->Session = new \Symfony\Component\HttpFoundation\Session\Session($this->Storage);
            define('QUIQQER_SESSION_STARTED', 1);
            return;
        }

        // symfony files
        $classNativeSessionStorage = NativeSessionStorage::class;
        $classSession = \Symfony\Component\HttpFoundation\Session\Session::class;
        $symfonyDir = OPT_DIR . 'symfony/http-foundation/';

        // options
        if (QUI::conf('session', 'max_life_time')) {
            $this->lifetime = QUI::conf('session', 'max_life_time');
        }

        $sessionName = QUI::conf('session', 'name');
        $sessionName = preg_replace("/[^a-zA-Z0-9]/", '', $sessionName);

        // If no session name set in the config, generate and set a 5 random character long name
        if (!$sessionName) {
            // Array with uppercase alphabet as values
            $alphabetAsValues = range('A', 'Z');

            // Array with uppercase alphabet as keys
            $alphabetAsKeys = array_flip($alphabetAsValues);

            // Pick 5 random keys (characters) as an array from the alphabet-array
            $randomCharacters = array_rand($alphabetAsKeys, 5);

            // Implode the array of characters to a string
            $sessionName = implode('', $randomCharacters);

            $Config = QUI::getConfig('etc/conf.ini.php');
            $Config->set('session', 'name', $sessionName);
            $Config->save();
        }

        $storageOptions = [
            'cookie_httponly' => true,
            'name' => $sessionName,
            'cookie_lifetime' => $this->lifetime,
            'gc_maxlifetime' => $this->lifetime,
            'cookie_secure' => QUI\Utils\System::isProtocolSecure()
        ];

        // cookie same site
        $sameSite = QUI::conf('cookies', 'sameSite');

        if ($sameSite && QUI\Utils\System::isProtocolSecure()) {
            switch ($sameSite) {
                case 'Lax':
                case 'None':
                case 'Strict':
                    $storageOptions['cookie_samesite'] = $sameSite;
                    break;
            }
        }

        QUI::getEvents()->fireEvent('quiqqerSessionStorageInit', [$this, &$storageOptions]);

        if (!class_exists('NativeSessionStorage')) {
            $fileNativeSessionStorage = $symfonyDir . 'Session/Storage/NativeSessionStorage.php';

            if (!file_exists($fileNativeSessionStorage)) {
                $fileNativeSessionStorage = $symfonyDir . 'Component/HttpFoundation/Session/Storage/NativeSessionStorage.php';
            }

            if (!file_exists($fileNativeSessionStorage)) {
                throw new \Exception(
                    'Session File not found ' . $fileNativeSessionStorage
                );
            }

            // Fallback include for different Symfony package layouts across installations.
            // The fallback path may not exist in every environment.
            self::includeFile($fileNativeSessionStorage);

            if (class_exists($classNativeSessionStorage)) {
                $this->Storage = new $classNativeSessionStorage(
                    $storageOptions,
                    $this->getStorage()
                );
            }
        } else {
            $this->Storage = new NativeSessionStorage(
                $storageOptions,
                $this->getStorage()
            );
        }

        if (!class_exists('NativeSessionStorage')) {
            $fileSession = $symfonyDir . 'Session/Session.php';

            if (!file_exists($fileSession)) {
                $fileSession = $symfonyDir . 'Symfony/Component/HttpFoundation/Session/Session.php';
            }

            if (!file_exists($fileSession)) {
                throw new \Exception('Session File not found ' . $fileSession);
            }

            // Fallback include for different Symfony package layouts across installations.
            // The fallback path may not exist in every environment.
            self::includeFile($fileSession);

            if (class_exists($classSession)) {
                $this->Session = new $classSession($this->Storage);
            }
        } else {
            $this->Session = new \Symfony\Component\HttpFoundation\Session\Session(
                $this->Storage
            );
        }

        if (headers_sent()) {
            $this->Storage = new MockFileSessionStorage();
            $this->Session = new \Symfony\Component\HttpFoundation\Session\Session($this->Storage);
        }

        $this->start();
        define('QUIQQER_SESSION_STARTED', 1);
    }

    /**
     * Set a variable to the session
     *
     * @param string $name - Name og the variable
     * @param mixed $value - value of the variable
     */
    public function set(string $name, mixed $value): void
    {
        if ($this->Session) {
            $this->Session->set($name, $value);
        }
    }

    /**
     * Return the storage type
     *
     * @throws QUI\Exception
     */
    protected function getStorage(): SessionHandlerInterface
    {
        $sessionType = QUI::conf('session', 'type');

        switch ($sessionType) {
            case 'database':
            case 'memcached':
            case 'memcache':
            case 'redis':
                break;

            default:
                return new NativeFileSessionHandler(VAR_DIR . 'sessions');
        }

        // redis sessions
        if ($sessionType === 'redis' && class_exists('RedisArray')) {
            $redisServer = QUI::conf('session_redis');
            $redisCluster = QUI::conf('session_redis_cluster');
            $RedisCluster = null;

            if (!empty($redisCluster['cluster'])) {
                $cluster = explode(',', $redisCluster['cluster']);
                $timeout = null;
                $readTimeout = null;

                $cluster = array_unique($cluster);

                try {
                    $RedisCluster = new RedisCluster(
                        'quiqqer-session',
                        $cluster,
                        $timeout, // @phpstan-ignore-line
                        $readTimeout, // @phpstan-ignore-line
                        false
                    );
                } catch (RedisClusterException $Exception) {
                    Log::addAlert($Exception->getMessage());

                    return new NativeFileSessionHandler(
                        VAR_DIR . 'sessions'
                    );
                }

                return new RedisSessionHandler($RedisCluster);
            }

            if (!empty($redisServer) && !empty($redisServer['server'])) {
                $redisServer = explode(',', $redisServer['server']);

                return new RedisSessionHandler(
                    new RedisArray($redisServer)
                );
            }

            return new RedisSessionHandler(
                new RedisArray(['localhost'])
            );
        }

        // memcached
        if ($sessionType == 'memcached' && class_exists('Memcached')) {
            $memcached_data = QUI::conf('session', 'memcached_data');
            $memcached_data = explode(';', $memcached_data);

            $Memcached = new Memcached('quiqqer-session');

            foreach ($memcached_data as $serverData) {
                $serverData = explode(':', $serverData);

                $server = $serverData[0];
                $port = 11211;

                if (isset($serverData[1]) && is_numeric($serverData[1])) {
                    $port = (int)$serverData[1];
                }

                $Memcached->addServer($server, $port, 1000);
            }

            return new MemcachedSessionHandler($Memcached);
        }

        if ($sessionType == 'memcached' && !class_exists('Memcached')) {
            Log::addWarning('Memcached not installed');
        }

        if ($sessionType == 'memcache' && !class_exists('Memcache')) {
            Log::addWarning('Memcache is not available anymore. Please install Memcached instead.');
        }

        // session via database
        if ($sessionType == 'database') {
            $PDO = QUI::getDataBaseConnection()->getNativeConnection();

            if (!$PDO instanceof PDO) {
                throw new \RuntimeException('Database session storage requires a PDO connection.');
            }

            if ($PDO->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                // SQLite has no advisory locks; transactional locks would interfere with the shared DBAL connection.
                return new NativeFileSessionHandler(VAR_DIR . 'sessions');
            }

            $PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return new PdoSessionHandler($PDO, [
                'db_table' => $this->table,
                'db_id_col' => 'session_id',
                'db_data_col' => 'session_value',
                'db_time_col' => 'session_time',
                'db_lifetime_col' => 'session_lifetime',
                'lock_mode' => PdoSessionHandler::LOCK_ADVISORY
            ]);
        }

        return new NativeFileSessionHandler(VAR_DIR . 'sessions');
    }

    private static function includeFile(string $file): void
    {
        include_once $file;
    }

    /**
     * Start the session
     */
    public function start(): void
    {
        if (!$this->Session) {
            return;
        }

        if ($this->Session->isStarted()) {
            if ($this->check() === false) {
                $this->destroy();
                return;
            }

            $MetaBag = $this->Session->getMetadataBag();

            // workaround for session refresh
            if ($this->lifetime && $MetaBag->getLastUsed() + ($this->lifetime / 2) < time()) {
                $this->refresh();
            }

            return;
        }

        $this->Session->start();
    }

    /**
     * Checks the validity of the session
     */
    public function check(): bool
    {
        if (!$this->Session) {
            return false;
        }

        $idle = time() - $this->Session->getMetadataBag()->getLastUsed();

        if ($idle > $this->lifetime) {
            $this->Session->invalidate();

            return false;
        }

        return true;
    }

    /**
     * Destroy the whole session
     */
    public function destroy(): void
    {
        if (!$this->Session) {
            return;
        }

        $this->Session->clear();
        $this->Session->invalidate();
    }

    /**
     * refresh the session and extend the session time
     */
    public function refresh(): void
    {
        if ($this->Session) {
            $this->Session->migrate();
        }
    }

    /**
     * Regenerate the session ID and invalidate the previous session.
     *
     * Use this when the session crosses a privilege boundary. Unlike
     * refresh(), this prevents the previous session ID from being reused.
     */
    public function regenerate(): bool
    {
        if (!$this->Session) {
            return false;
        }

        return $this->Session->migrate(true);
    }

    /**
     * Session setup
     *
     * @throws \Exception
     */
    public static function setup(): void
    {
        $SchemaManager = QUI::getSchemaManager();
        $tableName = QUI::getDBTableName("sessions");

        if ($SchemaManager->tablesExist([$tableName])) {
            return;
        }

        $Table = new \Doctrine\DBAL\Schema\Table($tableName);
        $Table->addOption("charset", "utf8mb4");
        $Table->addOption("collation", "utf8mb4_general_ci");
        $Table->addColumn("session_id", "string", ["length" => 255]);
        $Table->addColumn("session_value", "text");
        $Table->addColumn("session_time", "integer");
        $Table->addColumn("session_lifetime", "integer");
        $Table->addColumn("uid", "integer", ["notnull" => false]);
        $Table->setPrimaryKey(["session_id"]);

        $SchemaManager->createTable($Table);
    }

    /**
     * returns a variable from the session
     *
     * @param string $name - name of the variable
     *
     * @return mixed
     */
    public function get(string $name): mixed
    {
        if ($this->Session) {
            return $this->Session->get($name, false);
        }

        return false;
    }

    public static function isClientSessionKeyAllowed(string $key): bool
    {
        if (in_array($key, self::CLIENT_PROTECTED_KEYS, true)) {
            return false;
        }

        foreach (self::CLIENT_PROTECTED_KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function getId(): string
    {
        if ($this->Session) {
            return $this->Session->getId();
        }

        return md5(microtime()) . QUI\Utils\Security\Orthos::getPassword();
    }

    /**
     * Delete a session variable
     *
     * @param string $var - name of the variable
     */
    public function del(string $var): void
    {
        if (defined('QUIQQER_SETUP')) {
            return;
        }

        if ($this->Session) {
            $this->Session->remove($var);
        }
    }

    /**
     * Alias for del()
     */
    public function remove(string $var): void
    {
        $this->del($var);
    }

    /**
     * Return the last login from the session-id
     *
     * @param string $sid - Session-ID
     */
    public function getLastRefreshFrom(string $sid): int
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $sessionTime = $Connection->createQueryBuilder()
                ->select($Platform->quoteSingleIdentifier("session_time"))
                ->from($Platform->quoteSingleIdentifier($this->table))
                ->where($Platform->quoteSingleIdentifier("session_id") . " = :sessionId")
                ->setParameter("sessionId", $sid)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception) {
            return 0;
        }

        if ($sessionTime === false) {
            return 0;
        }

        return (int)$sessionTime;
    }

    /**
     * Is the user online?
     *
     * @todo is not working
     */
    public function isUserOnline(int|string $uid): bool
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $result = $Connection->createQueryBuilder()
                ->select("1")
                ->from($Platform->quoteSingleIdentifier($this->table))
                ->where($Platform->quoteSingleIdentifier("uid") . " = :uid")
                ->setParameter("uid", $uid)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $result !== false;
    }

    public function getSymfonySession(): \Symfony\Component\HttpFoundation\Session\Session|false
    {
        return $this->Session;
    }
}
