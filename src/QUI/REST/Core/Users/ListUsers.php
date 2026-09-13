<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListUsers extends UserEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'view');
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $search = $Request->getQueryParams()['search'] ?? '';

        if (!is_string($search) || mb_strlen($search) > 200) {
            throw new ApiException('invalid_input', 'search must be a string of at most 200 characters.');
        }

        $params = ['limit' => $limit, 'start' => $offset, 'field' => 'username', 'order' => 'ASC'];

        if (trim($search) !== '') {
            $params['search'] = true;
            $params['searchSettings'] = [
                'userSearchString' => trim($search),
                'fields' => ['uuid' => 1, 'email' => 1, 'username' => 1, 'firstname' => 1, 'lastname' => 1]
            ];
        }

        $Manager = QUI::getUsers();
        $rows = $Manager->search($params);
        $data = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $data[] = self::representation(self::user((string)$row['uuid']));
            }
        }

        return JsonResponse::write($Response, [
            'data' => $data,
            'meta' => ['total' => $Manager->count($params), 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
