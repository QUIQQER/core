<?php

declare(strict_types=1);

namespace QUI\Users;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Ajax;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Permissions\Permission;
use QUI\System\Console\Session;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserWipeTest extends TestCase
{
    /** @var list<User> */
    private array $users = [];
    private User $Root;

    protected function setUp(): void
    {
        $this->Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        $this->setActor($this->Root);
        QUI::$Ajax = new Ajax();
        require dirname(__DIR__, 4) . '/admin/ajax/users/wipe.php';
    }

    protected function tearDown(): void
    {
        $this->setActor($this->Root);
        $Connection = QUI::getDataBaseConnection();

        foreach ($this->users as $User) {
            $Connection->delete(Manager::tableAddress(), ['userUuid' => $User->getUUID()]);
            $Connection->delete(QUI::getPermissionManager()::table() . '2users', [
                'user_id' => $User->getUUID()
            ]);
            $Connection->delete(Manager::table(), ['uuid' => $User->getUUID()]);
            QUI::getUsers()->onDeleteUser($User);
        }
    }

    public function testWipeRetainsIdentityButClearsDataAndAddresses(): void
    {
        $User = $this->createUser();
        $User->addAddress(['firstname' => 'Personal', 'lastname' => 'Address', 'country' => 'DE']);
        $id = $User->getId();
        $uuid = $User->getUUID();

        self::assertTrue($this->wipe([$uuid]));

        $row = QUI::getDataBaseConnection()->fetchAssociative(
            'SELECT * FROM ' . Manager::table() . ' WHERE uuid = ?',
            [$uuid]
        );
        self::assertIsArray($row);
        self::assertSame($id, (int)$row['id']);
        self::assertSame($uuid, $row['uuid']);
        self::assertSame(-1, (int)$row['active']);
        foreach (['username', 'email', 'password', 'firstname', 'lastname', 'usergroup', 'extra'] as $field) {
            self::assertSame('', $row[$field], $field);
        }
        self::assertSame(0, (int)QUI::getDataBaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . Manager::tableAddress() . ' WHERE userUuid = ?',
            [$uuid]
        ));
    }

    public function testEditPermissionAloneCannotWipe(): void
    {
        $Target = $this->createUser();
        $Actor = $this->createUser();
        QUI::getPermissionManager()->setPermissions($Actor, [
            'quiqqer.admin' => true,
            'quiqqer.admin.users.edit' => true,
            'quiqqer.admin.users.delete' => false
        ], $this->Root);
        $this->setActor($Actor);

        try {
            $this->wipe([$Target->getUUID()]);
            self::fail('Wiping requires delete permission.');
        } catch (QUI\Permissions\Exception) {
            $this->assertUnchanged($Target);
        }
    }

    public function testChecksAllTargetsBeforeWipingAnyAccount(): void
    {
        $Target = $this->createUser();
        $Protected = $this->createUser();
        $Protected->setAttribute('su', true);
        $Protected->save();
        $Protected->refresh();
        $Actor = $this->createUser();
        QUI::getPermissionManager()->setPermissions($Actor, [
            'quiqqer.admin' => true,
            'quiqqer.admin.users.edit' => true,
            'quiqqer.admin.users.delete' => true
        ], $this->Root);
        $this->setActor($Actor);

        try {
            $this->wipe([$Target->getUUID(), $Protected->getUUID()]);
            self::fail('A non-SU must not wipe an active SU.');
        } catch (QUI\Users\Exception) {
            $this->assertUnchanged($Target);
            $this->assertUnchanged($Protected);
        }
    }

    public function testEndpointRequiresBackendAccess(): void
    {
        $Actor = $this->createUser();
        QUI::getPermissionManager()->setPermissions($Actor, ['quiqqer.admin' => false], $this->Root);
        $this->setActor($Actor);
        $this->expectException(QUI\Permissions\Exception::class);
        Ajax::checkPermissions('ajax_users_wipe');
    }

    private function wipe(array $ids): bool
    {
        Ajax::checkPermissions('ajax_users_wipe');
        return Ajax::getRegisteredCallables()['ajax_users_wipe']['callable'](json_encode($ids));
    }

    private function assertUnchanged(User $User): void
    {
        self::assertSame($User->getUsername(), QUI::getDataBaseConnection()->fetchOne(
            'SELECT username FROM ' . Manager::table() . ' WHERE uuid = ?',
            [$User->getUUID()]
        ));
    }

    private function createUser(): User
    {
        $username = 'wipe-test-' . bin2hex(random_bytes(8));
        $User = QUI::getUsers()->createChildWithAttributes([
            'username' => $username,
            'email' => $username . '@example.invalid',
            'firstname' => 'Personal',
            'lastname' => 'Data'
        ], QUI::getUsers()->getSystemUser());
        $this->users[] = $User;
        $User->setPassword('wipe-test-password');
        $User->activate();
        return $User;
    }

    private function setActor(UserInterface $User): void
    {
        $Session = new Session();
        $Session->set('uid', $User->getUUID());
        $Session->set('auth', 1);
        $Session->set('auth-primary', 1);
        $Session->set('auth-secondary', 1);
        QUI::$Session = $Session;
        (new ReflectionProperty(QUI::getUsers(), 'Session'))->setValue(QUI::getUsers(), $User);
        Permission::setUser($User);
    }
}
