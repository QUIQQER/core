<?php

/**
 * This file contains the \QUI\Projects\Media
 */

namespace QUI\Projects;

use Exception;
use Intervention\Image\ImageManager;
use QUI;
use QUI\Projects\Media\Utils;
use QUI\Utils\Security\SvgSanitizer;
use QUI\Utils\System\File as FileUtils;

use function class_exists;
use function count;
use function date;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Media Manager for a project
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Media extends QUI\QDOM
{
    /**
     * This flag indicates if the creation of media item/folder cache is disabled
     * when createCache() is called.
     *
     * This should only be set to true if a lot of media items are created (e.g. in a mass import).
     */
    public static bool $globalDisableMediaCacheCreation = false;

    protected static mixed $mediaPermissions = null;

    /**
     * internal child cache
     *
     * @var array<int, QUI\Interfaces\Projects\Media\File>
     */
    protected array $children = [];

    /** @var \WeakMap<self, true>|null */
    private static ?\WeakMap $instances = null;

    public function __construct(
        protected Project $Project
    ) {
        self::$instances ??= new \WeakMap();
        self::$instances[$this] = true;
    }

    /** Invalidate an item in every live media manager for this project, including other languages. */
    public function invalidateItemCache(int $id): void
    {
        unset($this->children[$id]);

        foreach (self::$instances ?? [] as $Media => $registered) {
            if ($Media->getProject()->getName() === $this->Project->getName()) {
                unset($Media->children[$id]);
            }
        }
    }

    /**
     * Use media permissions? Media permissions are available?
     */
    public static function useMediaPermissions(): ?bool
    {
        if (self::$mediaPermissions === null) {
            $mediaPermissions = QUI::conf('permissions', 'media');
            $mediaPermissions = (int)$mediaPermissions;
            $mediaPermissions = (bool)$mediaPermissions;

            self::$mediaPermissions = $mediaPermissions;
        }

        return self::$mediaPermissions;
    }

    /**
     * Return the Logo of the media / project
     */
    public function getLogo(): string
    {
        $Project = $this->getProject();

        if ($Project->getConfig('logo')) {
            try {
                $Image = Utils::getImageByUrl(
                    $Project->getConfig('logo')
                );

                return $Image->getUrl(true);
            } catch (QUI\Exception) {
            }
        }

        return $this->getPlaceholder();
    }

    /**
     * Return the project of the media
     */
    public function getProject(): Project
    {
        return $this->Project;
    }

    /**
     * Return the Placeholder of the media
     */
    public function getPlaceholder(): string
    {
        $Project = $this->getProject();

        if ($Project->getConfig('placeholder')) {
            try {
                $Image = Utils::getImageByUrl(
                    $Project->getConfig('placeholder')
                );

                return $Image->getUrl(true);
            } catch (QUI\Exception) {
            }
        }

        return URL_BIN_DIR . 'images/Q.png';
    }

    /**
     * Return the Logo image object of the media
     */
    public function getLogoImage(): QUI\Projects\Media\Image | null
    {
        $Project = $this->getProject();

        if ($Project->getConfig('logo')) {
            try {
                return Utils::getImageByUrl(
                    $Project->getConfig('logo')
                );
            } catch (QUI\Exception) {
            }
        }

        return $this->getPlaceholderImage();
    }

    /**
     * Return the Placeholder of the media
     *
     * @return QUI\Projects\Media\Image|null
     */
    public function getPlaceholderImage(): QUI\Projects\Media\Image | null
    {
        $Project = $this->getProject();

        if ($Project->getConfig('placeholder')) {
            try {
                return Utils::getImageByUrl(
                    $Project->getConfig('placeholder')
                );
            } catch (QUI\Exception) {
            }
        }

        return null;
    }

    /**
     * Setup for a media table
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function setup(): void
    {
        $table = $this->getTable();
        $Connection = QUI::getDataBaseConnection();
        $mediaTableCreated = self::ensureMediaTable($table);

        try {
            $entries = $Connection->createQueryBuilder()
                ->select("id", "file")
                ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($entries as $entry) {
                $Connection->update(
                    $table,
                    ["pathHash" => md5($entry["file"] ?? "")],
                    ["id" => $entry["id"]]
                );
            }
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        self::removeMediaSetupIndexes($table);

        // create first site -> id 1 if not exist
        $firstChildResult = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
            ->where($Connection->getDatabasePlatform()->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", 1)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($firstChildResult === false) {
            $firstMediaData = [
                'name' => 'Media',
                'title' => 'Media',
                'c_date' => date('Y-m-d H:i:s'),
                'c_user' => QUI::getUserBySession()->getUUID(),
                'type' => 'folder',
                'pathHash' => md5('')
            ];

            // Let auto-increment assign ID 1 and advance a fresh PostgreSQL sequence.
            if (!$mediaTableCreated) {
                $firstMediaData['id'] = 1;
            }

            $Connection->insert($table, $firstMediaData);
        } elseif ($firstChildResult["type"] != "folder") {
            // check if id 1 is a folder, id 1 MUST BE a folder
            $Connection->update(
                $table,
                ['type' => 'folder'],
                ['id' => 1]
            );
        }

        // Media Relations
        $table = $this->getTable('relations');
        self::ensureMediaRelationsTable($table);

        // multilingual patch

        $table = $this->getTable();

        // check if patch needed
        $firstEntry = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
            ->where($Connection->getDatabasePlatform()->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", 1)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($firstEntry === false) {
            return;
        }

        $title = $firstEntry['title'];
        $title = json_decode($title, true);

        if (is_array($title)) {
            return;
        }

        // patch is needed
        $result = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
            ->executeQuery()
            ->fetchAllAssociative();

        $languages = QUI::availableLanguages();

        $updateEntry = static function ($type, array $data, $table) use ($languages): void {
            $value = $data[$type];

            if (empty($value)) {
                return;
            }

            $valueJSON = json_decode($value, true);

            if (is_array($valueJSON)) {
                return;
            }

            $newData = [];

            foreach ($languages as $language) {
                $newData[$language] = $value;
            }

            QUI::getDataBaseConnection()->update($table, [
                $type => json_encode($newData)
            ], [
                'id' => $data['id']
            ]);
        };

        foreach ($result as $entry) {
            $updateEntry('title', $entry, $table);
            $updateEntry('short', $entry, $table);
            $updateEntry('alt', $entry, $table);
        }
    }

    private static function ensureMediaTable(string $tableName): bool
    {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            $Table = new \Doctrine\DBAL\Schema\Table($tableName);
            self::addUtf8Options($Table);
            self::addMediaColumns($Table);
            $Table->setPrimaryKey(["id"]);
            self::addMediaIndexes($Table);
            $SchemaManager->createTable($Table);
            return true;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $addedColumns = [];

        foreach (self::getMediaColumnDefinitions() as $name => $definition) {
            if ($Table->hasColumn($name)) {
                continue;
            }

            $addedColumns[] = new \Doctrine\DBAL\Schema\Column(
                self::getMediaSchemaColumnName($name),
                \Doctrine\DBAL\Types\Type::getType($definition["type"]),
                $definition["options"]
            );
        }

        if (!empty($addedColumns)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: $addedColumns));
            $Table = $SchemaManager->introspectTable($tableName);
        }

        $TargetTable = clone $Table;
        self::addMissingSingleColumnIndexes(
            $TargetTable,
            ["name", "type", "active", "deleted", "deleted_at", "e_date", "order", "hidden", "pathHash"]
        );
        $Diff = $SchemaManager->createComparator()->compareTables($Table, $TargetTable);

        if (!$Diff->isEmpty()) {
            $SchemaManager->alterTable($Diff);
        }

        return false;
    }

    private static function ensureMediaRelationsTable(string $tableName): void
    {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            $Table = new \Doctrine\DBAL\Schema\Table($tableName);
            self::addUtf8Options($Table);
            $Table->addColumn("parent", "bigint");
            $Table->addColumn("child", "bigint");
            self::addMissingSingleColumnIndexes($Table, ["parent", "child"]);
            $SchemaManager->createTable($Table);
            return;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $addedColumns = [];

        foreach (["parent", "child"] as $columnName) {
            if (!$Table->hasColumn($columnName)) {
                $addedColumns[] = new \Doctrine\DBAL\Schema\Column(
                    $columnName,
                    \Doctrine\DBAL\Types\Type::getType("bigint")
                );
            }
        }

        if (!empty($addedColumns)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: $addedColumns));
            $Table = $SchemaManager->introspectTable($tableName);
        }

        $TargetTable = clone $Table;
        self::addMissingSingleColumnIndexes($TargetTable, ["parent", "child"]);
        $Diff = $SchemaManager->createComparator()->compareTables($Table, $TargetTable);

        if (!$Diff->isEmpty()) {
            $SchemaManager->alterTable($Diff);
        }
    }

    private static function removeMediaSetupIndexes(string $tableName): void
    {
        $SchemaManager = QUI::getSchemaManager();
        $Table = $SchemaManager->introspectTable($tableName);

        foreach (["c_date", "c_user", "e_user", "md5hash", "sha1hash"] as $indexName) {
            if (!$Table->hasIndex($indexName)) {
                continue;
            }

            $Index = $Table->getIndex($indexName);
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, droppedIndexes: [$Index]));
            $Table = $SchemaManager->introspectTable($tableName);
        }
    }

    private static function addMediaColumns(\Doctrine\DBAL\Schema\Table $Table): void
    {
        foreach (self::getMediaColumnDefinitions() as $name => $definition) {
            $Table->addColumn(self::getMediaSchemaColumnName($name), $definition["type"], $definition["options"]);
        }
    }

    private static function getMediaSchemaColumnName(string $name): string
    {
        if ($name === "external") {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return $name;
    }

    private static function addMediaIndexes(\Doctrine\DBAL\Schema\Table $Table): void
    {
        self::addMissingSingleColumnIndexes(
            $Table,
            ["name", "type", "active", "deleted", "deleted_at", "e_date", "order", "hidden", "pathHash"]
        );
    }

    /**
     * @param list<string> $columnNames
     */
    private static function addMissingSingleColumnIndexes(
        \Doctrine\DBAL\Schema\Table $Table,
        array $columnNames
    ): void {
        foreach ($columnNames as $columnName) {
            if (self::hasSingleColumnIndex($Table, $columnName)) {
                continue;
            }

            $Table->addIndex([$columnName]);
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
     * @return array<string, array<string, mixed>>
     */
    private static function getMediaColumnDefinitions(): array
    {
        return [
            "id" => ["type" => "bigint", "options" => ["autoincrement" => true]],
            "name" => ["type" => "string", "options" => ["length" => 200]],
            "title" => ["type" => "text", "options" => ["notnull" => false]],
            "short" => ["type" => "text", "options" => ["notnull" => false]],
            "alt" => ["type" => "text", "options" => ["notnull" => false]],
            "type" => ["type" => "string", "options" => ["length" => 32, "notnull" => false]],
            "active" => ["type" => "smallint", "options" => ["default" => 0]],
            "deleted" => ["type" => "smallint", "options" => ["default" => 0]],
            "deleted_at" => ["type" => "datetime", "options" => ["notnull" => false]],
            "c_date" => ["type" => "datetime", "options" => ["notnull" => false]],
            "e_date" => ["type" => "datetime", "options" => ["notnull" => false]],
            "file" => ["type" => "text", "options" => ["notnull" => false]],
            "mime_type" => ["type" => "text", "options" => ["notnull" => false]],
            "image_height" => ["type" => "integer", "options" => ["notnull" => false]],
            "image_width" => ["type" => "integer", "options" => ["notnull" => false]],
            "image_effects" => ["type" => "text", "options" => ["notnull" => false]],
            "c_user" => ["type" => "string", "options" => ["length" => 50, "notnull" => false]],
            "e_user" => ["type" => "string", "options" => ["length" => 50, "notnull" => false]],
            "rate_users" => ["type" => "text", "options" => ["notnull" => false]],
            "rate_count" => ["type" => "float", "options" => ["notnull" => false]],
            "md5hash" => ["type" => "string", "options" => ["length" => 32, "notnull" => false]],
            "sha1hash" => ["type" => "string", "options" => ["length" => 40, "notnull" => false]],
            "priority" => ["type" => "integer", "options" => ["notnull" => false]],
            "order" => ["type" => "string", "options" => ["length" => 32, "notnull" => false]],
            "pathHistory" => ["type" => "text", "options" => ["notnull" => false]],
            "hidden" => ["type" => "smallint", "options" => ["default" => 0]],
            "pathHash" => ["type" => "string", "options" => ["length" => 32]],
            "extra" => ["type" => "text", "options" => ["notnull" => false]],
            "external" => ["type" => "text", "options" => ["notnull" => false]]
        ];
    }

    private static function addUtf8Options(\Doctrine\DBAL\Schema\Table $Table): void
    {
        $Table->addOption("charset", "utf8mb4");
        $Table->addOption("collation", "utf8mb4_general_ci");
    }


    /**
     * @param array<string, mixed> $conditions
     */
    private static function applyMediaConditions(\Doctrine\DBAL\Query\QueryBuilder $QueryBuilder, array $conditions, string $method): void
    {
        $Platform = QUI::getDataBaseConnection()->getDatabasePlatform();
        $index = 0;

        foreach ($conditions as $field => $data) {
            $parameter = "condition" . $method . $index;
            $fieldParts = explode(".", (string)$field);
            $column = $Platform->quoteSingleIdentifier((string)end($fieldParts));

            if (is_array($data)) {
                $type = $data["type"] ?? "";
                $value = $data["value"] ?? null;

                if ($type === "NOT LIKE") {
                    $QueryBuilder->{$method}($column . " NOT LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value);
                    $index++;
                    continue;
                }

                if ($type === "%LIKE%") {
                    $QueryBuilder->{$method}($column . " LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, "%" . $value . "%");
                    $index++;
                    continue;
                }

                if ($type === "IN" && is_array($value)) {
                    $placeholders = [];

                    foreach ($value as $valueIndex => $entry) {
                        $entryParameter = $parameter . "_" . $valueIndex;
                        $placeholders[] = ":" . $entryParameter;
                        $QueryBuilder->setParameter($entryParameter, $entry);
                    }

                    if (!empty($placeholders)) {
                        $QueryBuilder->{$method}($column . " IN (" . implode(",", $placeholders) . ")");
                    }

                    $index++;
                    continue;
                }
            }

            $QueryBuilder->{$method}($column . " = :" . $parameter);
            $QueryBuilder->setParameter($parameter, $data);
            $index++;
        }
    }


    /**
     * Return the DataBase table name
     *
     * @param false|string $type - (optional) standard=false; other options: relations
     *
     * @return string
     */
    public function getTable(false | string $type = false): string
    {
        if ($type == 'relations') {
            return QUI::getDBTableName($this->Project->getName() . '_media_relations');
        }

        return QUI::getDBTableName($this->Project->getName() . '_media');
    }

    /**
     * Delete the complete media cache
     *
     * @throws QUI\Exception
     */
    public function clearCache(): void
    {
        $dir = $this->getFullCachePath();

        QUI::getTemp()->moveToTemp($dir);
        QUI\Utils\System\File::mkdir($dir);
    }

    /**
     * Return the complete cache path from the media
     * (with the CMS_DIR)
     *
     * @return string - path to the directory, relative to the system
     */
    public function getFullCachePath(): string
    {
        return CMS_DIR . $this->getCacheDir();
    }

    /**
     * Return the cache directory from the media
     *
     * @return string - path to the directory, relative to the system
     */
    public function getCacheDir(): string
    {
        return 'media/cache/' . $this->getProject()->getName() . '/';
    }

    /**
     * Return the first child in the media
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function firstChild(): QUI\Projects\Media\Folder
    {
        $Folder = $this->get(1);

        if ($Folder instanceof QUI\Projects\Media\Folder) {
            return $Folder;
        }

        return new QUI\Projects\Media\Folder([
            'id' => 1
        ], $this);
    }

    /**
     * Return a media object
     *
     * @param integer $id - media id
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function get(int $id): QUI\Interfaces\Projects\Media\File
    {
        if (isset($this->children[$id])) {
            return $this->children[$id];
        }

        // If the RAM is full objects was once empty
        if (QUI\Utils\System::memUsageToHigh()) {
            $this->children = [];
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $result = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Platform->quoteSingleIdentifier($this->getTable()))
            ->where($Platform->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($result === false) {
            throw new QUI\Exception('Media file with ID "' . $id . '" not found', 404);
        }

        if (QUI::isFrontend() && $result['deleted']) {
            throw new QUI\Exception('Media file with ID "' . $id . '" not found', 404);
        }


        $this->children[$id] = $this->parseResultToItem($result);

        return $this->children[$id];
    }

    /**
     * methods for usage
     */
    /**
     * Parse a database entry to a media object
     *
     * @param array<string, mixed> $result
     */
    public function parseResultToItem(array $result): QUI\Interfaces\Projects\Media\File
    {
        return match ($result['type']) {
            "image" => new Media\Image($result, $this),
            "folder" => new Media\Folder($result, $this),
            "video" => new Media\Video($result, $this),
            default => new Media\File($result, $this),
        };
    }

    /**
     * Return the wanted children ids
     *
     * @param array<string, mixed> $params - DataBase params
     *
     * @return array<int, mixed> id list
     */
    public function getChildrenIds(array $params = []): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->select($Platform->quoteSingleIdentifier("id"))
            ->from($Platform->quoteSingleIdentifier($this->getTable()));

        if (isset($params["where"]) && is_array($params["where"])) {
            self::applyMediaConditions($QueryBuilder, $params["where"], "andWhere");
        }

        if (isset($params["where_or"]) && is_array($params["where_or"])) {
            self::applyMediaConditions($QueryBuilder, $params["where_or"], "orWhere");
        }

        if (!empty($params["order"])) {
            $order = explode(" ", (string)$params["order"], 2);
            $QueryBuilder->orderBy(
                $Platform->quoteSingleIdentifier($order[0]),
                isset($order[1]) && $order[1] === "DESC" ? "DESC" : "ASC"
            );
        }

        QUI\Utils\Doctrine::applyLimit($QueryBuilder, $params["limit"] ?? null);

        try {
            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addDebug($Exception->getMessage());

            return [];
        }

        $ids = [];

        foreach ($result as $entry) {
            $ids[] = $entry['id'];
        }

        return $ids;
    }

    /**
     * Return a file from its file oath
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function getChildByPath(string $filepath): QUI\Interfaces\Projects\Media\File
    {
        $cache = $this->getCacheDir() . 'filePathIds/' . md5($filepath);

        try {
            $id = QUI\Cache\LongTermCache::get($cache);
        } catch (QUI\Exception) {
            $table = $this->getTable();

            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $result = $Connection->createQueryBuilder()
                ->select($Platform->quoteSingleIdentifier("id"))
                ->from($Platform->quoteSingleIdentifier($table))
                ->where($Platform->quoteSingleIdentifier("deleted") . " = :deleted")
                ->andWhere($Platform->quoteSingleIdentifier("file") . " = :file")
                ->setParameter("deleted", 0)
                ->setParameter("file", $filepath)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($result === false) {
                throw new QUI\Exception('Media file with path "' . $filepath . '" not found', 404);
            }

            $id = (int)$result['id'];

            QUI\Cache\LongTermCache::set($cache, $id);
        }

        return $this->get($id);
    }

    /**
     * Replace a file with another
     *
     * @param integer $id
     * @param string $file - Path to the new file
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @return QUI\Interfaces\Projects\Media\File
     *
     * @throws QUI\Exception
     */
    public function replace(
        int $id,
        string $file,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): QUI\Interfaces\Projects\Media\File {
        if (!file_exists($file)) {
            throw new QUI\Exception('Replacement file could not be found in path "' . $file . '"', 404);
        }

        // use direct db not the objects, because
        // if file is not ok you can replace the file though
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $data = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Platform->quoteSingleIdentifier($this->getTable()))
            ->where($Platform->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();


        if ($data === false) {
            throw new QUI\Exception('Media file for replacement with ID "' . $id . '" not found', 404);
        }

        if ($data['type'] == 'folder') {
            throw new QUI\Exception('Folders cannot be replaced by files', 403);
        }

        $this->get($id)->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        $name = $data['name'];
        $info = QUI\Utils\System\File::getInfo($file);
        $extension = strtolower((string)($info['extension'] ?? ''));
        $hasSvgType = in_array($info['mime_type'], ['image/svg', 'image/svg+xml'], true);
        $mustBeSvg = in_array($extension, ['svg', 'svgz'], true) || $hasSvgType;
        $mayBeSvg = $mustBeSvg || in_array(
            $info['mime_type'],
            ['application/xml', 'application/xhtml+xml', 'text/html', 'text/plain', 'text/xml'],
            true
        );
        $sanitizedSvg = null;

        if ($mayBeSvg) {
            $content = file_get_contents($file);

            if (is_string($content)) {
                $sanitized = SvgSanitizer::sanitize($content);

                if ($sanitized !== '') {
                    $sanitizedSvg = $sanitized;
                    $info['mime_type'] = 'image/svg+xml';

                    if ($extension !== 'svg') {
                        $info['basename'] = $info['filename'] . '.svg';
                        $info['extension'] = 'svg';
                    }
                }
            }

            if ($mustBeSvg && $sanitizedSvg === null) {
                throw new QUI\Exception(
                    ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                    Media\ErrorCodes::FILE_IMAGE_CORRUPT
                );
            }
        }

        if ($info['mime_type'] != $data['mime_type']) {
            $name = $info['basename'];
            $name = trim($name, "_ \t\n\r\0\x0B"); // Trim the default characters and underscores
            $name = str_replace(' ', '_', $name);
            $name = preg_replace('#(_){2,}#', "$1", $name) ?? $name;
            $name = Utils::stripMediaName($name);
        }

        /**
         * get the parent and check, if a file, like the replaced file, exists
         */
        $parentId = $this->getParentIdFrom($data['id']);

        if (!$parentId) {
            throw new QUI\Exception('No Parent found.', 404);
        }

        $Parent = $this->get($parentId);

        if (
            $Parent instanceof QUI\Projects\Media\Folder
            && $data['name'] !== $name
            && $Parent->childWithNameExists($name)
        ) {
            throw new QUI\Exception(
                'A file with the name ' . $name . ' already exist.',
                403
            );
        }

        // check file size if needed and if the file is an image
        $imageType = Utils::getMediaTypeByMimeType($info['mime_type']);

        if ($sanitizedSvg !== null) {
            if (file_put_contents($file, $sanitizedSvg) === false) {
                throw new QUI\Exception(
                    ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                    Media\ErrorCodes::FILE_IMAGE_CORRUPT
                );
            }

            $Document = new \DOMDocument();
            $Document->resolveExternals = false;
            $Document->substituteEntities = false;
            $Document->loadXML($sanitizedSvg, LIBXML_NONET);
            $Svg = $Document->documentElement;

            if ($Svg instanceof \DOMElement) {
                $width = $Svg->getAttribute('width');
                $height = $Svg->getAttribute('height');
                $viewBox = preg_split('/[\s,]+/', $Svg->getAttribute('viewBox'));

                if ((!$width || !$height) && is_array($viewBox) && count($viewBox) === 4) {
                    $width = $width ?: $viewBox[2];
                    $height = $height ?: $viewBox[3];
                }

                $info['width'] = $width ? (int)$width : null;
                $info['height'] = $height ? (int)$height : null;
            }
        } elseif ($imageType === 'image') {
            $maxConfigSize = (int)$this->getProject()->getConfig('media_maxUploadSize');
            $info = FileUtils::getInfo($file, ['imagesize' => true]);

            // create image
            $Image = $this->getImageManager()->read($file);

            if (
                $maxConfigSize > 0
                && !empty($info['width'])
                && !empty($info['height'])
            ) {
                $sizes = QUI\Utils\Math::resize($info['width'], $info['height'], $maxConfigSize);
                $Image->scaleDown($sizes[1], $sizes[2]);
            }

            $Image->save($file);
            $info = QUI\Utils\System\File::getInfo($file);
        }

        QUI::getEvents()->fireEvent('mediaReplaceBegin', [$this, $id, $data]);

        // delete the file
        if (!empty($data['file'])) {
            QUI\Utils\System\File::unlink(
                $this->getFullPath() . $data['file']
            );
        }

        if ($data['name'] != $name) {
            $new_file = $Parent->getPath() . $name;
            $real_file = $Parent->getFullPath() . $name;
        } else {
            $new_file = $data['file'];
            $real_file = $this->getFullPath() . $data['file'];
        }

        $imageHeight = null;
        $imageWidth = null;

        if (!empty($info['height'])) {
            $imageHeight = $info['height'];
        }

        if (!empty($info['width'])) {
            $imageWidth = $info['width'];
        }

        QUI::getDataBaseConnection()->update(
            $this->getTable(),
            [
                'file' => $new_file,
                'name' => $name,
                'mime_type' => $info['mime_type'],
                'image_height' => $imageHeight,
                'image_width' => $imageWidth,
                'type' => $imageType,
                'e_date' => date('Y-m-d H:i:s')
            ],
            ['id' => $id]
        );

        QUI\Utils\System\File::move($file, $real_file);

        if (isset($this->children[$id])) {
            unset($this->children[$id]);
        }

        $File = $this->get($id);
        $File->deleteCache();

        QUI::getEvents()->fireEvent('mediaReplace', [$this, $File]);

        return $File;
    }

    /**
     * Return the parent id
     */
    public function getParentIdFrom(int $id): false | int
    {
        if ($id <= 1) {
            return false;
        }

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $parent = $Connection->createQueryBuilder()
                ->select($Platform->quoteSingleIdentifier("parent"))
                ->from($Platform->quoteSingleIdentifier($this->getTable("relations")))
                ->where($Platform->quoteSingleIdentifier("child") . " = :child")
                ->setParameter("child", $id)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        if ($parent !== false) {
            return (int)$parent;
        }

        return false;
    }

    /**
     * Return the ImageManager of the Media
     */
    public function getImageManager(): ImageManager
    {
        $Project = $this->getProject();
        $library = $Project->getConfig('media_image_library');

        switch ($library) {
            case '':
            case 'imagick':
            case 'gd':
                break;

            default:
                $library = '';
        }

        if (class_exists('Imagick') && ($library === '' || $library === 'imagick')) {
            return ImageManager::imagick();
        }

        return ImageManager::gd();
    }

    /**
     * Return the main media directory
     * Here are all files located
     * (with the CMS_DIR)
     *
     * @return string - path to the directory
     */
    public function getFullPath(): string
    {
        return CMS_DIR . $this->getPath();
    }

    /**
     * Return the main media directory
     * Here are all files located
     * (without the CMS_DIR)
     *
     * @return string - path to the directory, relative to the system
     */
    public function getPath(): string
    {
        return 'media/sites/' . $this->getProject()->getName() . '/';
    }

    /**
     * Returns the Media Trash
     */
    public function getTrash(): Media\Trash
    {
        return new Media\Trash($this);
    }

    /**
     * Updates all external images
     */
    public function updateExternalImages(): void
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $result = $Connection->createQueryBuilder()
                ->select("*")
                ->from($Platform->quoteSingleIdentifier($this->getTable()))
                ->where($Platform->quoteSingleIdentifier("external") . " <> :external")
                ->setParameter("external", "")
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($result as $item) {
            try {
                $Image = $this->get($item['id']);

                if ($Image instanceof QUI\Projects\Media\Image) {
                    $Image->updateExternalImage();
                }
            } catch (QUI\Exception) {
            }
        }
    }
}
