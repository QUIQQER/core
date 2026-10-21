<?php

namespace QUI\Users;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User as UserInterface;
use ReflectionProperty;
use Throwable;

class UserAddressSaveEventTest extends TestCase
{
    private const TEST_PREFIX = 'codex-address-event-test-user-';

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

    public function testCreateChildDoesNotSaveUserAddressFiveTimes(): void
    {
        $Users = QUI::getUsers();
        $SystemUser = $Users->getSystemUser();
        $username = self::TEST_PREFIX . uniqid();
        $addressSaveBeginCalls = 0;
        $listener = static function (Address $Address, UserInterface $User) use (&$addressSaveBeginCalls, $username): void {
            if ($User->getUsername() === $username) {
                $addressSaveBeginCalls++;
            }
        };

        QUI::getEvents()->addEvent(
            'onUserAddressSaveBegin',
            $listener
        );

        try {
            $User = $Users->createChild($username, $SystemUser);
        } catch (Exception $Exception) {
            self::cleanupTestUsers();

            if (str_contains($Exception->getMessage(), 'super-user')) {
                self::markTestSkipped('QUIQQER database has no usable super-user fixture.');
            }

            throw $Exception;
        }

        try {
            $Address = $User->getStandardAddress();

            $this->assertNotNull($Address);
            $this->assertSame($Address->getUUID(), $User->getAttribute('address'));
            $this->assertLessThanOrEqual(2, $addressSaveBeginCalls);
        } finally {
            QUI::getEvents()->removeEvent('onUserAddressSaveBegin', $listener);
            self::cleanupTestUsers();
        }
    }

    public function testAddressStateCanBeCreatedLoadedUpdatedAndCleared(): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChild(self::TEST_PREFIX . uniqid(), $SystemUser);
        $Address = $User->addAddress([
            'city' => 'Montréal',
            'state' => 'Québec',
            'country' => 'CA'
        ], $SystemUser);
        $id = $Address->getUUID();
        self::assertNotNull($id);
        $Reloaded = new Address($User, $id);
        self::assertSame('Québec', $Reloaded->getAttribute('state'));

        $Reloaded->setAttribute('city', 'Québec City');
        $Reloaded->save($SystemUser);
        self::assertSame('Québec', (new Address($User, $id))->getAttribute('state'));

        $Reloaded->setAttribute('state', 'Ontario');
        $Reloaded->save($SystemUser);
        self::assertSame('Ontario', (new Address($User, $id))->getAttribute('state'));

        $Reloaded->setAttribute('state', '');
        $Reloaded->save($SystemUser);
        self::assertSame('', (new Address($User, $id))->getAttribute('state'));
        self::assertSame('', $User->addAddress(['city' => 'Berlin'], $SystemUser)->getAttribute('state'));
    }

    public function testAddressStateIsIncludedInTextAndEscapedInHtml(): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChild(self::TEST_PREFIX . uniqid(), $SystemUser);
        $Address = $User->addAddress(['city' => 'Montréal', 'state' => 'Québec'], $SystemUser);
        self::assertStringContainsString('Montréal Québec', $Address->getText());
        self::assertStringContainsString('<div class="region">Québec</div>', $Address->getDisplay());

        $Address->setAttribute('state', '<img src=x onerror=alert(1)> & Québec');
        $html = $Address->getDisplay();
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringContainsString('&amp; Québec', $html);
        self::assertStringNotContainsString('<img', $html);

        $Address->setAttribute('state', '');
        self::assertStringNotContainsString('class="region"', $Address->getDisplay());
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
