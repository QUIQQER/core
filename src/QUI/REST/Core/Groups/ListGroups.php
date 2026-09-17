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

final class ListGroups extends GroupEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/groups';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'view');
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $search = $Request->getQueryParams()['search'] ?? '';

        if (!is_string($search) || mb_strlen($search) > 200) {
            throw new ApiException('invalid_input', 'search must contain at most 200 characters.');
        }

        $params = ['limit' => $limit, 'start' => $offset, 'field' => 'name', 'order' => 'ASC'];

        if (trim($search) !== '') {
            $params['search'] = trim($search);
        }

        $Manager = QUI::getGroups();
        $data = [];

        foreach ($Manager->search($params) as $row) {
            $data[] = self::groupRepresentation(self::group((string)($row['uuid'] ?? $row['id'])));
        }

        return JsonResponse::write($Response, [
            'data' => $data,
            'meta' => ['total' => $Manager->count($params), 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
