<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\Projects\ProjectTestHelper;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class MediaTest extends RestIntegrationTestCase
{
    public function testFolderLifecycleAndLocalizedTexts(): void
    {
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName() . '/media';
        $folder = $this->data($this->request('POST', $path . '/folders', [
            'parentId' => 1, 'name' => 'rest-media-' . bin2hex(random_bytes(5))
        ]), 201);
        $id = $folder['id'];

        try {
            $updated = $this->data($this->request('PATCH', $path . '/' . $id, [
                'title' => ['de' => 'Medien'], 'description' => ['de' => 'REST Beschreibung']
            ]));
            self::assertSame('Medien', $updated['title']['de']);
            self::assertSame('REST Beschreibung', $updated['description']['de']);
            $listed = $this->data($this->request('GET', $path . '?search=' . $folder['name']));
            self::assertContains($id, array_column($listed, 'id'));
            self::assertSame(422, $this->request('PATCH', $path . '/' . $id, [
                'title' => ['xx' => 'Invalid'], 'name' => 'unwanted-change'
            ])->getStatusCode());
            self::assertSame($folder['name'], $this->data($this->request('GET', $path . '/' . $id))['name']);
            $hidden = $this->data($this->request('PUT', $path . '/' . $id . '/visibility', ['visible' => false]));
            self::assertFalse($hidden['visible']);
            $effects = $this->data($this->request('PATCH', $path . '/' . $id . '/effects', [
                'effects' => ['brightness' => 20, 'greyscale' => true]
            ]));
            self::assertSame(20, $effects['effects']['brightness']);
            self::assertSame(422, $this->request('PATCH', $path . '/' . $id . '/effects', [
                'effects' => ['brightness' => 30, 'blur' => 101]
            ])->getStatusCode());
            $effects = $this->data($this->request('GET', $path . '/' . $id . '/effects'));
            self::assertSame(20, $effects['effects']['brightness']);
            $Moved = $this->request('POST', $path . '/' . $id . '/move', ['parentId' => $id]);
            self::assertSame(422, $Moved->getStatusCode());
            self::assertSame(204, $this->request('DELETE', $path . '/' . $id)->getStatusCode());
            self::assertSame(404, $this->request('GET', $path . '/' . $id)->getStatusCode());
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $id): void {
                try {
                    $Item = $Project->getMedia()->get($id);
                } catch (QUI\Exception $Error) {
                    if ($Error->getCode() === 404) {
                        return;
                    }

                    throw $Error;
                }

                if (!$Item->getAttribute('deleted')) {
                    $Item->delete();
                }

                $Item->destroy();
            });
        }
    }
}
