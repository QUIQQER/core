<?php

declare(strict_types=1);

namespace QUI\Users;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Permissions\Permission;
use QUI\Upload\MobileUpload\Devices;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UploadDevicesAuthorizationTest extends TestCase
{
    private const ENDPOINT = 'ajax_upload_mobileUpload_devices';
    private mixed $previousSession;
    private mixed $previousAjax;
    private mixed $previousActor;
    private mixed $previousPermissionUser;
    private array $previousServer;
    private array $users = [];

    protected function setUp(): void
    {
        $this->previousSession = QUI::$Session;
        $this->previousAjax = QUI::$Ajax;
        $this->previousActor = (new ReflectionProperty(Manager::class, 'Session'))->getValue(QUI::getUsers());
        $this->previousPermissionUser = (new ReflectionProperty(Permission::class, 'User'))->getValue();
        $this->previousServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        QUI::$Ajax = new QUI\Ajax();
        require dirname(__DIR__, 4) . '/admin/ajax/upload/mobileUpload/devices.php';
        require dirname(__DIR__, 4) . '/admin/ajax/users/save.php';
        $this->actor(QUI::getUsers()->get(QUI::conf('globals', 'rootuser')));
    }

    protected function tearDown(): void
    {
        try {
            $this->actor(QUI::getUsers()->get(QUI::conf('globals', 'rootuser')));

            foreach ($this->users as $User) {
                QUI::getDataBaseConnection()->delete(QUI::getPermissionManager()::table() . '2users', [
                    'user_id' => $User->getUUID()
                ]);
                $User->delete(QUI::getUsers()->getSystemUser());
            }
        } finally {
            QUI::$Session = $this->previousSession;
            QUI::$Ajax = $this->previousAjax;
            (new ReflectionProperty(Manager::class, 'Session'))->setValue(QUI::getUsers(), $this->previousActor);
            (new ReflectionProperty(Permission::class, 'User'))->setValue(null, $this->previousPermissionUser);
            $_SERVER = $this->previousServer;
        }
    }

    public function testOwnerCanListRenameAndRevokeButCannotAddUsingProfileSave(): void
    {
        $Owner = $this->user();
        $Devices = new Devices();
        $uuid = (string)$Owner->getUUID();
        $token = bin2hex(random_bytes(32));
        $Devices->register($uuid, $token, 'Phone', 'Browser', time());
        $this->actor($Owner);

        $response = $this->request($Owner, 'list');
        self::assertArrayNotHasKey('Exception', $response, json_encode($response, JSON_THROW_ON_ERROR));
        self::assertCount(1, $response['result']);
        self::assertStringNotContainsString(hash('sha256', $token), json_encode($response));
        $id = $response['result'][0]['id'];

        $response = $this->request($Owner, 'rename', $id, 'My phone');
        self::assertSame('My phone', $response['result'][0]['name']);
        $response = $this->request($Owner, 'revoke', $id);
        self::assertSame([], $response['result']);

        $response = QUI::getAjax()->callRequestFunction('ajax_users_save', [
            '_csrf' => QUI\Security\CsrfToken::get(),
            'uid' => $uuid,
            'attributes' => json_encode([Devices::ATTRIBUTE => ['forged' => ['name' => 'Forged device']]])
        ]);
        self::assertArrayNotHasKey('Exception', $response);
        self::assertSame([], $Devices->listing($uuid));
    }

    public function testOtherBackendUserCannotReadRenameOrRevokeDevices(): void
    {
        $Owner = $this->user();
        $Other = $this->user();
        $Devices = new Devices();
        $uuid = (string)$Owner->getUUID();
        $Devices->register($uuid, bin2hex(random_bytes(32)), 'Phone', 'Browser', time());
        $id = $Devices->listing($uuid)[0]['id'];
        $this->actor($Other);

        foreach (['list', 'rename', 'revoke'] as $action) {
            self::assertArrayHasKey('Exception', $this->request($Owner, $action, $id, 'Changed'));
        }

        self::assertSame('Phone', $Devices->listing($uuid)[0]['name']);
    }

    public function testUserEditorCanRevokeAndGetRequestsAreRejected(): void
    {
        $Owner = $this->user();
        $Editor = $this->user(true);
        $Devices = new Devices();
        $uuid = (string)$Owner->getUUID();
        $Devices->register($uuid, bin2hex(random_bytes(32)), 'Phone', 'Browser', time());
        $id = $Devices->listing($uuid)[0]['id'];
        $this->actor($Editor);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertArrayHasKey('Exception', $this->request($Owner, 'revoke', $id));
        self::assertCount(1, $Devices->listing($uuid));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = $this->request($Owner, 'revoke', $id);
        self::assertArrayNotHasKey('Exception', $response, json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame([], $response['result']);
    }

    private function user(bool $editor = false): User
    {
        $name = 'mobile-device-test-' . bin2hex(random_bytes(8));
        $System = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChildWithAttributes([
            'username' => $name,
            'email' => $name . '@example.invalid'
        ], $System);
        $this->users[] = $User;
        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        QUI::getPermissionManager()->setPermissions($User, [
            'quiqqer.admin' => true,
            'quiqqer.admin.users.edit' => $editor
        ], $Root);
        $User->setPassword('Test-' . bin2hex(random_bytes(16)), $System);
        $User->activate('', $System);

        return $User;
    }

    private function actor(User $User): void
    {
        $Session = new QUI\System\Console\Session();

        foreach (['uid' => $User->getUUID(), 'auth' => 1, 'auth-primary' => 1, 'auth-secondary' => 1] as $key => $value) {
            $Session->set($key, $value);
        }

        QUI::$Session = $Session;
        (new ReflectionProperty(Manager::class, 'Session'))->setValue(QUI::getUsers(), $User);
        (new ReflectionProperty(Permission::class, 'User'))->setValue(null, null);
    }

    private function request(User $User, string $action, string $id = '', string $name = ''): array
    {
        return QUI::getAjax()->callRequestFunction(self::ENDPOINT, [
            '_csrf' => QUI\Security\CsrfToken::get(),
            'uid' => (string)$User->getUUID(),
            'action' => $action,
            'id' => $id,
            'name' => $name
        ]);
    }
}
