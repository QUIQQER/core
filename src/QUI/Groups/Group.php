<?php

/**
 * This file contains QUI\Groups\Group
 */

namespace QUI\Groups;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use QUI;
use QUI\Database\Exception;
use Throwable;

use function array_filter;
use function array_merge;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function json_encode;
use function microtime;
use function mt_rand;
use function mt_srand;

/**
 * A group
 */
class Group extends QUI\QDOM
{
    /**
     * @var array<array-key, mixed>
     */
    protected array $settings;

    /**
     * @var string|int|bool|array<array-key, mixed>
     */
    protected string | int | bool | array $rootId;

    protected ?int $id = null;

    protected ?string $uuid = null;

    /**
     * internal right cache
     *
     * @var array<string, mixed>
     */
    protected mixed $rights = [];

    /**
     * @var array<int, int|string>|null
     */
    protected ?array $childrenIds = null;

    /**
     * internal parent id cache
     *
     * @var array<int, int|string>|null
     */
    protected mixed $parentIds = null;

    /**
     * constructor
     *
     * @param integer|string $id - Group ID
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function __construct(int | string $id)
    {
        if ($id === '') {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.lib.qui.manager.no.groupid'
            ]);
        }

        $this->rootId = QUI::conf('globals', 'root');

        if (is_numeric($id)) {
            $this->id = (int)$id;
        } else {
            $this->uuid = $id;
        }

        // exists groups cache
        try {
            if ($this->uuid) {
                $cache = QUI\Cache\Manager::get('qui/groups/group/' . $this->uuid);
            } else {
                $cache = QUI\Cache\Manager::get('qui/groups/group/' . $this->id);
            }


            if (isset($cache['parentids'])) {
                $this->parentIds = $cache['parentids'];
            }

            if (isset($cache['rights'])) {
                $this->rights = $cache['rights'];
            }

            if (isset($cache['attributes']) && is_array($cache['attributes'])) {
                foreach ($cache['attributes'] as $key => $value) {
                    $this->setAttribute($key, $value);
                }
            }

            if (!empty($cache)) {
                return;
            }
        } catch (QUI\Cache\Exception $Exception) {
            QUI\System\Log::addDebug($Exception->getMessage());
        }


        $result = QUI::getGroups()->getGroupData($id);

        if (!isset($result[0])) {
            if ($id == Manager::EVERYONE_ID || $id == Manager::GUEST_ID) {
                $this->id = (int)$id;
                $this->uuid = (string)$id;
                return;
            }

            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.qui.group.not.found',
                    ['groupId' => $id]
                ),
                404,
                ['groupId' => $id]
            );
        }

        $id = (int)$result[0]['id'];

        try {
            // create uuid for group. if not exists
            if (empty($result[0]['uuid']) && $result[0]['uuid'] !== '0') {
                $uuid = QUI\Utils\Uuid::get();

                if ($id === 0) {
                    $uuid = 0;
                }

                if ($id === 1) {
                    $uuid = 1;
                }

                $result[0]['uuid'] = $uuid;
                $this->uuid = (string)$result[0]['uuid'];

                QUI::getDataBaseConnection()->update(
                    QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                    ['uuid' => $result[0]['uuid']],
                    ['id' => $result[0]['id']]
                );
            }

            // @todo remove it in quiqqer/core v3
            // merge parent id to uuid
            if (is_numeric($result[0]['parent']) && (int)$result[0]['parent'] !== 0) {
                $parentId = (int)$result[0]['parent'];
                $Group = QUI::getGroups()->get($parentId);

                QUI::getDataBaseConnection()->update(
                    QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                    ['parent' => $Group->getUUID()],
                    ['id' => $result[0]['id']]
                );
            }

            // @phpstan-ignore-next-line Legacy fallback kept for defensive group repair.
            if (!isset($result[0]) && $id === Manager::EVERYONE_ID) {
                QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
                    'id' => Manager::EVERYONE_ID,
                    'name' => 'Everyone'
                ]);

                $result = QUI::getGroups()->getGroupData($id);
            // @phpstan-ignore-next-line Legacy fallback kept for defensive group repair.
            } elseif (!isset($result[0]) && $id === Manager::GUEST_ID) {
                QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
                    'id' => Manager::GUEST_ID,
                    'name' => 'Guest'
                ]);

                $result = QUI::getGroups()->getGroupData($id);
            }
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        // @phpstan-ignore-next-line Legacy guard kept for defensive group repair.
        if (!isset($result[0])) {
            throw new QUI\Exception(
                ['quiqqer/core', 'exception.lib.qui.group.doesnt.exist'],
                404
            );
        }

        foreach ($result[0] as $key => $value) {
            $this->setAttribute($key, $value);
        }

        $this->id = (int)$result[0]['id'];
        $this->uuid = $result[0]['uuid'];
        $this->rights = QUI::getPermissionManager()->getPermissions($this);

        // Extras are deprecated - we need an api
        if (isset($result[0]['extra'])) {
            $extraList = $this->getListOfExtraAttributes();
            $extras = [];
            $extraData = json_decode($result[0]['extra'], true);

            if (!is_array($extraData)) {
                $extraData = [];
            }

            foreach ($extraList as $attribute) {
                $extras[$attribute] = true;
            }

            foreach ($extraData as $attribute => $value) {
                if (isset($extras[$attribute])) {
                    $this->setAttribute($attribute, $value);
                }
            }
        }

        $this->createCache();

        QUI::getEvents()->fireEvent('groupLoad', [$this]);
    }

    /**
     * set a group attribute
     * ID cannot be set
     *
     * @param string $name - Attribute name
     * @param mixed $value - value
     */
    public function setAttribute(string $name, mixed $value): void
    {
        if ($name !== 'id') {
            parent::setAttribute($name, $value);
        }
    }

