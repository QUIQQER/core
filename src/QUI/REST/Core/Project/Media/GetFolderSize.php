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
        $force = match ($Request->getQueryParams()['force'] ?? 'false') {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new ApiException('invalid_input', 'force must be true or false.')
        };
        $size = QUI\Utils\System\Folder::getFolderSize($Folder->getFullPath(), $force);
        return JsonResponse::write($Response, ['data' => ['sizeBytes' => $size, 'sizeKnown' => $size !== null]]);
    }
}
