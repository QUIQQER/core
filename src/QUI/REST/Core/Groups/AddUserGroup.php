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

final class AddUserGroup extends GroupEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/users/{userId}/groups/{groupId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Group = self::group($arguments['groupId']);
        $Target = self::user($arguments['userId']);
        self::checkMembership($User, $Group, $Target);

        if (!$Target->isInGroup($Group->getUUID())) {
            $Group->addUser($Target);
            $Target->save($User);
        }

        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
