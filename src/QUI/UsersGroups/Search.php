<?php

/**
 * This file contains \QUI\UsersGroups\Search
 */

namespace QUI\UsersGroups;

use Exception;
use QUI;
use QUI\Utils\Security\Orthos;

use function array_merge;
use function explode;
use function implode;
use function is_array;
use function trim;

/**
 * Search for users and groups
 *
 * @author  www.pcsg.de (Patrick Müller)
 * @licence For copyright and license information, please view the /README.md
 */
class Search
{
    const DEFAULT_LIMIT_USERS = 20;

    const DEFAULT_LIMIT_GROUPS = 20;

    /**
     * Search users and groups
     *
     * Returns full details
     *
     * @param string $searchTerm - search term
     * @param array<string, mixed> $searchParams - search parameters
     * @param bool $count (optional) - return count only
     *
     * @return array<string, mixed>
     *
     * @throws QUI\Exception
     */
    public static function search(string $searchTerm, array $searchParams = [], bool $count = false): array
    {
        $searchUsers = false;
        $searchGroups = false;

        if ($count) {
            $searchResult = [
                'users' => 0,
                'groups' => 0
            ];
        } else {
            $searchResult = [
                'users' => [],
                'groups' => []
            ];
        }

        // search in user table
        if (isset($searchParams['searchUsers']) && $searchParams['searchUsers']) {
            $searchUsers = true;
        }

        // search in group table
        if (isset($searchParams['searchGroups']) && $searchParams['searchGroups']) {
            $searchGroups = true;
        }

        if ($searchUsers === false && $searchGroups === false) {
            $searchUsers = true;
        }

        if ($searchUsers) {
            if (empty($searchParams['users'])) {
                throw new QUI\Exception([
                    'quiqqer/core',
                    'exception.usergroups.search.cannot.search.users.without.parameters'
                ]);
            }

            $searchUserParams = $searchParams['users'];

            if (!empty($searchParams['users']['select'])) {
                $searchUserParams = array_merge(
                    $searchUserParams,
                    [
                        'searchFields' => $searchParams['users']['select']
                    ]
                );
            }

            $resultUsers = self::searchUsers($searchTerm, $searchUserParams, $count);

            if ($count) {
                $searchResult['users'] = $resultUsers;
            } elseif (!empty($resultUsers)) {
                if (!is_array($resultUsers)) {
                    $resultUsers = [];
                }

                $selectFieldsAvailable = [
                    'username' => true,
                    'usergroup' => true,
                    'email' => true,
                    'active' => true,
                    'regdate' => true,
                    'su' => true,
                    'expire' => true,
                    'lastedit' => true,
                    'firstname' => true,
                    'lastname' => true,
                    'usertitle' => true,
                    'birthday' => true,
                    'avatar' => true,
                    'lang' => true,
                    'company' => true
                ];
                $selectFields = [];
                if (
                    !empty($searchParams['users']['select'])
                    && is_array($searchParams['users']['select'])
                ) {
                    foreach ($searchParams['users']['select'] as $field => $select) {
                        if (!isset($selectFieldsAvailable[$field])) {
                            continue;
                        }

                        if (!$select) {
                            continue;
                        }

                        $selectFields[] = $field;
                    }
                }

                // always get id
                $selectFields[] = 'uuid';
                $result = self::fetchRowsByUuids(QUI\Users\Manager::table(), $selectFields, $resultUsers);
                foreach ($result as $row) {
                    $row['type'] = 'user';
                    $row['id'] = $row['uuid'];

                    $searchResult['users'][] = $row;
                }
            }
        }

        if ($searchGroups) {
            if (empty($searchParams['groups'])) {
                throw new QUI\Exception([
                    'quiqqer/core',
                    'exception.usergroups.search.cannot.search.groups.without.parameters'
                ]);
            }

            $resultGroups = self::searchGroups($searchTerm, $searchParams, $count);

            if ($count) {
                $searchResult['groups'] = $resultGroups;
            } elseif (!empty($resultGroups)) {
                if (!is_array($resultGroups)) {
                    $resultGroups = [];
                }

                $selectFieldsAvailable = [
                    'name' => true,
                    'parent' => true,
                    'active' => true
                ];
                $selectFields = [];
                if (
                    !empty($searchParams['groups']['select'])
                    && is_array($searchParams['groups']['select'])
                ) {
                    foreach ($searchParams['groups']['select'] as $field => $select) {
                        if (!isset($selectFieldsAvailable[$field])) {
                            continue;
                        }

                        if (!$select) {
                            continue;
                        }

                        $selectFields[] = $field;
                    }
                }

                // always get id
                $selectFields[] = 'uuid';
                $result = self::fetchRowsByUuids(QUI\Groups\Manager::table(), $selectFields, $resultGroups);
                foreach ($result as $row) {
                    $row['type'] = 'group';
                    $row['id'] = $row['uuid'];

                    $searchResult['groups'][] = $row;
                }
            }
        }

        return $searchResult;
    }

