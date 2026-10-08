<?php

namespace QUI\Users;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User as UserInterface;
use ReflectionProperty;
use Throwable;

class UserDbalLifecycleTest extends TestCase
{
    private const TEST_PREFIX = 'codex-dbal-test-user-';

    private ?UserInterface $previousSessionUser = null;

    public static function setUpBeforeClass(): void
    {
        self::skipIfDatabaseIsUnavailable();
        self::cleanupTestUsers();
    }

    protected function setUp(): void
    {
        self::skipIfDatabaseIsUnavailable();

        $this->previousSessionUser = self::replaceSessionUser(QUI::getUsers()->getSystemUser());
    }

    protected function tearDown(): void
    {
        self::cleanupTestUsers();

        if ($this->previousSessionUser !== null) {
            self::replaceSessionUser($this->previousSessionUser);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupTestUsers();
    }

    public function testUserCanBeCreatedChangedActivatedDeactivatedAndDeleted(): void
    {
        $Users = QUI::getUsers();
        $SystemUser = $Users->getSystemUser();
        $username = self::TEST_PREFIX . uniqid();

        try {
            $User = $Users->createChildWithAttributes([
                'username' => $username,
                'email' => $username . '@example.invalid',
                'firstname' => 'DBAL',
                'lastname' => 'Lifecycle'
            ], $SystemUser);
        } catch (Exception $Exception) {
            if (str_contains($Exception->getMessage(), 'super-user')) {
                self::markTestSkipped('QUIQQER database has no usable super-user fixture.');
            }

            throw $Exception;
        }

        $this->assertSame($username, $User->getUsername());
        $this->assertFalse($User->isActive());
        $this->assertTrue($Users->usernameExists($username));

        $changedFirstname = 'DBAL Changed';
        $User->setAttribute('firstname', $changedFirstname);
        $User->save($SystemUser);

        $ReloadedUser = $Users->get($User->getUUID());
        $this->assertSame($changedFirstname, $ReloadedUser->getAttribute('firstname'));

        $ReloadedUser->setPassword('codex-dbal-test-password', $SystemUser);
        $this->assertSame(1, $ReloadedUser->activate('', $SystemUser));
        $this->assertTrue($Users->get($ReloadedUser->getUUID())->isActive());

        $this->assertTrue($ReloadedUser->deactivate($SystemUser));
        $this->assertFalse($Users->get($ReloadedUser->getUUID())->isActive());

        $ReloadedUser->addAddress([
            'firstname' => 'DBAL',
            'lastname' => 'Address',
            'mail' => 'dbal-address@example.invalid',
            'country' => 'DE'
        ], $SystemUser);

        $this->assertGreaterThan(0, self::countAddresses($ReloadedUser->getUUID()));

        $this->assertTrue($Users->deleteUser($ReloadedUser->getUUID()));
        $this->assertFalse($Users->usernameExists($username));
        $this->assertSame(0, self::countAddresses($ReloadedUser->getUUID()));
    }

    public function testSavingUnrelatedAttributeDoesNotOverwriteGroupsChangedInDatabase(): void
    {
        $Users = QUI::getUsers();
        $SystemUser = $Users->getSystemUser();
        $username = self::TEST_PREFIX . uniqid();

        $User = $Users->createChildWithAttributes([
            'username' => $username,
            'email' => $username . '@example.invalid',
            'firstname' => 'Stale',
            'lastname' => 'Groups'
        ], $SystemUser);

        // Simulate another request assigning a group after this User instance
        // was loaded. The UUID does not need a group fixture because this test
        // verifies that an unrelated save leaves the database column untouched.
        $groupsChangedByAnotherRequest = ',1,concurrent-group,';
        self::getConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
            ['usergroup' => $groupsChangedByAnotherRequest],
            ['uuid' => $User->getUUID()]
        );

        $User->setAttribute('firstname', 'Changed without groups');
        $User->save($SystemUser);

        $groupsAfterSave = self::getConnection()
            ->createQueryBuilder()
            ->select('usergroup')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where('uuid = :uuid')
            ->setParameter('uuid', $User->getUUID())
            ->executeQuery()
            ->fetchOne();

        $this->assertSame($groupsChangedByAnotherRequest, $groupsAfterSave);
    }

