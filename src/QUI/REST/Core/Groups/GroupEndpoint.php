<?php

namespace QUI\REST\Core\Groups;

use QUI;
use QUI\Groups\Group;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Users\UserEndpoint;

abstract class GroupEndpoint extends UserEndpoint
{
    protected static function authorizeGroup(Actor $User, string $action): void
    {
        Permission::checkPermission('quiqqer.core.rest.groups.canUse', $User);
        Permission::checkPermission('quiqqer.admin.groups.' . $action, $User);
    }

    protected static function group(int|string $id): Group
    {
        try {
            return QUI::getGroups()->get($id);
        } catch (QUI\Groups\Exception) {
            throw new ApiException('not_found', 'The requested group does not exist.', 404);
        }
    }

    /** @return array<string, mixed> */
    protected static function groupRepresentation(Group $Group): array
    {
        return [
            'id' => $Group->getId(),
            'uuid' => $Group->getUUID(),
            'name' => $Group->getName(),
            'parentId' => $Group->getAttribute('parent'),
            'active' => $Group->isActive(),
            'userCount' => $Group->countUser(),
            'hasChildren' => (bool)$Group->hasChildren(),
            'toolbar' => $Group->getAttribute('toolbar'),
            'assignedToolbar' => $Group->getAttribute('assigned_toolbar'),
            'avatar' => $Group->getAttribute('avatar')
        ];
    }

    protected static function checkMembership(Actor $Actor, Group $Group, QUI\Users\User $Target): void
    {
        self::authorizeGroup($Actor, 'edit');
        self::authorize($Actor, 'edit');
        $Target->checkEditPermission($Actor);

        if ($Actor->isSU()) {
            return;
        }

        if ($Target->isSU()) {
            throw new ApiException('permission_denied', 'Only superusers may manage root group membership.', 403);
        }

        self::checkGroupDelegation($Actor, $Group);
    }

    public static function checkGroupDelegation(Actor $Actor, Group $Group): void
    {
        self::authorizeGroup($Actor, 'edit');

        if ($Actor->isSU()) {
            return;
        }

        $root = (string)QUI::conf('globals', 'root');

        if ((string)$Group->getId() === $root || (string)$Group->getUUID() === $root) {
            throw new ApiException('permission_denied', 'Only superusers may manage root group membership.', 403);
        }

        $Manager = QUI::getPermissionManager();
        $definitions = $Manager->getPermissionList('groups');

        foreach ($Manager->getPermissions($Group) as $name => $value) {
            if (empty($value)) {
                continue;
            }

            $actorValue = Permission::hasPermission($name, $Actor);
            $type = $definitions[$name]['type'] ?? null;
            $allowed = match ($type) {
                'bool' => (bool)$actorValue,
                'int' => is_numeric($actorValue) && (int)$actorValue >= (int)$value,
                'array' => is_array($value) && is_array($actorValue) && array_diff($value, $actorValue) === [],
                'group', 'groups', 'user', 'users', 'users_and_groups' =>
                    (is_string($actorValue) || is_int($actorValue))
                    && array_diff(
                        array_filter(array_map('trim', explode(',', (string)$value))),
                        array_filter(array_map('trim', explode(',', (string)$actorValue)))
                    ) === [],
                default => is_string($value) && is_string($actorValue) && hash_equals($value, $actorValue)
            };

            if (!$allowed) {
                throw new ApiException(
                    'permission_denied',
                    'This group exceeds your permission delegation rights.',
                    403
                );
            }
        }
    }
}