    /**
     * Search in user table
     *
     * @param string $searchTerm - search term
     * @param array<string, mixed> $searchParams - search parameters
     * @param bool $count (optional) - return count only
     *
     * @return string[]|int - user ids or count of user ids
     */
    protected static function searchUsers(string $searchTerm, array $searchParams, bool $count = false): int|array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->from($Platform->quoteSingleIdentifier(QUI\Users\Manager::table()));

        if ($count) {
            $QueryBuilder->select("COUNT(*)");
        } else {
            $QueryBuilder->select($Platform->quoteSingleIdentifier("uuid"));
        }

        $searchFieldsAvailable = [
            "uuid" => true,
            "username" => true,
            "email" => true,
            "firstname" => true,
            "lastname" => true
        ];

        $searchFields = [];

        if (!empty($searchParams["searchFields"]) && is_array($searchParams["searchFields"])) {
            foreach ($searchParams["searchFields"] as $field => $search) {
                if (!isset($searchFieldsAvailable[$field]) || !$search) {
                    continue;
                }

                $searchFields[] = $field;
            }
        }

        if (empty($searchFields)) {
            $searchFields = ["uuid", "username"];
        }

        $orParts = [];
        $index = 0;

        foreach ($searchFields as $field) {
            $parameter = "search" . $index;
            $orParts[] = $Platform->quoteSingleIdentifier($field) . " LIKE :" . $parameter;
            $QueryBuilder->setParameter($parameter, "%" . $searchTerm . "%");
            $index++;
        }