    /**
     * @deprecated
     */
    public function getId(): int
    {
        if ($this->id === null) {
            throw new \LogicException('Group ID was not initialized.');
        }

        return $this->id;
    }

    public function getUUID(): string
    {
        if ($this->uuid === null) {
            throw new \LogicException('Group UUID was not initialized.');
        }

        return $this->uuid;
    }

    /**
     * Return the list which extra attributes exist
     * Plugins could extend the group attributes
     *
     * Documentation:
     * - https://www.quiqqer.com/docs/developer/package-reference/user-xml
     * - https://www.quiqqer.com/docs/developer/package-reference/group-xml
     *
     * @return array<array-key, mixed>
     */
    protected function getListOfExtraAttributes(): array
    {
        return Manager::getListOfExtraAttributes();
    }

    /**
     * creates the group cache
     *
     * @ignore
     */
    protected function createCache(): void
    {
        QUI\Cache\Manager::set('quiqqer/groups/group/' . $this->getUUID(), [
            'parentids' => $this->getParentIds(),
            'attributes' => $this->getAttributes(),
            'rights' => $this->rights
        ]);
    }

    /**
     * @return array<int, int|string>
     */
    public function getParentIds(): array
    {
        if ($this->parentIds !== null) {
            return $this->parentIds;
        }

        $this->parentIds = [];

        try {
            $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
            $row = QUI::getQueryBuilder()
                ->select('id', 'parent')
                ->from($Platform->quoteSingleIdentifier(Manager::table()))
                ->where('uuid = :uuid')
                ->setParameter('uuid', $this->getUUID())
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            QUI\System\Log::addError($DBALException->getMessage());
            return [];
        } catch (\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
            return [];
        }

        $result = $row ? [$row] : [];

        if (!isset($result[0])) {
            return [];
        }

        $visited = [$this->getUUID() => true];
        $parentId = $result[0]['parent'];

        if (isset($visited[(string)$parentId])) {
            return $this->parentIds;
        }

        $this->parentIds[] = $parentId;

        if (!empty($parentId)) {
            $visited[(string)$parentId] = true;
            $this->getParentIdsHelper($parentId, $visited);
        }

        return $this->parentIds ?? [];
    }

    /**
     * @param array<string, bool> $visited
     */
    private function getParentIdsHelper(int | string $id, array &$visited): void
    {
        try {
            $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
            $row = QUI::getQueryBuilder()
                ->select('id', 'parent')
                ->from($Platform->quoteSingleIdentifier(Manager::table()))
                ->where('uuid = :uuid')
                ->setParameter('uuid', $id)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            QUI\System\Log::addError($DBALException->getMessage());
            return;
        }

        if (!$row || !isset($row['parent'])) {
            return;
        }

        $parentId = $row['parent'];

        if (empty($parentId) || isset($visited[(string)$parentId])) {
            return;
        }

        $visited[(string)$parentId] = true;
        $this->parentIds[] = $parentId;

        $this->getParentIdsHelper($parentId, $visited);
    }

