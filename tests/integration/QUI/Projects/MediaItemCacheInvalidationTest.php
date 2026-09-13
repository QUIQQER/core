<?php

namespace QUI\Projects;

use QUI;

class MediaItemCacheInvalidationTest extends ProjectIntegrationTestCase
{
    public function testDeletingFolderInvalidatesItsCachedDescendantsAcrossManagers(): void
    {
        $Project = self::getTestProject();
        $Media = new Media($Project);
        $Other = new Media($Project);
        $folderId = null;

        try {
            ProjectTestHelper::runAsSystemUser(function () use ($Media, $Other, &$folderId): void {
                $Folder = $Media->firstChild()->createFolder('cache-delete-' . bin2hex(random_bytes(5)));
                $Child = $Folder->createFolder('nested');
                $folderId = $Folder->getId();
                $childId = $Child->getId();
                $Media->get($folderId);
                $Media->get($childId);
                $Other->get($folderId);
                $Other->get($childId);
                $Folder->delete();

                foreach ([$Media, $Other] as $Manager) {
                    $this->assertMissing($Manager, $folderId);
                    $this->assertMissing($Manager, $childId);
                }
            });
        } finally {
            if ($folderId !== null) {
                $this->cleanupFolder($Project, $folderId);
            }
        }
    }

    public function testDeletingAndDestroyingFileInvalidatesBothManagers(): void
    {
        $Project = self::getTestProject();
        $Media = new Media($Project);
        $Other = new Media($Project);
        $folderId = null;
        $fileId = null;
        $temporary = tempnam(sys_get_temp_dir(), 'media-cache-');
        self::assertNotFalse($temporary);
        file_put_contents($temporary, 'media cache regression');

        try {
            ProjectTestHelper::runAsSystemUser(function () use (
                $Media,
                $Other,
                $temporary,
                &$folderId,
                &$fileId
            ): void {
                $Folder = $Media->firstChild()->createFolder('cache-file-' . bin2hex(random_bytes(5)));
                $folderId = $Folder->getId();
                $File = $Folder->uploadFile($temporary);
                $fileId = $File->getId();
                $Media->get($fileId);
                $Other->get($fileId);
                $File->delete();
                self::assertTrue($File->isDeleted());
                $File->destroy();
                $this->assertMissing($Media, $fileId);
                $this->assertMissing($Other, $fileId);
            });
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $fileId): void {
                if ($fileId === null) {
                    return;
                }

                try {
                    $Media = new Media($Project);
                    $row = QUI::getDataBaseConnection()->fetchAssociative(
                        'SELECT * FROM ' . $Media->getTable() . ' WHERE id = ?',
                        [$fileId]
                    );

                    if ($row === false) {
                        return;
                    }

                    $File = $Media->parseResultToItem($row);
                } catch (QUI\Exception $Error) {
                    if ($Error->getCode() === 404) {
                        return;
                    }

                    throw $Error;
                }

                if (!$File->isDeleted()) {
                    $File->delete();
                }

                $File->destroy();
            });

            if ($folderId !== null) {
                $this->cleanupFolder($Project, $folderId);
            }

            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function assertMissing(Media $Media, int $id): void
    {
        try {
            $Media->get($id);
            self::fail('A deleted media record was returned from the object cache.');
        } catch (QUI\Exception $Error) {
            self::assertSame(404, $Error->getCode());
        }
    }

    private function cleanupFolder(Project $Project, int $id): void
    {
        ProjectTestHelper::runAsSystemUser(function () use ($Project, $id): void {
            try {
                $Folder = (new Media($Project))->get($id);
            } catch (QUI\Exception $Error) {
                if ($Error->getCode() === 404) {
                    return;
                }

                throw $Error;
            }

            $Folder->delete();
        });
    }
}
