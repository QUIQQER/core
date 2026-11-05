<?php

namespace QUI\Users;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Groups\Manager as GroupManager;
use ReflectionClass;
use ReflectionProperty;

class UserTest extends TestCase
{
    private ?GroupManager $originalGroupManager;

    protected function setUp(): void
    {
        $this->originalGroupManager = QUI::$Groups;
    }

    protected function tearDown(): void
    {
        QUI::$Groups = $this->originalGroupManager;
    }

    public function testGetGroupsDoesNotResolveEmptyGroupIds(): void
    {
        $GroupManager = $this->createMock(GroupManager::class);
        $GroupManager->expects(self::never())->method('get');
        QUI::$Groups = $GroupManager;

        $Reflection = new ReflectionClass(User::class);
        $User = $Reflection->newInstanceWithoutConstructor();
        $GroupsProperty = new ReflectionProperty(User::class, 'groups');
        $GroupsProperty->setValue($User, ', ,');

        self::assertSame([], $User->getGroups());
    }

    public function testStandardAddressDoesNotTreatOtherUserExceptionsAsMissingAddress(): void
    {
        $Failure = new Exception('Address initialization failed');
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAddress',
                'getAddressList'
            ])
            ->getMock();
        (new ReflectionProperty(User::class, 'settings'))->setValue($User, ['address' => 'stored-reference']);
        $User->expects(self::once())->method('getAddress')->with('stored-reference')->willThrowException($Failure);
        $User->expects(self::never())->method('getAddressList');

        try {
            $User->getStandardAddress();
            self::fail('Only a missing address may trigger the standard address fallback.');
        } catch (Exception $Exception) {
            self::assertSame($Failure, $Exception);
        }
    }

    public function testDatabaseFailureRemainsWrappedWithoutTryingStandardAddressFallback(): void
    {
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getUUID',
                'getAddressList'
            ])
            ->getMock();
        $User->method('getUUID')->willReturn('test-user');
        $User->expects(self::never())->method('getAddressList');
        (new ReflectionProperty(User::class, 'settings'))->setValue($User, ['address' => 'stored-reference']);

        $ConnectionProperty = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $PreviousConnection = $ConnectionProperty->getValue();
        $Connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $ConnectionProperty->setValue(null, $Connection);

        try {
            $User->getStandardAddress();
            self::fail('Database errors must not trigger the standard address fallback.');
        } catch (Exception $Exception) {
            self::assertNotInstanceOf(AddressNotFoundException::class, $Exception);
            self::assertSame(404, $Exception->getCode());
            self::assertSame(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.not.found',
                    [
                        'addressId' => 'stored-reference',
                        'userId' => 'test-user'
                    ]
                ),
                $Exception->getMessage()
            );
            self::assertSame('stored-reference', $User->getAttribute('address'));
        } finally {
            $ConnectionProperty->setValue(null, $PreviousConnection);
            $Connection->close();
        }
    }
}
