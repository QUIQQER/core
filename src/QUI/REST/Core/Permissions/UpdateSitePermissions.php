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

final class UpdateSitePermissions extends PermissionEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/permissions';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizePermissions($User);
        $Target = self::target($arguments);
        $permissions = self::changes($Request, $Target);
        $body = Input::body($Request, ['permissions' => 'object', 'recursive' => 'boolean'], ['permissions']);
        $ids = ($body['recursive'] ?? false) && $Target instanceof QUI\Projects\Site\Edit
            ? $Target->getChildrenIdsRecursive(['active' => '0&1']) : [];
        self::apply($Target, $permissions, $User);
        $children = [];

        foreach ($ids as $id) {
            try {
                $Child = self::site($arguments, 'view', (int)$id);
                self::apply($Child, $permissions, $User);
                $children[] = ['id' => (int)$id, 'status' => 200];
            } catch (\Throwable $Error) {
                $ErrorResponse = JsonResponse::error(new QUI\REST\Response(), $Error);
                $error = json_decode((string)$ErrorResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $children[] = [
                    'id' => (int)$id, 'status' => $ErrorResponse->getStatusCode(), 'error' => $error['error']
                ];
            }
        }

        return JsonResponse::write($Response, ['data' => [
            ...self::permissionData($Target), 'children' => $children
        ]]);
    }
}
