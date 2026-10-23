<?php

/**
 * This file contains \QUI\Groups\Manager
 */

namespace QUI\Groups;

use DOMElement;
use QUI;
use QUI\Exception;
use QUI\Utils\Security\Orthos;

use function array_filter;
use function array_merge;
use function file_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function microtime;
use function mt_rand;
use function mt_srand;
use function is_object;
use function trim;

/**
 * Group Manager
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Manager extends QUI\QDOM
{
    const GUEST_ID = 0;

    const EVERYONE_ID = 1;

    /**
     * @var array<array-key, mixed>|null
     */
    protected static ?array $getListOfExtraAttributes = null;

    protected ?Everyone $Everyone = null;

    protected ?Guest $Guest = null;

    /**
     * internal group cache
     *
     * @var array<int|string, Group|Everyone|Guest>
     */
    protected array $groups = [];

    /**
     * @var array<int|string, int|string>
     */
    protected array $groupIdsToHashes = [];

    /**
     * @var array<int|string, array<int, array<string, mixed>>>
     */
    protected array $data = [];

    /**
     * @return array<array-key, mixed>
     */
    public static function getListOfExtraAttributes(): array
    {
        if (self::$getListOfExtraAttributes !== null) {
            return self::$getListOfExtraAttributes;
        }

        $cache = 'quiqqer/groups/plugin-attribute-list';

        try {
            $attributes = QUI\Cache\Manager::get($cache);

            if (is_array($attributes)) {
                self::$getListOfExtraAttributes = $attributes;

                return $attributes;
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addDebug($Exception->getMessage());
        }

        $list = QUI::getPackageManager()->getInstalled();
        $attributes = [];

        foreach ($list as $entry) {
            $plugin = $entry['name'];
            $userXml = OPT_DIR . $plugin . '/group.xml';

            if (!file_exists($userXml)) {
                continue;
            }

            $attributes = array_merge(
                $attributes,
                self::readAttributesFromGroupXML($userXml)
            );
        }

        self::$getListOfExtraAttributes = $attributes;

        QUI\Cache\Manager::set($cache, $attributes);

        return $attributes;
    }

    /**
     * @throws Exception
     */
    public function get(int | string $id): Group | Everyone | Guest
    {
        if (is_numeric($id)) {
            $id = (int)$id;
        }

        if ($id === Manager::EVERYONE_ID) {
            if ($this->Everyone === null) {
                $this->Everyone = new Everyone();
            }

            return $this->Everyone;
        }

        if ($id === Manager::GUEST_ID) {
            if ($this->Guest === null) {
                $this->Guest = new Guest();
            }

            return new Guest();
        }

        if (!$id) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.qui.manager.no.groupid'
                )
            );
        }

        if (isset($this->groups[$id])) {
            return $this->groups[$id];
        }

        if (isset($this->groupIdsToHashes[$id])) {
            $hash = $this->groupIdsToHashes[$id];

            if (isset($this->groups[$hash])) {
                return $this->groups[$hash];
            }
        }

        $Group = new Group($id);
        $uuid = $Group->getUUID();

        $this->groups[$uuid] = $Group;
        $this->groupIdsToHashes[$Group->getId()] = $uuid;

        return $this->groups[$uuid];
    }

    /**
     * Read a user.xml and return the attributes,
     * if some extra attributes defined
     *
     * @return array<int, string>
     */
    protected static function readAttributesFromGroupXML(string $file): array
    {
        $Dom = QUI\Utils\Text\XML::getDomFromXml($file);
        $Attr = $Dom->getElementsByTagName('attributes');

        if (!$Attr->length) {
            return [];
        }

        /* @var $Attributes DOMElement */
        $Attributes = $Attr->item(0);

        if ($Attributes === null) {
            return [];
        }

        $list = $Attributes->getElementsByTagName('attribute');

        if (!$list->length) {
            return [];
        }

        $attributes = [];

        for ($c = 0; $c < $list->length; $c++) {
            $Attribute = $list->item($c);

            if ($Attribute === null || $Attribute->nodeName == '#text') {
                continue;
            }

            $attributes[] = trim($Attribute->nodeValue ?? '');
        }

        return $attributes;
    }

    /**
     * Setup for groups
     *
     * @throws QUI\Database\Exception
     */
    public function setup(): void
    {
        $root = QUI::conf('globals', 'root');

        if (!empty($root) && (is_int($root) || is_string($root))) {
            try {
                $this->get($root);
                return;
            } catch (QUI\Exception) {
            }
        }

        $uuid = $root;

        if (empty($uuid) || !is_string($uuid) || is_numeric($uuid)) {
            $uuid = QUI\Utils\Uuid::get();
        }

        try {
            $newId = false;

            while ($newId === false) {
                mt_srand((int)(microtime(true) * 1_000_000));
                $id = mt_rand(10, 1_000_000_000);

                $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
                $row = QUI::getQueryBuilder()
                    ->select('id')
                    ->from($Platform->quoteSingleIdentifier(self::table()))
                    ->where('id = :id')
                    ->setParameter('id', $id)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                if (!$row || !$row['id']) {
                    $newId = $id;
                }
            }

            QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(self::table()), [
                'id' => $newId,
                'uuid' => $uuid,
                'name' => 'Root',
                'parent' => 0,
                'active' => 1,
                'toolbar' => ''
            ]);
        } catch (\Doctrine\DBAL\Exception $DBALException) {
            throw new QUI\Database\Exception(
                $DBALException->getMessage(),
                (int)$DBALException->getCode()
            );
        }

        $Config = QUI::getConfig('etc/conf.ini.php');
        $Config->setValue('globals', 'root', $uuid);
        $Config->save();
    }

    public static function table(): string
    {
        return QUI::getDBTableName('groups');
    }

    /**
     * @throws Exception
     */
    public function firstChild(): Group
    {
        return $this->get(QUI::conf('globals', 'root'));
    }

    /**
     * Return the db data of a group
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupData(int | string $groupId): array
    {
        if (isset($this->data[$groupId])) {
            return $this->data[$groupId];
        }

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $QueryBuilder = $Connection->createQueryBuilder();
            $groupField = is_numeric($groupId) ? 'id' : 'uuid';
            $value = is_numeric($groupId) ? (int)$groupId : (string)$groupId;

            $row = $QueryBuilder
                ->select('*')
                ->from($Platform->quoteSingleIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq($Platform->quoteSingleIdentifier($groupField), ':groupId'))
                ->setParameter('groupId', $value)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        if (empty($row)) {
            return [];
        }

        $result = [$row];
        $uuid = $row['uuid'];
        $this->data[$uuid] = $result;
        $this->data[$groupId] = $result;

        return $result;
    }

    /**
     * @throws Exception
     */
    public function getGroupNameById(int | string $id): string
    {
        $data = $this->getGroupData($id);

        if (isset($data[0]['name'])) {
            return (string)$data[0]['name'];
        }

        try {
            return (string)$this->get($id)->getAttribute('name');
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return '';
    }

    /**
     * Discard group objects and lookup data after deleting a group hierarchy.
     */
    public function clearCache(): void
    {
        $this->groups = [];
        $this->groupIdsToHashes = [];
        $this->data = [];

        QUI\Cache\Manager::clearGroupsCache();
        QUI\Cache\Manager::clear('qui/groups/group/');
    }

    /**
     * @param boolean $objects - as objects=true, as array=false
     *
     * @return ($objects is true
     *     ? array<int, Group|Everyone|Guest>
     *     : array<int, array<string, mixed>>)
     *
     * @throws QUI\Database\Exception
     */
    public function getAllGroups(bool $objects = false): array
    {
        if (!$objects) {
            try {
                $Connection = QUI::getDataBaseConnection();
                $Platform = $Connection->getDatabasePlatform();

                return $Connection
                    ->createQueryBuilder()
                    ->select('*')
                    ->from($Platform->quoteSingleIdentifier(self::table()))
                    ->orderBy($Platform->quoteSingleIdentifier('name'))
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (\Throwable $Exception) {
                QUI\System\Log::addError($Exception->getMessage());

                return [];
            }
        }

        $result = [];
        $ids = $this->getAllGroupIds();

        foreach ($ids as $id) {
            try {
                $result[] = $this->get($id['id']);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllGroupIds(): array
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();

            return $Connection
                ->createQueryBuilder()
                ->select('id', 'uuid')
                ->from($Platform->quoteSingleIdentifier(self::table()))
                ->orderBy($Platform->quoteSingleIdentifier('name'))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }
    }

    /**
     * @param array<string, mixed> $params - QUI\Database\DB params
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(array $params = []): array
    {
        return $this->searchHelper($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchHelper(array $params): array
    {
        $params = Orthos::clearArray($params);

        $allowOrderFields = [
            'id',
            'uuid',
            'name',
            'parent',
            'active'
        ];

        $allowSearchFields = [
            'id' => true,
            'uuid' => true,
            'name' => true,
            'parent' => true,
            'active' => true
        ];

        $max = 10;
        $start = 0;

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $QueryBuilder = $Connection->createQueryBuilder();

            if (isset($params['count'])) {
                $QueryBuilder->select('COUNT(id) AS count');
            } else {
                $QueryBuilder->select('*');
            }

            $QueryBuilder->from($Platform->quoteSingleIdentifier(self::table()));
            $paramIndex = 0;

            $addCondition = static function (
                string $field,
                mixed $data,
                string $method
            ) use (
                $QueryBuilder,
                $Platform,
                &$paramIndex
            ): void {
                $parameter = 'param' . $paramIndex++;
                $column = $Platform->quoteSingleIdentifier($field);

                if (is_array($data)) {
                    $type = $data['type'] ?? '=';
                    $value = $data['value'] ?? '';
                } else {
                    $type = '=';
                    $value = $data;
                }

                if ($type === '%LIKE%') {
                    $QueryBuilder->{$method}($QueryBuilder->expr()->like($column, ':' . $parameter));
                    $QueryBuilder->setParameter($parameter, '%' . $value . '%');
                    return;
                }

                $QueryBuilder->{$method}($QueryBuilder->expr()->eq($column, ':' . $parameter));
                $QueryBuilder->setParameter($parameter, $value);
            };

            if (isset($params['where']) && is_array($params['where'])) {
                foreach ($params['where'] as $field => $data) {
                    if (!isset($allowSearchFields[$field])) {
                        continue;
                    }

                    $addCondition($field, $data, 'andWhere');
                }
            }

            if (isset($params['where_or']) && is_array($params['where_or'])) {
                foreach ($params['where_or'] as $field => $data) {
                    if (!isset($allowSearchFields[$field])) {
                        continue;
                    }

                    $addCondition($field, $data, 'orWhere');
                }
            }

            if (isset($params['search']) && !isset($params['searchSettings'])) {
                $addCondition('name', [
                    'type' => '%LIKE%',
                    'value' => $params['search']
                ], 'andWhere');
            } elseif (
                isset($params['search'])
                && isset($params['searchSettings'])
                && is_array($params['searchSettings'])
            ) {
                foreach ($params['searchSettings'] as $field) {
                    if (!isset($allowSearchFields[$field])) {
                        continue;
                    }

                    $addCondition($field, [
                        'type' => '%LIKE%',
                        'value' => $params['search']
                    ], 'orWhere');
                }
            }

            if (!isset($params['count'])) {
                if (isset($params['limit'])) {
                    $max = (int)$params['limit'];
                }

                if (isset($params['start'])) {
                    $start = (int)$params['start'];
                }

                $QueryBuilder->setFirstResult($start);
                $QueryBuilder->setMaxResults($max);

                if (
                    isset($params['order'], $params['field'])
                    && $params['field']
                    && in_array($params['field'], $allowOrderFields)
                ) {
                    $order = strtoupper((string)$params['order']) === 'DESC' ? 'DESC' : 'ASC';
                    $QueryBuilder->orderBy($Platform->quoteSingleIdentifier($params['field']), $order);
                }
            }

            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }
    }

    public function isGroup(mixed $Group): bool
    {
        if (!is_object($Group)) {
            return false;
        }

        return $Group instanceof Group;
    }

    /**
     * @param array<string, mixed> $params - QUI\Database\DB params
     */
    public function count(array $params = []): int
    {
        $params['count'] = true;

        unset($params['limit']);
        unset($params['start']);

        $result = $this->searchHelper($params);

        if (isset($result[0]['count'])) {
            return (int)$result[0]['count'];
        }

        return 0;
    }
}
