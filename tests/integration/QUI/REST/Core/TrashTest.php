<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\Projects\Media;
use QUI\Projects\ProjectTestHelper;
use QUI\Projects\Site\Edit;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class TrashTest extends RestIntegrationTestCase
{
    public function testSiteTrashRestoresAndPermanentlyRemovesSelectedSites(): void
    {
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName() . '/de/sites';
        $site = $this->data($this->request('POST', $path, [
            'parentId' => 1, 'name' => 'rest-trash-' . bin2hex(random_bytes(5))
        ]), 201);
        $id = $site['id'];

        try {
            self::assertSame(204, $this->request('DELETE', $path . '/' . $id)->getStatusCode());
            self::assertContains($id, array_column($this->data($this->request('GET', $path . '/trash')), 'id'));
            $restored = $this->data($this->request('POST', $path . '/trash/restore', [
                'siteIds' => [$id], 'parentId' => 1
            ]));
            self::assertSame(200, $restored[0]['status'], json_encode($restored));
            self::assertFalse($this->data($this->request('GET', $path . '/' . $id))['active']);
            self::assertSame(204, $this->request('DELETE', $path . '/' . $id)->getStatusCode());
            $destroyed = $this->data($this->request('POST', $path . '/trash/destroy', ['siteIds' => [$id, 99999999]]));
            self::assertSame(200, $destroyed[0]['status']);
            self::assertSame(404, $destroyed[1]['status']);
            self::assertSame(404, $this->request('GET', $path . '/' . $id)->getStatusCode());
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $id): void {
                try {
                    $Site = new Edit($Project, $id);
                } catch (QUI\Exception $Error) {
                    if ($Error->getCode() === 705) {
                        return;
                    }

                    throw $Error;
                }

                if (!$Site->getAttribute('deleted')) {
                    $Site->delete();
                }

                $Site->destroy();
            });
        }
    }

    public function testMediaTrashWorksWithoutCachedSourceObjects(): void
    {
        $Project = ProjectTestHelper::getProject();
        $Media = $Project->getMedia();
        $path = '/projects/' . $Project->getName() . '/media';
        $temporary = tempnam(sys_get_temp_dir(), 'rest-trash-');
        self::assertNotFalse($temporary);
        file_put_contents($temporary, 'rest trash content');
        $folder = $this->data($this->request('POST', $path . '/folders', [
            'parentId' => 1, 'name' => 'rest-trash-' . bin2hex(random_bytes(5))
        ]), 201);
        $ids = [];

        try {
            $File = ProjectTestHelper::runAsSystemUser(fn() => $Media->get($folder['id'])->uploadFile($temporary));
            $ids[] = $File->getId();
            self::assertSame(204, $this->request('DELETE', $path . '/' . $File->getId())->getStatusCode());
            $Media->invalidateItemCache($File->getId());
            $restored = $this->data($this->request('POST', $path . '/trash/restore', [
                'fileIds' => [$File->getId()], 'parentId' => $folder['id']
            ]));
            self::assertSame(200, $restored[0]['status'], json_encode($restored));
            $id = $restored[0]['data']['id'];
            $ids[] = $id;
            self::assertNotSame($File->getId(), $id);
            self::assertSame(204, $this->request('DELETE', $path . '/' . $id)->getStatusCode());
            $Media->invalidateItemCache($id);
            self::assertContains($id, array_column($this->data($this->request('GET', $path . '/trash')), 'id'));
            $destroyed = $this->data($this->request('POST', $path . '/trash/destroy', ['fileIds' => [$id]]));
            self::assertSame(200, $destroyed[0]['status'], json_encode($destroyed));
            self::assertNotContains($id, array_column($this->data($this->request('GET', $path . '/trash')), 'id'));
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Media, $ids, $folder): void {
                $Connection = QUI::getDataBaseConnection();
                $table = $Connection->getDatabasePlatform()->quoteSingleIdentifier($Media->getTable());

                foreach ($ids as $id) {
                    $row = $Connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);

                    if ($row === false) {
                        continue;
                    }

                    $Item = $Media->parseResultToItem($row);

                    if (!$Item->isDeleted()) {
                        $Item->delete();
                    }

                    $Item->destroy();
                }

                $Media->get($folder['id'])->delete();
            });

            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
