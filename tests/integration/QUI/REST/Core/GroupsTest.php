<?php

namespace QUI\REST\Core;

use QUI;
use QUI\Permissions\Permission;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class GroupsTest extends RestIntegrationTestCase
{
    public function testInvitationCannotDelegateRootGroupMembership(): void
    {
        $user = $this->createUser();
        $Actor = QUI::getUsers()->get($user['uuid']);
        self::assertSame(204, $this->request('PUT', '/users/' . $user['uuid'] . '/password', [
            'password' => 'rest-actor-test-password-123!'
        ])->getStatusCode());
        $activated = $this->data($this->request('POST', '/users/activate', ['userIds' => [$user['uuid']]]));
        self::assertSame(200, $activated[0]['status']);
        QUI::getPermissionManager()->setPermissions($Actor, [
            'quiqqer.core.rest.canUse' => true,
            'quiqqer.core.rest.users.canUse' => true,
            'quiqqer.core.rest.groups.canUse' => true,
            'quiqqer.admin.users.create' => true,
            'quiqqer.admin.users.send_mail' => true,
            'quiqqer.admin.groups.edit' => true
        ], $this->Root);
        $Actor->refresh();
        $email = 'rest-invite-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $Response = $this->request('POST', '/users/invite', [
            'email' => $email, 'groupIds' => [(string)QUI::conf('globals', 'root')]
        ], $Actor);
        self::assertSame(403, $Response->getStatusCode(), (string)$Response->getBody());
        self::assertStringContainsString('Only superusers', (string)$Response->getBody());
        $Connection = QUI::getDataBaseConnection();
        $Query = $Connection->createQueryBuilder()->select('COUNT(*)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table()))
            ->where('email = :email')->setParameter('email', $email);
        self::assertSame(0, (int)$Query->executeQuery()->fetchOne());
    }

    public function testGroupAndMembershipLifecycle(): void
    {
        $name = 'rest-group-' . bin2hex(random_bytes(5));
        $group = $this->data($this->request('POST', '/groups', [
            'name' => $name,
            'parentId' => (string)QUI::conf('globals', 'root')
        ]), 201);
        $id = $group['uuid'];
        $deleted = false;

        try {
            $user = $this->createUser();
            $membership = '/users/' . $user['uuid'] . '/groups/' . $id;
            $updated = $this->data($this->request('PATCH', '/groups/' . $id, ['name' => $name . '-edit']));
            self::assertSame($name . '-edit', $updated['name']);
            $activated = $this->data($this->request('POST', '/groups/activate', ['groupIds' => [$id]]));
            self::assertTrue($activated[0]['data']['active']);

            foreach ([1, 2] as $attempt) {
                $Response = $this->request('PUT', $membership);
                self::assertSame(204, $Response->getStatusCode(), (string)$Response->getBody());
            }

            $members = $this->data($this->request('GET', '/groups/' . $id . '/users'));
            self::assertContains($user['uuid'], array_column($members, 'uuid'));
            $groups = $this->data($this->request('GET', '/users/' . $user['uuid'] . '/groups'));
            self::assertContains($id, array_column($groups, 'uuid'));
            self::assertSame(204, $this->request('DELETE', $membership)->getStatusCode());
            self::assertSame(204, $this->request('DELETE', '/groups/' . $id)->getStatusCode());
            $deleted = true;
        } finally {
            if (!$deleted) {
                Permission::withUser($this->Root, function () use ($id): void {
                    QUI::getUsers()->withSessionUser($this->Root, fn() => QUI::getGroups()->get($id)->delete());
                });
            }
        }
    }
}
