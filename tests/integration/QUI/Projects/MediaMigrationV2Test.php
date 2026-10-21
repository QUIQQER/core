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
}
