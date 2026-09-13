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

final class GetEffectivePermission extends PermissionEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users/{userId}/permissions/effective';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizePermissions($User);
        $Subject = self::target(['userId' => $arguments['userId']]);

        if (!$Subject instanceof QUI\Users\User) {
            throw new ApiException('not_found', 'The requested user does not exist.', 404);
        }

        $query = $Request->getQueryParams();
        $permission = $query['permission'] ?? null;

        if (!is_string($permission) || $permission === '') {
            throw new ApiException('invalid_input', 'Provide the permission name.');
        }

        $targetArgs = [];

        foreach (['project', 'lang', 'siteId', 'fileId'] as $key) {
            if (!isset($query[$key])) {
                continue;
            }

            if (!is_string($query[$key]) || $query[$key] === '') {
                throw new ApiException('invalid_input', 'Invalid permission target.');
            }

            $targetArgs[$key] = $query[$key];
        }

        if (
            $targetArgs !== [] && (
            !isset($targetArgs['project'])
            || (!isset($targetArgs['fileId']) && !isset($targetArgs['lang']))
            || (isset($targetArgs['siteId']) && isset($targetArgs['fileId']))
            )
        ) {
            throw new ApiException('invalid_input', 'Project and site targets require project and lang.');
        }

        $Target = $targetArgs === [] ? null : self::target($targetArgs);
        $Manager = QUI::getPermissionManager();
        $area = $Target === null ? 'user' : QUI\Permissions\Manager::classToArea($Target::class);
        $definitions = $Manager->getPermissionList($area);

        if (!isset($definitions[$permission])) {
            throw new ApiException('invalid_input', 'Unknown permission for this target.');
        }

        $value = match (true) {
            $Target instanceof QUI\Projects\Site\Edit => Permission::hasSitePermission($permission, $Target, $Subject),
            $Target instanceof QUI\Projects\Media\Item =>
                Permission::hasMediaPermission($permission, $Target, $Subject),
            $Target instanceof QUI\Projects\Project => self::projectPermission($permission, $Target, $Subject),
            default => Permission::hasPermission($permission, $Subject)
        };
        $groups = [];

        foreach ($Subject->getGroups() as $Group) {
            $groups[] = [
                'uuid' => $Group->getUUID(), 'name' => $Group->getName(),
                'value' => $Manager->getPermissions($Group)[$permission] ?? null
            ];
        }

        return JsonResponse::write($Response, ['data' => [
            'userId' => $Subject->getUUID(), 'permission' => $permission, 'value' => $value,
            'target' => (object)$targetArgs,
            'definition' => self::definitions([$permission => $definitions[$permission]])[0],
            'configuredTargetValue' =>
                $Target === null ? null : ($Manager->getPermissions($Target)[$permission] ?? null),
            'directUserValue' => $Manager->getUserPermissionData($Subject)[$permission] ?? null,
            'groupValues' => $groups
        ]]);
    }
}
