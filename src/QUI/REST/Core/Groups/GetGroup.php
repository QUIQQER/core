<?php

namespace QUI\REST\Core\Groups;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class GetGroup extends GroupEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/groups/{groupId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'view');
        return JsonResponse::write($Response, ['data' => self::groupRepresentation(self::group($arguments['groupId']))]);
    }
}
