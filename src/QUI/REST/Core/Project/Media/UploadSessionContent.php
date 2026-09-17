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

final class UploadSessionContent extends MediaEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/media/uploads/{uploadId}/content';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $type = strtolower(trim(explode(';', $Request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'application/octet-stream') {
            throw new ApiException('unsupported_media_type', 'Use application/octet-stream for file content.', 415);
        }

        $data = UploadSessions::withSession(
            $User,
            $arguments['project'],
            $arguments['uploadId'],
            function (array &$state, string $directory) use ($Request, $arguments): array {
                self::folder($arguments, 'upload', $state['parentId']);

                if (!in_array($state['status'], ['pending', 'uploaded'], true)) {
                    throw new ApiException('conflict', 'This upload session has already been finalized.', 409);
                }

                IncomingFile::consume($Request->getBody(), $state['filename'], function (string $path) use (
                    &$state,
                    $directory
                ): void {
                    $size = filesize($path);

                    if ($size === false || $size > $state['maxBytes']) {
                        throw new ApiException('payload_too_large', 'The session upload limit was exceeded.', 413);
                    }

                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                    $allowed = $state['allowedMimeTypes'] === [];

                    foreach ($state['allowedMimeTypes'] as $type) {
                        if (is_string($mime) && fnmatch($type, $mime)) {
                            $allowed = true;
                        }
                    }

                    if (!$allowed) {
                        throw new ApiException('invalid_input', 'The uploaded file MIME type is not allowed.');
                    }

                    if (!rename($path, $directory . '/payload')) {
                        throw new \RuntimeException('Could not store upload content.');
                    }

                    $state['size'] = $size;
                    $state['mimeType'] = $mime;
                    $state['status'] = 'uploaded';
                });

                return $state;
            }
        );
        return JsonResponse::write($Response, ['data' => $data]);
    }
}
