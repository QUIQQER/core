<?php

/**
 * this file contains QUI\Lock\Locker
 */

namespace QUI\Lock;

use QUI;
use QUI\Package\Package;
use Stash\Interfaces\ItemInterface;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\StoreFactory;

use function is_null;
use function time;

/**
 * Class Lock
 * Helps to lock an item or an object
 */
class Locker
{
    private static ?LockFactory $ProcessLockFactory = null;
    private static ?EditingLocks $EditingLocks = null;

    /**
     * Installation changes must remain locked while configuration, caches and database schemas change.
     * This filesystem lock has no lease to expire while a child process is blocked.
     */
    public static function createFilesystemLock(string $key, string $directory): LockInterface
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A process lock key is required.');
        }

        $Factory = new LockFactory(new FlockStore($directory));

        return $Factory->createLock('quiqqer-process-' . hash('sha256', $key), null);
    }

    /**
     * Override the process lock backend during application bootstrap.
     * Passing null restores the backend configured in conf.ini.php [locks].
     * Configure the same shared store on all workers before acquiring locks.
     */
    public static function setProcessLockStore(?PersistingStoreInterface $Store): void
    {
        self::$ProcessLockFactory = $Store === null ? null : new LockFactory($Store);
        self::$EditingLocks = null;
    }

    public static function editing(): EditingLocks
    {
        if (self::$EditingLocks !== null) {
            return self::$EditingLocks;
        }

        $dsn = QUI::conf('locks', 'dsn') ?: 'flock';
        $namespace = 'editing-' . hash('sha256', QUI::conf('locks', 'namespace') ?: CMS_DIR);

        if ($dsn === 'flock' || str_starts_with($dsn, 'flock://')) {
            $path = $dsn === 'flock' ? VAR_DIR . 'locks/' : substr($dsn, 8);
            $Store = new \Symfony\Component\Cache\Adapter\FilesystemAdapter($namespace, 0, $path . '/editing');
        } elseif ($dsn === 'dbal') {
            $Store = new EditingDbalAdapter(
                QUI::getDataBaseConnection(),
                $namespace,
                0,
                ['db_table' => QUI::getDBTableName('editing_locks')]
            );
        } elseif (preg_match('~^rediss?://~', $dsn)) {
            $Store = new \Symfony\Component\Cache\Adapter\RedisAdapter(
                \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection($dsn),
                $namespace
            );
        } else {
            throw new Exception('The configured backend does not support editing locks.', 503);
        }

        $Store->setLogger(new StoreLogger());
        return self::$EditingLocks = new EditingLocks($Store);
    }

    /**
     * Create an independent process lock, without acquiring it.
     * The TTL applies to expiring backends; file locks last until release or process exit.
     * [locks] namespace defaults to CMS_DIR; distributed nodes must use the same namespace.
     */
    public static function createProcessLock(string $key, float $ttl = 300.0): LockInterface
    {
        if ($key === '' || !is_finite($ttl) || $ttl <= 0) {
            throw new \InvalidArgumentException('Process locks require a key and a finite, positive TTL.');
        }

        $namespace = QUI::conf('locks', 'namespace');

        if (!is_string($namespace) || $namespace === '') {
            $namespace = CMS_DIR;
        }

        $resource = 'quiqqer-process-' . hash('sha256', $namespace . "\0" . $key);

        return self::getProcessLockFactory()->createLock($resource, $ttl);
    }

    /**
     * Execute a callback while owning a process lock. Timeout and TTL are seconds.
     * Long-running callbacks must refresh expiring locks before their TTL elapses.
     * Timeout bounds contention retries, not blocking backend I/O or callback execution.
     * For cache generation, recheck the cached value inside the callback.
     * Ownership checks cannot undo side effects performed after a lease expired.
     *
     * @template T
     * @param callable(LockInterface): T $callback
     * @return T
     * @throws TimeoutException
     */
    public static function synchronized(
        string $key,
        callable $callback,
        float $timeout = 10.0,
        float $ttl = 300.0
    ): mixed {
        if (!is_finite($timeout) || $timeout < 0) {
            throw new \InvalidArgumentException('Process lock timeout must be finite and non-negative.');
        }

        $Lock = self::createProcessLock($key, $ttl);
        $deadline = hrtime(true) / 1e9 + $timeout;

        while (!$Lock->acquire()) {
            $remaining = $deadline - hrtime(true) / 1e9;

            if ($remaining <= 0) {
                throw new TimeoutException('Timed out waiting for a process lock.', 503);
            }

            usleep((int)min(50000, ceil($remaining * 1e6)));

            if (hrtime(true) / 1e9 >= $deadline) {
                throw new TimeoutException('Timed out waiting for a process lock.', 503);
            }
        }

        try {
            $result = $callback($Lock);

            if (!$Lock->isAcquired()) {
                throw new LockExpiredException('Process lock ownership was lost during the callback.');
            }

            return $result;
        } finally {
            $Lock->release();
        }
    }

    /**
     * conf.ini.php [locks] dsn: flock (default), flock:///path, dbal, or a Symfony store DSN.
     * Redis DSNs require ext-redis or another supported client and a database separate from cache clears.
     * The dbal store uses the prefixed process_locks table, created on first acquisition if missing.
     * Provision it in advance when DDL is restricted; acquire outside application transactions.
     * Never delete active flock files. Configured backend failures do not fall back to local locks.
     */
    private static function getProcessLockFactory(): LockFactory
    {
        if (self::$ProcessLockFactory !== null) {
            return self::$ProcessLockFactory;
        }

        $dsn = QUI::conf('locks', 'dsn');

        self::$ProcessLockFactory = new LockFactory(self::createProcessLockStore($dsn ?: 'flock'));

        return self::$ProcessLockFactory;
    }

    /** Create a store without replacing the active factory, e.g. for a connection test. */
    public static function createProcessLockStore(string $dsn): PersistingStoreInterface
    {

        if ($dsn === '' || $dsn === 'flock') {
            $Store = new FlockStore(VAR_DIR . 'locks/');
        } elseif ($dsn === 'dbal') {
            $Store = new DoctrineDbalStore(QUI::getDataBaseConnection(), [
                'db_table' => QUI::getDBTableName('process_locks')
            ]);
        } else {
            if (in_array($dsn, ['null', 'in-memory'], true)) {
                throw new \InvalidArgumentException('A shared process lock backend is required.');
            }

            $Store = StoreFactory::createStore($dsn);
        }

        return $Store;
    }

    /**
     * Lock an item or an object and checks the permissions
     *
     * @throws QUI\Permissions\Exception
     * @throws QUI\Lock\Exception
     */
    public static function lockWithPermissions(
        Package $Package,
        string $key,
        string $permission = '',
        null | QUI\Interfaces\Users\User $User = null
    ): void {
        if (is_null($User)) {
            $User = QUI::getUserBySession();
        }

        self::checkLocked($Package, $key, $User);

        if (!empty($permission)) {
            QUI\Permissions\Permission::checkPermission($permission, $User);
        }

        self::lock($Package, $key, false, $User);
    }

    /**
     * @throws QUI\Lock\Exception
     */
    public static function checkLocked(
        Package $Package,
        string $key,
        null | QUI\Interfaces\Users\User $User = null
    ): void {
        if (self::isLocked($Package, $key, $User)) {
            throw new QUI\Lock\Exception('Item is locked');
        }
    }

    /**
     * @param Package $Package
     * @param string $key
     * @param null|QUI\Interfaces\Users\User $User
     * @param bool $considerUser (optional) - Consider a $key as NOT locked if it was created by the given $User [default: true]
     *
     * @return mixed
     */
    public static function isLocked(
        Package $Package,
        string $key,
        null | QUI\Interfaces\Users\User $User = null,
        bool $considerUser = true
    ): mixed {
        if (is_null($User)) {
            $User = QUI::getUserBySession();
        }

        try {
            $uid = self::getStashData(self::getLockKey($Package, $key));

            if ($considerUser && $User->getUUID() == $uid) {
                return false;
            }

            try {
                return QUI::getUsers()->get($uid)->getUUID();
            } catch (QUI\Exception) {
                return $uid;
            }
        } catch (QUI\Lock\Exception) {
        }

        return false;
    }

    /**
     * Return the data from the cache
     *
     * @return mixed|null
     * @throws QUI\Lock\Exception
     */
    protected static function getStashData(string $name): mixed
    {
        $Item = self::getStash($name);
        $data = $Item->get();
        $isMiss = $Item->isMiss();

        if ($isMiss) {
            throw new QUI\Lock\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.cache.manager.not.exist'
                ),
                404
            );
        }

        return $data;
    }

    /**
     * Return the stash item
     *
     * @throws QUI\Lock\Exception
     */
    protected static function getStash(string $name): ItemInterface
    {
        try {
            return QUI\Cache\Manager::getStash($name);
        } catch (\Exception) {
            throw new QUI\Lock\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.cache.manager.not.exist'
                ),
                404
            );
        }
    }

    /**
     * Return the key for the lock item
     *
     * @throws QUI\Lock\Exception
     */
    protected static function getLockKey(Package $Package, string $key): string
    {
        if (empty($key)) {
            throw new QUI\Lock\Exception('Lock::lock() need a string as key');
        }

        return 'lock/' . $Package->getName() . '_' . $key;
    }

    /**
     * Lock an item or an object
     * no permission check
     *
     * @throws QUI\Lock\Exception
     */
    public static function lock(
        Package $Package,
        string $key,
        bool | int $lifetime = false,
        null | QUI\Interfaces\Users\User $User = null
    ): void {
        if (is_null($User)) {
            $User = QUI::getUserBySession();
        }

        $name = self::getLockKey($Package, $key);
        $value = $User->getUUID();

        if (!$lifetime) {
            $lifetime = (int)QUI::conf('session', 'max_life_time');
        }

        $Item = self::getStash($name);
        $Item->expiresAfter((int)$lifetime);
        $Item->set($value);
        $Item->save();
    }

    /**
     * Unlock an item or an object and checks the permissions
     *
     * @throws QUI\Permissions\Exception
     * @throws QUI\Lock\Exception
     */
    public static function unlockWithPermissions(
        Package $Package,
        string $key,
        string $permission = '',
        null | QUI\Interfaces\Users\User $User = null
    ): void {
        if (is_null($User)) {
            $User = QUI::getUserBySession();
        }

        $locked = self::isLocked($Package, $key, $User);

        if ($locked === false) {
            return;
        }

        if (!empty($permission)) {
            QUI\Permissions\Permission::checkPermission($permission, $User);
            self::unlock($Package, $key);

            return;
        }

        if (
            $User->isSU()
            || QUI::getUsers()->isSystemUser($User)
            || $locked === $User->getUUID()
            || (!empty($locked['id']) && $locked['id'] === $User->getUUID())
        ) {
            self::unlock($Package, $key);
        }
    }

    /**
     * Unlock an item or an object
     * no permission check
     *
     * @throws QUI\Lock\Exception
     */
    public static function unlock(Package $Package, string $key): void
    {
        $Item = self::getStash(self::getLockKey($Package, $key));
        $Item->clear();
    }

    /**
     * Return the seconds from the last lock
     *
     * @throws QUI\Lock\Exception
     */
    public static function getLockTime(Package $Package, string $key): int
    {
        $Item = self::getStash(self::getLockKey($Package, $key));
        $Expire = $Item->getExpiration();

        return time() - $Expire->getTimestamp();
    }
}
