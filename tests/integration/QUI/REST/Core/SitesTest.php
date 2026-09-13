<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\Projects\ProjectTestHelper;
use QUI\Projects\Site\Edit;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class SitesTest extends RestIntegrationTestCase
{
    public function testPaginationCountsOnlyReadableSites(): void
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
            'quiqqer.projects.sites.view' => true
        ], $this->Root);
        $Actor->refresh();
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName() . '/de/sites';
        $prefix = 'rest-list-acl-' . bin2hex(random_bytes(5));
        $ids = [];

        try {
            foreach (['hidden', 'visible'] as $suffix) {
                $site = $this->data($this->request('POST', $path, [
                    'parentId' => 1, 'name' => $prefix . '-' . $suffix
                ]), 201);
                $ids[] = $site['id'];
            }

            ProjectTestHelper::runAsSystemUser(function () use ($Project, $ids): void {
                QUI::getPermissionManager()->setPermissions(new Edit($Project, $ids[0]), [
                    'quiqqer.projects.site.view' => 'u' . $this->Root->getUUID()
                ], $this->Root);
            });
            self::assertSame(403, $this->request('GET', $path . '/' . $ids[0], null, $Actor)->getStatusCode());
            $Response = $this->request('GET', $path . '?search=' . $prefix . '&limit=1', null, $Actor);
            $data = $this->data($Response);
            self::assertSame([$ids[1]], array_column($data, 'id'));
            $body = json_decode((string)$Response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $body['meta']['total']);
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $ids): void {
                foreach ($ids as $id) {
                    $Site = new Edit($Project, $id);
                    $Site->delete();
                    $Site->destroy();
                }
            });
        }
    }

    public function testSiteLifecycleUsesProjectAndLanguagePath(): void
    {
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName() . '/de/sites';
        $created = $this->data($this->request('POST', $path, [
            'parentId' => 1, 'name' => 'rest-site-' . bin2hex(random_bytes(4)), 'title' => 'REST site'
        ]), 201);
        $id = $created['id'];
        $copyId = null;

        try {
            $updated = $this->data($this->request('PATCH', $path . '/' . $id, ['content' => '<p>REST content</p>']));
            self::assertSame('<p>REST content</p>', $updated['content']);
            self::assertSame('REST site', $updated['title']);
            $activated = $this->data($this->request('POST', $path . '/activate', ['siteIds' => [$id]]));
            self::assertSame(200, $activated[0]['status']);
            self::assertTrue($activated[0]['data']['active']);
            $listed = $this->data($this->request('GET', $path));
            self::assertContains($id, array_column($listed, 'id'));

            $lock = $this->data($this->request('POST', $path . '/' . $id . '/lock'));
            self::assertNotEmpty($lock['token']);
            self::assertTrue($this->data($this->request('GET', $path . '/' . $id . '/lock'))['locked']);
            $Unlocked = $this->request('POST', $path . '/' . $id . '/unlock', ['token' => $lock['token']]);
            self::assertSame(204, $Unlocked->getStatusCode());
            self::assertFalse($this->data($this->request('GET', $path . '/' . $id . '/lock'))['locked']);

            $copy = $this->data($this->request('POST', $path . '/' . $id . '/copy', ['parentId' => 1]), 201);
            $copyId = $copy['id'];
            self::assertNotSame($id, $copyId);
            $Response = $this->request('DELETE', $path . '/' . $id);
            self::assertSame(204, $Response->getStatusCode(), (string)$Response->getBody());
            self::assertSame(404, $this->request('GET', $path . '/' . $id)->getStatusCode());
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $id, $copyId): void {
                foreach (array_filter([$copyId, $id]) as $siteId) {
                    $Site = new Edit($Project, $siteId);
                    $Site->unlockWithRights();
                    if (!$Site->getAttribute('deleted')) {
                        $Site->delete();
                    }
                    $Site->destroy();
                }
            });
        }
    }
}