        $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$orParts));

        if (!empty($searchParams["filter"]) && is_array($searchParams["filter"])) {
            foreach ($searchParams["filter"] as $filter => $value) {
                switch ($filter) {
                    case "status":
                        switch ($value) {
                            case 1:
                            case 0:
                            case -1:
                                $QueryBuilder
                                    ->andWhere($Platform->quoteSingleIdentifier("active") . " = :active")
                                    ->setParameter("active", $value);
                                break;
                        }

                        break;

                    case "groups":
                        $groupIds = explode(",", trim($value, ","));
                        $groupParts = [];
                        $groupIndex = 0;

                        foreach ($groupIds as $groupId) {
                            $parameter = "group" . $groupIndex;
                            $groupParts[] = $Platform->quoteSingleIdentifier("usergroup") . " LIKE :" . $parameter;
                            $QueryBuilder->setParameter($parameter, "%," . $groupId . ",%");
                            $groupIndex++;
                        }

                        $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$groupParts));
                        break;

                    case "regDateFrom":
                        $QueryBuilder
                            ->andWhere($Platform->quoteSingleIdentifier("regdate") . " >= :regDateFrom")
                            ->setParameter("regDateFrom", QUI\Utils\Convert::convertMySqlDatetime($value . " 00:00:00"));
                        break;

                    case "regDateTo":
                        $QueryBuilder
                            ->andWhere($Platform->quoteSingleIdentifier("regdate") . " <= :regDateTo")
                            ->setParameter("regDateTo", QUI\Utils\Convert::convertMySqlDatetime($value . " 00:00:00"));
                        break;
                }
            }
        }

        if (!empty($searchParams["sortOn"])) {
            $sortBy = !empty($searchParams["sortBy"]) && strtoupper((string)$searchParams["sortBy"]) === "DESC" ? "DESC" : "ASC";
            $QueryBuilder->orderBy($Platform->quoteSingleIdentifier(Orthos::clear($searchParams["sortOn"])), $sortBy);
        }

        if (!$count) {
            $limit = !empty($searchParams["limit"]) ? (int)$searchParams["limit"] : self::DEFAULT_LIMIT_USERS;
            $QueryBuilder->setMaxResults($limit);
        }

        try {
            if ($count) {
                return (int)$QueryBuilder->executeQuery()->fetchOne();
            }

            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::addError(
                "\QUI\UsersGroups\Search searchUsers() Database error :: " . $Exception->getMessage()
            );

            return [];
        }

        $ids = [];

        foreach ($result as $row) {
            $ids[] = $row["uuid"];
        }

        return $ids;
    }

    /**
     * Search in group table
     *
     * @param string $searchTerm - search term
     * @param array<string, mixed> $searchParams - search parameters
     * @param bool $count (optional) - return count only
     *
     * @return string[]|int - group ids or count of group ids
     */
    protected static function searchGroups(string $searchTerm, array $searchParams, bool $count = false): int|array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->from($Platform->quoteSingleIdentifier(QUI\Groups\Manager::table()));

        if ($count) {
            $QueryBuilder->select("COUNT(*)");
        } else {
            $QueryBuilder->select($Platform->quoteSingleIdentifier("uuid"));
        }

        $searchFieldsAvailable = [
            "uuid" => true,
            "name" => true
        ];

        $searchFields = [];

        if (!empty($searchParams["searchFields"]) && is_array($searchParams["searchFields"])) {
            foreach ($searchParams["searchFields"] as $field => $search) {
                if (!isset($searchFieldsAvailable[$field]) || !$search) {
                    continue;
                }

                $searchFields[] = $field;
            }
        }

        if (empty($searchFields)) {
            $searchFields = ["uuid", "name"];
        }

        $orParts = [];
        $index = 0;

        foreach ($searchFields as $field) {
            $parameter = "search" . $index;
            $orParts[] = $Platform->quoteSingleIdentifier($field) . " LIKE :" . $parameter;
            $QueryBuilder->setParameter($parameter, "%" . $searchTerm . "%");
            $index++;
        }

        $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$orParts));

        if (!empty($searchParams["filter"]) && is_array($searchParams["filter"])) {
            foreach ($searchParams["filter"] as $filter => $value) {
                if ($filter !== "status") {
                    continue;
                }

                switch ($value) {
                    case 1:
                    case 0:
                        $QueryBuilder
                            ->andWhere($Platform->quoteSingleIdentifier("active") . " = :active")
                            ->setParameter("active", $value);
                        break;
                }
            }
        }

        if (!empty($searchParams["sortOn"])) {
            $sortBy = !empty($searchParams["sortBy"]) && strtoupper((string)$searchParams["sortBy"]) === "DESC" ? "DESC" : "ASC";
            $QueryBuilder->orderBy($Platform->quoteSingleIdentifier(Orthos::clear($searchParams["sortOn"])), $sortBy);
        }

        if (!$count) {
            $limit = !empty($searchParams["limit"]) ? (int)$searchParams["limit"] : self::DEFAULT_LIMIT_GROUPS;
            $QueryBuilder->setMaxResults($limit);
        }

        try {
            if ($count) {
                return (int)$QueryBuilder->executeQuery()->fetchOne();
            }

            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::addError(
                "\QUI\UsersGroups\Search searchGroups() Database error :: " . $Exception->getMessage()
            );

            return [];
        }

        $ids = [];

        foreach ($result as $row) {
            $ids[] = $row["uuid"];
        }

        return $ids;
    }

    /**
     * @param string[] $selectFields
     * @param string[] $uuids
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRowsByUuids(string $table, array $selectFields, array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $select = [];

        foreach ($selectFields as $field) {
            $select[] = $Platform->quoteSingleIdentifier($field);
        }

        return $Connection->createQueryBuilder()
            ->select(...$select)
            ->from($Platform->quoteSingleIdentifier($table))
            ->where($Platform->quoteSingleIdentifier("uuid") . " IN (:uuids)")
            ->setParameter("uuids", $uuids, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Search users and groups
     *
     * Returns only username, user ID and user Avatar
     *
     * @param array<string, mixed> $searchParams
     * @return array<int, array<string, mixed>>
     *
     * @throws QUI\Exception
     */
    public static function suggestSearch(string $searchTerm, array $searchParams): array
    {
        $searchUsers = false;
        $searchGroups = false;
        $searchResult = [];

        // search in user table
        if (isset($searchParams['searchUsers']) && $searchParams['searchUsers']) {
            $searchUsers = true;
        }

        // search in group table
        if (isset($searchParams['searchGroups']) && $searchParams['searchGroups']) {
            $searchGroups = true;
        }

        if ($searchUsers === false && $searchGroups === false) {
            $searchUsers = true;
        }

        if ($searchUsers) {
            if (empty($searchParams['users'])) {
                throw new QUI\Exception([
                    'quiqqer/core',
                    'exception.usergroups.search.cannot.search.users.without.parameters'
                ]);
            }

            $resultUsers = self::searchUsers($searchTerm, $searchParams['users']);

            if (!empty($resultUsers)) {
                if (!is_array($resultUsers)) {
                    $resultUsers = [];
                }

                $selectFields = [
                    'uuid',
                    'username'
                ];

                $result = self::fetchRowsByUuids(QUI\Users\Manager::table(), $selectFields, $resultUsers);

                foreach ($result as $row) {
                    $searchResult[] = [
                        'id' => 'u' . $row['uuid'],
                        'name' => $row['username']
                    ];
                }
            }
        }

        if ($searchGroups) {
            if (empty($searchParams['groups'])) {
                throw new QUI\Exception([
                    'quiqqer/core',
                    'exception.usergroups.search.cannot.search.groups.without.parameters'
                ]);
            }

            $resultGroups = self::searchGroups($searchTerm, $searchParams);

            if (!empty($resultGroups)) {
                if (!is_array($resultGroups)) {
                    $resultGroups = [];
                }

                $selectFields = [
                    'uuid',
                    'name'
                ];

                $result = self::fetchRowsByUuids(QUI\Groups\Manager::table(), $selectFields, $resultGroups);

                foreach ($result as $row) {
                    $searchResult[] = [
                        'id' => 'g' . $row['uuid'],
                        'name' => $row['name']
                    ];
                }
            }
        }

        return $searchResult;
    }
}
