<?php

namespace QUI\REST\Core\Project\Media;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Projects\Media\Folder;
use QUI\REST\Core\ApiException;
use RuntimeException;
use ZipArchive;

final class DownloadMedia extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/{fileId}/content';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface {
        $Item = self::item($arguments);
        $temporary = $Item instanceof Folder;
        $path = $temporary ? self::archive($Item, $User) : $Item->getFullPath();
        $filename = $temporary ? $Item->getAttribute('name') . '.zip' : basename($Item->getFullPath());

        try {
            $Handle = fopen($path, 'rb');

            if ($Handle === false) {
                throw new ApiException('not_found', 'The media content is unavailable.', 404);
            }

            $Stream = Utils::streamFor($Handle);
            $Response = $Response->withBody($Stream)
                ->withHeader('Content-Type', $temporary ? 'application/zip' : 'application/octet-stream')
                ->withHeader('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($filename))
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');

            if ($Stream->getSize() !== null) {
                $Response = $Response->withHeader('Content-Length', (string)$Stream->getSize());
            }

            return $Response;
        } finally {
            // The open stream owns the descriptor and remains readable after unlink on the server.
            if ($temporary && is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    private static function archive(Folder $Root, User $User): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('unavailable', 'The ZIP extension is required for folder downloads.', 503);
        }

        $directory = QUI::getTemp()->createFolder('rest-archive-' . bin2hex(random_bytes(16)));
        chmod($directory, 0700);
        $path = $directory . 'media.zip';
        $Zip = new ZipArchive();

        if ($Zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            rmdir($directory);
            throw new RuntimeException('Could not create media archive.');
        }

        try {
            $queue = [[$Root, '']];
            $count = 0;
            $size = 0;
            $seen = [];

            while ($entry = array_shift($queue)) {
                [$Folder, $prefix] = $entry;
                $ids = $Folder->getChildrenIds();

                foreach (is_array($ids) ? $ids : [] as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }

                    $seen[$id] = true;
                    $Child = self::item(['project' => $Root->getProject()->getName()], 'view', (int)$id);
                    $Child->checkPermission('quiqqer.projects.media.view', $User);
                    $name = $prefix . self::filename((string)$Child->getAttribute('name'));

                    if (++$count > 10000) {
                        throw new ApiException('payload_too_large', 'Folder archives are limited to 10000 items.', 413);
                    }

                    if ($Child instanceof Folder) {
                        $Zip->addEmptyDir($name);
                        $queue[] = [$Child, $name . '/'];
                        continue;
                    }

                    $file = $Child->getFullPath();
                    $size += filesize($file) ?: 0;

                    if ($size > IncomingFile::MAX_BYTES) {
                        throw new ApiException('payload_too_large', 'Folder archives are limited to 50 MiB.', 413);
                    }

                    if (!$Zip->addFile($file, $name)) {
                        throw new RuntimeException('Could not add media file to archive.');
                    }
                }
            }

            if (!$Zip->close()) {
                throw new RuntimeException('Could not finish media archive.');
            }

            // ZipArchive does not create a file for an entirely empty archive.
            if (!is_file($path)) {
                throw new ApiException('conflict', 'The folder is empty.', 409);
            }

            return $path;
        } catch (\Throwable $Error) {
            unset($Zip);

            if (is_file($path)) {
                unlink($path);
            }

            rmdir($directory);
            throw $Error;
        }
    }
}
