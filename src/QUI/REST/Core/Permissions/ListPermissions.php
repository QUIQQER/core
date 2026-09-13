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

final class ListPermissions extends PermissionEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/permissions';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizePermissions($User);
        $area = $Request->getQueryParams()['area'] ?? null;

        if ($area !== null && !in_array($area, ['global', 'user', 'groups', 'project', 'site', 'media'], true)) {
            throw new ApiException('invalid_input', 'Unknown permission area.');
        }

        $definitions = QUI::getPermissionManager()->getPermissionList($area === 'global' ? false : ($area ?? false));

        if ($area === 'global') {
            $definitions = array_filter($definitions, static fn(array $definition): bool =>
                in_array($definition['area'] ?? '', ['', 'global'], true));
        }

        return JsonResponse::write($Response, ['data' => self::definitions($definitions)]);
    }
}
