<?php

declare(strict_types=1);

namespace QUI\Projects;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use QUI;
use QUI\Exception;
use QUI\Interfaces\Projects\Media\File as MediaFile;
use QUI\Projects\Media\ErrorCodes;
use QUI\Projects\Media\Folder;

final class MediaFileRenameTest extends ProjectIntegrationTestCase
{
    public function testFilesWithDifferentExtensionsCanShareTheirBasename(): void
    {
        $this->withFiles(function (Folder $Folder, string $png, string $pdf): void {
            $Pdf = $Folder->uploadFile($pdf);
            $Pdf->rename('datei');
            $Image = $Folder->uploadFile($png);
            $Image->rename('datei');

            self::assertSame($Folder->getPath() . 'datei.pdf', $Pdf->getAttribute('file'));
            self::assertSame($Folder->getPath() . 'datei.png', $Image->getAttribute('file'));
            self::assertSame(file_get_contents($pdf), file_get_contents($Pdf->getFullPath()));
            self::assertSame(file_get_contents($png), file_get_contents($Image->getFullPath()));
            self::assertSame(
                $Pdf->getId(),
                $Folder->getMedia()->getChildByPath($Pdf->getAttribute('file'))->getId()
            );
            self::assertSame(
                $Image->getId(),
                $Folder->getMedia()->getChildByPath($Image->getAttribute('file'))->getId()
            );
        });
    }

    public function testAFileCanShareABasenameWithAFolder(): void
    {
        $this->withFiles(function (Folder $Folder, string $png): void {
            $Child = $Folder->createFolder('assets');
            $Image = $Folder->uploadFile($png);
            $Image->rename('assets');

            self::assertDirectoryExists($Child->getFullPath());
            self::assertSame($Folder->getPath() . 'assets.png', $Image->getAttribute('file'));
            self::assertFileExists($Image->getFullPath());
        });
    }

    #[DataProvider('occupiedTargets')]
    public function testExistingFullFilenamesRemainProtected(string $kind, bool $uppercase = false): void
    {
        $this->withFiles(function (Folder $Folder, string $png) use ($kind, $uppercase): void {
            $destination = $Folder->getFullPath() . 'occupied.png';

            if ($kind === 'file') {
                $Existing = $Folder->uploadFile($png);
                $Existing->rename('occupied');
            } elseif ($kind === 'folder') {
                self::assertTrue(mkdir($destination));
            } else {
                file_put_contents($destination, 'untracked file must survive');
            }

            $Source = $Folder->uploadFile($png);

            if ($uppercase) {
                $Source = $this->uppercaseExtension($Source);
            }

            $originalPath = $Source->getFullPath();
            $originalName = $Source->getAttribute('name');
            $originalDestination = is_file($destination) ? file_get_contents($destination) : null;

            try {
                $Source->rename('occupied');
                self::fail('Renaming onto an occupied path must be rejected.');
            } catch (Exception $Exception) {
                self::assertSame(ErrorCodes::FILE_ALREADY_EXISTS, $Exception->getCode());
            }

            self::assertSame($originalName, $Source->getAttribute('name'));
            self::assertFileExists($originalPath);
            self::assertSame(file_get_contents($png), file_get_contents($originalPath));

            if ($kind === 'folder') {
                self::assertDirectoryExists($destination);
            } else {
                self::assertSame($originalDestination, file_get_contents($destination));
            }
        });
    }

    public function testLegacyUppercaseExtensionsAreNormalizedDuringRegularRename(): void
    {
        $this->withFiles(function (Folder $Folder, string $png): void {
            $Source = $this->uppercaseExtension($Folder->uploadFile($png));
            $originalPath = $Source->getFullPath();
            $Source->rename('renamed');

            self::assertSame($Folder->getPath() . 'renamed.png', $Source->getAttribute('file'));
            self::assertFileDoesNotExist($originalPath);
            self::assertSame(file_get_contents($png), file_get_contents($Source->getFullPath()));
        });
    }

    /** @return iterable<string, array{string, bool}> */
    public static function occupiedTargets(): iterable
    {
        yield 'registered file' => ['file', false];
        yield 'folder at full filename' => ['folder', false];
        yield 'file missing from media database' => ['untracked', false];
        yield 'legacy uppercase extension' => ['file', true];
    }

    private function uppercaseExtension(MediaFile $Source): MediaFile
    {
        $Media = $Source->getMedia();
        $path = substr($Source->getAttribute('file'), 0, -3) . 'PNG';
        self::assertTrue(rename($Source->getFullPath(), $Media->getFullPath() . $path));
        QUI::getDataBaseConnection()->update(
            $Media->getTable(),
            ['file' => $path, 'pathHash' => md5($path)],
            ['id' => $Source->getId()]
        );
        $Media->invalidateItemCache($Source->getId());

        return $Media->get($Source->getId());
    }

    /** @param Closure(Folder, string, string): void $check */
    private function withFiles(Closure $check): void
    {
        $Media = self::getTestProject()->getMedia();
        $directory = sys_get_temp_dir() . '/media-rename-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $png = $directory . '/source.png';
        $pdf = $directory . '/source.pdf';
        $Image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($Image);
        imagepng($Image, $png);
        file_put_contents($pdf, "%PDF-1.4\nQUIQQER rename regression\n%%EOF\n");
        $Folder = null;

        try {
            ProjectTestHelper::runAsSystemUser(function () use ($Media, $check, $png, $pdf, &$Folder): void {
                $Folder = $Media->firstChild()->createFolder('rename-' . bin2hex(random_bytes(6)));
                $check($Folder, $png, $pdf);
            });
        } finally {
            if ($Folder !== null) {
                ProjectTestHelper::runAsSystemUser(static function () use ($Folder): void {
                    $Folder->delete();
                });
            }

            unlink($png);
            unlink($pdf);
            rmdir($directory);
        }
    }
}
