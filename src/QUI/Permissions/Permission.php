<?php

/**
 * This file contains \QUI\Permissions\Permission
 */

namespace QUI\Permissions;

use QUI;
use QUI\Groups\Group;
use QUI\Projects\Media;
use QUI\Projects\Media\Item;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;
use QUI\Interfaces\Users\User;

use function array_flip;
use function explode;
use function implode;
use function is_null;
use function substr;
use function trim;

/**
 * Provides methods for quick rights checking
 *
 * all methods with check throws Exceptions
 * all methods with is or has returned the permission value
 *     it makes a check and capture the exceptions
 *
 * all methods with get return the permission entries
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Permission
{
    protected static null | QUI\Interfaces\Users\User $User = null;

    /**
     * has the User the permission
     *
     * @return bool|int|array<array-key, mixed>|string
     */
    public static function hasPermission(
        string $perm,
        null | false | User $User = null
    ): bool | int | array | string {
        try {
            return self::checkPermission($perm, $User);
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Checks whether the user has the permission
     *
     * @return bool|int|array<array-key, mixed>|string
     *
     * @throws Exception
     */
    public static function checkPermission(
        string $perm,
        false | User | null $User = null
    ): bool | int | array | string {
        if (!$User) {
            $User = self::getUser();
        }

        if ($User->isSU()) {
            return true;
        }

        if (QUI::getUsers()->isSystemUser($User)) {
            return true;
        }

        $Manager = QUI::getPermissionManager();
        $grant = PermissionOrder::firstGranted($perm, $User, $Manager);

        if ($grant !== false) {
            return $grant;
        }

        throw new Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.no.permission'),
            403,
            [
                'permission' => $perm
            ]
        );
    }

    protected static function getUser(): QUI\Interfaces\Users\User
    {
        if (!is_null(self::$User)) {
            return self::$User;
        }

        return QUI::getUserBySession();
    }

    /**
     * Set the global user for the permissions
     * You can set the default user for the permission checks,
     * default is the session user
     */
    public static function setUser(QUI\Interfaces\Users\User $User): void
    {
        self::$User = $User;
    }

    /**
     * Run permission checks with an explicit actor and restore the previous context.
     *
     * @template T
     * @param callable(): T $Callback
     * @return T
     */
    public static function withUser(User $User, callable $Callback): mixed
    {
        $PreviousUser = self::$User;
        self::$User = $User;

        try {
            return $Callback();
        } finally {
            self::$User = $PreviousUser;
        }
    }

    /**
     * Checks, if the user has the SuperUser flag
     */
    public static function isSU(null | false | User $User = null): bool
    {
        if (!$User) {
            $User = self::getUser();
        }

        return $User->isSU();
    }

    /**
     * Checks if the user is allowed to enter the admin area
     *
     * @param false|User $User - optional
     *
     * @throws \QUI\Exception
     */
    public static function checkAdminUser(null | false | User $User = null): void
    {
        $UserToCheck = $User ?: self::getUser();

        self::checkUser($UserToCheck);

        if (!self::isAdmin($UserToCheck)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.no.permission'
                ),
                440,
                [
                    'permission' => 'checkAdminUser'
                ]
            );
        }
    }

    /**
     * Checks if the object is also a user object
     *
     * @param false|User $User - optional
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    public static function checkUser(null | false | User $User = null): void
    {
        $UserToCheck = $User;

        if (!$User) {
            $UserToCheck = self::getUser();
        }

        if (!($UserToCheck instanceof QUI\Users\User)) {
            if (!$User) {
                QUI::getUsers()->checkUserSession();
            }

            // if no exception throws
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permission.session.expired'
                ),
                401
            );
        }
    }

    /**
     * Checks, if the user is an admin user
     */
    public static function isAdmin(null | false | User $User = null): bool
    {
        if (!$User) {
            $User = self::getUser();
        }

        try {
            return (bool)self::checkPermission('quiqqer.admin', $User);
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Checks if the user is a SuperUser
     * if not, it throws an exception
     *
     * @param false|User $User - optional
     *
     * @throws Exception
     * @throws \QUI\Exception
     */
    public static function checkSU(null | false | User $User = null): void
    {
        $UserToCheck = $User ?: self::getUser();

        self::checkUser($UserToCheck);

        if (!self::isSU($UserToCheck)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.no.permission'
                ),
                403,
                [
                    'permission' => 'checkSu'
                ]
            );
        }
    }

    /**
     * Checks if the permission is set
     */
    public static function existsPermission(string $perm, null | false | User $User = null): bool
    {
        if (!$User) {
            $User = self::getUser();
        }

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getPermissions($User);

        // first check user permission
        if (isset($permissions[$perm])) {
            return true;
        }

        $groups = $User->getGroups();

        foreach ($groups as $Group) {
            $permissions = $Manager->getPermissions($Group);

            if (isset($permissions[$perm])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a user to the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function addUserToSitePermission(
        User $User,
        Edit | Site $Site,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Site\Utils::isSiteObject($Site)) {
            return false;
        }

        /* @var $Site Site */
        $Site->checkPermission('quiqqer.projects.site.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$user])) {
            return true;
        }

        $permList[] = $user;

        $Manager->setSitePermissions(
            $Site,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Sites
     */
    /**
     * Add a group to the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function addGroupToSitePermission(
        Group $Group,
        Edit | Site $Site,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Site\Utils::isSiteObject($Site)) {
            return false;
        }

        /* @var $Site Site */
        $Site->checkPermission('quiqqer.projects.site.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $group = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$group])) {
            return true;
        }

        $permList[] = $group;


        $Manager->setSitePermissions(
            $Site,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Checks if the permission exists in the Site
     */
    public static function existsSitePermission(string $perm, Edit | Site $Site): bool
    {
        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        return isset($permissions[$perm]);
    }

    /**
     * Return the Site Permission
     *
     * @return mixed|boolean
     */
    public static function getSitePermission(Edit | Site $Site, string $perm): mixed
    {
        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        return $permissions[$perm] ?? false;
    }

    /**
     * Return a permission of the user
     * - can be user for string permissions
     */
    public static function getPermission(string $perm, null | QUI\Interfaces\Users\User $User = null): mixed
    {
        if ($User === null) {
            $User = self::getUser();
        }

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getPermissions($User);

        // first check user permission
        if (!empty($permissions[$perm])) {
            return $permissions[$perm];
        }

        return $permissions[$perm] ?? false;
    }

    /**
     * has the User the permission at the site?
     */
    public static function hasSitePermission(
        string $perm,
        Site $Site,
        null | false | User $User = null
    ): bool {
        try {
            return self::checkSitePermission($perm, $Site, $User);
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Checks if the User have the permission of the Site
     *
     * @throws Exception
     */
    public static function checkSitePermission(
        string $perm,
        Edit | Site $Site,
        null | false | User $User = null
    ): bool {
        if (!$User) {
            $User = self::getUser();
        }

        if ($User->isSU()) {
            return true;
        }

        if (QUI::getUsers()->isSystemUser($User)) {
            return true;
        }


        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        // default site rights, view, edit, del, new: has their own special checks
        // with project and site checks
        switch ($perm) {
            case 'quiqqer.projects.site.view':
            case 'quiqqer.projects.sites.view':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.projects.site.view',
                        $User
                    );
                } catch (Exception $Exception) {
                    // if site permissions are not empty do not check user permissions
                    if (!empty($permissions['quiqqer.projects.site.view'])) {
                        throw $Exception;
                    }

                    return (bool)self::checkPermission(
                        'quiqqer.projects.sites.view',
                        $User
                    );
                }

            case 'quiqqer.projects.site.edit':
            case 'quiqqer.projects.sites.edit':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.projects.site.edit',
                        $User
                    );
                } catch (Exception $Exception) {
                    // if site permissions are not empty do not check user permissions
                    if (!empty($permissions['quiqqer.projects.site.edit'])) {
                        throw $Exception;
                    }

                    return (bool)self::checkPermission(
                        'quiqqer.projects.sites.edit',
                        $User
                    );
                }

            case 'quiqqer.projects.site.del':
            case 'quiqqer.projects.sites.del':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.projects.site.del',
                        $User
                    );
                } catch (Exception $Exception) {
                    // if site permissions are not empty do not check user permissions
                    if (!empty($permissions['quiqqer.projects.site.del'])) {
                        throw $Exception;
                    }

                    return (bool)self::checkPermission(
                        'quiqqer.projects.sites.del',
                        $User
                    );
                }

            case 'quiqqer.projects.site.new':
            case 'quiqqer.projects.sites.new':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.projects.site.new',
                        $User
                    );
                } catch (Exception $Exception) {
                    // if site permissions are not empty do not check user permissions
                    if (!empty($permissions['quiqqer.projects.site.new'])) {
                        throw $Exception;
                    }

                    return (bool)self::checkPermission(
                        'quiqqer.projects.sites.new',
                        $User
                    );
                }
        }

        return self::checkPermissionList($permissions, $perm, $User);
    }

    /**
     * Check the permission with a given permission list
     *
     * @param array<string, mixed> $permissions
     *
     * @throws Exception
     */
    public static function checkPermissionList(
        array $permissions,
        string $perm,
        null | false | User $User = null
    ): bool {
        if (!isset($permissions[$perm])) {
            QUI\System\Log::addNotice(
                'Permission missing: ' . $perm
            );

            return true;
        }

        if (!$User) {
            $User = self::getUser();
        }

        if (empty($permissions[$perm])) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.no.permission'
                ),
                403,
                [
                    'userid' => $User->getUUID(),
                    'username' => $User->getName(),
                    'permission' => $permissions

                ]
            );
        }

        // what type
        try {
            $Manager = QUI::getPermissionManager();
            $perm_data = $Manager->getPermissionData($perm);
        } catch (QUI\Exception $Exception) {
            throw new Exception(
                $Exception->getMessage(),
                $Exception->getCode()
            );
        }

        $perm_value = $permissions[$perm];
        $check = false;

        switch ($perm_data['type']) {
            case 'bool':
                $check = true;
                break;

            case 'group':
                $group_ids = $User->getGroups(false);

                if (str_contains($perm_value, 'g') || str_contains($perm_value, 'u')) {
                    $perm_value = (int)substr($perm_value, 1);
                }

                foreach ($group_ids as $groupId) {
                    if ($groupId == $perm_value) {
                        $check = true;
                    }
                }

                break;

            case 'user':
                if ($perm_value == $User->getUUID()) {
                    $check = true;
                }

                break;

            case 'users':
                $uIds = explode(',', $perm_value);

                foreach ($uIds as $uid) {
                    if ((int)$uid == $User->getId()) {
                        $check = true;
                    }

                    if ($uid == $User->getUUID()) {
                        $check = true;
                    }
                }

                break;

            case 'groups':
            case 'users_and_groups':
                // groups ids from the user
                $group_ids = $User->getGroups(false);
                $user_group_ids = [];

                foreach ($group_ids as $gid) {
                    $user_group_ids[$gid] = true;
                }

                $ids = explode(',', $perm_value);

                foreach ($ids as $id) {
                    $real_id = $id;
                    $type = 'g';

                    if (str_starts_with($id, 'g') || str_starts_with($id, 'u')) {
                        $real_id = mb_substr($id, 1);
                        $type = mb_substr($id, 0, 1);
                    }

                    switch ($type) {
                        case 'u':
                            if ($real_id == $User->getId() || $real_id == $User->getUUID()) {
                                $check = true;
                            }

                            break;

                        case 'g':
                            if (isset($user_group_ids[$real_id])) {
                                $check = true;
                            }

                            break;
                    }
                }

                break;
        }

        if ($check) {
            return true;
        }

        throw new Exception(
            QUI::getLocale()->get(
                'quiqqer/core',
                'exception.no.permission'
            ),
            403,
            [
                'permission' => $perm
            ]
        );
    }

    /**
     * Remove a group from the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function removeGroupFromSitePermission(
        Group $Group,
        Edit | Site $Site,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Site\Utils::isSiteObject($Site)) {
            return false;
        }

        /* @var $Site Site */
        $Site->checkPermission('quiqqer.projects.site.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $group = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$group])) {
            unset($flip[$group]);
        }

        $permList = array_flip($flip);


        $Manager->setSitePermissions(
            $Site,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Remove a user from the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function removeUserFromSitePermission(
        User $User,
        QUI\Interfaces\Projects\Site $Site,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Site\Utils::isSiteObject($Site)) {
            return false;
        }

        /* @var $Site Site */
        $Site->checkPermission('quiqqer.projects.site.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getSitePermissions($Site);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$user])) {
            unset($flip[$user]);
        }

        $permList = array_flip($flip);


        $Manager->setSitePermissions(
            $Site,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }


    /**
     * Projects
     */
    /**
     * Adds a user to the Project permission
     *
     * @throws Exception|QUI\Database\Exception
     */
    public static function addGroupToProjectPermission(
        Group $Group,
        Project $Project,
        string $permission,
        null | User $EditUser = null
    ): bool {
        self::checkProjectPermission('quiqqer.projects.edit', $Project, $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getProjectPermissions($Project);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $groups = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$groups])) {
            return true;
        }

        $permList[] = $groups;

        $Manager->setProjectPermissions(
            $Project,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Checks if the User have the permission of the Project
     *
     * @throws Exception
     */
    public static function checkProjectPermission(
        string $perm,
        Project $Project,
        null | false | User $User = null
    ): bool {
        if (!$User) {
            $User = self::getUser();
        }

        if ($User->isSU()) {
            return true;
        }

        if (QUI::getUsers()->isSystemUser($User)) {
            return true;
        }

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getProjectPermissions($Project);


        // default project rights, edit, destroy, setconfig, editCustomCSS: has their own special checks
        // with project and site checks
        switch ($perm) {
            case 'quiqqer.projects.edit':
            case 'quiqqer.project.edit':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.project.edit',
                        $User
                    );
                } catch (Exception) {
                    return (bool)self::checkPermission(
                        'quiqqer.projects.edit',
                        $User
                    );
                }

            case 'quiqqer.projects.destroy':
            case 'quiqqer.project.destroy':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.project.destroy',
                        $User
                    );
                } catch (Exception) {
                    return (bool)self::checkPermission(
                        'quiqqer.projects.destroy',
                        $User
                    );
                }

            case 'quiqqer.projects.setconfig':
            case 'quiqqer.project.setconfig':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.project.setconfig',
                        $User
                    );
                } catch (Exception) {
                    return (bool)self::checkPermission(
                        'quiqqer.projects.setconfig',
                        $User
                    );
                }

            case 'quiqqer.projects.editCustomCSS':
            case 'quiqqer.project.editCustomCSS':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.project.editCustomCSS',
                        $User
                    );
                } catch (Exception) {
                    return (bool)self::checkPermission(
                        'quiqqer.projects.editCustomCSS',
                        $User
                    );
                }

            case 'quiqqer.projects.editCustomJS':
            case 'quiqqer.project.editCustomJS':
                try {
                    return self::checkPermissionList(
                        $permissions,
                        'quiqqer.project.editCustomJS',
                        $User
                    );
                } catch (Exception) {
                    return (bool)self::checkPermission(
                        'quiqqer.projects.editCustomJS',
                        $User
                    );
                }
        }

        return self::checkPermissionList($permissions, $perm, $User);
    }

    /**
     * Adds a user to the Project permission
     *
     * @throws QUI\Exception
     */
    public static function addUserToProjectPermission(
        User $User,
        Project $Project,
        string $permission,
        null | User $EditUser = null
    ): bool {
        self::checkProjectPermission('quiqqer.projects.edit', $Project, $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getProjectPermissions($Project);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$user])) {
            return true;
        }

        $permList[] = $user;

        $Manager->setProjectPermissions(
            $Project,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Remove a user from the project permission
     *
     * @throws Exception|QUI\Database\Exception
     */
    public static function removeUserFromProjectPermission(
        User $User,
        Project $Project,
        string $permission
    ): void {
        self::checkProjectPermission('quiqqer.projects.edit', $Project);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getProjectPermissions($Project);

        if (!isset($permissions[$permission])) {
            return;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$user])) {
            unset($flip[$user]);
        }

        $permList = array_flip($flip);


        $Manager->setProjectPermissions(
            $Project,
            [$permission => implode(',', $permList)]
        );
    }

    /**
     * Remove a group from the project permission
     *
     * @throws Exception
     */
    public static function removeGroupFromProjectPermission(
        Group $Group,
        Project $Project,
        string $permission
    ): bool {
        self::checkProjectPermission('quiqqer.projects.edit', $Project);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getProjectPermissions($Project);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $group = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$group])) {
            unset($flip[$group]);
        }

        $permList = array_flip($flip);


        $Manager->setProjectPermissions(
            $Project,
            [$permission => implode(',', $permList)]
        );

        return true;
    }

    //region media permissions

    /**
     * has the User the permission for the media item?
     */
    public static function hasMediaPermission(
        string $perm,
        Item $MediaItem,
        null | User $User = null
    ): bool {
        if (Media::useMediaPermissions() === false) {
            return true;
        }

        try {
            return self::checkMediaPermission($perm, $MediaItem, $User);
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Checks if the User have the permission of the Site
     *
     * @throws Exception
     */
    public static function checkMediaPermission(
        string $perm,
        Item $MediaItem,
        null | User $User = null
    ): bool {
        if (Media::useMediaPermissions() === false) {
            return true;
        }

        if (!$User) {
            $User = self::getUser();
        }

        if ($User->isSU()) {
            return true;
        }

        if (QUI::getUsers()->isSystemUser($User)) {
            return true;
        }


        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getMediaPermissions($MediaItem);

        return self::checkPermissionList($permissions, $perm, $User);
    }


    /**
     * Remove a group from the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function removeGroupFromMediaPermission(
        Group $Group,
        Item $MediaItem,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Media\Utils::isItem($MediaItem)) {
            return false;
        }

        $MediaItem->checkPermission('quiqqer.projects.media.set_permissions', $EditUser);
        $MediaItem->checkPermission('quiqqer.projects.media.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getMediaPermissions($MediaItem);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $group = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$group])) {
            unset($flip[$group]);
        }

        $permList = array_flip($flip);


        $Manager->setMediaPermissions(
            $MediaItem,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Remove a user from the permission
     *
     * @throws Exception
     * @throws Exception
     * @throws QUI\Exception
     */
    public static function removeUserFromMediaPermission(
        User $User,
        Item $MediaItem,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Media\Utils::isItem($MediaItem)) {
            return false;
        }

        $MediaItem->checkPermission('quiqqer.projects.media.set_permissions', $EditUser);
        $MediaItem->checkPermission('quiqqer.projects.media.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getMediaPermissions($MediaItem);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions, then unset it
        if (isset($flip[$user])) {
            unset($flip[$user]);
        }

        $permList = array_flip($flip);


        $Manager->setMediaPermissions(
            $MediaItem,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }


    /**
     * Add a group to the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function addGroupToMediaPermission(
        Group $Group,
        Item $MediaItem,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Media\Utils::isItem($MediaItem)) {
            return false;
        }

        $MediaItem->checkPermission('quiqqer.projects.media.set_permissions', $EditUser);
        $MediaItem->checkPermission('quiqqer.projects.media.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getMediaPermissions($MediaItem);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $group = 'g' . $Group->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$group])) {
            return true;
        }

        $permList[] = $group;


        $Manager->setMediaPermissions(
            $MediaItem,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    /**
     * Add a user to the permission
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function addUserToMediaPermission(
        User $User,
        Item $MediaItem,
        string $permission,
        null | User $EditUser = null
    ): bool {
        if (!QUI\Projects\Media\Utils::isItem($MediaItem)) {
            return false;
        }

        $MediaItem->checkPermission('quiqqer.projects.media.set_permissions', $EditUser);
        $MediaItem->checkPermission('quiqqer.projects.media.edit', $EditUser);

        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getMediaPermissions($MediaItem);

        if (!isset($permissions[$permission])) {
            return false;
        }

        $permList = [];
        $user = 'u' . $User->getUUID();

        if (!empty($permissions[$permission])) {
            $permList = explode(',', trim($permissions[$permission], ' ,'));
        }

        $flip = array_flip($permList);

        // user is in the permissions
        if (isset($flip[$user])) {
            return true;
        }

        $permList[] = $user;

        $Manager->setMediaPermissions(
            $MediaItem,
            [$permission => implode(',', $permList)],
            $EditUser
        );

        return true;
    }

    //endregion
}
