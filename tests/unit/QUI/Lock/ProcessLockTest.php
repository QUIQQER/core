<?php

namespace QUI\Lock;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\FlockStore;

class ProcessLockTest extends TestCase
{
    private string $directory;
    private mixed $originalConfig;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-process-lock-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->originalConfig = QUI::$Conf;
        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturn(false);
        QUI::$Conf = $Config;
        Locker::setProcessLockStore(new FlockStore($this->directory));
    }

    protected function tearDown(): void
    {
        Locker::setProcessLockStore(null);
        QUI::$Conf = $this->originalConfig;

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testIndependentOwnersContendWhileDifferentKeysRemainAvailable(): void
    {
        $First = Locker::createProcessLock('image/42');
        $Second = Locker::createProcessLock('image/42');
        $Other = Locker::createProcessLock('image/43');

        try {
            self::assertTrue($First->acquire());
            self::assertFalse($Second->acquire());
            self::assertTrue($Other->acquire());
            $Second->release();
            self::assertTrue($First->isAcquired(), 'A different owner must not release the lock.');
            $First->release();
            self::assertTrue($Second->acquire());
        } finally {
            $First->release();
            $Second->release();
            $Other->release();
        }
    }

    public function testFilesystemLockDoesNotExpireDuringInstallationChanges(): void
    {
        $First = Locker::createFilesystemLock('system-maintenance', $this->directory);
        $Second = Locker::createFilesystemLock('system-maintenance', $this->directory);

        try {
            self::assertTrue($First->acquire());
            self::assertNull($First->getRemainingLifetime());
            self::assertFalse($Second->acquire());
            $First->release();
            self::assertTrue($Second->acquire());
        } finally {
            $First->release();
            $Second->release();
        }
    }

    public function testCallbackReceivesTheLockAndItsResultIsReturned(): void
    {
        $result = Locker::synchronized('result', static function (LockInterface $Lock): array {
            self::assertTrue($Lock->isAcquired());
            $Lock->refresh();
            return ['answer' => 42];
        });

        self::assertSame(['answer' => 42], $result);
        $Lock = Locker::createProcessLock('result');
        self::assertTrue($Lock->acquire());
        $Lock->release();
    }

    public function testCallbackExceptionIsPreservedAndLockReleased(): void
    {
        $Failure = new \RuntimeException('Generation failed');

        try {
            Locker::synchronized('failure', static function () use ($Failure): never {
                throw $Failure;
            });
            self::fail('The callback exception must propagate.');
        } catch (\RuntimeException $Exception) {
            self::assertSame($Failure, $Exception);
        }

        self::assertSame('retry', Locker::synchronized('failure', static fn() => 'retry', timeout: 0));
    }

    public function testTimeoutDoesNotExecuteTheCallbackOrReleaseAnotherOwner(): void
    {
        $Owner = Locker::createProcessLock('busy');
        self::assertTrue($Owner->acquire());
        $called = false;
        $start = hrtime(true);

        try {
            Locker::synchronized('busy', static function () use (&$called): void {
                $called = true;
            }, timeout: 0.05);
            self::fail('A held lock must time out.');
        } catch (TimeoutException $Exception) {
            self::assertSame(503, $Exception->getCode());
            self::assertFalse($called);
            self::assertTrue($Owner->isAcquired());
            self::assertGreaterThanOrEqual(0.04, (hrtime(true) - $start) / 1e9);
            self::assertLessThan(2, (hrtime(true) - $start) / 1e9);
        } finally {
            $Owner->release();
        }
    }

    public function testBackendFailureDoesNotExecuteTheCallback(): void
    {
        $Store = $this->createMock(PersistingStoreInterface::class);
        $Store->method('save')->willThrowException(new LockStorageException('Backend unavailable'));
        Locker::setProcessLockStore($Store);

        $this->expectException(LockAcquiringException::class);
        Locker::synchronized('unavailable', static function (): never {
            self::fail('Unavailable storage must not allow unprotected execution.');
        });
    }

    public function testLostOwnershipCannotReturnASuccessfulResult(): void
    {
        $this->expectException(LockExpiredException::class);
        Locker::synchronized('lost', static function (LockInterface $Lock): string {
            $Lock->release();
            return 'not protected';
        });
    }

    public function testConfiguredNamespacesDoNotShareLocks(): void
    {
        $namespace = 'installation-a';
        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(
            static function (string $section, ?string $key = null) use (&$namespace): mixed {
                return $section === 'locks' && $key === 'namespace' ? $namespace : false;
            }
        );
        QUI::$Conf = $Config;
        $First = Locker::createProcessLock('same-key');
        self::assertTrue($First->acquire());
        $namespace = 'installation-b';
        $Second = Locker::createProcessLock('same-key');

        try {
            self::assertTrue($Second->acquire());
        } finally {
            $First->release();
            $Second->release();
        }
    }

    public function testDbalStoreCoordinatesIndependentConnections(): void
    {
        $options = ['driver' => 'pdo_sqlite', 'path' => $this->directory . '/locks.sqlite'];
        $FirstStore = new DoctrineDbalStore(DriverManager::getConnection($options));
        $SecondStore = new DoctrineDbalStore(DriverManager::getConnection($options));
        $FirstStore->createTable();
        Locker::setProcessLockStore($FirstStore);
        $First = Locker::createProcessLock('database');
        self::assertTrue($First->acquire());
        Locker::setProcessLockStore($SecondStore);
        $Second = Locker::createProcessLock('database');

        try {
            self::assertFalse($Second->acquire());
            $First->refresh();
            self::assertTrue($First->isAcquired());
            $First->release();
            self::assertTrue($Second->acquire());
            $First->release();
            self::assertTrue($Second->isAcquired());
        } finally {
            $First->release();
            $Second->release();
        }
    }

    public function testConfiguredFlockStoreCanBeRestoredAfterAnOverride(): void
    {
        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(fn(string $section, ?string $key = null): mixed =>
            $section === 'locks' && $key === 'dsn' ? 'flock://' . $this->directory : false);
        QUI::$Conf = $Config;
        Locker::setProcessLockStore(null);
        self::assertSame('configured', Locker::synchronized('config', static fn() => 'configured'));
        self::assertNotEmpty(glob($this->directory . '/*.lock'));
    }

    public function testDefaultStoreUsesTheLockDirectoryOutsideTheCache(): void
    {
        Locker::setProcessLockStore(null);
        $key = 'default-store-' . bin2hex(random_bytes(8));
        self::assertSame('default', Locker::synchronized($key, static fn() => 'default'));
        $IndependentStore = new FlockStore(VAR_DIR . 'locks/');
        $First = Locker::createProcessLock($key);
        self::assertTrue($First->acquire());
        Locker::setProcessLockStore($IndependentStore);
        $Second = Locker::createProcessLock($key);

        try {
            self::assertFalse($Second->acquire());
        } finally {
            $First->release();
            $Second->release();
        }
    }

    public function testConfiguredDbalStoreUsesTheQuiqqerConnectionAndTablePrefix(): void
    {
        $Original = QUI::getDataBaseConnection();
        $Property = new \ReflectionProperty(QUI::class, 'QueryBuilder');
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $Property->setValue(null, $Connection);
        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(fn(string $section, ?string $key = null): mixed =>
            $section === 'locks' && $key === 'dsn' ? 'dbal' : false);
        QUI::$Conf = $Config;
        Locker::setProcessLockStore(null);

        try {
            Locker::synchronized('dbal-config', static function () use ($Connection): void {
                self::assertSame(1, (int)$Connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . QUI::getDBTableName('process_locks')
                ));
            });
            self::assertSame(0, (int)$Connection->fetchOne(
                'SELECT COUNT(*) FROM ' . QUI::getDBTableName('process_locks')
            ));
        } finally {
            Locker::setProcessLockStore(null);
            $Property->setValue(null, $Original);
            $Connection->close();
        }
    }

    public function testExpiredOwnerCannotReleaseItsSuccessor(): void
    {
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Locker::setProcessLockStore(new DoctrineDbalStore($Connection));
        $First = Locker::createProcessLock('lease');
        $Second = Locker::createProcessLock('lease');
        self::assertTrue($First->acquire());

        try {
            $Connection->executeStatement('UPDATE lock_keys SET key_expiration = 0');
            self::assertTrue($Second->acquire());
            self::assertFalse($First->isAcquired());
            $First->release();
            self::assertTrue($Second->isAcquired());
        } finally {
            $First->release();
            $Second->release();
            $Connection->close();
        }
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejected(string $key, float $timeout, float $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Locker::synchronized($key, static fn() => self::fail('Invalid parameters'), $timeout, $ttl);
    }

    public static function invalidParameters(): array
    {
        return [
            ['', 0, 1], ['key', -1, 1], ['key', INF, 1], ['key', NAN, 1],
            ['key', 0, 0], ['key', 0, -1], ['key', 0, INF], ['key', 0, NAN]
        ];
    }

    #[DataProvider('unsafeBackends')]
    public function testInvalidBackendCannotFallBackToUnprotectedExecution(string $dsn): void
    {
        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(fn(string $section, ?string $key = null): mixed =>
            $section === 'locks' && $key === 'dsn' ? $dsn : false);
        QUI::$Conf = $Config;
        Locker::setProcessLockStore(null);
        $this->expectException(\InvalidArgumentException::class);
        Locker::synchronized('invalid', static fn() => self::fail('Invalid backend'));
    }

    public static function unsafeBackends(): array
    {
        return [['null'], ['in-memory'], ['unsupported-backend']];
    }
}
