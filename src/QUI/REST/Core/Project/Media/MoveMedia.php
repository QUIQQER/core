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

final class MoveMedia extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/{fileId}/move';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'edit');
        $body = Input::body($Request, ['parentId' => 'integer'], ['parentId']);
        $Parent = self::folder($arguments, 'upload', self::siteId($body['parentId']));
        self::checkMove($Item, $Parent);
        $Item->moveTo($Parent, $User);
        return JsonResponse::write($Response, ['data' => self::mediaData($Item)]);
    }
}
