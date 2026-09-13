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

final class DeleteGroup extends GroupEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/groups/{groupId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkPermission('quiqqer.core.rest.groups.canUse', $User);
        Permission::checkSU($User);
        self::group($arguments['groupId'])->delete();
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
