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

final class GetFolderSize extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/{fileId}/size';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Folder = self::folder($arguments);
        $size = QUI\Utils\System\Folder::getFolderSize($Folder->getFullPath());
        return JsonResponse::write($Response, ['data' => ['sizeBytes' => $size, 'sizeKnown' => $size !== null]]);
    }
}
