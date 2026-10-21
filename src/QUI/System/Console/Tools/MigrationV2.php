<?php

namespace QUI\System\Console\Tools;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use QUI;
use QUI\ExceptionStack;

use function array_chunk;
use function array_merge;
use function array_unique;
use function array_filter;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_numeric;
use function json_decode;
use function sprintf;
use function str_contains;
use function trim;

use const OPT_DIR;

/**
 * MailQueue Console Manager
 */
class MigrationV2 extends QUI\System\Console\Tool
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setName('quiqqer:migration-v2')
            ->setDescription('Migration QUIQQER V1 to V2');
    }

    /**
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws ExceptionStack
     */
    public function execute(): void
    {
        // messages
        $this->writeLn("- Update messages table");
        $this->ensureStringColumn(QUI::getDBTableName("messages"), "uid", 50, false);

        // session
        $this->writeLn("- Update session table");
        $this->ensureStringColumn(QUI::getDBTableName("sessions"), "uid", 50, false);

        $this->users();
        $this->groups();
        $this->groupsInUsers();
        $this->projectSites();
        $this->media();
        $this->permissions();
        $this->workspaces();
        $this->loginLog();

        $this->writeLn('- Migrate root user and root group');

        $Config = QUI::getConfig('etc/conf.ini.php');
        $rootUser = $Config->getValue('globals', 'rootuser');
        $rootGroup = $Config->getValue('globals', 'root');

        if (is_string($rootUser)) {
            try {
                $Config->setValue('globals', 'rootuser', QUI::getUsers()->get($rootUser)->getUUID());
            } catch (QUI\Exception) {
            }
        }

        if (is_string($rootGroup)) {
            try {
                $Config->setValue('globals', 'root', QUI::getGroups()->get($rootGroup)->getUUID());
            } catch (QUI\Exception) {
            }
        }

        $Config->save();

        QUI::getEvents()->fireEvent('quiqqerMigrationV2', [$this]);


        // migrate databases to innodb
        try {
            $this->writeLn('- Migrate database from MyISAM to InnoDB');

            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();

            if (!$Platform instanceof AbstractMySQLPlatform) {
                $this->writeLn('-> Skip MyISAM to InnoDB conversion for non-MySQL platform');
            } else {
                $result = $Connection->executeQuery(
                    "SELECT table_name
                    FROM information_schema.tables
                    WHERE table_schema = :dbname AND engine = 'MyISAM'",
                    ['dbname' => QUI::conf('db', 'database')]
                );

                foreach ($result->fetchAllAssociative() as $table) {
                    $tableName = $table['table_name'] ?? $table['TABLE_NAME'] ?? null;

                    if ($tableName === null) {
                        continue;
                    }

                    try {
                        $Connection->executeStatement(
                            // MigrationV2 intentionally converts legacy MyISAM tables to InnoDB.
                            // nosemgrep: quiqqer.forbid-mysql-specific-sql
                            'ALTER TABLE ' . $Platform->quoteSingleIdentifier($tableName) . ' ENGINE=InnoDB'
                        );
                        $this->writeLn('-> Converted ' . $tableName . ' to InnoDB');
                    } catch (\Exception $exception) {
                        $this->writeLn('Error at table ' . $tableName, 'red');
                        $this->writeLn($exception->getMessage(), 'red');
                        $this->resetColor();
                    }
                }

                $this->writeLn('-> Conversion complete');
            }
        } catch (Exception $Exception) {
            $this->writeLn('An error occurred: ' . $Exception->getMessage());
        }

        $this->writeLn('Migration complete!', 'green');
        $this->resetColor();
    }

    public function users(): void
    {
        $this->writeLn('- Update users table');

        QUI\Users\Install::user();

        $userTable = QUI\Users\Manager::table();

        // uuid extreme indexes patch
        $Table = QUI::getSchemaManager()->introspectTable($userTable);
        $droppedIndexes = [];

        foreach ($Table->getIndexes() as $Index) {
            if (!$Index->isUnique() || $Index->isPrimary()) {
                continue;
            }

            if (str_starts_with($Index->getName(), 'uuid_')) {
                $droppedIndexes[] = $Index;
            }
        }

        if (!empty($droppedIndexes)) {
            try {
                QUI::getSchemaManager()->alterTable(
                    new \Doctrine\DBAL\Schema\TableDiff($Table, droppedIndexes: $droppedIndexes)
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeRecursive(array_map(static function ($Index): string {
                    return $Index->getName();
                }, $droppedIndexes));
                QUI\System\Log::writeException($Exception);
            }
        }

        // users with no uuid
        $usersWithoutUuid = $this->fetchRows($userTable, ['uuid' => '']);

        foreach ($usersWithoutUuid as $entry) {
            $this->updateRows(
                $userTable,
                ['uuid' => QUI\Utils\Uuid::get()],
                ['id' => $entry['id']]
            );
        }

        $this->ensureUniqueColumn($userTable, 'uuid');

        // addresses
        $this->writeLn('- Migrate users addresses');

        $tableAddresses = QUI\Users\Manager::tableAddress();
        $setAddressUuidColumnToUnique = false;

        if (!$this->columnExists($tableAddresses, 'uuid')) {
            $this->addVarcharColumn($tableAddresses, 'uuid');
            $setAddressUuidColumnToUnique = true;
        }

        if (!$this->columnExists($tableAddresses, 'userUuid')) {
            $this->addVarcharColumn($tableAddresses, 'userUuid');
        }

        if (!$this->isColumnVarchar($userTable, "address")) {
            $this->ensureStringColumn($userTable, "address", 50, true);
        }

        $addressesWithoutUuid = $this->fetchRows($tableAddresses, ['uuid' => ''], ['id']);

        $this->writeLn('-- Found addresses without UUID: ' . count($addressesWithoutUuid));
        $this->writeLn('-- Start migration ...');

        foreach ($addressesWithoutUuid as $entry) {
            $this->updateRows(
                $tableAddresses,
                ['uuid' => QUI\Utils\Uuid::get()],
                ['id' => $entry['id']]
            );
        }

        // MIGRATE DEFAULT ADDRESS
        $users = $this->fetchRows($userTable);

        foreach ($users as $user) {
            $standardAddress = $user['address'];

            if (is_numeric($standardAddress)) {
                $addressData = $this->fetchRows($tableAddresses, ['id' => $standardAddress], ['uuid'], 1);

                if (!count($addressData)) {
                    continue;
                }

                $this->updateRows(
                    $userTable,
                    ['address' => $addressData[0]['uuid']],
                    ['id' => $user['id']]
                );
            }
        }

        if ($setAddressUuidColumnToUnique) {
            $this->ensureUniqueColumn($tableAddresses, 'uuid');
        }

        $addressesWithoutUserUuid = $this->fetchRows(
            $tableAddresses,
            ['userUuid' => ''],
            ['id', 'uid']
        );

        $this->writeLn('-- Found addresses without user UUID: ' . count($addressesWithoutUserUuid));
        $this->writeLn('-- Start migration ...');

        foreach ($addressesWithoutUserUuid as $entry) {
            $result = $this->fetchRows($userTable, ['id' => $entry['uid']], ['uuid'], 1);

            if (empty($result)) {
                $this->writeLn(
                    "-> Found orphaned address ID #{$entry['id']}. User #{$entry['uid']} referenced by address does not exist.",
                    'yellow'
                );
                $this->resetColor();
                continue;
            }

            $this->updateRows(
                $tableAddresses,
                ['userUuid' => $result[0]['uuid']],
                ['id' => $entry['id']]
            );
        }
    }
    public function groups(): void
    {
        $this->writeLn('- Migrate groups table');
        try {
            QUI\Users\Install::groups();
        } catch (QUI\Exception $exception) {
            if ((int)$exception->getCode() !== 404) {
                throw $exception;
            }

            $this->writeLn($exception->getMessage(), 'yellow');
            $this->resetColor();
        }


        // migrate group parents
        $Root = QUI::getGroups()->get(QUI::conf('globals', 'root'));
        $rootUUID = $Root->getUUID();
        $groupTable = QUI\Groups\Manager::table();

        $result = $this->fetchRows($groupTable);

        foreach ($result as $entry) {
            $groupUUID = $entry['uuid'];

            if ($groupUUID == 0 || $groupUUID == 1 || $groupUUID == $rootUUID) {
                continue;
            }

            $parent = $entry['parent'];

            try {
                if ($parent == 0) {
                    $this->updateRows(
                        $groupTable,
                        ['parent' => $rootUUID],
                        ['id' => $entry['id']]
                    );
                } else {
                    $this->updateRows(
                        $groupTable,
                        ['parent' => QUI::getGroups()->get($parent)->getUUID()],
                        ['id' => $entry['id']]
                    );
                }
            } catch (QUI\Exception $exception) {
                $this->writeLn($exception->getMessage(), 'red');
                $this->resetColor();
            }
        }
    }

    public function groupsInUsers(): void
    {
        $this->writeLn('- Migrate groups in users');
        $table = QUI\Users\Manager::table();

        $result = $this->fetchRows($table);

        foreach ($result as $entry) {
            $userGroups = $entry['usergroup'];
            $newGroups = [];

            $userGroups = trim($userGroups, ',');
            $userGroups = explode(',', $userGroups);

            foreach ($userGroups as $groupId) {
                try {
                    $newGroups[] = QUI::getGroups()->get($groupId)->getUUID();
                } catch (QUI\Exception) {
                    $newGroups[] = $groupId;
                }
            }

            try {
                $this->updateRows(
                    $table,
                    ['usergroup' => ',' . implode(',', $newGroups) . ','],
                    ['id' => $entry['id']]
                );
            } catch (QUI\Exception) {
            }
        }
    }

    public function projectSites(): void
    {
        $this->writeLn('- Migrate project & sites');
        $projects = QUI::getProjectManager()->getProjects(true);

        /* @var $Project QUI\Projects\Project */
        foreach ($projects as $Project) {
            $Project->setup([
                'executePackagesSetup' => false
            ]);

            $languages = $Project->getLanguages();
            $name = $Project->getName();

            foreach ($languages as $language) {
                $table = QUI::getProject($name, $language)->table();
                $sites = $this->fetchRows($table);

                foreach ($sites as $site) {
                    $cUser = $site['c_user'];
                    $eUser = $site['e_user'];

                    if (is_numeric($cUser)) {
                        $cUser = $this->getUserHash(is_float($cUser) ? (int)$cUser : $cUser);
                    }

                    if (is_numeric($eUser)) {
                        $eUser = $this->getUserHash(is_float($eUser) ? (int)$eUser : $eUser);
                    }

                    $this->updateRows(
                        $table,
                        [
                            'c_user' => $cUser,
                            'e_user' => $eUser,
                        ],
                        ['id' => $site['id']]
                    );
                }
            }
        }
    }

    public function media(): void
    {
        $this->writeLn('- Migrate media');
        $projects = QUI::getProjectManager()->getProjects(true);

        /* @var $Project QUI\Projects\Project */
        foreach ($projects as $Project) {
            $Media = $Project->getMedia();
            $table = $Media->getTable();

            $files = $this->fetchRows($table);

            foreach ($files as $file) {
                $cUser = $file['c_user'];
                $eUser = $file['e_user'];

                if (is_numeric($cUser)) {
                    $cUser = $this->getUserHash(is_float($cUser) ? (int)$cUser : $cUser);
                }

                if (is_numeric($eUser)) {
                    $eUser = $this->getUserHash(is_float($eUser) ? (int)$eUser : $eUser);
                }

                $this->updateRows(
                    $table,
                    [
                        'c_user' => $cUser,
                        'e_user' => $eUser,
                    ],
                    ['id' => $file['id']]
                );

                if (
                    (int)$file['id'] <= 1
                    || $file['type'] === 'folder'
                    || !empty($file['deleted'])
                    || !str_contains((string)$file['name'], ' ')
                ) {
                    continue;
                }

                try {
                    $Media->get((int)$file['id'])->save();
                } catch (\Exception $Exception) {
                    $this->writeLn(
                        sprintf(
                            '-> Could not normalize media file #%d in project "%s": %s',
                            (int)$file['id'],
                            $Project->getName(),
                            $Exception->getMessage()
                        ),
                        'red'
                    );
                    $this->resetColor();
                    QUI\System\Log::writeException($Exception);
                }
            }
        }
    }

    /**
     * @throws Exception
     * @throws QUI\Database\Exception
     */
    public function permissions(): void
    {
        $this->writeLn('- Migrate permissions');

        $table2Users = QUI::getDBTableName('permissions2users');
        $table2Groups = QUI::getDBTableName('permissions2groups');

        $this->ensureStringColumn($table2Users, "user_id", 50, true, "0");
        $this->ensureStringColumn($table2Groups, "group_id", 50, true, "0");


        $permissions = $this->fetchRows($table2Users);

        foreach ($permissions as $entry) {
            $userId = $entry['user_id'];

            if (
                !is_int($userId)
                && (!is_string($userId) || !is_numeric($userId))
            ) {
                continue;
            }

            try {
                $userUUID = QUI::getUsers()->get($userId)->getUUID();
            } catch (QUI\Exception) {
                // nutzer existiert nicht, kann als permission gelöscht werden
                QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier($table2Users), [
                    'user_id' => $entry['user_id']
                ]);

                continue;
            }

            QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier($table2Users), [
                'user_id' => $userUUID,
                'permissions' => $entry['permissions']
            ]);

            QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier($table2Users), [
                'user_id' => $entry['user_id']
            ]);
        }


        $permissions = $this->fetchRows($table2Groups);

        foreach ($permissions as $entry) {
            $groupId = $entry['group_id'];

            if (
                !is_int($groupId)
                && (!is_string($groupId) || !is_numeric($groupId))
            ) {
                continue;
            }

            try {
                $groupUUID = QUI::getGroups()->get($groupId)->getUUID();
            } catch (\Exception) {
                // gruppe existiert nicht, kann als permission gelöscht werden

                QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier($table2Groups), [
                    'group_id' => $entry['group_id']
                ]);
                continue;
            }

            if ($groupUUID == $entry['group_id']) {
                continue;
            }

            try {
                QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier($table2Groups), [
                    'group_id' => $groupUUID,
                    'permissions' => $entry['permissions']
                ]);

                QUI::getDataBaseConnection()->delete(QUI\Utils\Doctrine::quoteIdentifier($table2Groups), [
                    'group_id' => $entry['group_id']
                ]);
            } catch (\Exception) {
            }
        }
    }

    /**
     * @throws Exception
     */
    public function workspaces(): void
    {
        $this->writeLn('- Migrate workspaces');
        $this->writeLn('> Cleanup workspaces');

        $workspaceTable = QUI\Workspace\Manager::table();
        $workspaceUsers = $this->getWorkspaceUsers($workspaceTable);
        $this->writeLn('>> Found workspace owners: ' . count($workspaceUsers));

        $userMap = $this->getWorkspaceUserMap($workspaceUsers);
        $invalidWorkspaceUsers = [];

        foreach ($workspaceUsers as $uid) {
            if (!isset($userMap[$uid])) {
                $invalidWorkspaceUsers[] = $uid;
                continue;
            }

            if ($userMap[$uid]['admin'] !== true) {
                $invalidWorkspaceUsers[] = $uid;
            }
        }

        $this->writeLn('>> Workspace owner analysis complete');
        $this->writeLn('>> Invalid workspace owners queued for cleanup: ' . count($invalidWorkspaceUsers));

        if (!empty($invalidWorkspaceUsers)) {
            $this->writeLn('>> Start deleting workspaces for invalid owners');
        }

        $deletedWorkspaces = $this->deleteWorkspacesByUids($workspaceTable, $invalidWorkspaceUsers);

        if ($deletedWorkspaces > 0) {
            $this->writeLn('>> Deleted workspaces: ' . $deletedWorkspaces);
        }

        $this->writeLn('> Upgrade workspaces');
        $table = QUI::getDBTableName('users_workspaces');

        $this->ensureStringColumn($table, "uid", 50, true);
        $updatedWorkspaces = 0;

        foreach ($workspaceUsers as $uid) {
            if ($uid === "5" || !is_numeric($uid) || !isset($userMap[$uid])) {
                continue;
            }

            $updatedWorkspaces += $this->updateWorkspaceUid($table, $uid, $userMap[$uid]["uuid"]);
        }

        if ($updatedWorkspaces > 0) {
            $this->writeLn('>> Upgraded workspace owners: ' . $updatedWorkspaces);
        }
    }

    /**
     * @throws Exception
     */
    public function loginLog(): void
    {
        $this->writeLn('- Migrate login log table');
        $this->ensureStringColumn(QUI::getDBTableName("login_log"), "uid", 50, true);
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string> $select
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRows(
        string $table,
        array $where = [],
        array $select = ['*'],
        ?int $limit = null
    ): array {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $columns = [];

        foreach ($select as $column) {
            $columns[] = $column === '*' ? '*' : $Platform->quoteSingleIdentifier($column);
        }

        $QueryBuilder
            ->select(...$columns)
            ->from(QUI\Utils\Doctrine::quoteIdentifier($table));

        $index = 0;

        foreach ($where as $field => $value) {
            $parameter = 'where' . $index;
            $QueryBuilder
                ->andWhere($QueryBuilder->expr()->eq($Platform->quoteSingleIdentifier($field), ':' . $parameter))
                ->setParameter($parameter, $value);
            $index++;
        }

        if ($limit !== null) {
            $QueryBuilder->setMaxResults($limit);
        }

        return $QueryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    protected function updateRows(string $table, array $data, array $where): void
    {
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier($table),
            $data,
            $where
        );
    }

    protected function columnExists(string $table, string $column): bool
    {
        return QUI::getSchemaManager()
            ->introspectTable($table)
            ->hasColumn($column);
    }

    protected function addVarcharColumn(string $table, string $column): void
    {
        $this->ensureStringColumn($table, $column, 50, true);
    }

    protected function ensureStringColumn(
        string $table,
        string $column,
        int $length = 50,
        bool $notnull = true,
        ?string $default = null
    ): void {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$table])) {
            return;
        }

        $Table = $SchemaManager->introspectTable($table);
        $options = [
            'length' => $length,
            'notnull' => $notnull
        ];

        if ($default !== null) {
            $options['default'] = $default;
        }

        $Column = new \Doctrine\DBAL\Schema\Column(
            $column,
            \Doctrine\DBAL\Types\Type::getType('string'),
            $options
        );

        if (!$Table->hasColumn($column)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: [$Column]));
            return;
        }

        $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff(
            $Table,
            changedColumns: [$column => new \Doctrine\DBAL\Schema\ColumnDiff($Table->getColumn($column), $Column)]
        ));
    }

    protected function ensureUniqueColumn(string $table, string $column): void
    {
        $Table = QUI::getSchemaManager()->introspectTable($table);

        foreach ($Table->getIndexes() as $Index) {
            if (!$Index->isUnique()) {
                continue;
            }

            if ($Index->getColumns() === [$column]) {
                return;
            }
        }

        $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
        $indexName = $table . '_' . $column . '_uniq';

        QUI::getDataBaseConnection()->executeStatement(
            'CREATE UNIQUE INDEX ' . $Platform->quoteSingleIdentifier($indexName)
            . ' ON ' . QUI\Utils\Doctrine::quoteIdentifier($table)
            . ' (' . $Platform->quoteSingleIdentifier($column) . ')'
        );
    }

    protected function isColumnVarchar(string $table, string $column): bool
    {
        $type = QUI::getSchemaManager()
            ->introspectTable($table)
            ->getColumn($column)
            ->getType();

        return $type instanceof \Doctrine\DBAL\Types\StringType || $type instanceof \Doctrine\DBAL\Types\TextType;
    }

    protected function getUserHash(int | string $userId): string | int
    {
        try {
            return QUI::getUsers()->get($userId)->getUUID();
        } catch (QUI\Exception) {
            return $userId;
        }
    }

    protected function updateWorkspaceUid(string $workspaceTable, string $oldUid, string $newUid): int
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();

        return (int)$QueryBuilder
            ->update($Platform->quoteSingleIdentifier($workspaceTable))
            ->set($Platform->quoteSingleIdentifier("uid"), ":newUid")
            ->where($QueryBuilder->expr()->eq($Platform->quoteSingleIdentifier("uid"), ":oldUid"))
            ->setParameter("newUid", $newUid)
            ->setParameter("oldUid", $oldUid)
            ->executeStatement();
    }

    /**
     * @return array<int, string>
     *
     * @throws Exception
     */
    protected function getWorkspaceUsers(string $workspaceTable): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $result = $QueryBuilder
            ->select("DISTINCT " . $Platform->quoteSingleIdentifier("uid"))
            ->from($Platform->quoteSingleIdentifier($workspaceTable))
            ->executeQuery();

        $workspaceUsers = [];

        while ($entry = $result->fetchAssociative()) {
            if (!isset($entry['uid'])) {
                continue;
            }

            $workspaceUsers[] = (string)$entry['uid'];
        }

        return array_values(array_unique($workspaceUsers));
    }

    /**
     * @param array<int, string> $workspaceUsers
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    protected function getWorkspaceUserMap(array $workspaceUsers): array
    {
        $workspaceUsers = array_values(array_unique(array_map('strval', $workspaceUsers)));

        if (empty($workspaceUsers)) {
            return [];
        }

        $numericUserIds = [];
        $uuidUserIds = [];

        foreach ($workspaceUsers as $uid) {
            if ($uid === '5') {
                continue;
            }

            if (is_numeric($uid)) {
                $numericUserIds[] = (string)$uid;
                continue;
            }

            $uuidUserIds[] = $uid;
        }

        $users = $this->fetchWorkspaceUsers($numericUserIds, $uuidUserIds);
        $adminUserUUIDs = $this->getAdminWorkspaceUserUUIDs($users);
        $userMap = [
            '5' => [
                'uuid' => '5',
                'admin' => true
            ]
        ];

        foreach ($users as $user) {
            $isAdmin = (int)$user['su'] === 1 || isset($adminUserUUIDs[$user['uuid']]);

            $payload = [
                'uuid' => $user['uuid'],
                'admin' => $isAdmin
            ];

            $userMap[(string)$user['id']] = $payload;
            $userMap[$user['uuid']] = $payload;
        }

        return $userMap;
    }

    /**
     * @param array<int, string> $numericUserIds
     * @param array<int, string> $uuidUserIds
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    protected function fetchWorkspaceUsers(array $numericUserIds, array $uuidUserIds): array
    {
        $userTable = QUI\Users\Manager::table();
        $conn = QUI::getDataBaseConnection();
        $Platform = $conn->getDatabasePlatform();
        $users = [];
        $processedNumericUserIds = 0;
        $totalUserIds = count(array_unique($numericUserIds));
        $totalUserUUIDs = count(array_unique($uuidUserIds));

        if ($totalUserIds > 0) {
            $this->writeLn('>> Load workspace owners by numeric id');
        }

        foreach (array_chunk(array_values(array_unique($numericUserIds)), 1000) as $idChunk) {
            $QueryBuilder = $conn->createQueryBuilder();
            $result = $QueryBuilder
                ->select(
                    $Platform->quoteSingleIdentifier("id"),
                    $Platform->quoteSingleIdentifier("uuid"),
                    $Platform->quoteSingleIdentifier("usergroup"),
                    $Platform->quoteSingleIdentifier("su")
                )
                ->from($Platform->quoteSingleIdentifier($userTable))
                ->where($QueryBuilder->expr()->in($Platform->quoteSingleIdentifier("id"), ":ids"))
                ->setParameter("ids", $idChunk, ArrayParameterType::STRING)
                ->executeQuery();

            while ($entry = $result->fetchAssociative()) {
                $users[$entry['uuid']] = $entry;
            }

            $processedNumericUserIds += count($idChunk);

            $this->writeLn(
                sprintf(
                    '>> Processed numeric owner ids: %d/%d',
                    min($processedNumericUserIds, $totalUserIds),
                    $totalUserIds
                )
            );
        }

        $processedUuidUsers = 0;

        if ($totalUserUUIDs > 0) {
            $this->writeLn('>> Load workspace owners by uuid');
        }

        foreach (array_chunk(array_values(array_unique($uuidUserIds)), 1000) as $uuidChunk) {
            $QueryBuilder = $conn->createQueryBuilder();
            $result = $QueryBuilder
                ->select(
                    $Platform->quoteSingleIdentifier("id"),
                    $Platform->quoteSingleIdentifier("uuid"),
                    $Platform->quoteSingleIdentifier("usergroup"),
                    $Platform->quoteSingleIdentifier("su")
                )
                ->from($Platform->quoteSingleIdentifier($userTable))
                ->where($QueryBuilder->expr()->in($Platform->quoteSingleIdentifier("uuid"), ":uuids"))
                ->setParameter("uuids", $uuidChunk, ArrayParameterType::STRING)
                ->executeQuery();

            while ($entry = $result->fetchAssociative()) {
                $users[$entry['uuid']] = $entry;
            }

            $processedUuidUsers += count($uuidChunk);

            $this->writeLn(
                sprintf(
                    '>> Processed uuid owners: %d/%d',
                    min($processedUuidUsers, $totalUserUUIDs),
                    $totalUserUUIDs
                )
            );
        }

        return $users;
    }

    /**
     * @param array<string, array<string, mixed>> $users
     *
     * @return array<string, bool>
     */
    protected function getAdminWorkspaceUserUUIDs(array $users): array
    {
        if (empty($users)) {
            return [];
        }

        $adminUsers = [];
        $userUUIDs = [];
        $groupUUIDs = [];
        $groupsByUser = [];

        foreach ($users as $user) {
            $userUUID = $user['uuid'];
            $userUUIDs[] = $userUUID;

            if ((int)$user['su'] === 1) {
                $adminUsers[$userUUID] = true;
            }

            $groupsByUser[$userUUID] = $this->parseUserGroups($user['usergroup'] ?? '');
            $groupUUIDs = array_merge($groupUUIDs, $groupsByUser[$userUUID]);
        }

        $this->writeLn('>> Start admin owner resolution for ' . count($users) . ' users');
        $this->writeLn('>> Check direct admin permissions for users');

        $adminUsers = array_merge(
            $adminUsers,
            $this->getAdminSubjects(
                QUI::getDBTableName('permissions2users'),
                'user_id',
                $userUUIDs
            )
        );

        $this->writeLn('>> Check admin permissions for groups');

        $adminGroups = $this->getAdminSubjects(
            QUI::getDBTableName('permissions2groups'),
            'group_id',
            $groupUUIDs
        );

        foreach ($groupsByUser as $userUUID => $groups) {
            if (isset($adminUsers[$userUUID])) {
                continue;
            }

            foreach ($groups as $groupUUID) {
                if (!isset($adminGroups[$groupUUID])) {
                    continue;
                }

                $adminUsers[$userUUID] = true;
                break;
            }
        }

        $this->writeLn('>> Resolved admin workspace owners: ' . count($adminUsers));

        return $adminUsers;
    }

    /**
     * @param array<int, string> $ids
     *
     * @return array<string, bool>
     */
    protected function getAdminSubjects(
        string $table,
        string $idColumn,
        array $ids
    ): array {
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return [];
        }

        $conn = QUI::getDataBaseConnection();
        $Platform = $conn->getDatabasePlatform();
        $adminSubjects = [];
        $resolvedIds = 0;
        $totalIds = count($ids);

        foreach (array_chunk($ids, 1000) as $chunk) {
            $QueryBuilder = $conn->createQueryBuilder();
            $result = $QueryBuilder
                ->select(
                    $Platform->quoteSingleIdentifier($idColumn),
                    $Platform->quoteSingleIdentifier("permissions")
                )
                ->from($Platform->quoteSingleIdentifier($table))
                ->where($QueryBuilder->expr()->in($Platform->quoteSingleIdentifier($idColumn), ":ids"))
                ->setParameter("ids", $chunk, ArrayParameterType::STRING)
                ->executeQuery();

            $resolvedIds += count($chunk);

            while ($entry = $result->fetchAssociative()) {
                if (!$this->hasAdminPermission($entry['permissions'] ?? '')) {
                    continue;
                }

                $adminSubjects[$entry[$idColumn]] = true;
            }

            $this->writeLn(
                sprintf(
                    '>> Checked %s permissions: %d/%d',
                    $idColumn,
                    min($resolvedIds, $totalIds),
                    $totalIds
                )
            );
        }

        return $adminSubjects;
    }

    protected function hasAdminPermission(string $permissions): bool
    {
        $permissions = json_decode($permissions, true);

        if (!is_array($permissions)) {
            return false;
        }

        return !empty($permissions['quiqqer.admin']);
    }

    /**
     * @return array<int, string>
     */
    protected function parseUserGroups(string $userGroups): array
    {
        $userGroups = trim($userGroups, ',');

        if ($userGroups === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $userGroups)));
    }

    /**
     * @param array<int, string> $uids
     */
    protected function deleteWorkspacesByUids(string $workspaceTable, array $uids): int
    {
        $uids = array_values(array_unique(array_filter($uids)));

        if (empty($uids)) {
            return 0;
        }

        $conn = QUI::getDataBaseConnection();
        $Platform = $conn->getDatabasePlatform();
        $deleted = 0;
        $processed = 0;
        $total = count($uids);

        $this->writeLn('>> Cleanup delete batches: ' . $total . ' owner ids');

        foreach (array_chunk($uids, 500) as $chunk) {
            $QueryBuilder = $conn->createQueryBuilder();
            $deleted += $QueryBuilder
                ->delete($Platform->quoteSingleIdentifier($workspaceTable))
                ->where($QueryBuilder->expr()->in($Platform->quoteSingleIdentifier("uid"), ":uids"))
                ->setParameter("uids", $chunk, ArrayParameterType::STRING)
                ->executeStatement();

            $processed += count($chunk);

            $this->writeLn(
                sprintf(
                    '>> Cleanup batches: %d/%d owner ids processed, %d workspaces deleted',
                    min($processed, $total),
                    $total,
                    $deleted
                )
            );
        }

        return (int)$deleted;
    }
}
