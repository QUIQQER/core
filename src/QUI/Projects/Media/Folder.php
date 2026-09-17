<?php

/**
 * This file contains the \QUI\Projects\Media\Folder
 */

namespace QUI\Projects\Media;

use Exception;
use QUI;
use QUI\ExceptionStack;
use QUI\Interfaces\Users\User;
use QUI\Projects\Media;
use QUI\Projects\Media\Utils as MediaUtils;
use QUI\Utils\Security\SvgSanitizer;
use QUI\Utils\StringHelper as StringUtils;
use QUI\Utils\System\File as FileUtils;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

use function class_exists;
use function count;
use function date;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_array;
use function is_string;
use function in_array;
use function ltrim;
use function md5;
use function rtrim;
use function set_time_limit;
use function str_replace;
use function strtolower;
use function strlen;
use function strpos;
use function substr;
use function time;
use function trim;
use function unlink;
use function usort;

/**
 * A media folder
 */
class Folder extends Item implements QUI\Interfaces\Projects\Media\File
{
    /**
     * Upload file flag - don't overwrite the file
     */
    const FILE_OVERWRITE_NONE = 0;

    /**
     * Upload file flag - overwrite the file, don't delete the old file
     */
    const FILE_OVERWRITE_TRUE = 1;

    /**
     * Upload file flag - overwrite the file and delete the old file
     */
    const FILE_OVERWRITE_DESTROY = 2;

    /**
     * direct children of the folder
     *
     * @var array<int, QUI\Interfaces\Projects\Media\File>
     */
    protected array $children = [];

    /**
     * (non-PHPdoc)
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws QUI\Exception
     * @see QUI\Interfaces\Projects\Media\File::activate()
     */
    public function activate(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['active' => 1],
            ['id' => $this->getId()]
        );

        $this->setAttribute('active', 1);

        // activate recursive to the top
        $Media = $this->Media;
        $parents_ids = $this->getParentIds();

