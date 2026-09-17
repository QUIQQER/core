<?php

namespace QUI\REST\Core\Permissions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class GetGroupPermissions extends PermissionEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/groups/{groupId}/permissions';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizePermissions($User);
        $Target = self::target($arguments);
        return JsonResponse::write($Response, ['data' => self::permissionData($Target)]);
    }
}
