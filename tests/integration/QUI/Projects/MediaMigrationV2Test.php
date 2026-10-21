<?php

namespace QUI\Projects;

use PHPUnit\Framework\Attributes\DataProvider;
use QUI;
use QUI\Projects\Media\Item;
use QUI\System\Console\Tools\MigrationV2;

class MediaMigrationV2Test extends ProjectIntegrationTestCase
{
    public static function legacyNames(): array
    {
        return [
            ['PCSG AGB - Dienstvertraege', 'PCSG_AGB_-_Dienstvertraege'],
            ['AGB - Support  Wartung von Softwareloesungen', 'AGB_-_Support_Wartung_von_Softwareloesungen']
        ];
    }

    #[DataProvider('legacyNames')]
    public function testMigrationNormalizesLegacyNamesAndDoesNotSaveThemAgain(
        string $legacyName,
        string $normalizedName
    ): void {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $temporary = tempnam(sys_get_temp_dir(), 'media-migration-');
        self::assertNotFalse($temporary);
        $content = "%PDF-1.4\nQUIQQER media migration regression\n%%EOF\n";
        file_put_contents($temporary, $content);
        $Folder = null;
        $savedIds = [];
        $onSave = static function (Item $Item) use (&$savedIds): void {
            $savedIds[] = $Item->getId();
        };

        try {
            ProjectTestHelper::runAsSystemUser(function () use (
                $Media,
                $temporary,
                $legacyName,
                $normalizedName,
                $content,
                $onSave,
                &$Folder,
                &$savedIds
            ): void {
                $Folder = $Media->firstChild()->createFolder('migration-' . bin2hex(random_bytes(6)));
                $File = $Folder->uploadFile($temporary);
                $File->rename('legacy');
                $fileId = $File->getId();
                $legacyPath = $Folder->getPath() . $legacyName . '.pdf';
                $normalizedPath = $Folder->getPath() . $normalizedName . '.pdf';
                $CleanFile = $Folder->uploadFile($temporary);
                $CleanFile->rename('unchanged');

                // Reproduce a v1 entry without passing its name through the v2 rename API.
                self::assertTrue(rename($File->getFullPath(), $Media->getFullPath() . $legacyPath));
                QUI::getDataBaseConnection()->update(
                    $Media->getTable(),
                    [
                        'name' => $legacyName,
                        'file' => $legacyPath,
                        'pathHash' => md5($legacyPath),
                        'pathHistory' => json_encode([$legacyPath]),
                        'mime_type' => 'application/pdf'
                    ],
                    ['id' => $fileId]
                );
                $Media->invalidateItemCache($fileId);

                // Limit this migration test to its own project, including in shared CI installations.
                $Tool = $this->getMockBuilder(MigrationV2::class)
                    ->onlyMethods(['fetchRows'])
                    ->getMock();
                $Tool->method('fetchRows')->willReturnCallback(static function (string $table) use ($Media): array {
                    if ($table !== $Media->getTable()) {
                        return [];
                    }

                    $Connection = QUI::getDataBaseConnection();

                    return $Connection->createQueryBuilder()
                        ->select('*')
                        ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
                        ->executeQuery()
                        ->fetchAllAssociative();
                });

                QUI::getEvents()->addEvent('onMediaSaveBegin', $onSave);
                $Tool->media();

                $Media->invalidateItemCache($fileId);
                $Migrated = $Media->get($fileId);
                self::assertSame($normalizedName, $Migrated->getAttribute('name'));
                self::assertSame($normalizedPath, $Migrated->getAttribute('file'));
                self::assertSame($content, file_get_contents($Migrated->getFullPath()));
                self::assertFileDoesNotExist($Media->getFullPath() . $legacyPath);
                self::assertContains($legacyPath, $Migrated->getPathHistory());
                self::assertContains($normalizedPath, $Migrated->getPathHistory());
                self::assertSame([$fileId], $savedIds);

                $Tool->media();
                self::assertSame([$fileId], $savedIds);
            });
        } finally {
            QUI::getEvents()->removeEvent('onMediaSaveBegin', $onSave);

            if ($Folder !== null) {
                ProjectTestHelper::runAsSystemUser(static function () use ($Media, $Folder): void {
                    $Media->get($Folder->getId())->delete();
                });
            }

            unlink($temporary);
        }
    }

