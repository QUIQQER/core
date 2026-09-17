<?php

namespace QUI\REST\Core\Permissions;

use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Groups\Group;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Manager;
use QUI\Permissions\Permission;
use QUI\Projects\Media\Item;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\Project\Sites\SiteEndpoint;
use QUI\Users\User;
use Ramsey\Uuid\Uuid;

abstract class PermissionEndpoint extends SiteEndpoint
{
    protected static function projectPermission(string $name, Project $Project, User $User): bool
    {
        try {
            return Permission::checkProjectPermission($name, $Project, $User);
        } catch (QUI\Permissions\Exception) {
            return false;
        }
    }

    protected static function authorizePermissions(Actor $User): void
    {
        Permission::checkPermission('quiqqer.core.rest.permissions.canUse', $User);
        Permission::checkPermission('quiqqer.system.permissions', $User);
    }

    /** @param array<string, string> $arguments */
    protected static function target(array $arguments): User|Group|Project|Edit|Item
    {
        if (isset($arguments['userId'])) {
            $Target = QUI::getUsers()->get($arguments['userId']);

            if (!$Target instanceof User) {
                throw new ApiException('not_found', 'The requested user does not exist.', 404);
            }

            return $Target;
        }

        if (isset($arguments['groupId'])) {
            return QUI::getGroups()->get($arguments['groupId']);
        }

        if (isset($arguments['siteId'])) {
            return self::site($arguments);
        }

        $Project = self::project($arguments['project'], $arguments['lang'] ?? null);

        if (isset($arguments['fileId'])) {
            $Item = $Project->getMedia()->get(self::siteId($arguments['fileId']));

            if (!$Item instanceof Item || $Item->getAttribute('deleted')) {
                throw new ApiException('not_found', 'The requested media item does not exist.', 404);
            }

            $Item->checkPermission('quiqqer.projects.media.view', QUI::getUserBySession());
            return $Item;
        }

        return $Project;
    }

    /** @return array<string, mixed> */
    protected static function permissionData(object $Target): array
    {
        $Manager = QUI::getPermissionManager();
        $area = Manager::classToArea($Target::class);
        return [
            'area' => $area,
            'permissions' => (object)$Manager->getPermissions($Target),
            'definitions' => self::definitions($Manager->getPermissionList($area))
        ];
    }

    /** @param array<string, array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    protected static function definitions(array $definitions): array
    {
        $result = [];

        foreach ($definitions as $name => $definition) {
            $result[] = [
                'name' => $name, 'type' => $definition['type'] ?? 'bool',
                'area' => ($definition['area'] ?? '') ?: 'global',
                'defaultValue' => $definition['defaultvalue'] ?? null,
                'source' => $definition['src'] ?? null,
                'title' => $definition['title'] ?? null, 'description' => $definition['desc'] ?? null
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    protected static function changes(ServerRequestInterface $Request, object $Target): array
    {
        $fields = ['permissions' => 'object'];

        if ($Target instanceof Edit) {
            $fields['recursive'] = 'boolean';
        }

        $body = Input::body($Request, $fields, ['permissions']);
        $permissions = (array)$body['permissions'];
        $definitions = QUI::getPermissionManager()->getPermissionList(Manager::classToArea($Target::class));

        if ($permissions === []) {
            throw new ApiException('invalid_input', 'Provide at least one permission.');
        }

        foreach ($permissions as $name => $value) {
            if (!isset($definitions[$name])) {
                throw new ApiException('invalid_input', 'Unknown permission: ' . $name);
            }

            $type = $definitions[$name]['type'] ?? 'bool';
            $valid = match ($type) {
                'bool' => is_bool($value),
                'int' => is_int($value),
                'array' => is_array($value),
                'user', 'group', 'users', 'groups', 'users_and_groups' =>
                    is_string($value) && self::identifiers($value, $type),
                default => is_string($value)
            };

            if (!$valid) {
                throw new ApiException('invalid_input', 'Invalid value for permission: ' . $name);
            }
        }

        return $permissions;
    }

    private static function identifiers(string $value, string $type): bool
    {
        if ($value === '') {
            return true;
        }

        $ids = explode(',', $value);

        if (in_array($type, ['user', 'group'], true) && count($ids) !== 1) {
            return false;
        }

        foreach ($ids as $id) {
            $id = trim($id);

            if ($type === 'users_and_groups') {
                if (!str_starts_with($id, 'u') && !str_starts_with($id, 'g')) {
                    return false;
                }

                $id = substr($id, 1);
            }

            if ($id === '' || (!ctype_digit($id) && !Uuid::isValid($id))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $permissions */
    protected static function apply(User|Group|Project|Edit|Item $Target, array $permissions, Actor $User): void
    {
        if (!$User->isSU() && ($Target instanceof User || $Target instanceof Group)) {
            if ($Target instanceof User) {
                $Target->checkEditPermission($User);
            }

            $root = (string)QUI::conf('globals', 'root');

            if (
                ($Target instanceof User && $Target->isSU())
                || ($Target instanceof Group && in_array($root, [(string)$Target->getId(), $Target->getUUID()], true))
            ) {
                throw new ApiException('permission_denied', 'Only superusers may change root permissions.', 403);
            }

            $definitions = QUI::getPermissionManager()->getPermissionList(Manager::classToArea($Target::class));

            foreach ($permissions as $name => $value) {
                $definition = $definitions[$name];
                $own = Permission::hasPermission($name, $User);
                $allowed = match ($definition['type'] ?? 'bool') {
                    'bool' => !$value || (bool)$own,
                    'int' => is_numeric($own) && $value <= (int)$own,
                    'array' => is_array($own) && array_diff($value, $own) === [],
                    default => $value === '' || $value === $own
                };

                if (!empty($definition['rootPermission']) || !$allowed) {
                    throw new ApiException('permission_denied', 'This change exceeds your delegation rights.', 403);
                }
            }
        }

        QUI::getPermissionManager()->setPermissions($Target, $permissions, $User);
    }
}
