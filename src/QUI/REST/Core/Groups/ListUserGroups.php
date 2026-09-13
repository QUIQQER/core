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

final class ListUserGroups extends GroupEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users/{userId}/groups';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'view');
        self::authorize($User, 'view');
        $groups = [];

        foreach (self::user($arguments['userId'])->getGroups() as $Group) {
            $groups[] = self::groupRepresentation($Group);
        }

        return JsonResponse::write($Response, ['data' => $groups, 'meta' => ['total' => count($groups)]]);
    }
}
