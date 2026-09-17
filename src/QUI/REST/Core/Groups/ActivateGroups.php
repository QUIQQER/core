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

final class ActivateGroups extends GroupEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/groups/activate';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'edit');
        $body = Input::body($Request, ['groupIds' => 'array'], ['groupIds']);
        $ids = Input::ids($body['groupIds'], 'groupIds');
        $data = [];

        foreach ($ids as $id) {
            try {
                $Group = self::group($id);
                $Group->activate();
                $data[] = ['id' => $id, 'status' => 200, 'data' => self::groupRepresentation($Group)];
            } catch (\Throwable $Error) {
                $ErrorResponse = JsonResponse::error(new QUI\REST\Response(), $Error);
                $error = json_decode((string)$ErrorResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $data[] = ['id' => $id, 'status' => $ErrorResponse->getStatusCode(), 'error' => $error['error']];
            }
        }

        return JsonResponse::write($Response, ['data' => $data]);
    }
}
