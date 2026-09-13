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

final class GetFolderPreview extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/{fileId}/preview';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Image = self::folder($arguments)->firstImage();
        $Image->checkPermission('quiqqer.projects.media.view', $User);
        return JsonResponse::write($Response, ['data' => self::mediaData($Image)]);
    }
}
