<?php

/**
 * This file contains the \QUI\Permissions\PermissionOrder
 */

namespace QUI\Permissions;

use QUI;
use QUI\Exception;
use QUI\Groups\Group;
use QUI\Interfaces\Users\User as UserInterface;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Class PermissionOrder
 *
 * The PermissionOrder class provides methods for calculating the maximum and minimum integer values
 * of a specified permission from a list of objects, as well as checking if a permission is granted
 * for any object in the given list.
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * @example $User->getPermission($perm, 'max_integer');
 * @example $User->getPermission($perm, 'min_integer');
 */
class PermissionOrder
{
    /**
     * Resolve a global grant from the user or any of their groups.
     * False and empty values do not revoke grants from other sources.
     * Object ACLs and numeric aggregation rules are evaluated separately.
     *
     * @return bool|int|array<array-key, mixed>|string
     */
    public static function firstGranted(
        string $permission,
        UserInterface $User,
        Manager $Manager
    ): bool|int|array|string {
        $permissions = $Manager->getPermissions($User);

        if (!empty($permissions[$permission])) {
            return $permissions[$permission];
        }

        foreach ($User->getGroups() as $Group) {
            $permissions = $Manager->getPermissions($Group);

            if (!empty($permissions[$permission])) {
                return $permissions[$permission];
            }
        }

        return false;
    }

    /**
     * Finds the maximum integer value of a specified permission from a list of objects.
     *
     * @param string $permission The permission to check.
     * @param array<int, Group|UserInterface> $list An array of objects to check permissions against.
     *
     * @return int|null The maximum integer value of the permission, or null if no objects have the permission.
     * @throws Exception
     */
    public static function maxInteger(string $permission, array $list): ?int
    {
        $result = null;

        foreach ($list as $Object) {
            if (QUI::getGroups()->isGroup($Object)) {
                /* @var $Object Group */
                $hasPermissionResult = $Object->hasPermission($permission);
            } else {
                /* @var $Object User */
                $hasPermissionResult = $Object->hasPermission($permission);
            }

            if ($hasPermissionResult === false) {
                continue;
            }

            if ($result === null || (int)$hasPermissionResult > $result) {
                $result = (int)$hasPermissionResult;
            }
        }

        // default
        if ($result === null) {
            $Manager = QUI::getPermissionManager();
            $permData = $Manager->getPermissionData($permission);

            if (!empty($permData['defaultvalue'])) {
                return $permData['defaultvalue'];
            }
        }

        return $result;
    }

    /**
     * Calculates the minimum integer result of checking a permission against a list of objects.
     *
     * @param string $permission The permission to check against.
     * @param array<int, Group|UserInterface> $list The list of objects to check the permission against.
     * @return int|null The minimum integer result. If no object has the permission, returns null.
     * @throws Exception
     */
    public static function minInteger(string $permission, array $list): ?int
    {
        $result = null;

        /* @var $Object Group */
        foreach ($list as $Object) {
            $hasPermissionResult = $Object->hasPermission($permission);

            if ($hasPermissionResult === false) {
                continue;
            }

            if ($result === null || (int)$hasPermissionResult < $result) {
                $result = (int)$Object->hasPermission($permission);
            }
        }

        // default
        if ($result === null) {
            $Manager = QUI::getPermissionManager();
            $permData = $Manager->getPermissionData($permission);

            if (!empty($permData['defaultvalue'])) {
                return $permData['defaultvalue'];
            }
        }

        return $result;
    }

    /**
     * Checks if a permission is granted for any object in the given list.
     *
     * @param string $permission The permission to check for.
     * @param array<int, Group|UserInterface> $list The list of objects to check against.
     *
     * @return bool|int|string Returns true if the permission is granted by any object,
     *         the highest integer permission value if multiple objects have integer
     *         permissions, or the string permission if granted by any object.
     */
    public static function permission(string $permission, array $list): bool|int|string
    {
        $result = false;

        /* @var $Group Group */
        foreach ($list as $Object) {
            if (QUI::getGroups()->isGroup($Object)) {
                /* @var $Object Group */
                $hasPermissionResult = $Object->hasPermission($permission);
            } else {
                /* @var $Object User */
                $hasPermissionResult = $Object->hasPermission($permission);
            }

            if ($hasPermissionResult === true) {
                return true;
            }

            // falls numerisch ist
            if (is_numeric($hasPermissionResult)) {
                $hasPermissionResult = (int) $hasPermissionResult;

                if (is_bool($result)) {
                    $result = $hasPermissionResult;
                    continue;
                }

                if ($hasPermissionResult > $result) {
                    $result = $hasPermissionResult;
                }

                continue;
            }

            // string
            if (is_string($hasPermissionResult) && $hasPermissionResult !== '') {
                return $hasPermissionResult;
            }
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $arguments
     *
     * @return void
     */
    public static function __callStatic(string $name, array $arguments)
    {
        // TODO: Implement __callStatic() method.
    }
}
