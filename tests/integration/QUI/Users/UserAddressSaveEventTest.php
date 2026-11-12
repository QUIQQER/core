<?php

namespace QUI\Users;

use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('standardAddressReferenceFormats')]
    public function testSavingUserPreservesNewStandardAddressData(bool $useNumericId): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = $this->createAddressUser();
        $Address = $User->addAddress(['city' => 'Berlin'], $SystemUser);
        $uuid = $Address->getUUID();
        self::assertNotNull($uuid);

        $Address->addPhone([
            'type' => 'mobile',
            'no' => '111'
        ]);
        $Address->addMail('address-cache@example.invalid');
        $Address->setAttribute('city', 'Bonn');
        $Address->save($SystemUser);

        $StoredAddress = new Address($User, $uuid);
        self::assertSame($Address->getPhoneList(), $StoredAddress->getPhoneList());
        self::assertSame('Bonn', $StoredAddress->getAttribute('city'));

        $User->setAttribute('address', $useNumericId ? $Address->getId() : $uuid);
        $User->save($SystemUser);

        $ReloadedUser = new User($User->getUUID());
        $ReloadedAddress = $ReloadedUser->getStandardAddress();
        self::assertSame($uuid, $ReloadedUser->getAttribute('address'));
        self::assertSame([
            [
                'type' => 'mobile',
                'no' => '111'
            ]
        ], $ReloadedAddress->getPhoneList());
        self::assertSame(['address-cache@example.invalid'], $ReloadedAddress->getMailList());
        self::assertSame('Bonn', $ReloadedAddress->getAttribute('city'));
        self::assertSame($Address, $User->getStandardAddress());
    }

    #[DataProvider('standardAddressReferenceFormats')]
    public function testAddressLookupsSharePendingChangesAcrossIdentifiers(bool $useNumericId): void
    {
        $User = $this->createAddressUser();
        $CreatedAddress = $User->addAddress(['city' => 'Berlin'], QUI::getUsers()->getSystemUser());
        $id = $CreatedAddress->getId();
        $uuid = $CreatedAddress->getUUID();
        self::assertNotNull($uuid);

        $ReloadedUser = new User($User->getUUID());
        $Address = $ReloadedUser->getAddress($useNumericId ? $id : $uuid);
        $Address->setAttribute('city', 'Bonn');
        $Address->addPhone([
            'type' => 'mobile',
            'no' => '111'
        ]);

        $OtherLookup = $ReloadedUser->getAddress($useNumericId ? $uuid : $id);
        self::assertSame($Address, $OtherLookup);
        self::assertSame('Bonn', $OtherLookup->getAttribute('city'));
        self::assertSame($Address->getPhoneList(), $OtherLookup->getPhoneList());
        self::assertSame($Address, $ReloadedUser->getAddress((string)$id));
        self::assertSame($Address, $ReloadedUser->getAddress('00' . $id));

        $addresses = $ReloadedUser->getAddressList();
        self::assertCount(2, $addresses);
        self::assertSame($Address, $addresses[$uuid]);

        $ReloadedUser->setAttribute('address', $id);
        self::assertSame($Address, $ReloadedUser->getStandardAddress());
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

    public static function invalidStandardReferences(): iterable
    {
        yield 'missing UUID' => ['missing-uuid'];
        yield 'foreign UUID' => ['foreign-uuid'];
        yield 'missing numeric ID' => ['missing-id'];
        yield 'foreign numeric ID' => ['foreign-id'];
    }

    #[DataProvider('invalidStandardReferences')]
    public function testInvalidStoredStandardAddressIsRecoveredWithoutSavingAsNobody(string $referenceType): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = $this->createAddressUser();
        $OwnAddress = $User->getStandardAddress();
        $invalidReference = $this->createInvalidReference($referenceType);
        self::getConnection()->update(
            Manager::table(),
            ['address' => $invalidReference],
            ['uuid' => $User->getUUID()]
        );
        $User = new User($User->getUUID());

        [$Address, $Handler] = $this->captureAddressLogs(
            static fn (): Address => QUI::getUsers()->withSessionUser(
                QUI::getUsers()->getNobody(),
                static fn (): Address => $User->getStandardAddress()
            )
        );

        self::assertSame($OwnAddress->getUUID(), $Address->getUUID());
        self::assertSame($OwnAddress->getUUID(), $User->getAttribute('address'));
        self::assertSame((string)$invalidReference, (string)$this->getStoredStandardAddress($User));
        self::assertCount(1, $User->getAddressList());
        self::assertFalse($Handler->hasRecords(Level::Error));

        $User->save($SystemUser);

        $ReloadedUser = new User($User->getUUID());
        self::assertSame($OwnAddress->getUUID(), $this->getStoredStandardAddress($User));
        self::assertSame($OwnAddress->getUUID(), $ReloadedUser->getStandardAddress()->getUUID());
    }

    #[DataProvider('invalidStandardReferences')]
    public function testExplicitInvalidAddressLookupDoesNotChangeTheStandardAddress(string $referenceType): void
    {
        $User = $this->createAddressUser();
        $OwnAddress = $User->getStandardAddress();
        $invalidReference = $this->createInvalidReference($referenceType);

        try {
            $User->getAddress($invalidReference);
            self::fail('An explicit missing or foreign address must be rejected.');
        } catch (AddressNotFoundException) {
            self::assertSame($OwnAddress->getUUID(), $User->getAttribute('address'));
            self::assertSame($OwnAddress->getUUID(), $this->getStoredStandardAddress($User));
        }
    }

    #[DataProvider('invalidStandardReferences')]
    public function testExplicitInvalidStandardAddressAssignmentIsRejected(string $referenceType): void
    {
        $User = $this->createAddressUser();
        $OwnAddress = $User->getStandardAddress();
        $invalidReference = $this->createInvalidReference($referenceType);

        try {
            $User->setAttribute('address', $invalidReference);
            self::fail('An invalid explicit standard address must be rejected.');
        } catch (AddressNotFoundException) {
            self::assertSame($OwnAddress, $User->getStandardAddress());
            self::assertSame($OwnAddress->getUUID(), $User->getAttribute('address'));
            self::assertSame($OwnAddress->getUUID(), $this->getStoredStandardAddress($User));
        }
    }

    public function testMissingStandardAddressWithoutFallbackIsOnlyRecreatedDuringAuthorizedSave(): void
    {
        $User = $this->createAddressUser();
        $missingReference = $User->getAttribute('address');
        self::getConnection()->delete(Manager::tableAddress(), ['userUuid' => $User->getUUID()]);
        $User = new User($User->getUUID());

        try {
            QUI::getUsers()->withSessionUser(
                QUI::getUsers()->getNobody(),
                static fn (): Address => $User->getStandardAddress()
            );
            self::fail('A missing fallback must not create an address while reading.');
        } catch (AddressNotFoundException) {
            self::assertSame([], $User->getAddressList());
            self::assertSame($missingReference, $this->getStoredStandardAddress($User));
        }

        try {
            QUI::getUsers()->withSessionUser(
                QUI::getUsers()->getNobody(),
                static fn () => $User->save()
            );
            self::fail('An anonymous caller must not save the recovered reference.');
        } catch (QUI\Permissions\Exception) {
            self::assertSame([], $User->getAddressList());
            self::assertSame($missingReference, $this->getStoredStandardAddress($User));
        }

        $User->save(QUI::getUsers()->getSystemUser());

        self::assertCount(1, $User->getAddressList());
        self::assertNotSame($missingReference, $this->getStoredStandardAddress($User));
        self::assertSame($User->getStandardAddress()->getUUID(), $this->getStoredStandardAddress($User));
    }

    public static function standardAddressReferenceFormats(): iterable
    {
        yield 'UUID' => [false];
        yield 'legacy numeric ID' => [true];
    }

    #[DataProvider('standardAddressReferenceFormats')]
    public function testStandardAddressCannotBeDeletedDirectly(bool $useNumericId): void
    {
        $User = $this->createAddressUser();
        $Address = $User->getStandardAddress();
        $reference = $useNumericId ? $Address->getId() : $Address->getUUID();
        self::getConnection()->update(
            Manager::table(),
            ['address' => $reference],
            ['uuid' => $User->getUUID()]
        );
        $User = new User($User->getUUID());

        try {
            $User->getAddress($reference)->delete();
            self::fail('Direct API calls must protect the standard address too.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(
                QUI::getLocale()->get('quiqqer/core', 'exception.users.address.delete.default'),
                $Exception->getMessage()
            );
            self::assertCount(1, $User->getAddressList());
        }
    }

    public function testNonStandardAddressCanStillBeDeleted(): void
    {
        $User = $this->createAddressUser();
        $StandardAddress = $User->getStandardAddress();
        $OtherAddress = $User->addAddress(['city' => 'Berlin'], QUI::getUsers()->getSystemUser());

        $OtherAddress->delete();

        self::assertCount(1, $User->getAddressList());
        self::assertSame($StandardAddress->getUUID(), $User->getStandardAddress()->getUUID());
        self::assertSame($StandardAddress->getUUID(), $this->getStoredStandardAddress($User));
    }

    private function createAddressUser(): User
    {
        $Users = QUI::getUsers();

        return $Users->createChild(
            self::TEST_PREFIX . uniqid(),
            $Users->getSystemUser()
        );
    }

    private function createInvalidReference(string $type): int | string
    {
        return match ($type) {
            'missing-uuid' => QUI\Utils\Uuid::get(),
            'missing-id' => -12345,
            'foreign-uuid' => (string)$this->createAddressUser()->getStandardAddress()->getUUID(),
            'foreign-id' => $this->createAddressUser()->getStandardAddress()->getId()
        };
    }

    private function getStoredStandardAddress(User $User): mixed
    {
        return self::getConnection()->createQueryBuilder()
            ->select('address')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where('uuid = :uuid')
            ->setParameter('uuid', $User->getUUID())
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{mixed, TestHandler}
     */
    private function captureAddressLogs(callable $Callback): array
    {
        $PreviousLogger = QUI\Log\Logger::getLogger();
        $Config = QUI\Log\Config::getPackageConfig();
        self::assertNotNull($Config);
        $previousLogLevels = $Config->get('log_levels');
        $Handler = new TestHandler();
        QUI\Log\Logger::$Logger = new MonologLogger('standard-address-test', [$Handler]);
        $Config->setValue('log_levels', 'error', 1);

        try {
            $result = $Callback();

            return [
                $result,
                $Handler
            ];
        } finally {
            QUI\Log\Logger::$Logger = $PreviousLogger;
            $Config->setSection('log_levels', $previousLogLevels);
        }
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