    /**
     * Deletes the group and subgroups
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function delete(): void
    {
        // @todo remove getId in QUIQQER V3
        if (
            (int)QUI::conf('globals', 'root') === $this->getId()
            || QUI::conf('globals', 'root') === $this->getUUID()
        ) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.lib.qui.group.root.delete'
            ]);
        }

        QUI::getEvents()->fireEvent('groupDelete', [$this]);

        try {
            $Connection = QUI::getDataBaseConnection();
            $groupsTable = QUI\Utils\Doctrine::quoteIdentifier(Manager::table());
            $usersTable = QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table());

            // A cached nonrecursive traversal may not contain all descendants.
            $this->childrenIds = null;
            $children = $this->getChildrenIds(true) ?? [];
            $groupUuids = array_merge($children, [$this->getUUID()]);
            $groups = $Connection->createQueryBuilder()
                ->select('id', 'uuid')
                ->from($groupsTable)
                ->where('uuid IN (:uuids)')
                ->setParameter('uuids', $groupUuids, ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();

            $Connection->transactional(static function (Connection $Connection) use (
                $groups,
                $groupsTable,
                $usersTable
            ): void {
                $Statement = $Connection->prepare(
                    "UPDATE {$usersTable}
                    SET usergroup = CASE WHEN usergroup = :singleGroup THEN ''
                        ELSE replace(usergroup, :search, :replace) END
                    WHERE usergroup LIKE :memberships OR usergroup = :groupId"
                );

                foreach ($groups as $group) {
                    // Numeric memberships remain supported until Core v3.
                    foreach ([$group['uuid'], (string)$group['id']] as $groupId) {
                        $Statement->bindValue('singleGroup', $groupId);
                        $Statement->bindValue('search', ',' . $groupId . ',');
                        $Statement->bindValue('replace', ',');
                        $Statement->bindValue('memberships', '%,' . $groupId . ',%');
                        $Statement->bindValue('groupId', $groupId);
                        $Statement->executeStatement();
                    }

                    $Connection->delete($groupsTable, ['uuid' => $group['uuid']]);
                }
            });
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        QUI::getGroups()->clearCache();
    }

    /**
     * return the subgroup ids
     *
     * @param boolean $recursive - recursive true / false
     * @param array<string, mixed> $params - SQL Params (limit, order)
     *
     * @return array<int, int|string>
     *
     * @throws Exception
     */
    public function getChildrenIds(bool $recursive = false, array $params = []): ?array
    {
        if ($this->childrenIds) {
            return $this->childrenIds;
        }

        $this->childrenIds = [];

        try {
            $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
            $QueryBuilder = QUI::getQueryBuilder()
                ->select('uuid', 'parent')
                ->from($Platform->quoteSingleIdentifier(Manager::table()))
                ->where('parent = :parent')
                ->setParameter('parent', $this->getUUID());

            if (isset($params['order'])) {
                $order = explode(' ', (string)$params['order'], 2);
                $QueryBuilder->orderBy($order[0], $order[1] ?? null);
            }

            if (isset($params['limit'])) {
                $limit = explode(',', (string)$params['limit'], 2);

                if (isset($limit[1])) {
                    $QueryBuilder->setFirstResult((int)$limit[0]);
                    $QueryBuilder->setMaxResults((int)$limit[1]);
                } else {
                    $QueryBuilder->setFirstResult((int)($params['start'] ?? 0));
                    $QueryBuilder->setMaxResults((int)$limit[0]);
                }
            } elseif (isset($params['start'])) {
                $QueryBuilder->setFirstResult((int)$params['start']);
            }

            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        if (!isset($result[0])) {
            return $this->childrenIds;
        }

        $visited = [$this->getUUID() => true];

        foreach ($result as $entry) {
            if (isset($entry['uuid'])) {
                $uuid = (string)$entry['uuid'];

                if (isset($visited[$uuid])) {
                    continue;
                }

                $visited[$uuid] = true;
                $this->childrenIds[] = $entry['uuid'];

                if ($recursive) {
                    $this->getChildrenIdsHelper($entry['uuid'], $visited);
                }
            }
        }

        return $this->childrenIds;
    }

    /**
     * @param array<string, bool> $visited
     * @throws QUI\Database\Exception
     */
    private function getChildrenIdsHelper(int | string $id, array &$visited): void
    {
        try {
            $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
            $result = QUI::getQueryBuilder()
                ->select('id', 'uuid')
                ->from($Platform->quoteSingleIdentifier(Manager::table()))
                ->where('parent = :parent')
                ->setParameter('parent', $id)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        foreach ($result as $entry) {
            $uuid = (string)$entry['uuid'];

            if (isset($visited[$uuid])) {
                continue;
            }

            $visited[$uuid] = true;
            $this->childrenIds[] = $entry['uuid'];
            $this->getChildrenIdsHelper($entry['uuid'], $visited);
        }
    }

    public function getName(): string
    {
        return $this->getAttribute('name');
    }

    /**
     * Return the group avatar
     *
     * @return QUI\Projects\Media\Image|false
     *
     * @throws QUI\Exception
     */
    public function getAvatar(): QUI\Projects\Media\Image | bool
    {
        $avatar = $this->getAttribute('avatar');

        if (!QUI\Projects\Media\Utils::isMediaUrl($avatar)) {
            $Project = QUI::getProjectManager()->getStandard();
            $Media = $Project->getMedia();

            return $Media->getPlaceholderImage() ?? false;
        }

        try {
            return QUI\Projects\Media\Utils::getImageByUrl($avatar);
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        $Project = QUI::getProjectManager()->getStandard();
        $Media = $Project->getMedia();

        return $Media->getPlaceholderImage() ?? false;
    }

    /**
     * saves the group
     * All attributes are set in the database
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function save(): void
    {
        $this->rights = QUI::getPermissionManager()->getRightParamsFromGroup($this);

        $extra = [];
        $attributes = $this->getListOfExtraAttributes();

        foreach ($attributes as $attribute) {
            $extra[$attribute] = $this->getAttribute($attribute);
        }

        // avatar
        $avatar = '';

        if (
            $this->getAttribute('avatar')
            && QUI\Projects\Media\Utils::isMediaUrl($this->getAttribute('avatar'))
        ) {
            $avatar = $this->getAttribute('avatar');
        }

        // check assigned toolbars
        $assignedToolbars = '';
        $toolbar = '';

        if ($this->getAttribute('assigned_toolbar')) {
            $toolbars = explode(',', $this->getAttribute('assigned_toolbar'));
            $toolbars = array_map(
                ['QUI\\Editor\\Manager', 'normalizeToolbarIdentifier'],
                $toolbars
            );

            $assignedToolbars = array_filter($toolbars, static function ($toolbar): bool {
                return QUI\Editor\Manager::existsToolbar($toolbar);
            });

            $assignedToolbars = implode(',', $assignedToolbars);
        }

        if (QUI\Editor\Manager::existsToolbar($this->getAttribute('toolbar'))) {
            $toolbar = QUI\Editor\Manager::normalizeToolbarIdentifier(
                $this->getAttribute('toolbar')
            );
        }

        // saving
        QUI::getEvents()->fireEvent('groupSaveBegin', [$this]);
        QUI::getEvents()->fireEvent('groupSave', [$this]);

        try {
            QUI::getDataBaseConnection()->update(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                [
                    'name' => $this->getAttribute('name'),
                    'rights' => json_encode($this->rights),
                    'extra' => json_encode($extra),
                    'avatar' => $avatar,
                    'assigned_toolbar' => $assignedToolbars,
                    'toolbar' => $toolbar
                ],
                ['uuid' => $this->getUUID()]
            );
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $this->createCache();

        QUI::getEvents()->fireEvent('groupSaveEnd', [$this]);
    }

    /**
     * Activate the group
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function activate(): void
    {
        try {
            QUI::getDataBaseConnection()->update(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['active' => 1],
                ['uuid' => $this->getUUID()]
            );
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $this->setAttribute('active', 1);
        $this->createCache();

        QUI::getEvents()->fireEvent('groupActivate', [$this]);
    }

    /**
     * deactivate the group
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function deactivate(): void
    {
        try {
            QUI::getDataBaseConnection()->update(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['active' => 0],
                ['uuid' => $this->getUUID()]
            );
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $this->setAttribute('active', 0);
        $this->createCache();

        QUI::getEvents()->fireEvent('groupDeactivate', [$this]);
    }

    public function isActive(): bool
    {
        return (bool)$this->getAttribute('active');
    }

    /**
     * @deprecated
     *
     * @return bool|int|array<array-key, mixed>|string
     */
    public function hasRight(string $right): bool | int | array | string
    {
        return $this->hasPermission($right);
    }

    /**
     * @return bool|int|array<array-key, mixed>|string
     */
    public function hasPermission(string $permission): bool | int | array | string
    {
        return $this->rights[$permission] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRights(): array
    {
        return $this->rights;
    }

    /**
     * @param array<string, mixed> $rights
     *
     * @throws QUI\Exception
     */
    public function setRights(array $rights = []): void
    {
        $User = QUI::getUserBySession();

        if (!$User->isSU()) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.lib.qui.group.no.edit.permissions'
            ]);
        }

        foreach ($rights as $k => $v) {
            $this->rights[$k] = $v;
        }
    }

    public function existsRight(string $right): bool
    {
        if ($this->existsAttribute($right)) {
            return true;
        }

        if (isset($this->rights[$right])) {
            return true;
        }

        return false;
    }

    /**
     * @throws QUI\Groups\Exception
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function setParent(int | string $parentId): void
    {
        // @todo remove getId in QUIQQER V3
        // you can't set for the root group, everyone or guest a parent group
        if (
            $this->getId() == QUI::conf('globals', 'root')
            || $this->getId() == Manager::EVERYONE_ID
            || $this->getId() == Manager::GUEST_ID
            || $this->getUUID() == QUI::conf('globals', 'root')
            || $this->getUUID() == Manager::EVERYONE_ID
            || $this->getUUID() == Manager::GUEST_ID
        ) {
            return;
        }

        $NewParent = QUI::getGroups()->get($parentId);
        $children = $this->getChildrenIds(true);

        if (
            $NewParent->getUUID() === $this->getUUID()
            || in_array($NewParent->getUUID(), $children ?? [], true)
        ) {
            throw new QUI\Groups\Exception(
                [
                    'quiqqer/core',
                    'exception.group.set.parent.not.allowed'
                ],
                400,
                [
                    'groupId' => $this->getUUID(),
                    'newParent' => $NewParent->getUUID(),
                    'currentParent' => $this->getParent()?->getUUID()
                ]
            );
        }


        $this->setAttribute('parent', $NewParent->getId());

        try {
            QUI::getDataBaseConnection()->update(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['parent' => $NewParent->getUUID()],
                ['uuid' => $this->getUUID()]
            );
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        QUI::getEvents()->fireEvent('setParent', [$this, $NewParent]);
    }

    /**
     * @throws QUI\Exception
     */
    public function getParent(): Group | Everyone | Guest | null
    {
        if ($this->getAttribute('parent') === null) {
            return null;
        }

        if ($this->getAttribute('parent')) {
            return QUI::getGroups()->get($this->getAttribute('parent'));
        }

        $ids = $this->getParentIds();

        if (isset($ids[0])) {
            return QUI::getGroups()->get($ids[0]);
        }

        return null;
    }

    /**
     * @throws QUI\Exception
     */
    public function addUser(QUI\Users\User $User): void
    {
        $User->addToGroup($this->getUUID());
    }

    /**
     * @throws QUI\Exception
     */
    public function removeUser(QUI\Users\User $User): void
    {
        $User->removeGroup($this);
    }

    /**
     * @return array<int, int|string>
     */
    public function getUserIds(): array
    {
        $userIds = [];
        $users = $this->getUsers();

        foreach ($users as $row) {
            $userIds[] = $row['uuid'];
        }

        return $userIds;
    }

    /**
     * return the users from the group
     *
     * @param array<string, mixed> $params - SQL Params
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(array $params = []): array
    {
        $uuid = $this->getUUID();

        try {
            $query = QUI::getQueryBuilder()
                ->select('*')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table()))
                ->where('usergroup LIKE :groupLike OR usergroup = :groupEqual')
                ->setParameter('groupLike', '%,' . $uuid . ',%')
                ->setParameter('groupEqual', $uuid);

            QUI\Utils\Doctrine::parseDbArrayToQueryBuilder($query, $params);

            return $query->executeQuery()->fetchAllAssociative();
        } catch (Throwable $e) {
            QUI\System\Log::addError($e->getMessage());
            return [];
        }
    }

    /**
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function getUserByName(string $username): QUI\Interfaces\Users\User
    {
        try {
            $row = QUI::getQueryBuilder()
                ->select('id', 'uuid')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table()))
                ->where('username = :username')
                ->andWhere('usergroup LIKE :usergroup')
                ->setParameter('username', $username)
                ->setParameter('usergroup', '%,' . $this->getUUID() . ',%')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $result = $row ? [$row] : [];

        if (!isset($result[0])) {
            throw new QUI\Exception(
                [
                    'quiqqer/core',
                    'exception.lib.qui.group.user.not.found'
                ],
                404
            );
        }

        return QUI::getUsers()->get($result[0]['uuid']);
    }

    /**
     * @param array<string, mixed> $params - SQL Params
     *
     * @return integer
     *
     * @throws Exception
     */
    public function countUser(array $params = []): int
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder()
                ->select('id')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table()))
                ->where('usergroup LIKE :usergroup')
                ->setParameter('usergroup', '%,' . $this->getUUID() . ',%');

            if (!empty($params['order'])) {
                $order = explode(' ', (string)$params['order'], 2);
                $QueryBuilder->orderBy($order[0], $order[1] ?? null);
            }

            if (isset($params['limit'])) {
                $limit = explode(',', (string)$params['limit'], 2);

                if (isset($limit[1])) {
                    $QueryBuilder->setFirstResult((int)$limit[0]);
                    $QueryBuilder->setMaxResults((int)$limit[1]);
                } else {
                    $QueryBuilder->setFirstResult((int)($params['start'] ?? 0));
                    $QueryBuilder->setMaxResults((int)$limit[0]);
                }
            } elseif (isset($params['start'])) {
                $QueryBuilder->setFirstResult((int)$params['start']);
            }

            $CountQueryBuilder = QUI::getQueryBuilder();
            $count = $CountQueryBuilder
                ->select('COUNT(*)')
                ->from('(' . $QueryBuilder->getSQL() . ')', 'group_users')
                ->setParameter('usergroup', '%,' . $this->getUUID() . ',%')
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        return (int)$count;
    }

    /**
     * @throws QUI\Exception
     */
    public function isParent(int | string $id, bool $recursive = false): bool
    {
        if ($recursive) {
            if (in_array($id, $this->getParentIds(), true)) {
                return true;
            }

            return false;
        }

        $Parent = $this->getParent();

        if ($Parent === null) {
            return false;
        }

        if (is_numeric($id)) {
            return $Parent->getId() === (int)$id;
        }

        return $Parent->getUUID() === $id;
    }

    public function hasChildren(): int
    {
        return count($this->getChildren());
    }

    /**
     * Returns the subgroups
     *
     * @param array<string, mixed> $params - Where Parameter
     *
     * @return array<int, mixed>
     */
    public function getChildren(array $params = []): array
    {
        try {
            $ids = $this->getChildrenIds(false, $params) ?? [];
        } catch (\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
            return [];
        }

        $children = [];
        $Groups = QUI::getGroups();

        foreach ($ids as $id) {
            if (
                is_numeric($id)
                && ((int)$id === Manager::GUEST_ID || (int)$id === Manager::EVERYONE_ID)
            ) {
                continue;
            }

            try {
                $Child = $Groups->get($id);

                $children[] = array_merge(
                    $Child->getAttributes(),
                    ['hasChildren' => $Child->hasChildren()]
                );
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        return $children;
    }

    /**
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function createChild(string $name, ?QUI\Interfaces\Users\User $ParentUser = null): Group
    {
        // check, is the user allowed to create new users
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.admin.groups.create',
            $ParentUser
        );

        $create = true;
        $newId = false;

        try {
            // @todo IMPORTANT!!! wird wahrscheinlich nicht mehr benötigt, da wir uuids nutzen?
            while ($create) {
                $rand = (int)(microtime(true) * 1_000_000);
                mt_srand($rand);
                $newId = mt_rand(10, 1_000_000_000);

                $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
                $row = QUI::getQueryBuilder()
                    ->select('id')
                    ->from($Platform->quoteSingleIdentifier(Manager::table()))
                    ->where('id = :id')
                    ->setParameter('id', $newId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                if (!$row || !$row['id']) {
                    $create = false;
                }
            }

            QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
                'id' => $newId,
                'uuid' => QUI\Utils\Uuid::get(),
                'name' => $name,
                'parent' => $this->getUUID(),
                'active' => 0
            ]);
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $Group = QUI::getGroups()->get($newId);

        // set standard permissions
        QUI::getPermissionManager()->importPermissionsForGroup($Group, $ParentUser);

        QUI::getEvents()->fireEvent('groupCreate', [$Group]);

        return $Group;
    }
}