    public function testUploadDevicesSurviveStaleUserSaveAndCannotBeForgedOrExported(): void
    {
        $System = QUI::getUsers()->getSystemUser();
        $username = self::TEST_PREFIX . uniqid();
        $User = QUI::getUsers()->createChildWithAttributes([
            'username' => $username,
            'email' => $username . '@example.invalid'
        ], $System);
        $uuid = (string)$User->getUUID();
        $Devices = new QUI\Upload\MobileUpload\Devices();
        $token = bin2hex(random_bytes(32));
        $Devices->register($uuid, $token, 'Test phone', 'Test browser', time());
        $registered = $Devices->get($uuid);

        $User->setAttribute(QUI\Upload\MobileUpload\Devices::ATTRIBUTE, ['forged' => true]);
        $User->setAttribute('firstname', 'Saved with protected devices');
        $User->save($System);
        self::assertSame($registered, $Devices->get($uuid));

        $User->refresh();
        self::assertSame('Saved with protected devices', $User->getAttribute('firstname'));
        $export = json_encode($User->getAttributes(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(hash('sha256', $token), $export);
        self::assertStringNotContainsString(QUI\Upload\MobileUpload\Devices::ATTRIBUTE, $export);

        $Devices->edit($uuid, $Devices->listing($uuid)[0]['id'], null);
        $User->save($System);
        self::assertSame([], $Devices->get($uuid));
    }

    private static function skipIfDatabaseIsUnavailable(): void
    {
        try {
            $Connection = self::getConnection();
            $Connection->executeQuery(
                'SELECT 1 FROM ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::table()) . ' LIMIT 1'
            )->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER database is not available: ' . $Exception->getMessage());
        }

        self::skipIfSystemHasNoSuperUser();
    }

    private static function skipIfSystemHasNoSuperUser(): void
    {
        $superUsers = QUI::getUsers()->getUsers([
            'where' => [
                'su' => 1
            ],
            'limit' => 1
        ]);

        if (!isset($superUsers[0])) {
            self::markTestSkipped('QUIQQER database has no usable super-user fixture.');
        }
    }

    private static function getConnection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private static function countAddresses(string | int $userUuid): int
    {
        return (int)self::getConnection()
            ->createQueryBuilder()
            ->select('COUNT(id)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::tableAddress()))
            ->where('userUuid = :userUuid')
            ->setParameter('userUuid', $userUuid)
            ->executeQuery()
            ->fetchOne();
    }

    private static function cleanupTestUsers(): void
    {
        try {
            $Connection = self::getConnection();
            $Platform = $Connection->getDatabasePlatform();
            $usersTable = QUI\Utils\Doctrine::quoteIdentifier(Manager::table());

            $rows = $Connection->createQueryBuilder()
                ->select('id', 'uuid')
                ->from($usersTable)
                ->where('username LIKE :username')
                ->setParameter('username', self::TEST_PREFIX . '%')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $Connection->delete(
                    QUI\Utils\Doctrine::quoteIdentifier(Manager::tableAddress()),
                    ['userUuid' => $row['uuid']]
                );

                $Connection->delete(
                    QUI\Utils\Doctrine::quoteIdentifier(QUI\Workspace\Manager::table()),
                    ['uid' => $row['uuid']]
                );

                $Connection->delete(
                    QUI\Utils\Doctrine::quoteIdentifier(QUI\Workspace\Manager::table()),
                    ['uid' => $row['id']]
                );
            }

            $Connection->createQueryBuilder()
                ->delete($usersTable)
                ->where($Platform->quoteSingleIdentifier('username') . ' LIKE :username')
                ->setParameter('username', self::TEST_PREFIX . '%')
                ->executeStatement();
        } catch (Throwable) {
            // The availability check reports DB problems. Cleanup should not hide the test result.
        }
    }

    private static function replaceSessionUser(UserInterface $User): ?UserInterface
    {
        $Users = QUI::getUsers();
        $Property = new ReflectionProperty($Users, 'Session');
        $Property->setAccessible(true);

        $PreviousUser = $Property->getValue($Users);
        $Property->setValue($Users, $User);

        return $PreviousUser instanceof UserInterface ? $PreviousUser : null;
    }
}
