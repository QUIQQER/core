<?php

/**
 * This file contains \QUI\Projects\Media\Trash
 */

namespace QUI\Projects\Media;

use QUI;
use QUI\Exception;
use QUI\Projects\Media;

use function end;
use function explode;
use function file_exists;
use function json_decode;

/**
 * The media trash
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Trash implements QUI\Interfaces\Projects\Trash
{
    public function __construct(
        protected Media $Media
    ) {
        QUI\Utils\System\File::mkdir($this->getPath());
    }

    /**
     * Returns the trash path for the Media
     */
    public function getPath(): string
    {
        return VAR_DIR . 'media/trash/' . $this->Media->getProject()->getName() . '/';
    }

    /**
     * Returns the items in the trash
     *
     * @param array<string, mixed> $params - QUI\Utils\Grid parameters
     */
    public function getList(array $params = []): array
    {
        $Grid = new QUI\Utils\Grid();
        $query = $Grid->parseDBParams($params);

        $query['from'] = $this->Media->getTable();
        $query['where'] = [
            'deleted' => 1
        ];

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $table = $Platform->quoteSingleIdentifier($this->Media->getTable());

        try {
            $count = $Connection->createQueryBuilder()
                ->select("COUNT(*)")
                ->from($table)
                ->where($Platform->quoteSingleIdentifier("deleted") . " = :deleted")
                ->setParameter("deleted", 1)
                ->executeQuery()
                ->fetchOne();

            $QueryBuilder = $Connection->createQueryBuilder()
                ->select("*")
                ->from($table)
                ->where($Platform->quoteSingleIdentifier("deleted") . " = :deleted")
                ->setParameter("deleted", 1);

            if (!empty($query["order"])) {
                $order = explode(" ", (string)$query["order"], 2);
                $orderField = match ($order[0]) {
                    "id", "name", "title", "file", "type", "mime_type", "c_date", "e_date", "deleted_at" => $order[0],
                    default => "id"
                };
                $orderDirection = isset($order[1]) && $order[1] === "ASC" ? "ASC" : "DESC";
                $QueryBuilder->orderBy($Platform->quoteSingleIdentifier($orderField), $orderDirection);
            }

            QUI\Utils\Doctrine::applyLimit($QueryBuilder, $query["limit"] ?? null);

            $data = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            return $Grid->parseResult([], 0);
        }

        foreach ($data as $key => $entry) {
            $data[$key]['icon'] = Utils::getIconByExtension(
                Utils::getExtension($entry['file'])
            );

            $data[$key]['path'] = '---';

            $pathHistory = json_decode($entry['pathHistory'], true);

            if (!empty($pathHistory)) {
                $data[$key]['path'] = end($pathHistory) . '/';
            }

            $data[$key]['deleted_at'] = $entry['deleted_at'] ?: ($entry['e_date'] ?? '');
        }

        return $Grid->parseResult($data, (int)$count);
    }

    /**
     * Clears the complete trash
     * @throws QUI\Database\Exception
     */
    public function clear(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $data = $Connection->createQueryBuilder()
            ->select($Platform->quoteSingleIdentifier("id"))
            ->from($Platform->quoteSingleIdentifier($this->Media->getTable()))
            ->where($Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->setParameter("deleted", 1)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($data as $entry) {
            try {
                $File = $this->Media->get($entry['id']);

                if (!$File->isDeleted()) {
                    continue;
                }

                $File->destroy();
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addNotice($Exception->getMessage(), [
                    'method' => 'Media/Trash::clear()',
                    'fileId' => $entry['id']
                ]);
            }
        }
    }

    /**
     * Destroys a file by deleting it and marking it as destroyed.
     *
     * @throws Exception
     */
    public function destroy(int $id): void
    {
        // check if the file is really deleted?
        $File = $this->Media->get($id);

        if (!$File->isDeleted()) {
            $File->delete();
        }

        $File->destroy();
    }

    /**
     * Restore an item to a folder
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws QUI\Exception
     */
    public function restore(
        int $id,
        Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): QUI\Interfaces\Projects\Media\File {
        $file = $this->getPath() . $id;

        if (!file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.trash.file.not.found', [
                    'id' => $id
                ]),
                ErrorCodes::FILE_IN_TRASH_NOT_FOUND
            );
        }

        // search old db entry for data
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $data = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Platform->quoteSingleIdentifier($this->Media->getTable()))
            ->where($Platform->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($data === false) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.trash.file.not.found'),
                ErrorCodes::FILE_IN_TRASH_NOT_FOUND
            );
        }


        // rename the file for upload
        $extension = QUI\Utils\System\File::getEndingByMimeType(
            $data['mime_type']
        );

        $newFile = $this->getPath() . $data['name'] . $extension;

        $Source = $this->Media->get($id);
        $Source->checkPermission('quiqqer.projects.media.view', $PermissionUser);
        $Folder->checkPermission('quiqqer.projects.media.upload', $PermissionUser);

        QUI\Utils\System\File::move($file, $newFile);

        // insert the file
        $Item = $Folder->uploadFile(
            $newFile,
            Folder::FILE_OVERWRITE_NONE,
            $PermissionUser
        );

        // change old db entry, if one exist
        $Item->setAttributes([
            'title' => $data['title'],
            'alt' => $data['alt'],
            'short' => $data['short']
        ]);

        $Item->save();

        // delete the old db entry
        QUI::getDataBaseConnection()->delete(
            $this->Media->getTable(),
            ['id' => $id]
        );

        $this->Media->invalidateItemCache($id);

        return $Item;
    }
}
