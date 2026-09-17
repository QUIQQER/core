<?php

namespace QUI\REST\Core\Project\Media;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class FinalizeUpload extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/uploads/{uploadId}/finalize';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $data = UploadSessions::withSession(
            $User,
            $arguments['project'],
            $arguments['uploadId'],
            function (array &$state, string $directory) use ($arguments, $User): array {
                if ($state['status'] === 'complete') {
                    return self::mediaData(self::item($arguments, 'view', $state['fileId']));
                }

                if ($state['status'] !== 'uploaded') {
                    throw new ApiException('conflict', 'The upload is not ready for finalization.', 409);
                }

                $Parent = self::folder($arguments, 'upload', $state['parentId']);
                $Handle = fopen($directory . '/payload', 'rb');

                if ($Handle === false) {
                    throw new ApiException('conflict', 'The uploaded content is missing.', 409);
                }

                $Stream = \GuzzleHttp\Psr7\Utils::streamFor($Handle);
                // Persist before import so a process crash cannot silently import the same session twice.
                $state['status'] = 'finalizing';
                UploadSessions::save($directory, $state);
                $File = IncomingFile::consume($Stream, $state['filename'], fn(string $path) =>
                    $Parent->uploadFile($path, QUI\Projects\Media\Folder::FILE_OVERWRITE_NONE, $User));
                $Stream->close();
                $state['status'] = 'complete';
                $state['fileId'] = $File->getId();
                UploadSessions::save($directory, $state);
                unlink($directory . '/payload');
                return self::mediaData($File);
            }
        );
        return JsonResponse::write($Response, ['data' => $data]);
    }
}
