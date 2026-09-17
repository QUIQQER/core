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

final class CreateUploadSession extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/uploads';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $body = Input::body($Request, [
            'parentId' => 'integer', 'filename' => 'string', 'maxBytes' => 'integer', 'allowedMimeTypes' => 'array'
        ], ['parentId', 'filename']);
        $Parent = self::folder($arguments, 'upload', self::siteId($body['parentId']));
        $filename = self::filename($body['filename']);
        $max = $body['maxBytes'] ?? IncomingFile::MAX_BYTES;
        $types = $body['allowedMimeTypes'] ?? [];

        if ($max < 1 || $max > IncomingFile::MAX_BYTES || count($types) > 50) {
            throw new ApiException('invalid_input', 'Invalid upload size or MIME type limit.');
        }

        foreach ($types as $type) {
            if (!is_string($type) || !preg_match('~^[a-z0-9.+-]+/(?:[a-z0-9.+-]+|\*)$~D', $type)) {
                throw new ApiException('invalid_input', 'Invalid allowed MIME type.');
            }
        }

        $data = UploadSessions::create($User, [
            'project' => $arguments['project'], 'parentId' => $Parent->getId(), 'filename' => $filename,
            'maxBytes' => $max, 'allowedMimeTypes' => $types
        ]);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . $data['id'];
        return JsonResponse::write($Response->withHeader('Location', $location), ['data' => $data], 201);
    }
}
