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

final class GetMediaEffects extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/{fileId}/effects';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'view');

        if (!$Item instanceof QUI\Projects\Media\Folder && !$Item instanceof QUI\Projects\Media\Image) {
            throw new ApiException('invalid_input', 'Effects require an image or a folder.');
        }
        return JsonResponse::write($Response, ['data' => ['effects' => (object)$Item->getEffects()]]);
    }
}
