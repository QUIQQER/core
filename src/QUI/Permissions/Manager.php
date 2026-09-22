<?php

/**
 * This file contains the \QUI\Permissions\Manager
 */

namespace QUI\Permissions;

use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Groups\Group;
use QUI\Interfaces\Projects\Media\File;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit;
use QUI\Users\User;
use QUI\Utils\Security\Orthos;
use Ramsey\Uuid\Uuid;

use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Rights management
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Manager
{
    const TABLE = 'permissions';

    /**
     * internal right cache
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $cache = [];

    /**
     * Permissions2 data cache
     *
     * @var array<string, array<array-key, mixed>>
     */
    protected array $dataCache = [];

    /**
     * internal ram cache for permissions
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $permissionsCache = [];

    /**
     * constructor
     * load the available rights
     */
    public function __construct()
    {
        try {
            $result = self::fetchRows(self::table());

            foreach ($result as $entry) {
                $this->cache[$entry['name']] = $entry;
            }
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }
    }

    public static function table(): string
    {
        return QUI::getDBTableName(self::TABLE);
    }

    /**
     * Rechte Setup, legt alle Felder für die Rechte an
     *
     * @throws QUI\Database\Exception
     */
    public static function setup(): void
    {
        $table = self::table();

        self::ensureTableColumns($table, [
            "name" => ["type" => "string", "options" => ["length" => 100]],
            "type" => ["type" => "string", "options" => ["length" => 20]],
            "area" => ["type" => "string", "options" => ["length" => 20]],
            "title" => ["type" => "string", "options" => ["length" => 255, "notnull" => false]],
            "desc" => ["type" => "text", "options" => ["notnull" => false]],
            "src" => ["type" => "string", "options" => ["length" => 200, "notnull" => false]],
            "defaultvalue" => ["type" => "text", "options" => ["notnull" => false]]
        ], ["name"]);

        self::ensureTableColumns($table . "2users", [
            "user_id" => ["type" => "string", "options" => ["length" => 50]],
            "permissions" => ["type" => "text", "options" => ["notnull" => false]]
        ]);

        self::ensureTableColumns($table . "2groups", [
            "group_id" => ["type" => "string", "options" => ["length" => 50]],
            "permissions" => ["type" => "text", "options" => ["notnull" => false]]
        ]);

        self::ensureTableColumns($table . "2sites", [
            "project" => ["type" => "string", "options" => ["length" => 200]],
            "lang" => ["type" => "string", "options" => ["length" => 2]],
            "id" => ["type" => "bigint", "options" => ["notnull" => false]],
            "permission" => ["type" => "text", "options" => ["notnull" => false]],
            "value" => ["type" => "text", "options" => ["notnull" => false]]
        ]);

        self::ensureTableColumns($table . "2projects", [
            "project" => ["type" => "string", "options" => ["length" => 200]],
            "lang" => ["type" => "string", "options" => ["length" => 2]],
            "permission" => ["type" => "text", "options" => ["notnull" => false]],
            "value" => ["type" => "text", "options" => ["notnull" => false]]
        ]);

        $table2media = $table . "2media";

        self::ensureTableColumns($table2media, [
            "project" => ["type" => "string", "options" => ["length" => 200]],
            "id" => ["type" => "bigint", "options" => ["notnull" => false]],
            "permission" => ["type" => "text", "options" => ["notnull" => false]],
            "value" => ["type" => "text", "options" => ["notnull" => false]]
        ]);

        $SchemaManager = QUI::getSchemaManager();
        $Table = $SchemaManager->introspectTable($table2media);

        if ($Table->hasColumn("lang")) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff(
                $Table,
                droppedColumns: [$Table->getColumn("lang")]
            ));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     * @param array<int, string> $indexes
     */
    private static function ensureTableColumns(string $tableName, array $columns, array $indexes = []): void
    {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            $Table = new \Doctrine\DBAL\Schema\Table($tableName);

            foreach ($columns as $name => $definition) {
                $Table->addColumn($name, $definition["type"], $definition["options"] ?? []);
            }

            foreach ($indexes as $columnName) {
                $Table->addIndex([$columnName]);
            }

            $SchemaManager->createTable($Table);
            return;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $addedColumns = [];

        foreach ($columns as $name => $definition) {
            if ($Table->hasColumn($name)) {
                continue;
            }

            $addedColumns[] = new \Doctrine\DBAL\Schema\Column(
                $name,
                \Doctrine\DBAL\Types\Type::getType($definition["type"]),
                $definition["options"] ?? []
            );
        }

        if (!empty($addedColumns)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: $addedColumns));
            $Table = $SchemaManager->introspectTable($tableName);
        }

        $TargetTable = clone $Table;

        foreach ($indexes as $columnName) {
            if (self::hasSingleColumnIndex($TargetTable, $columnName)) {
                continue;
            }

            $TargetTable->addIndex([$columnName]);
        }

        $Diff = $SchemaManager->createComparator()->compareTables($Table, $TargetTable);

        if (!$Diff->isEmpty()) {
            $SchemaManager->alterTable($Diff);
        }
    }

    private static function hasSingleColumnIndex(
        \Doctrine\DBAL\Schema\Table $Table,
        string $columnName
    ): bool {
        $columnName = strtolower(str_replace(['`', '"', '[', ']'], '', $columnName));

        foreach ($Table->getIndexes() as $Index) {
            $indexedColumns = $Index->getIndexedColumns();

            if (count($indexedColumns) !== 1) {
                continue;
            }

            $indexedColumnName = strtolower(
                $indexedColumns[0]->getColumnName()->getIdentifier()->getValue()
            );

            if ($indexedColumnName === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search all groups and set the default permissions if the permissions not exists
     */
    public static function importPermissionsForGroups(): void
    {
        $Groups = QUI::getGroups();
        $groups = $Groups->search();

        foreach ($groups as $groupData) {
            try {
                self::importPermissionsForGroup(
                    $Groups->get($groupData['id'])
                );
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError(
                    '\QUI\Permissions\Manager::importPermissionsForGroups() -> ' .
                    $Exception->getMessage()
                );
            }
        }
    }

    /**
     * Set the default permissions for the group
     *
     * @throws QUI\Exception
     */
    public static function importPermissionsForGroup(
        Group $Group,
        null | QUI\Interfaces\Users\User $ParentUser = null
    ): void {
        $Manager = QUI::getPermissionManager();
        $permissions = $Manager->getPermissions($Group);

        if (empty($permissions)) {
            $permissions = $Manager->getPermissions(new QUI\Users\Nobody());
        }

        if (empty($permissions)) {
            // Group has no permissions, so no permissions have to be set; see quiqqer/core#1389
            return;
        }

        $Manager->setPermissions($Group, $permissions, $ParentUser);
    }

    /**
     * Return the current permissions from a group, user, site, project or media
     * Returns the set permissions
     *
     * @return array<string, mixed>
     */
    public function getPermissions(mixed $Obj): array
    {
        $area = $this->objectToArea($Obj);

        switch ($area) {
            case 'project':
                return $this->getProjectPermissions($Obj);

            case 'site':
                return $this->getSitePermissions($Obj);

            case 'media':
                return $this->getMediaPermissions($Obj);
        }

        $cache = $this->getDataCacheId($Obj);

        if (isset($this->permissionsCache[$cache])) {
            return $this->permissionsCache[$cache];
        }

        $permissions = [];

        $data = $this->getData($Obj);
        $_list = $this->getPermissionList($area);

        if (!($Obj instanceof User)) {
            foreach ($_list as $permission => $params) {
                $permissions[$permission] = $params['defaultvalue'] ?? false;
            }
        }

        if (!isset($data[0])) {
            $this->permissionsCache[$cache] = $permissions;

            return $permissions;
        }

        $obj_permissions = json_decode($data[0]['permissions'], true);

        if (!is_array($obj_permissions)) {
            $obj_permissions = [];
        }

        foreach ($obj_permissions as $obj_permission => $value) {
            // parse var type
            if (
                isset($permissions[$obj_permission]) ||
                isset($obj_permissions[$obj_permission])
            ) {
                $permissions[$obj_permission] = $value;
            }
        }

        $this->permissionsCache[$cache] = $permissions;

        return $permissions;
    }

    /**
     * Return the corresponding area for the object
     */
    protected function objectToArea(mixed $Object): string
    {
        if ($Object instanceof QUI\Interfaces\Users\User) {
            return 'user';
        }

        if ($Object instanceof QUI\Groups\Group) {
            return 'groups';
        }

        $className = is_object($Object) ? $Object::class : '';

        return self::classToArea($className);
    }

    /**
     * Return the corresponding area of a php class
     */
    public static function classToArea(string $cls): string
    {
        switch ($cls) {
            case User::class:
            case QUI\Users\SystemUser::class:
            case QUI\Users\Nobody::class:
                return 'user';

            case QUI\Groups\Guest::class:
            case QUI\Groups\Everyone::class:
            case Group::class:
                return 'groups';

            case QUI\Projects\Site::class:
            case QUI\Projects\Site\Edit::class:
            case QUI\Projects\Site\OnlyDB::class:
                return 'site';

            case Project::class:
                return 'project';

            case QUI\Projects\Media::class:
            case QUI\Projects\Media\File::class:
            case QUI\Projects\Media\Folder::class:
            case QUI\Projects\Media\Image::class:
                return 'media';
        }

        return '__null__';
    }

    /**
     * Return the permissions from a site
     *
     * @return array<string, mixed>
     */
    public function getProjectPermissions(Project $Project): array
    {
        $data = $this->getData($Project);
        $_list = $this->getPermissionList('project');

        $permissions = [];

        foreach (array_keys($_list) as $permission) {
            $permissions[$permission] = false;
        }

        foreach ($data as $permission => $value) {
            $permissions[$permission] = $value;
        }

        return $permissions;
    }

    /**
     * Return the permissions data of an object
     *
     * @return array<array-key, mixed>
     */
    protected function getData(mixed $Obj): array
    {
        $table = self::table();
        $area = $this->objectToArea($Obj);
        $cache = $this->getDataCacheId($Obj);

        if (isset($this->dataCache[$cache])) {
            return $this->dataCache[$cache];
        }

        if ($area === "user") {
            /*  $Obj User */
            $this->dataCache[$cache] = self::fetchRows(
                $table . "2users",
                ["user_id" => $Obj->getUUID()],
                1
            );

            return $this->dataCache[$cache];
        }

        if ($area === "groups") {
            /*  $Obj Group */
            $this->dataCache[$cache] = self::fetchRows(
                $table . "2groups",
                ["group_id" => $Obj->getUUID()],
                1
            );

            return $this->dataCache[$cache];
        }

        if ($area === "project") {
            /*  $Obj Project */
            $data = self::fetchRows($table . "2projects", [
                "project" => $Obj->getName(),
                "lang" => $Obj->getLang()
            ]);

            $result = [];

            foreach ($data as $entry) {
                $result[$entry["permission"]] = $entry["value"];
            }

            $this->dataCache[$cache] = $result;

            return $this->dataCache[$cache];
        }

        if ($area === "site") {
            /*  $Obj QUI\Projects\Site */
            $Project = $Obj->getProject();

            try {
                $data = self::fetchRows($table . "2sites", [
                    "project" => $Project->getName(),
                    "lang" => $Project->getLang(),
                    "id" => $Obj->getId()
                ]);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeDebugException($Exception);

                return [];
            }

            $result = [];

            foreach ($data as $entry) {
                $result[$entry["permission"]] = $entry["value"];
            }

            $this->dataCache[$cache] = $result;

            return $this->dataCache[$cache];
        }

        if ($area === "media") {
            /*  $Obj QUI\Projects\Media\Item */
            /*  $Project Project */
            $Media = $Obj->getMedia();
            $Project = $Media->getProject();

            $data = self::fetchRows($table . "2media", [
                "project" => $Project->getName(),
                "id" => $Obj->getId()
            ]);

            $result = [];

            foreach ($data as $entry) {
                $result[$entry["permission"]] = $entry["value"];
            }

            $this->dataCache[$cache] = $result;

            return $this->dataCache[$cache];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string> $select
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRows(string $table, array $where = [], int | null $limit = null, array $select = ["*"]): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $selectParts = [];

        foreach ($select as $field) {
            if ($field === "*") {
                $selectParts[] = "*";
                continue;
            }

            $selectParts[] = $Platform->quoteSingleIdentifier((string)$field);
        }

        $QueryBuilder
            ->select(...$selectParts)
            ->from($Platform->quoteSingleIdentifier($table));

        $whereIndex = 0;

        foreach ($where as $field => $value) {
            $parameter = "where" . $whereIndex;
            $QueryBuilder
                ->andWhere($Platform->quoteSingleIdentifier((string)$field) . " = :" . $parameter)
                ->setParameter($parameter, $value);
            $whereIndex++;
        }

        if ($limit !== null) {
            $QueryBuilder->setMaxResults($limit);
        }

        return $QueryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return the internal permission cache id
     */
    protected function getDataCacheId(mixed $Obj): string
    {
        $area = $this->objectToArea($Obj);

        try {
            switch ($area) {
                case 'user':
                    /* @var $Obj User */
                    return 'permission2user_' . $Obj->getUUID();

                case 'groups':
                    /* @var $Obj Group */
                    return 'permission2groups_' . $Obj->getUUID();

                case 'project':
                    /* @var $Obj Project */
                    $id = $Obj->getName() . '_' . $Obj->getLang();

                    return 'permission2groups_' . $id;

                case 'site':
                    /* @var $Obj QUI\Projects\Site */
                    /* @var $Project Project */
                    $Project = $Obj->getProject();
                    $id = $Project->getName() . '_' . $Project->getLang() . '_' . $Obj->getId();

                    return 'permission2site_' . $id;

                case 'media':
                    /* @var $Obj File */
                    /* @var $Project Project */
                    $Project = $Obj->getProject();
                    $id = $Project->getName() . '_' . $Project->getLang() . '_' . $Obj->getId();

                    return 'permission2media_' . $id;
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        $objectName = is_object($Obj) ? $Obj::class : $Obj;

        QUI\System\Log::addInfo(
            "Permission Area '$objectName' not found"
        );

        return '__NULL__';
    }

    /**
     * Return all available permissions
     *
     * @param boolean|string $area - optional, specified the area of the permissions
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPermissionList(bool | string $area = false): array
    {
        if (!$area) {
            return $this->cache;
        }

        // if an area is specified
        $result = [];

        foreach ($this->cache as $key => $params) {
            if ($params['area'] == $area) {
                $result[$key] = $params;
                continue;
            }

            switch ($area) {
                case 'user':
                case 'groups':
                    if ($params['area'] == 'global') {
                        $result[$key] = $params;
                        continue 2;
                    }

                    break;
            }

            if (
                empty($params['area'])
                && ($area == 'user' || $area == 'groups')
            ) {
                $result[$key] = $params;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSitePermissions(QUI\Interfaces\Projects\Site $Site): array
    {
        if (QUI\Projects\Site\Utils::isSiteObject($Site) === false) {
            return [];
        }


        $data = $this->getData($Site);
        $_list = $this->getPermissionList('site');

        $permissions = [];

        foreach (array_keys($_list) as $permission) {
            $permissions[$permission] = false;
        }

        foreach ($data as $permission => $value) {
            $permissions[$permission] = $value;
        }

        return $permissions;
    }

    /**
     * Return the permissions from a media item
     *
     * @param QUI\Projects\Media\Item $MediaItem
     *
     * @return array<string, mixed>
     */
    public function getMediaPermissions($MediaItem): array
    {
        if (QUI\Projects\Media\Utils::isItem($MediaItem) === false) {
            return [];
        }


        $data = $this->getData($MediaItem);
        $_list = $this->getPermissionList('media');

        $permissions = [];

        foreach (array_keys($_list) as $permission) {
            $permissions[$permission] = false;
        }

        foreach ($data as $permission => $value) {
            $permissions[$permission] = $value;
        }

        return $permissions;
    }

    /**
     * Set the permissions for an object
     *
     * @param QUI\Interfaces\Users\User|Group|Project|Site|Edit|QUI\Projects\Media\Item $Obj
     * @param array<string, mixed> $permissions - Array of permissions
     * @param QUI\Interfaces\Users\User|null $EditUser - Edit user
     *
     * @throws Exception
     * @throws QUI\Exception
     * @throws ExceptionStack
     * @throws Exception
     * @todo  permissions for project
     */
    public function setPermissions(
        mixed $Obj,
        array $permissions,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        if (empty($permissions)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.permissions.are.empty')
            );
        }

        $area = $this->objectToArea($Obj);

        switch ($area) {
            case 'user':
            case 'groups':
                break;

            case 'project':
                if (!($Obj instanceof Project)) {
                    throw new QUI\Exception('Cannot set Permissions. Object not allowed');
                }

                $this->setProjectPermissions($Obj, $permissions, $EditUser);

                return;

            case 'site':
                if (!($Obj instanceof QUI\Interfaces\Projects\Site)) {
                    throw new QUI\Exception('Cannot set Permissions. Object not allowed');
                }

                $this->setSitePermissions($Obj, $permissions, $EditUser);

                return;

            case 'media':
                if (!($Obj instanceof QUI\Projects\Media\Item)) {
                    throw new QUI\Exception('Cannot set Permissions. Object not allowed');
                }

                $this->setMediaPermissions($Obj, $permissions, $EditUser);

                return;

            default:
                throw new QUI\Exception(
                    'Cannot set Permissions. Object not allowed'
                );
        }

        if (
            !($Obj instanceof QUI\Interfaces\Users\User)
            && !($Obj instanceof Group)
        ) {
            throw new QUI\Exception('Cannot set Permissions. Object not allowed');
        }

        QUI\Permissions\Permission::checkPermission(
            'quiqqer.system.permissions',
            $EditUser
        );

        $_data = $this->getData($Obj); // old permissions
        $list = $this->getPermissionList($area);

        $data = [];

        if (isset($_data[0]['permissions'])) {
            $data = json_decode($_data[0]['permissions'], true);
        }

        foreach ($list as $permission => $params) {
            if (!isset($permissions[$permission])) {
                continue;
            }

            $data[$permission] = $this->cleanValue(
                $params['type'],
                $permissions[$permission]
            );
        }

        $Connection = QUI::getDataBaseConnection();
        $table = self::table();

        $table2users = $table . '2users';
        $table2groups = $table . '2groups';

        // areas
        switch ($area) {
            case 'user':
                /* @var $Obj User */
                if (!isset($_data[0])) {
                    $Connection->insert(
                        $table2users,
                        ['user_id' => $Obj->getUUID()]
                    );
                }

                if (isset($data['permissions'])) {
                    unset($data['permissions']);
                }

                $Connection->update(
                    $table2users,
                    ['permissions' => json_encode($data)],
                    ['user_id' => $Obj->getUUID()]
                );

                QUI\Cache\Manager::clear($this->getDataCacheId($Obj) . '/complete');
                break;

            case 'groups':
                /* @var $Obj Group */
                if (!isset($_data[0])) {
                    $Connection->insert(
                        $table2groups,
                        ['group_id' => $Obj->getUUID()]
                    );
                }

                if (isset($data['permissions'])) {
                    unset($data['permissions']);
                }


                $Connection->update(
                    $table2groups,
                    ['permissions' => json_encode($data)],
                    ['group_id' => $Obj->getUUID()]
                );

                QUI\Cache\Manager::clear('qui/groups/group/' . $Obj->getUUID() . '/');
                QUI\Cache\Manager::clear($this->getDataCacheId($Obj) . '/complete');
                break;
        }

        $cacheId = $this->getDataCacheId($Obj);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }

        QUI\Cache\Manager::clear('qui/admin/menu/');
        QUI\Cache\Manager::clear('settings/backend-menu/');
        QUI\Cache\Manager::clear('quiqqer/permissions/' . $this->getDataCacheId($Obj));

        QUI::getEvents()->fireEvent('permissionsSet', [$Obj, $permissions]);
    }

    /**
     * Set the permissions for a project object
     *
     * @param array<string, mixed> $permissions
     *
     * @throws Exception|\QUI\Permissions\Exception
     */
    public function setProjectPermissions(
        Project $Project,
        array $permissions,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        $data = [];
        $_data = $this->getData($Project);
        $list = $this->getPermissionList('project');

        QUI\Permissions\Permission::checkPermission(
            'quiqqer.system.permissions',
            $EditUser
        );

        // look at permission list and cleanup the values
        foreach ($list as $permission => $params) {
            if (!isset($permissions[$permission])) {
                continue;
            }

            $data[$permission] = $this->cleanValue(
                $params['type'],
                $permissions[$permission]
            );
        }


        // set add permissions
        foreach ($data as $permission => $value) {
            if (is_bool($value)) {
                $value = (int)$value;
            }

            if (is_array($value)) {
                continue;
            }

            if (!isset($_data[$permission])) {
                $this->addProjectPermission($Project, $permission, $value);
                continue;
            }

            $this->setProjectPermission($Project, $permission, $value);
        }


        $cacheId = $this->getDataCacheId($Project);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Cleanup the value for the type
     *
     * @param array<array-key, mixed>|int|string $val
     *
     * @return array<array-key, mixed>|bool|int|string
     */
    protected function cleanValue(
        string $type,
        int | array | string $val
    ): int | bool | array | string {
        switch ($type) {
            case 'int':
                $val = (int)$val;
                break;

            case 'users_and_groups':
                if (is_array($val)) {
                    return '';
                }

                $val = (string)$val;

                // ue8547a92-9d03-11f0-b2b2-42476d08324f <- user-id
                // ge8547a92-9d03-11f0-b2b2-42476d08324f <- group-id
                if (str_contains($val, ',')) {
                    $val = explode(',', $val);
                    $val = array_map(function ($val) {
                        if (Uuid::isValid(mb_substr($val, 1))) {
                            return $val;
                        }

                        return preg_replace('/[^0-9,ug]/', '', $val) ?? $val;
                    }, $val);
                    $val = array_filter($val);
                    $val = implode(',', $val);
                } else {
                    if (!Uuid::isValid(mb_substr($val, 1))) {
                        $val = preg_replace('/[^0-9,ug]/', '', $val) ?? $val;
                    }
                }
                break;

            case 'users':
            case 'groups':
                if (is_array($val)) {
                    return '';
                }

                $val = (string)$val;

                if (str_contains($val, ',')) {
                    $val = explode(',', $val);
                    $val = array_map(function ($val) {
                        if (Uuid::isValid($val)) {
                            return $val;
                        }

                        return preg_replace('/[^0-9]/', '', $val) ?? $val;
                    }, $val);
                    $val = array_filter($val);
                    $val = implode(',', $val);
                } else {
                    if (!Uuid::isValid($val)) {
                        $val = preg_replace('/[^0-9,]/', '', $val) ?? $val;
                    }
                }
                break;

            case 'user':
            case 'group':
                if (is_array($val)) {
                    return '';
                }

                $val = (string)$val;

                if (!Uuid::isValid($val)) {
                    $val = preg_replace('/[^0-9]/', '', $val) ?? $val;
                }
                break;

            case 'array':
                $val = Orthos::clearArray($val);
                break;

            case 'string':
                break;

            default:
                $val = (bool)$val;
                break;
        }

        return $val;
    }

    /**
     * Add a new permission entry for site
     *
     * @throws Exception
     */
    protected function addProjectPermission(
        Project $Project,
        string $permission,
        int | string $value
    ): void {
        QUI::getDataBaseConnection()->insert(
            self::table() . '2projects',
            [
                'project' => $Project->getName(),
                'lang' => $Project->getLang(),
                'permission' => $permission,
                'value' => $value
            ]
        );
    }

    /**
     * Updates the permission entry for the site
     *
     * @throws Exception
     */
    protected function setProjectPermission(
        Project $Project,
        string $permission,
        int | string $value
    ): void {
        QUI::getDataBaseConnection()->update(
            self::table() . '2projects',
            ['value' => $value],
            [
                'project' => $Project->getName(),
                'lang' => $Project->getLang(),
                'permission' => $permission
            ]
        );


        $cacheId = $this->getDataCacheId($Project);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Set the permissions for a site object
     *
     * @param array<string, mixed> $permissions
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public function setSitePermissions(
        QUI\Interfaces\Projects\Site $Site,
        array $permissions,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        if (QUI\Projects\Site\Utils::isSiteObject($Site) === false) {
            return;
        }

        $Site->checkPermission('quiqqer.projects.sites.set_permissions', $EditUser);
        $Site->checkPermission('quiqqer.project.sites.edit', $EditUser);

        $_data = $this->getData($Site);

        $data = [];
        $list = $this->getPermissionList('site');

        // look at permission list and cleanup the values
        foreach ($list as $permission => $params) {
            if (!isset($permissions[$permission])) {
                continue;
            }

            $Perm = $permissions[$permission];

            if (is_string($Perm)) {
                $permissionValue = $Perm;
            } elseif (is_array($Perm)) {
                $permissionValues = [];

                foreach ($Perm as $PermValue) {
                    if (QUI::getUsers()->isUser($PermValue)) {
                        /* @var $PermValue User */
                        $permissionValues[] = 'u' . $PermValue->getUUID();
                        continue;
                    }

                    if (QUI::getGroups()->isGroup($PermValue)) {
                        /* @var $PermValue QUI\Groups\Group */
                        $permissionValues[] = 'g' . $PermValue->getUUID();
                    }
                }

                $permissionValue = implode(',', $permissionValues);
            } elseif (QUI::getUsers()->isUser($Perm)) {
                /* @var $Perm User */
                $permissionValue = 'u' . $Perm->getUUID();
            } elseif (QUI::getGroups()->isGroup($Perm)) {
                /* @var $Perm QUI\Groups\Group */
                $permissionValue = 'g' . $Perm->getUUID();
            } else {
                continue;
            }

            $data[$permission] = $this->cleanValue(
                $params['type'],
                $permissionValue
            );
        }

        // set add permissions
        foreach ($data as $permission => $value) {
            if (is_bool($value)) {
                $value = (int)$value;
            }

            if (is_array($value)) {
                continue;
            }

            if (!isset($_data[$permission])) {
                $this->addSitePermission($Site, $permission, $value);
                continue;
            }

            $this->setSitePermission($Site, $permission, $value);
        }


        $cacheId = $this->getDataCacheId($Site);
        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Add a new permission entry for site
     *
     * @throws Exception
     * @throws QUI\Exception
     */
    protected function addSitePermission(
        QUI\Interfaces\Projects\Site $Site,
        string $permission,
        int | string $value
    ): void {
        $Project = $Site->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->insert($table . '2sites', [
            'project' => $Project->getName(),
            'lang' => $Project->getLang(),
            'id' => $Site->getId(),
            'permission' => $permission,
            'value' => $value
        ]);
    }

    /**
     * Updates the permission entry for the site
     *
     * @throws Exception
     * @throws QUI\Exception
     */
    protected function setSitePermission(
        QUI\Interfaces\Projects\Site $Site,
        string $permission,
        int | string $value
    ): void {
        $Project = $Site->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->update(
            $table . '2sites',
            ['value' => $value],
            [
                'project' => $Project->getName(),
                'lang' => $Project->getLang(),
                'id' => $Site->getId(),
                'permission' => $permission
            ]
        );


        $cacheId = $this->getDataCacheId($Site);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Set the permissions for a site object
     *
     * @param array<string, mixed> $permissions
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public function setMediaPermissions(
        QUI\Projects\Media\Item $MediaItem,
        array $permissions,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        if (QUI\Projects\Media\Utils::isItem($MediaItem) === false) {
            return;
        }

        $MediaItem->checkPermission('quiqqer.projects.media.set_permissions', $EditUser);
        $MediaItem->checkPermission('quiqqer.project.media.edit', $EditUser);

        $_data = $this->getData($MediaItem);

        $data = [];
        $list = $this->getPermissionList('media');

        // look at permission list and cleanup the values
        foreach ($list as $permission => $params) {
            if (!isset($permissions[$permission])) {
                continue;
            }

            $Perm = $permissions[$permission];

            if (is_string($Perm)) {
                $permissionValue = $Perm;
            } elseif (is_array($Perm)) {
                $permissionValues = [];

                foreach ($Perm as $PermValue) {
                    if (QUI::getUsers()->isUser($PermValue)) {
                        /* @var $PermValue User */
                        $permissionValues[] = 'u' . $PermValue->getUUID();
                        continue;
                    }

                    if (QUI::getGroups()->isGroup($PermValue)) {
                        /* @var $PermValue QUI\Groups\Group */
                        $permissionValues[] = 'g' . $PermValue->getUUID();
                    }
                }

                $permissionValue = implode(',', $permissionValues);
            } elseif (QUI::getUsers()->isUser($Perm)) {
                /* @var $Perm User */
                $permissionValue = 'u' . $Perm->getUUID();
            } elseif (QUI::getGroups()->isGroup($Perm)) {
                /* @var $Perm QUI\Groups\Group */
                $permissionValue = 'g' . $Perm->getUUID();
            } else {
                continue;
            }

            $data[$permission] = $this->cleanValue(
                $params['type'],
                $permissionValue
            );
        }

        // set add permissions
        foreach ($data as $permission => $value) {
            if (is_bool($value)) {
                $value = (int)$value;
            }

            if (is_array($value)) {
                continue;
            }

            if (!isset($_data[$permission])) {
                $this->addMediaPermission($MediaItem, $permission, $value);
                continue;
            }

            $this->setMediaPermission($MediaItem, $permission, $value);
        }


        $cacheId = $this->getDataCacheId($MediaItem);
        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }

        if (method_exists($MediaItem, 'deleteCache')) {
            $MediaItem->deleteCache();
        }
    }

    /**
     * @throws QUI\Exception
     */
    protected function addMediaPermission(
        QUI\Projects\Media\Item $MediaItem,
        string $permission,
        int | string $value
    ): void {
        $Media = $MediaItem->getMedia();
        $Project = $Media->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->insert($table . '2media', [
            'project' => $Project->getName(),
            'id' => $MediaItem->getId(),
            'permission' => $permission,
            'value' => $value
        ]);
    }

    /**
     * Updates the permission entry for the media entry
     *
     * @throws QUI\Exception
     */
    protected function setMediaPermission(
        QUI\Projects\Media\Item $MediaItem,
        string $permission,
        int | string $value
    ): void {
        $Media = $MediaItem->getMedia();
        $Project = $Media->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->update(
            $table . '2media',
            ['value' => $value],
            [
                'project' => $Project->getName(),
                'id' => $MediaItem->getId(),
                'permission' => $permission
            ]
        );


        $cacheId = $this->getDataCacheId($MediaItem);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Delete a permission
     *
     * @param string $permission - name of the permission
     *
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function deletePermission(string $permission): void
    {
        $permissions = $this->getPermissionList();

        if (!isset($permissions[$permission])) {
            throw new QUI\Permissions\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permissions.permission.not.found'
                )
            );
        }

        $params = $permissions[$permission];

        if ($params['src'] != 'user') {
            throw new QUI\Permissions\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permissions.delete.only.user.permissions'
                )
            );
        }

        QUI::getDataBaseConnection()->delete(
            self::table(),
            [
                'name' => $permission,
                'src' => 'user'
            ]
        );
    }

    /**
     * Import a permissions.xml
     *
     * @param string $xmlFile - Path to the file
     * @param string $src - optional, the src from where the rights come from
     *                            eq: system, plugin-name, user
     *
     */
    public function importPermissionsFromXml(string $xmlFile, string $src = ''): void
    {
        $rootPermissions = [];
        $permissions = QUI\Utils\Text\XML::getPermissionsFromXml($xmlFile);

        if (!count($permissions)) {
            return;
        }

        $Everyone = null;
        $RootGroup = null;
        $Guest = null;

        $everyonePermissions = [];
        $guestPermissions = [];

        try {
            $RootGroup = QUI::getGroups()->get(QUI::conf('globals', 'root'));
            $rootPermissions = $this->getPermissions($RootGroup);
        } catch (QUI\Exception) {
        }

        try {
            $Everyone = QUI::getGroups()->get(QUI\Groups\Manager::EVERYONE_ID);
            $everyonePermissions = $this->getPermissions($Everyone);
        } catch (QUI\Exception) {
        }

        try {
            $Guest = QUI::getGroups()->get(QUI\Groups\Manager::GUEST_ID);
            $guestPermissions = $this->getPermissions($Guest);
        } catch (QUI\Exception) {
        }

        foreach ($permissions as $permission) {
            $permission['src'] = $src;

            if (!isset($permission['defaultvalue'])) {
                $permission['defaultvalue'] = '';
            }

            if (isset($permission['default'])) {
                $permission['defaultvalue'] = $permission['default'];
            }

            try {
                $this->addPermission($permission);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }

            if (
                isset($permission['rootPermission'])        // if root permission === null, no root permission is set
                && !isset($rootPermissions[$permission['name']]) // if not exists, use root permission default
            ) {
                $rootPermissions[$permission['name']] = $permission['rootPermission'];
            }

            if (
                isset($permission['everyonePermission'])        // if root permission === null, no root permission is set
                && !isset($everyonePermissions[$permission['name']]) // if not exists, use root permission default
            ) {
                $everyonePermissions[$permission['name']] = $permission['everyonePermission'];
            }

            if (
                isset($permission['guestPermission'])        // if root permission === null, no root permission is set
                && !isset($guestPermissions[$permission['name']]) // if not exists, use root permission default
            ) {
                $guestPermissions[$permission['name']] = $permission['guestPermission'];
            }
        }

        if ($RootGroup && count($rootPermissions)) {
            try {
                $this->setPermissions($RootGroup, $rootPermissions);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        if ($Everyone !== null && count($everyonePermissions)) {
            try {
                $this->setPermissions($Everyone, $everyonePermissions);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        if ($Guest !== null && count($guestPermissions)) {
            try {
                $this->setPermissions($Guest, $guestPermissions);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }
    }

    /**
     * Add a permission
     *
     * @param array<string, mixed> $params - Permission params
     *                            array(
     *                            name =>
     *                            desc =>
     *                            area =>
     *                            title => translation.var.var
     *                            type =>
     *                            defaultvalue =>
     *                            src =>
     *                            )
     *
     * @throws QUI\Database\Exception
     */
    public function addPermission(array $params): void
    {
        $Connection = QUI::getDataBaseConnection();
        $needles = [
            'name',
            'title',
            'desc',
            'type',
            'area',
            'src',
            'defaultvalue'
        ];

        foreach ($needles as $needle) {
            if (!isset($params[$needle])) {
                $params[$needle] = '';
            }
        }

        if (empty($params['name'])) {
            return;
        }

        // if exist update it
        $where = [
            'name' => $params['name']
        ];

        $data = [
            'title' => trim($params['title']),
            'desc' => trim($params['desc']),
            'type' => self::parseType($params['type']),
            'area' => self::parseArea($params['area']),
            'src' => $params['src'],
            'defaultvalue' => $params['defaultvalue']
        ];

        if (isset($this->cache[$params['name']])) {
            self::updatePermissionRow($params["name"], $data);
            return;
        }

        $result = self::fetchRows(self::table(), $where, 1);

        if (isset($result[0])) {
            self::updatePermissionRow($params["name"], $data);
            return;
        }

        // if not exist, insert it
        self::insertPermissionRow([
            "name" => $params["name"],
            "title" => trim($params["title"]),
            "desc" => trim($params["desc"]),
            "type" => self::parseType($params["type"]),
            "area" => self::parseArea($params["area"]),
            "src" => $params["src"],
            "defaultvalue" => $params["defaultvalue"]
        ]);

        $this->cache[$params['name']] = $params;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function updatePermissionRow(string $name, array $data): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->update($Platform->quoteSingleIdentifier(self::table()))
            ->where($Platform->quoteSingleIdentifier("name") . " = :permissionName")
            ->setParameter("permissionName", $name);

        $index = 0;

        foreach ($data as $field => $value) {
            $parameter = "value" . $index;
            $QueryBuilder
                ->set($Platform->quoteSingleIdentifier((string)$field), ":" . $parameter)
                ->setParameter($parameter, $value);
            $index++;
        }

        $QueryBuilder->executeStatement();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insertPermissionRow(array $data): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->insert($Platform->quoteSingleIdentifier(self::table()));

        $index = 0;

        foreach ($data as $field => $value) {
            $parameter = "value" . $index;
            $QueryBuilder
                ->setValue($Platform->quoteSingleIdentifier((string)$field), ":" . $parameter)
                ->setParameter($parameter, $value);
            $index++;
        }

        $QueryBuilder->executeStatement();
    }

    /**
     * Return the type if the type is an allowed permission type
     *
     * @param string $type - bool, string, int, group, groups, user, users, users_and_groups
     *
     * @return string
     */
    public static function parseType(string $type): string
    {
        return match ($type) {
            'bool', 'string', 'int', 'array', 'group', 'groups', 'user', 'users', 'users_and_groups' => $type,
            default => 'bool',
        };
    }

    /**
     * Return the area, if the area is an allowed area
     *
     * @param string $area - area string, global, user, groups, site, project, media
     *
     * @return string
     */
    public static function parseArea(string $area): string
    {
        return match ($area) {
            'global', 'user', 'groups', 'site', 'project', 'media' => $area,
            default => '',
        };
    }

    /**
     * Delete all permissions from the package list
     * It does not delete permissions created by the user
     *
     * @throws Exception
     */
    public function deletePermissionsFromPackage(QUI\Package\Package $Package): void
    {
        // remove from cache
        $result = self::fetchRows(
            self::table(),
            ["src" => $Package->getName()],
            null,
            ["name"]
        );

        foreach ($result as $permission) {
            if (isset($this->cache[$permission['name']])) {
                unset($this->cache[$permission['name']]);
            }
        }

        // delete from DB
        QUI::getDataBaseConnection()->delete(
            self::table(),
            ['src' => $Package->getName()]
        );
    }

    /**
     * Return the permission data
     *
     * @param string $permission - Name of the permission
     *
     * @return array<string, mixed>
     *
     * @throws QUI\Exception
     */
    public function getPermissionData(string $permission): array
    {
        if (!isset($this->cache[$permission])) {
            throw new QUI\Exception('Permission not found');
        }

        return $this->cache[$permission];
    }


    /**
     * ab hier old
     */
    /**
     * Return all permissions from a group, user, site, project or media
     *
     * @param QUI\Groups\Group|User|Project|QUI\Projects\Site|QUI\Interfaces\Users\User $Obj
     *
     * @return array<string, mixed>
     */
    public function getCompletePermissionList(mixed $Obj): array
    {
        $cache = 'quiqqer/permissions/' . $this->getDataCacheId($Obj) . '/complete';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }


        $area = $this->objectToArea($Obj);

        switch ($area) {
            case 'project':
                if ($Obj instanceof Project) {
                    return $this->getProjectPermissions($Obj);
                }

                break;

            case 'site':
                if ($Obj instanceof QUI\Interfaces\Projects\Site) {
                    return $this->getSitePermissions($Obj);
                }

                break;
        }

        $permissions = [];

        $data = $this->getData($Obj);
        $_list = $this->getPermissionList($area);

        foreach ($_list as $permission => $params) {
            $permissions[$permission] = false;

            if (isset($params['defaultvalue'])) {
                $permissions[$permission] = $params['defaultvalue'];
            }
        }

        if (!isset($data[0])) {
            return $permissions;
        }

        $obj_permissions = json_decode($data[0]['permissions'], true);

        if (!is_array($obj_permissions)) {
            $obj_permissions = [];
        }

        foreach ($obj_permissions as $obj_permission => $value) {
            // parse var type
            if (isset($permissions[$obj_permission])) {
                $permissions[$obj_permission] = $value;
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $permissions);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        return $permissions;
    }

    /**
     * Remove all permissions from the site
     *
     * @throws Exception
     * @throws QUI\Exception
     */
    public function removeSitePermissions(
        QUI\Interfaces\Projects\Site $Site,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        $Site->checkPermission('quiqqer.projects.site.edit', $EditUser);


        $Project = $Site->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->delete($table . '2sites', [
            'project' => $Project->getName(),
            'lang' => $Project->getLang(),
            'id' => $Site->getId()
        ]);


        $cacheId = $this->getDataCacheId($Site);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    /**
     * Rechte vom Benutzer bekommen
     * Geht besser über User->getPermission('right')
     *
     * @throws QUI\Exception
     *
     * @example
     * $result = getUserPermission($User, 'name.of.the.permission', function($params)
     * {
     * return $params['result'];
     * });
     *
     * @example
     * $right = $User->getPermission($perm, 'maxInteger');
     */
    public function getUserPermission(
        QUI\Interfaces\Users\User $User,
        string $permission,
        callable | bool | string $ruleset = false
    ): mixed {
        // Boolean rights are additive: any user/group grant allows the action.
        // Keep explicit rulesets and non-boolean value precedence unchanged.
        if (!$ruleset && ($this->cache[$permission]['type'] ?? null) === 'bool') {
            return (bool)PermissionOrder::firstGranted($permission, $User, $this);
        }

        /* @var $User User */
        $usersAndGroups = $User->getGroups();
        $result = false;

//        if (!$User->getId()) {
//            return false;
//        }

        // user permission check
        if ($User->getUUID()) {
            if ($ruleset) {
                $usersAndGroups[] = $User;
            } else {
                $userPermissions = $this->getData($User);

                if (isset($userPermissions[0]['permissions'])) {
                    $userPermissions = json_decode(
                        $userPermissions[0]['permissions'],
                        true
                    );

                    if (isset($userPermissions[$permission])) {
                        return $userPermissions[$permission];
                    }
                }
            }
        }

        // group permissions
        if ($ruleset) {
            if (is_string($ruleset) && method_exists(PermissionOrder::class, $ruleset)) {
                $result = PermissionOrder::$ruleset($permission, $usersAndGroups);
            } else {
                throw new QUI\Exception('Unknown ruleset [getUserPermission]');
            }
        } else {
            $result = PermissionOrder::permission($permission, $usersAndGroups);
        }

        return $result;
    }

    // region media
    /**
     * @param QUI\Interfaces\Users\User $User
     *
     * @return array<string, mixed>
     */
    public function getUserPermissionData($User): array
    {
        $userPermissions = $this->getData($User);

        if (isset($userPermissions[0]['permissions'])) {
            $userPermissions = json_decode(
                $userPermissions[0]['permissions'],
                true
            );

            if (is_array($userPermissions)) {
                return $userPermissions;
            }
        }

        return [];
    }

    /**
     * Rechte Array einer Gruppe aus den Attributen erstellen
     * Wird zum Beispiel zum Speichern einer Gruppe verwendet
     *
     * @return array<string, mixed>
     *
     * @todo das muss vielleicht überdacht werden
     */
    public function getRightParamsFromGroup(Group $Group): array
    {
        $result = [];
        $rights = self::fetchRows(self::table(), [], null, ["name", "type"]);

        foreach ($rights as $right) {
            if ($Group->existsRight($right['name']) === false) {
                continue;
            }

            $val = $Group->hasPermission($right['name']);

            // bool, string, int, group, array
            $val = match ($right['type']) {
                'int' => (int)$val,
                'groups' => preg_replace(
                    '/[^0-9,]/',
                    '',
                    is_array($val) ? implode(',', $val) : (string)$val
                ),
                'array' => Orthos::clearArray($val),
                'string' => is_string($val) ? $val : '',
                default => (bool)$val,
            };

            $result[$right['name']] = $val;
        }

        return $result;
    }

    /**
     * Remove all permissions from the site
     *
     * @throws QUI\Exception
     */
    public function removeMediaPermissions(
        QUI\Interfaces\Projects\Media\File $MediaItem,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        $MediaItem->checkPermission('quiqqer.projects.media.edit', $EditUser);

        $Media = $MediaItem->getMedia();
        $Project = $Media->getProject();
        $table = self::table();

        QUI::getDataBaseConnection()->delete($table . '2media', [
            'project' => $Project->getName(),
            'id' => $MediaItem->getId()
        ]);


        $cacheId = $this->getDataCacheId($MediaItem);

        unset($this->dataCache[$cacheId]);

        if (isset($this->permissionsCache[$cacheId])) {
            unset($this->permissionsCache[$cacheId]);
        }
    }

    //endregion
}
