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

final class CreateFolder extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/folders';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $body = Input::body($Request, ['parentId' => 'integer', 'name' => 'string'], ['parentId', 'name']);
        $Parent = self::folder($arguments, 'upload', self::siteId($body['parentId']));
        $Folder = $Parent->createFolder(self::filename($body['name']), $User);
        $path = substr($Request->getUri()->getPath(), 0, -strlen('/folders')) . '/' . $Folder->getId();
        return JsonResponse::write($Response->withHeader('Location', $path), ['data' => self::mediaData($Folder)], 201);
    }
}
