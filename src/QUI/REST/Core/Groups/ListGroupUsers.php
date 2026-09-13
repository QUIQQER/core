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

final class ListGroupUsers extends GroupEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/groups/{groupId}/users';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'view');
        self::authorize($User, 'view');
        $Group = self::group($arguments['groupId']);
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $data = [];

        foreach ($Group->getUsers(['limit' => $offset . ',' . $limit, 'order' => 'username ASC']) as $row) {
            $data[] = self::representation(self::user((string)$row['uuid']));
        }

        return JsonResponse::write($Response, [
            'data' => $data,
            'meta' => ['total' => $Group->countUser(), 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
