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

final class SetMediaOrder extends MediaEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/media/{fileId}/order';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Folder = self::folder($arguments, 'edit');
        $body = Input::body($Request, ['fileIds' => 'array'], ['fileIds']);
        $ids = array_map(self::siteId(...), Input::ids($body['fileIds'], 'fileIds'));
        return JsonResponse::write($Response, ['data' => ['fileIds' => self::order($Folder, $ids, $User)]]);
    }
}