        foreach ($parents_ids as $id) {
            try {
                $Item = $Media->get($id);
                $Item->activate($PermissionUser);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        // Cacheordner erstellen
        $this->createCache();

        QUI::getEvents()->fireEvent('mediaActivate', [$this]);
    }

    /**
     * @throws QUI\Exception
     */
    public function createCache(): bool | string
    {
        if (Media::$globalDisableMediaCacheCreation) {
            return false;
        }

        if (!$this->getAttribute('active')) {
            return true;
        }

        $cacheDir = CMS_DIR . $this->Media->getCacheDir() . $this->getAttribute('file');

        if (FileUtils::mkdir($cacheDir)) {
            return true;
        }

        throw new QUI\Exception(
            'createCache() Error; Could not create Folder ' . $cacheDir,
            ErrorCodes::FOLDER_CACHE_CREATION_MKDIR_ERROR
        );
    }

    /**
     * @see QUI\Interfaces\Projects\Media\File::restore()
     */
    public function restore(QUI\Projects\Media\Folder $Parent): void
    {
        // nothing
        // folders are not in the trash
    }

    /**
     * (non-PHPdoc)
     *
     * @param string $newName - new name for the folder
     *
     * @throws QUI\Exception
     * @see QUI\Projects\Media\Item::rename()
     *
     */
    public function rename(string $newName, null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        if (empty($newName)) {
            throw new QUI\Exception(
                ['quiqqer/core', 'exception.media.folder.name.invalid'],
                ErrorCodes::FOLDER_NAME_INVALID
            );
        }

        if ($this->getId() == 1) {
            throw new QUI\Exception(
                ['quiqqer/core', 'exception.media.root.folder.rename'],
                ErrorCodes::ROOT_FOLDER_CANT_RENAMED
            );
        }

        // filter illegal characters
        $Parent = $this->getParent();
        $newName = Utils::stripFolderName($newName);

        // rename
        if ($newName == $this->getAttribute('name')) {
            return;
        }


        // check if a folder with the new name exist
        if ($Parent->childWithNameExists($newName)) {
            throw new QUI\Exception(
                ['quiqqer/core', 'exception.media.folder.with.same.name.exists'],
                ErrorCodes::FOLDER_ALREADY_EXISTS
            );
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $old_path = $this->getPath() . "/";
        $new_path = $Parent->getPath() . "/" . $newName;

        $new_path = StringUtils::replaceDblSlashes($new_path);
        $new_path = ltrim($new_path, "/");

        $old_path = StringUtils::replaceDblSlashes($old_path);
        $old_path = rtrim($old_path, "/");
        $old_path = ltrim($old_path, "/");

        // update children paths
        $Connection->createQueryBuilder()
            ->update($Platform->quoteSingleIdentifier($this->Media->getTable()))
            ->set(
                $Platform->quoteSingleIdentifier("file"),
                "REPLACE(" . $Platform->quoteSingleIdentifier("file") . ", :oldpath, :newpath)"
            )
            ->where($Platform->quoteSingleIdentifier("file") . " LIKE :search")
            ->setParameter("oldpath", $old_path . "/")
            ->setParameter("newpath", $new_path . "/")
            ->setParameter("search", $old_path . "/%")
            ->executeStatement();

        $title = $this->getAttribute('title');

        if ($title == $this->getAttribute('name')) {
            $title = $newName;
        }

        $file = StringUtils::replaceDblSlashes($new_path . '/');
        $md5File = md5($file);

        // update me
        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            [
                'name' => $newName,
                'file' => $file,
                'title' => $title,
                'pathHash' => $md5File
            ],
            ['id' => $this->getId()]
        );

        FileUtils::move(
            $this->Media->getFullPath() . $old_path,
            $this->Media->getFullPath() . $new_path
        );

        // @todo rename cache instead of delete
        $this->deleteCache();

        $this->setAttribute('name', $newName);
        $this->setAttribute('file', $new_path . '/');

        QUI::getEvents()->fireEvent('mediaRename', [$this]);
    }

    /**
     * Return true if a child with the name exist
     */
    public function childWithNameExists(string $name): bool
    {
        try {
            $this->getChildByName($name);

            return true;
        } catch (QUI\Exception) {
        }

        return false;
    }

    /**
     * Return a file from the folder by its name
     *
     * @throws QUI\Exception
     */
    public function getChildByName(string $filename): Item
    {
        $children = $this->getChildrenByName($filename, 1);

        return $children[0];
    }

    /**
     * Return all children with the wanted name
     *
     * @param string $filename
     *
     * @return array<int, mixed>
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function getChildrenByName($filename, false | int $limit = false): array
    {
        $table = $this->Media->getTable();
        $table_rel = $this->Media->getTable('relations');

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->select("media." . $Platform->quoteSingleIdentifier("id"))
            ->from($Platform->quoteSingleIdentifier($table), "media")
            ->innerJoin(
                "media",
                $Platform->quoteSingleIdentifier($table_rel),
                "rel",
                "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
            )
            ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("name") . " = :name")
            ->setParameter("parent", $this->getId())
            ->setParameter("deleted", 0)
            ->setParameter("name", $filename);

        if ($limit) {
            $QueryBuilder->setMaxResults((int)$limit);
        }

        $dbResult = $QueryBuilder->executeQuery()->fetchAllAssociative();

        if (!isset($dbResult[0])) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.file.not.found.NAME', [
                    'file' => $filename
                ]),
                ErrorCodes::FILE_NOT_FOUND
            );
        }

        $result = [];

        foreach ($dbResult as $entry) {
            $result[] = $this->Media->get((int)$entry['id']);
        }

        return $result;
    }

    /**
     * (non-PHPdoc)
     *
     * @throws QUI\Exception
     * @see QUI\Interfaces\Projects\Media\File::deleteCache()
     */
    public function deleteCache(): void
    {
        $cacheDir = $this->Media->getFullCachePath();
        $cacheFile = $cacheDir . $this->getAttribute('file');

        FileUtils::unlink($cacheFile);
    }

    /**
     * @throws QUI\Exception
     *
     * @see QUI\Projects\Media\Item::moveTo()
     */
    public function moveTo(
        QUI\Projects\Media\Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): void {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);
        $Folder->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        if ($this->getId() === $Folder->getId() || in_array($this->getId(), $Folder->getParentIds(), true)) {
            throw new QUI\Exception('A folder cannot be moved into itself or one of its descendants.');
        }

        $Parent = $this->getParent();

        if ($Folder->getId() === $Parent->getId()) {
            return;
        }


        if ($Folder->childWithNameExists($this->getAttribute('name'))) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.folder.already.exists', [
                    'name' => $this->getAttribute('name')
                ]),
                ErrorCodes::FOLDER_ALREADY_EXISTS
            );
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $old_path = $this->getPath();
        $new_path = $Folder->getPath() . "/" . $this->getAttribute("name");

        $old_path = StringUtils::replaceDblSlashes($old_path);
        $new_path = StringUtils::replaceDblSlashes($new_path);
        $oldFullPath = $this->Media->getFullPath() . $old_path;
        $newFullPath = $this->Media->getFullPath() . $new_path;
        $filesystemMoved = false;

        try {
            $Connection->transactional(function () use (
                $Connection,
                $Platform,
                $old_path,
                $new_path,
                $oldFullPath,
                $newFullPath,
                $Parent,
                $Folder,
                &$filesystemMoved
            ): void {
                // update children paths
                $Connection->createQueryBuilder()
                    ->update($Platform->quoteSingleIdentifier($this->Media->getTable()))
                    ->set(
                        $Platform->quoteSingleIdentifier("file"),
                        "REPLACE(" . $Platform->quoteSingleIdentifier("file") . ", :oldpath, :newpath)"
                    )
                    ->where($Platform->quoteSingleIdentifier("file") . " LIKE :search")
                    ->setParameter("oldpath", StringUtils::replaceDblSlashes($old_path . "/"))
                    ->setParameter("newpath", StringUtils::replaceDblSlashes($new_path . "/"))
                    ->setParameter("search", $old_path . "%")
                    ->executeStatement();

                // update me
                $file = StringUtils::replaceDblSlashes($new_path . '/');

                $Connection->update(
                    $this->Media->getTable(),
                    [
                        'file' => $file,
                        'pathHash' => md5($file)
                    ],
                    ['id' => $this->getId()]
                );

                // set the new parent relationship
                $updatedRelations = $Connection->update(
                    $this->Media->getTable('relations'),
                    [
                        'parent' => $Folder->getId()
                    ],
                    [
                        'parent' => $Parent->getId(),
                        'child' => $this->getId()
                    ]
                );

                if ((int)$updatedRelations !== 1) {
                    throw new QUI\Exception('Could not update the media folder parent relation.');
                }

                if (!FileUtils::move($oldFullPath, $newFullPath)) {
                    throw new QUI\Exception('Could not move media folder on the filesystem.');
                }

                $filesystemMoved = true;
            });
        } catch (\Throwable $Exception) {
            if ($filesystemMoved) {
                try {
                    if (!FileUtils::move($newFullPath, $oldFullPath)) {
                        throw new QUI\Exception('Could not roll back media folder move on the filesystem.');
                    }
                } catch (\Throwable $RollbackException) {
                    throw new QUI\Exception(
                        'Media folder move failed and its filesystem rollback could not be completed.',
                        500,
                        ['rollbackException' => $RollbackException->getMessage()],
                        $Exception
                    );
                }
            }

            throw $Exception;
        }

        // @todo rename cache instead of delete
        $this->deleteCache();
        $this->setAttribute('file', StringUtils::replaceDblSlashes($new_path . '/'));
        $this->parent_id = $Folder->getId();
    }

    /**
     * @throws QUI\Exception
     *
     * @see QUI\Projects\Media\Item::copyTo()
     */
    public function copyTo(
        QUI\Projects\Media\Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): QUI\Interfaces\Projects\Media\File {
        $this->checkCopyViewPermissions($PermissionUser);
        $Folder->checkPermission('quiqqer.projects.media.upload', $PermissionUser);
        $Folder->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        return $this->copyToAfterPermissionCheck($Folder, $PermissionUser);
    }

    /**
     * Check view permissions for the complete source tree before creating the copy.
     *
     * @throws QUI\Exception
     */
    private function checkCopyViewPermissions(
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): void {
        $this->checkPermission('quiqqer.projects.media.view', $PermissionUser);

        $ids = $this->getChildrenIds();

        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
            $Item = $this->Media->get($id);

            if ($Item instanceof self) {
                $Item->checkCopyViewPermissions($PermissionUser);
                continue;
            }

            $Item->checkPermission('quiqqer.projects.media.view', $PermissionUser);
        }
    }

    /**
     * Copy a folder after the complete source tree has been authorized.
     *
     * @throws QUI\Exception
     */
    private function copyToAfterPermissionCheck(
        QUI\Projects\Media\Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): QUI\Interfaces\Projects\Media\File {
        if ($Folder->childWithNameExists($this->getAttribute('name'))) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.folder.already.exists', [
                    'name' => $this->getAttribute('name')
                ]),
                ErrorCodes::FOLDER_ALREADY_EXISTS
            );
        }

        // copy me
        $Copy = $Folder->createFolder($this->getAttribute('name'), $PermissionUser);

        $attributes = $this->getAttributes();

        foreach (
            [
            'id',
            'name',
            'file',
            'pathHash',
            'pathHistory',
            'url',
            'cache_url'
            ] as $attribute
        ) {
            unset($attributes[$attribute]);
        }

        $Copy->setAttributes($attributes);
        $Copy->save();

        // copy the children
        $ids = $this->getChildrenIds();

        if (!is_array($ids)) {
            $ids = [];
        }

        foreach ($ids as $id) {
            try {
                $Item = $this->Media->get($id);

                if ($Item instanceof self) {
                    $Item->copyToAfterPermissionCheck($Copy, $PermissionUser);
                    continue;
                }

                if ($Item instanceof Item) {
                    $Item->copyTo($Copy, $PermissionUser);
                    continue;
                }

                $Item->copyTo($Copy);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        return $Copy;
    }

    /**
     * Adds / create a subfolder
     *
     * @param string $foldername
     * @param User|null $PermissionUser
     *
     * @throws QUI\Exception
     */
    public function createFolder(
        string $foldername,
        null | User $PermissionUser = null
    ): Folder {
        $this->checkPermission('quiqqer.projects.media.upload', $PermissionUser);
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        // Namensprüfung wegen unerlaubten Zeichen
        MediaUtils::checkFolderName($foldername);

        // Whitespaces am Anfang und am Ende rausnehmen
        $new_name = trim($foldername);


        $User = $PermissionUser ?? QUI::getUserBySession();
        $dir = $this->Media->getFullPath() . $this->getPath();

        if (is_dir($dir . $new_name)) {
            // prüfen ob dieser ordner schon als kind existiert
            // wenn nein, muss dieser ordner in der DB angelegt werden

            try {
                $children = $this->getChildByName($new_name);
            } catch (QUI\Exception) {
                $children = false;
            }

            if ($children) {
                throw new QUI\Exception(
                    'Der Ordner existiert schon ' . $dir . $new_name,
                    ErrorCodes::FOLDER_ALREADY_EXISTS
                );
            }
        }

        FileUtils::mkdir($dir . $new_name);

        $table = $this->Media->getTable();
        $table_rel = $this->Media->getTable('relations');
        $file = $this->getAttribute('file') . $new_name . '/';

        QUI::getDataBaseConnection()->insert($table, [
            'name' => $new_name,
            'title' => $new_name,
            'short' => $new_name,
            'type' => 'folder',
            'file' => $file,
            'pathHash' => md5($file),
            'alt' => $new_name,
            'c_date' => date('Y-m-d h:i:s'),
            'e_date' => date('Y-m-d h:i:s'),
            'c_user' => $User->getUUID(),
            'e_user' => $User->getUUID(),
            'mime_type' => 'folder'
        ]);

        $id = (int)QUI::getDataBaseConnection()->lastInsertId();

        QUI::getDataBaseConnection()->insert($table_rel, [
            'parent' => $this->getId(),
            'child' => $id
        ]);

        QUI\Cache\Manager::clear($this->getCachePath());

        if (is_dir($dir . $new_name)) {
            $Folder = $this->Media->get((int)$id);

            if ($Folder instanceof Folder) {
                $Folder->setEffects($this->getEffects());
                $Folder->save();

                return $Folder;
            }
        }

        throw new QUI\Exception(
            ['quiqqer/core', 'exception.media.folder.could.not.be.created'],
            ErrorCodes::FOLDER_ERROR_CREATION
        );
    }

    /**
     * Get cache path where internal folder statistics are cached (e.g. children count, subfolder count).
     */
    protected function getCachePath(): string
    {
        return 'quiqqer/media/' . $this->getProject()->getName() . '/folder/' . $this->getId() . '/';
    }

    /**
     * Return the children ids ( not resursive )
     * folders first, files seconds
     *
     * @param array<string, mixed> $params - [optional] db query fields
     *
     * If $params['count'] = true is set, then the total number of search results is returned!
     *
     * @return array<int, int>|int
     */
    public function getChildrenIds(array $params = []): array | int
    {
        $table = $this->Media->getTable();
        $tableRel = $this->Media->getTable("relations");
        $order = "name";

        if ($this->getAttribute("order")) {
            $order = $this->getAttribute("order");
        }

        if (isset($params["order"])) {
            $order = $params["order"];
        }

        switch ($order) {
            case "priority":
            case "priority ASC":
            case "priority DESC":
            case "c_date":
            case "c_date ASC":
            case "c_date DESC":
            case "name":
            case "name ASC":
            case "name DESC":
            case "title":
            case "title ASC":
            case "title DESC":
            case "id":
            case "id ASC":
            case "id DESC":
                break;

            default:
                $order = "name";
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->from($Platform->quoteSingleIdentifier($table), "media")
            ->innerJoin(
                "media",
                $Platform->quoteSingleIdentifier($tableRel),
                "rel",
                "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
            )
            ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->setParameter("parent", $this->getId())
            ->setParameter("deleted", 0);

        if (isset($params["where"]["hidden"])) {
            if ($params["where"]["hidden"] === 0 || $params["where"]["hidden"] === 1) {
                $QueryBuilder
                    ->andWhere("media." . $Platform->quoteSingleIdentifier("hidden") . " = :hidden")
                    ->setParameter("hidden", $params["where"]["hidden"]);
            }
        }

        if (!empty($params["count"])) {
            return (int)$QueryBuilder
                ->select("COUNT(media." . $Platform->quoteSingleIdentifier("id") . ")")
                ->executeQuery()
                ->fetchOne();
        }

        $QueryBuilder->select("media." . $Platform->quoteSingleIdentifier("id"));

        $orderParts = explode(" ", $order, 2);
        $orderField = $orderParts[0];
        $orderDirection = isset($orderParts[1]) && $orderParts[1] === "DESC" ? "DESC" : "ASC";

        if ($orderField === "priority") {
            $QueryBuilder->orderBy("media." . $Platform->quoteSingleIdentifier("priority"), $orderDirection);
        } else {
            $QueryBuilder
                ->addOrderBy(
                    "CASE WHEN media." . $Platform->quoteSingleIdentifier("type") . " = :folderType THEN 0 ELSE 1 END",
                    "ASC"
                )
                ->addOrderBy("media." . $Platform->quoteSingleIdentifier($orderField), $orderDirection)
                ->setParameter("folderType", "folder");
        }

        QUI\Utils\Doctrine::applyLimit($QueryBuilder, $params["limit"] ?? null);

        $fetch = $QueryBuilder->executeQuery()->fetchAllAssociative();
        $result = [];

        foreach ($fetch as $entry) {
            $result[] = (int)$entry["id"];
        }

        return $result;
    }

    /**
     * Creates a zip in the temp and return the path to it
     *
     * @throws QUI\Exception
     */
    public function createZIP(): string
    {
        $path = $this->getFullPath();

        $tempFolder = QUI::getTemp()->createFolder();
        $newZipFile = $tempFolder . $this->getAttribute('name') . '.zip';

        if (!class_exists('\ZipArchive')) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.zip.extension.not.installed'
            ]);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $countFiles = 0;

        foreach ($files as $File) {
            if (!$File->isDir()) {
                $countFiles++;
            }
        }

        if (!$countFiles) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.zip.folder.is.empty'
            ]);
        }

        $Zip = new ZipArchive();
        $Zip->open($newZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $File) {
            if ($File->isDir()) {
                continue;
            }

            $filePath = $File->getRealPath();
            $relativePath = substr($filePath, strlen($path));

            $Zip->addFile($filePath, $relativePath);
        }

        $Zip->close();

        return $newZipFile;
    }

    /**
     * Return the first child
     *
     * @throws QUI\Exception
     */
    public function firstChild(): File
    {
        $result = $this->getChildren(
            ['limit' => 1]
        );

        if (isset($result[0])) {
            return $result[0];
        }

        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.folder.has.no.files'),
            ErrorCodes::FOLDER_HAS_NO_FILES
        );
    }

    /**
     * Returns all children in the folder
     *
     * @param array<string, mixed> $params - [optional] db query fields
     *
     * @return array<int, mixed>
     */
    public function getChildren(array $params = []): array
    {
        $this->children = [];

        if (!isset($params['order'])) {
            $params['order'] = $this->getAttribute('order');
        }

        if (empty($params['order'])) {
            $params['order'] = 'priority';
        }

        $ids = $this->getChildrenIds($params);

        if (!is_array($ids)) {
            $ids = [];
        }

        foreach ($ids as $id) {
            try {
                $this->children[] = $this->Media->get($id);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        return $this->children;
    }

    /**
     * Returns the count of the children
     */
    public function hasChildren(): int
    {
        $cachePath = $this->getCachePath() . '/hasChildren';

        try {
            return QUI\Cache\Manager::get($cachePath);
        } catch (Exception) {
            // re-build cache
        }

        $table = $this->Media->getTable();
        $table_rel = $this->Media->getTable('relations');

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $childrenCount = (int)$Connection->createQueryBuilder()
                ->select("COUNT(media." . $Platform->quoteSingleIdentifier("id") . ")")
                ->from($Platform->quoteSingleIdentifier($table), "media")
                ->innerJoin(
                    "media",
                    $Platform->quoteSingleIdentifier($table_rel),
                    "rel",
                    "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
                )
                ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
                ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
                ->setParameter("parent", $this->getId())
                ->setParameter("deleted", 0)
                ->executeQuery()
                ->fetchOne();
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return 0;
        }

        QUI\Cache\Manager::set($cachePath, $childrenCount);

        return $childrenCount;
    }

    /**
     * Return the first image
     *
     * @throws QUI\Exception
     */
    public function firstImage(): Image
    {
        $result = $this->getImages([
            'limit' => 1
        ]);

        if (isset($result[0])) {
            return $result[0];
        }

        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.folder.has.no.images'),
            ErrorCodes::FOLDER_HAS_NO_IMAGES
        );
    }

    /**
     * Return the images from the folder
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>|int
     */
    public function getImages(array $params = []): array | int
    {
        return $this->getElements('image', $params);
    }

    /**
     * Return children / elements in the folder
     *
     * @param 'image'|'file'|'folder' $type
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>|int
     */
    protected function getElements(string $type, array $params): array | int
    {
        switch ($type) {
            case "image":
            case "file":
            case "folder":
                break;

            default:
                return [];
        }

        $table = $this->Media->getTable();
        $tableRel = $this->Media->getTable("relations");
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->from($Platform->quoteSingleIdentifier($table), "media")
            ->innerJoin(
                "media",
                $Platform->quoteSingleIdentifier($tableRel),
                "rel",
                "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
            )
            ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("type") . " = :type")
            ->setParameter("parent", $this->getId())
            ->setParameter("deleted", 0)
            ->setParameter("type", $type);

        if (isset($params["active"])) {
            $active = $params["active"];
        } else {
            $active = 1;
        }

        $QueryBuilder
            ->andWhere("media." . $Platform->quoteSingleIdentifier("active") . " = :active")
            ->setParameter("active", $active);

        if (isset($params["where"]["file"])) {
            $QueryBuilder
                ->andWhere("media." . $Platform->quoteSingleIdentifier("pathHash") . " = :pathHash")
                ->setParameter("pathHash", md5($params["where"]["file"]));
        }

        if (isset($params["count"])) {
            try {
                return (int)$QueryBuilder
                    ->select("COUNT(media." . $Platform->quoteSingleIdentifier("id") . ")")
                    ->executeQuery()
                    ->fetchOne();
            } catch (QUI\Exception) {
                return 0;
            }
        }

        QUI\Utils\Doctrine::applyLimit($QueryBuilder, $params["limit"] ?? null);

        // sorting
        $order = "title ASC";

        if ($this->getAttribute("order")) {
            $order = $this->getAttribute("order");
        }

        if (isset($params["order"])) {
            $order = $params["order"];
        }

        switch ($order) {
            case "title":
            case "title DESC":
            case "title ASC":
            case "name":
            case "name DESC":
            case "name ASC":
            case "c_date":
            case "c_date DESC":
            case "c_date ASC":
            case "e_date":
            case "e_date ASC":
            case "e_date DESC":
                break;

            case "priority":
            case "priority ASC":
            case "priority DESC":
                //  priority, title
                break;

            default:
                $order = "title ASC"; // title aufsteigend
                break;
        }

        $orderParts = explode(" ", $order, 2);
        $orderField = $orderParts[0];
        $orderDirection = isset($orderParts[1]) && $orderParts[1] === "DESC" ? "DESC" : "ASC";

        $QueryBuilder
            ->select("media." . $Platform->quoteSingleIdentifier("id"))
            ->orderBy("media." . $Platform->quoteSingleIdentifier($orderField), $orderDirection);

        try {
            $fetch = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        $result = [];

        foreach ($fetch as $entry) {
            try {
                $result[] = $this->Media->get((int)$entry["id"]);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
            }
        }

        switch ($order) {
            case "priority":
            case "priority ASC":
            case "priority DESC":
                // if priority, sort, that empty priority is the last
                usort($result, static function ($ImageA, $ImageB): int {
                    /*  $ImageA Image */
                    $a = $ImageA->getAttribute("priority");
                    /*  $ImageB Image */
                    $b = $ImageB->getAttribute("priority");

                    if (empty($a)) {
                        return 1;
                    }

                    if (empty($b)) {
                        return -1;
                    }

                    if ($a == $b) {
                        return 0;
                    }

                    return ($a < $b) ? -1 : 1;
                });
                break;
        }

        return $result;
    }

    /**
     * Return the sub folders from the folder
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>|int
     */
    public function getFolders(array $params = []): array | int
    {
        return $this->getElements('folder', $params);
    }

    /**
     * Return the files from folder
     *
     * @param array<string, mixed> $params - filter parameter
     *
     * @return array<int, mixed>|int
     */
    public function getFiles(array $params = []): array | int
    {
        return $this->getElements('file', $params);
    }

    /**
     * @todo as cron
     */
    public function getSize(): ?int
    {
        return QUI\Utils\System\Folder::getFolderSize($this->getFullPath());
    }

    /**
     * Returns the count of the children
     *
     * @todo use getElements folder with count
     */
    public function hasSubFolders(): int
    {
        $cachePath = $this->getCachePath() . "/hasSubFolders";

        try {
            return QUI\Cache\Manager::get($cachePath);
        } catch (Exception) {
            // re-build cache
        }

        $table = $this->Media->getTable();
        $tableRel = $this->Media->getTable("relations");

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $childrenCount = (int)$Connection->createQueryBuilder()
                ->select("COUNT(media." . $Platform->quoteSingleIdentifier("id") . ")")
                ->from($Platform->quoteSingleIdentifier($table), "media")
                ->innerJoin(
                    "media",
                    $Platform->quoteSingleIdentifier($tableRel),
                    "rel",
                    "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
                )
                ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
                ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
                ->andWhere("media." . $Platform->quoteSingleIdentifier("type") . " = :type")
                ->setParameter("parent", $this->getId())
                ->setParameter("deleted", 0)
                ->setParameter("type", "folder")
                ->executeQuery()
                ->fetchOne();
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return 0;
        }

        QUI\Cache\Manager::set($cachePath, $childrenCount);

        return $childrenCount;
    }

    /**
     * Returns only the sub folders
     *
     * @return array<int, mixed>
     *
     * @throws QUI\Database\Exception
     * @deprecated use getFolders
     */
    public function getSubFolders(): array
    {
        $table = $this->Media->getTable();
        $tableRel = $this->Media->getTable("relations");
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $result = $Connection->createQueryBuilder()
            ->select("media." . $Platform->quoteSingleIdentifier("id"))
            ->from($Platform->quoteSingleIdentifier($table), "media")
            ->innerJoin(
                "media",
                $Platform->quoteSingleIdentifier($tableRel),
                "rel",
                "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
            )
            ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->andWhere("media." . $Platform->quoteSingleIdentifier("type") . " = :type")
            ->setParameter("parent", $this->getId())
            ->setParameter("deleted", 0)
            ->setParameter("type", "folder")
            ->orderBy("media." . $Platform->quoteSingleIdentifier("name"), "ASC")
            ->executeQuery()
            ->fetchAllAssociative();

        $folders = [];

        foreach ($result as $entry) {
            try {
                $folders[] = $this->Media->get((int)$entry["id"]);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        return $folders;
    }

    /**
     * Return true if a file with the filename in the folder exists
     */
    public function fileWithNameExists(string $file): bool
    {
        $table = $this->Media->getTable();
        $tableRel = $this->Media->getTable("relations");

        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $result = $Connection->createQueryBuilder()
                ->select("1")
                ->from($Platform->quoteSingleIdentifier($table), "media")
                ->innerJoin(
                    "media",
                    $Platform->quoteSingleIdentifier($tableRel),
                    "rel",
                    "rel." . $Platform->quoteSingleIdentifier("child") . " = media." . $Platform->quoteSingleIdentifier("id")
                )
                ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
                ->andWhere("media." . $Platform->quoteSingleIdentifier("file") . " = :file")
                ->setParameter("parent", $this->getId())
                ->setParameter("file", $this->getPath() . $file)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $result !== false;
    }

    /**
     * Uploads a file to the Folder
     *
     * @param string $file - Path to the File
     * @param integer $options - Overwrite flags,
     *                           self::FILE_OVERWRITE_NONE
     *                           self::FILE_OVERWRITE_FILE
     *                           self::FILE_OVERWRITE_DESTROY
     * @param User|null $EditUser
     *
     * @return QUI\Interfaces\Projects\Media\File
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function uploadFile(
        string $file,
        int $options = Folder::FILE_OVERWRITE_NONE,
        ?QUI\Interfaces\Users\User $EditUser = null
    ): QUI\Interfaces\Projects\Media\File {
        if (empty($EditUser)) {
            $EditUser = QUI::getUserBySession();
        }

        if (Media::useMediaPermissions()) {
            QUI\Permissions\Permission::checkPermission(
                'quiqqer.projects.media.upload',
                $EditUser
            );

            $this->checkPermission(
                'quiqqer.projects.media.upload',
                $EditUser
            );
        }

        if (!file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.file.not.found', [
                    'file' => $file
                ]),
                ErrorCodes::FILE_NOT_FOUND
            );
        }

        if (is_dir($file)) {
            return $this->uploadFolder($file, false, $EditUser);
        }

        $fileInfo = FileUtils::getInfo($file);
        $filename = MediaUtils::stripMediaName($fileInfo['basename']);
        $sanitizedSvg = null;


        // test if the image is readable
        if (
            MediaUtils::getMediaTypeByMimeType($fileInfo['mime_type']) === 'image'
            && !str_contains($fileInfo['mime_type'], 'svg')
        ) {
            try {
                $this->getMedia()->getImageManager()->read($file);
            } catch (Exception $Exception) {
                $message = $Exception->getMessage();

                // gd lib has some unsupported image types
                // we can go on
                if (!str_contains($message, 'Unsupported image type')) {
                    QUI\System\Log::addError($Exception->getMessage());

                    throw new QUI\Exception(
                        ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                        ErrorCodes::FILE_IMAGE_CORRUPT
                    );
                }
            }
        }


        // mb_strtolower hat folgenden Grund: file_exists beachtet Gross und Kleinschreibung im Unix Systemen
        // Daher sind die Namen im Mediabereich alle klein geschrieben damit es keine Doppelten Dateien geben kann
        // Test.jpg und test.jpg wären unterschiedliche Dateien bei Windows aber nicht

        // $filename = \mb_strtolower($filename); -> mor will das raus haben

        $extension = strtolower((string)($fileInfo['extension'] ?? ''));
        $hasSvgType = in_array($fileInfo['mime_type'], ['image/svg', 'image/svg+xml'], true);
        $mustBeSvg = in_array($extension, ['svg', 'svgz'], true) || $hasSvgType;
        $mayBeSvg = $mustBeSvg || in_array(
            $fileInfo['mime_type'],
            ['application/xml', 'application/xhtml+xml', 'text/html', 'text/plain', 'text/xml'],
            true
        );

        if ($mayBeSvg) {
            $content = file_get_contents($file);

            if (is_string($content)) {
                $sanitized = SvgSanitizer::sanitize($content);

                if ($sanitized !== '') {
                    $sanitizedSvg = $sanitized;
                    $fileInfo['mime_type'] = 'image/svg+xml';

                    if ($extension !== 'svg') {
                        $filename = MediaUtils::stripMediaName($fileInfo['filename']) . '.svg';
                        $fileInfo['extension'] = 'svg';
                    }
                }
            }

            if ($mustBeSvg && $sanitizedSvg === null) {
                throw new QUI\Exception(
                    ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                    ErrorCodes::FILE_IMAGE_CORRUPT
                );
            }
        }

        // if no ending, we search for one
        if (empty($fileInfo['extension'])) {
            $filename .= FileUtils::getEndingByMimeType($fileInfo['mime_type']);
        }

        $new_file = $this->getFullPath() . '/' . $filename;
        $new_file = str_replace("//", "/", $new_file);

        // overwrite the file
        if (file_exists($new_file)) {
            if ($options != self::FILE_OVERWRITE_DESTROY && $options != self::FILE_OVERWRITE_TRUE) {
                throw new QUI\Exception(
                    QUI::getLocale()->get('quiqqer/core', 'exception.media.file.already.exists', [
                        'filename' => $filename
                    ]),
                    ErrorCodes::FILE_ALREADY_EXISTS
                );
            }

            // overwrite file
            try {
                $Item = MediaUtils::getElement($new_file);
                $Item->checkPermission('quiqqer.projects.media.edit', $EditUser);
                $Item->checkPermission('quiqqer.projects.media.del', $EditUser);
                $Item->deleteCache();
                $Item->deactivate($EditUser);
                $Item->delete($EditUser);

                if ($options == self::FILE_OVERWRITE_DESTROY) {
                    $Item->destroy($EditUser);
                }
            } catch (QUI\Permissions\Exception $Exception) {
                throw $Exception;
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addDebug(
                    $Exception->getMessage(),
                    ['file' => $new_file]
                );

                unlink($new_file);
            }
        }

        // copy the file to the media
        if ($sanitizedSvg !== null) {
            if (file_put_contents($new_file, $sanitizedSvg) === false) {
                throw new QUI\Exception(
                    ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                    ErrorCodes::FILE_IMAGE_CORRUPT
                );
            }
        } else {
            FileUtils::copy($file, $new_file);
        }


        // create the database entry
        $table = $this->Media->getTable();
        $table_rel = $this->Media->getTable('relations');

        $new_file_info = FileUtils::getInfo($new_file);

        if ($sanitizedSvg !== null) {
            $new_file_info['mime_type'] = 'image/svg+xml';
        }

        $title = str_replace('_', ' ', $new_file_info['filename']);

        if (empty($new_file_info['filename'])) {
            $new_file_info['filename'] = time();
        }

        $filePath = $this->getAttribute('file') . '/' . $new_file_info['basename'];

        if ($this->getId() == 1) {
            $filePath = $new_file_info['basename'];
        }

        $filePath = StringUtils::replaceDblSlashes($filePath);
        $imageWidth = null;
        $imageHeight = null;

        if ($sanitizedSvg !== null) {
            try {
                $dom = new \DOMDocument();
                $dom->resolveExternals = false;
                $dom->substituteEntities = false;
                $dom->loadXML($sanitizedSvg, LIBXML_NONET);
                $svg = $dom->getElementsByTagName('svg')->item(0);
            } catch (\Exception) {
                $svg = null;
            }

            if ($svg) {
                $width = $svg->getAttribute('width');
                $height = $svg->getAttribute('height');
                $viewBox = $svg->getAttribute('viewBox');

                // Fallback auf viewBox, falls width/height fehlen oder 0 sind
                if ((!$width || !$height) && $viewBox) {
                    $parts = preg_split('/[\s,]+/', $viewBox);

                    if (!is_array($parts)) {
                        $parts = [];
                    }

                    if (count($parts) === 4) {
                        if (!$width) {
                            $width = $parts[2];
                            $new_file_info['width'] = (int)$width;
                        }

                        if (!$height) {
                            $height = $parts[3];
                            $new_file_info['height'] = (int)$height;
                        }
                    }
                }

                if ($width) {
                    $new_file_info['width'] = (int)$width;
                }

                if ($height) {
                    $new_file_info['height'] = (int)$height;
                }
            }
        }

        if (isset($new_file_info['width']) && $new_file_info['width']) {
            $imageWidth = (int)$new_file_info['width'];
        }

        if (isset($new_file_info['height']) && $new_file_info['height']) {
            $imageHeight = (int)$new_file_info['height'];
        }


        QUI::getDataBaseConnection()->insert($table, [
            'name' => $new_file_info['filename'],
            'short' => '',
            'file' => $filePath,
            'pathHash' => md5($filePath),
            'c_date' => date('Y-m-d h:i:s'),
            'e_date' => date('Y-m-d h:i:s'),
            'c_user' => $EditUser->getUUID(),
            'e_user' => $EditUser->getUUID(),
            'mime_type' => $new_file_info['mime_type'],
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
            'type' => MediaUtils::getMediaTypeByMimeType($new_file_info['mime_type'])
        ]);

        $id = (int)QUI::getDataBaseConnection()->lastInsertId();

        QUI::getDataBaseConnection()->insert($table_rel, [
            'parent' => $this->getId(),
            'child' => $id
        ]);

        $File = $this->Media->get((int)$id);

        if ($File instanceof QUI\Projects\Media\File) {
            $File->generateMD5();
            $File->generateSHA1();
            $File->setTitle($title);
            $File->setAlt($title);
        }

        $maxSize = $this->getProject()->getConfig('media_maxUploadSize');

        // if it is an image, then resize -> if needed
        if (
            $File instanceof Image
            && $maxSize
            && isset($new_file_info['width'])
            && isset($new_file_info['height'])
        ) {
            $resizeData = $File->getResizeSize($maxSize, $maxSize);

            if ($new_file_info['width'] > $maxSize || $new_file_info['height'] > $maxSize) {
                $File->resize($resizeData['width'], $resizeData['height']);

                QUI::getDataBaseConnection()->update(
                    $table,
                    [
                        'image_width' => $resizeData['width'],
                        'image_height' => $resizeData['height'],
                    ],
                    [
                        'id' => $id
                    ]
                );
            }

            $File->setEffects($this->getEffects());
        }

        $File->save();

        QUI\Cache\Manager::clear($this->getCachePath());

        return $File;
    }

    /**
     * If the file is a folder
     *
     * @param string $path - Path to the dir
     * @param QUI\Projects\Media\Folder|false $Folder - (optional) Uploaded Folder
     * @param User|null $PermissionUser
     *
     * @return Folder
     * @throws QUI\Exception
     */
    protected function uploadFolder(
        string $path,
        false | Folder $Folder = false,
        null | User $PermissionUser = null
    ): Folder {
        $files = FileUtils::readDir($path);

        foreach ($files as $file) {
            // subfolders
            if (is_dir($path . '/' . $file)) {
                $folderName = MediaUtils::stripFolderName($file);

                try {
                    $NewFolder = $this->getChildByName($folderName);
                } catch (QUI\Exception) {
                    $NewFolder = $this->createFolder($folderName, $PermissionUser);
                }

                if ($NewFolder instanceof Folder) {
                    $NewFolder->uploadFolder($path . '/' . $file, false, $PermissionUser);
                }

                continue;
            }

            // import files
            if ($Folder) {
                $Folder->uploadFile($path . '/' . $file, self::FILE_OVERWRITE_NONE, $PermissionUser);
            } else {
                $this->uploadFile($path . '/' . $file, self::FILE_OVERWRITE_NONE, $PermissionUser);
            }
        }

        QUI\Cache\Manager::clear($this->getCachePath());

        return $this;
    }

    /**
     * Deactivate the folder
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws QUI\Exception
     * @see QUI\Interfaces\Projects\Media\File::deactivate()
     */
    public function deactivate(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        if ($this->isActive() === false) {
            return;
        }

        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['active' => 0],
            ['id' => $this->getId()]
        );

        $this->setAttribute('active', 0);

        // Images / Folders / Files rekursive deactivasion
        $ids = $this->getAllRecursiveChildrenIds();
        $Media = $this->Media;

        foreach ($ids as $id) {
            try {
                $Item = $Media->get($id);
                $Item->deactivate($PermissionUser);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        $this->deleteCache();

        QUI::getEvents()->fireEvent('mediaDeactivate', [$this]);
    }

    /**
     * Returns all ids from children under the folder
     *
     * @return array<int, mixed>
     */
    protected function getAllRecursiveChildrenIds(): array
    {
        // own sql statement, not over the getChildren() method,
        // its better for performance
        try {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $children = $Connection->createQueryBuilder()
                ->select($Platform->quoteSingleIdentifier("id"))
                ->from($Platform->quoteSingleIdentifier($this->Media->getTable()))
                ->where($Platform->quoteSingleIdentifier("file") . " LIKE :file")
                ->setParameter("file", $this->getAttribute("file") . "%")
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (QUI\Database\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        $result = [];

        foreach ($children as $child) {
            $result[] = $child['id'];
        }

        return $result;
    }

    /**
     * Delete the folder
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws QUI\Exception
     * @see QUI\Projects\Media\Item::delete()
     */
    public function delete(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.del', $PermissionUser);


        if ($this->isDeleted()) {
            throw new QUI\Exception(
                'Folder is already deleted',
                ErrorCodes::FOLDER_ALREADY_DELETED
            );
        }

        if ($this->getId() == 1) {
            throw new QUI\Exception(
                'Root cannot deleted',
                ErrorCodes::ROOT_FOLDER_CANT_DELETED
            );
        }

        QUI::getEvents()->fireEvent('mediaDeleteBegin', [$this]);

        $children = $this->getAllRecursiveChildrenIds();

        // move files to the temp folder
        // and delete the files first
        foreach ($children as $id) {
            try {
                $File = $this->Media->get($id);

                if (MediaUtils::isFolder($File) === false) {
                    $File->delete($PermissionUser);
                }
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        // now delete all sub folders
        foreach ($children as $id) {
            try {
                $File = $this->Media->get($id);

                if (MediaUtils::isFolder($File) === false) {
                    continue;
                }

                // delete database entries
                QUI::getDataBaseConnection()->delete(
                    $this->Media->getTable(),
                    ['id' => $id]
                );

                $this->Media->invalidateItemCache((int)$id);

                QUI::getDataBaseConnection()->delete(
                    $this->Media->getTable('relations'),
                    ['child' => $id]
                );
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        // delete the own database entries
        QUI::getDataBaseConnection()->delete(
            $this->Media->getTable(),
            ['id' => $this->getId()]
        );

        $this->Media->invalidateItemCache($this->getId());

        QUI::getDataBaseConnection()->delete(
            $this->Media->getTable('relations'),
            ['child' => $this->getId()]
        );

        FileUtils::unlink($this->getFullPath());


        // delete cache
        $this->deleteCache();

        QUI::getEvents()->fireEvent('mediaDelete', [$this]);
    }

    /**
     * (non-PHPdoc)
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     * @throws ExceptionStack
     * @see QUI\Projects\Media\Item::destroy()
     */
    public function destroy(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        // nothing
        // folders are not in the trash

        QUI::getEvents()->fireEvent('mediaDestroy', [$this]);
    }

    /**
     * Set the effects recursive to all items and folders
     *
     * @todo do this as a job
     */
    public function setEffectsRecursive(): void
    {
        $Media = $this->getMedia();
        $ids = $this->getAllRecursiveChildrenIds();
        $effects = $this->getEffects();

        foreach ($ids as $id) {
            try {
                set_time_limit(1);
                $Item = $Media->get($id);

                if ($Item instanceof Folder || $Item instanceof Image) {
                    $Item->setEffects($effects);
                    $Item->save();
                }
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
            }
        }
    }
}
