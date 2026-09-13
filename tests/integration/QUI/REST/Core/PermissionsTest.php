<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\Permissions\Permission;
use QUI\Projects\ProjectTestHelper;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class PermissionsTest extends RestIntegrationTestCase
{
    public function testUserPermissionUpdatesValidateWholeRequestAndEnforceAccess(): void
    {
        $user = $this->createUser();
        $path = '/users/' . $user['uuid'] . '/permissions';
        $name = 'quiqqer.admin.users.edit';
        $updated = $this->data($this->request('PATCH', $path, ['permissions' => [$name => true]]));
        self::assertTrue($updated['permissions'][$name]);
        self::assertSame('user', $this->data($this->request('GET', $path, null, $Actor))['area']);
        $Response = $this->request('PATCH', $path, ['permissions' => [$name => false, 'unknown.permission' => true]]);
        self::assertSame(422, $Response->getStatusCode());
        self::assertTrue($this->data($this->request('GET', $path))['permissions'][$name]);
        self::assertSame(422, $this->request('PATCH', $path, ['permissions' => [$name => 'true']])->getStatusCode());
        $Actor = QUI::getUsers()->get($user['uuid']);
        self::assertSame(204, $this->request('PUT', '/users/' . $user['uuid'] . '/password', [
            'password' => 'rest-permissions-test-123!'
        ])->getStatusCode());
        $activation = $this->data($this->request('POST', '/users/activate', ['userIds' => [$user['uuid']]]));
        self::assertSame(200, $activation[0]['status']);
        $Actor->refresh();
        self::assertSame(403, $this->request('GET', $path, null, $Actor)->getStatusCode());
        $Denied = $this->request('PATCH', $path, ['permissions' => [$name => false]], $Actor);
        self::assertSame(403, $Denied->getStatusCode());
    }

    public function testDelegatedPermissionAdministratorCannotGrantMissingRights(): void
    {
        $actor = $this->createUser();
        $target = $this->createUser();
        self::assertSame(204, $this->request('PUT', '/users/' . $actor['uuid'] . '/password', [
            'password' => 'rest-permissions-test-123!'
        ])->getStatusCode());
        $activation = $this->data($this->request('POST', '/users/activate', ['userIds' => [$actor['uuid']]]));
        self::assertSame(200, $activation[0]['status']);
        $Actor = QUI::getUsers()->get($actor['uuid']);
        $Actor->refresh();
        QUI::getPermissionManager()->setPermissions($Actor, [
            'quiqqer.core.rest.canUse' => true,
            'quiqqer.core.rest.permissions.canUse' => true,
            'quiqqer.system.permissions' => true,
            'quiqqer.admin.users.edit' => true,
            'quiqqer.admin.users.delete' => false
        ], $this->Root);
        $path = '/users/' . $target['uuid'] . '/permissions';
        self::assertSame('user', $this->data($this->request('GET', $path, null, $Actor))['area']);
        $Response = $this->request('PATCH', $path, ['permissions' => [
            'quiqqer.admin.users.delete' => true
        ]], $Actor);
        self::assertSame(403, $Response->getStatusCode(), (string)$Response->getBody());
        $permissions = $this->data($this->request('GET', $path))['permissions'];
        self::assertNotTrue($permissions['quiqqer.admin.users.delete'] ?? false);
    }

    public function testProjectPermissionsRequireAnExistingLanguage(): void
    {
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName();
        self::assertSame('project', $this->data($this->request('GET', $path . '/de/permissions'))['area']);
        self::assertSame(404, $this->request('GET', $path . '/xx/permissions')->getStatusCode());
    }
}