    public function testMigrationSkipsRootAndDeletedEntriesAndReportsOrphans(): void
    {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $Root = $Media->firstChild();
        $rootName = $Root->getAttribute('name');
        $Connection = QUI::getDataBaseConnection();
        $temporary = tempnam(sys_get_temp_dir(), 'media-migration-orphan-');
        self::assertNotFalse($temporary);
        file_put_contents($temporary, 'media migration regression');
        $Folder = null;

        try {
            ProjectTestHelper::runAsSystemUser(function () use (
                $Media,
                $Root,
                $Connection,
                $temporary,
                &$Folder
            ): void {
                $Folder = $Root->createFolder('migration-orphan-' . bin2hex(random_bytes(6)));
                $Orphan = $Folder->uploadFile($temporary);
                $Orphan->rename('orphan');
                $Deleted = $Folder->uploadFile($temporary);
                $Deleted->rename('deleted');
                $Deleted->delete();
                $Valid = $Folder->uploadFile($temporary);
                $Valid->rename('valid');
                $legacyPath = $Folder->getPath() . 'Valid legacy file.txt';
                self::assertTrue(rename($Valid->getFullPath(), $Media->getFullPath() . $legacyPath));

                $Connection->update($Media->getTable(), ['name' => 'Media Library'], ['id' => 1]);
                $Connection->update(
                    $Media->getTable(),
                    ['name' => 'Legacy Folder'],
                    ['id' => $Folder->getId()]
                );
                $Connection->update(
                    $Media->getTable(),
                    ['name' => 'Deleted legacy file'],
                    ['id' => $Deleted->getId()]
                );
                $Connection->update(
                    $Media->getTable(),
                    ['name' => 'Orphan legacy file'],
                    ['id' => $Orphan->getId()]
                );
                $Connection->update(
                    $Media->getTable(),
                    [
                        'name' => 'Valid legacy file',
                        'file' => $legacyPath,
                        'pathHash' => md5($legacyPath)
                    ],
                    ['id' => $Valid->getId()]
                );
                $Connection->delete($Media->getTable('relations'), ['child' => $Orphan->getId()]);
                $Media->invalidateItemCache(1);
                $Media->invalidateItemCache($Deleted->getId());
                $Media->invalidateItemCache($Orphan->getId());
                $Media->invalidateItemCache($Folder->getId());
                $Media->invalidateItemCache($Valid->getId());

                $messages = [];
                $Tool = $this->getMockBuilder(MigrationV2::class)
                    ->onlyMethods(['fetchRows', 'writeLn'])
                    ->getMock();
                $Tool->method('fetchRows')->willReturnCallback(static function (string $table) use ($Media): array {
                    if ($table !== $Media->getTable()) {
                        return [];
                    }

                    $Connection = QUI::getDataBaseConnection();

                    return $Connection->createQueryBuilder()
                        ->select('*')
                        ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
                        ->orderBy('id', 'ASC')
                        ->executeQuery()
                        ->fetchAllAssociative();
                });
                $Tool->method('writeLn')->willReturnCallback(static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                });

                $Tool->media();

                $deletedName = $Connection->createQueryBuilder()
                    ->select('name')
                    ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($Media->getTable()))
                    ->where('id = :id')
                    ->setParameter('id', $Deleted->getId())
                    ->executeQuery()
                    ->fetchOne();
                $Migrated = $Media->get($Valid->getId());
                $messagesText = implode("\n", $messages);

                self::assertSame('Media Library', $Media->get(1)->getAttribute('name'));
                self::assertSame('Legacy Folder', $Media->get($Folder->getId())->getAttribute('name'));
                self::assertSame('Deleted legacy file', $deletedName);
                self::assertSame('Orphan legacy file', $Media->get($Orphan->getId())->getAttribute('name'));
                self::assertSame('Valid_legacy_file', $Migrated->getAttribute('name'));
                self::assertSame('media migration regression', file_get_contents($Migrated->getFullPath()));
                self::assertCount(2, $messages);
                self::assertStringContainsString($Media->getProject()->getName(), $messagesText);
                self::assertStringContainsString('#' . $Orphan->getId(), $messagesText);
                self::assertStringContainsString('Media file with ID "0" not found', $messagesText);
            });
        } finally {
            $Connection->update($Media->getTable(), ['name' => $rootName], ['id' => 1]);
            $Media->invalidateItemCache(1);

            if ($Folder !== null) {
                ProjectTestHelper::runAsSystemUser(static function () use ($Folder): void {
                    $Folder->delete();
                });
            }

            unlink($temporary);
        }
    }
}
