<?php

namespace QUI\REST\Core;

use QUI;
use QUI\Permissions\Permission;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class GroupsTest extends RestIntegrationTestCase
{
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
