<?php

namespace QUI\REST\Core\Project\Media;

use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Projects\Media\File as MediaFile;
use QUI\Interfaces\Users\User;
use QUI\Projects\Media\Folder;
use QUI\Projects\Media\Item;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Project\Sites\SiteEndpoint;

abstract class MediaEndpoint extends SiteEndpoint
{
    /** @param array<string, string> $arguments */
    protected static function item(array $arguments, string $permission = 'view', ?int $id = null): Item&MediaFile
    {
        $id ??= self::siteId($arguments['fileId']);
        $Item = self::project($arguments['project'])->getMedia()->get($id);

        if (!$Item instanceof Item || $Item->getAttribute('deleted')) {
            throw new ApiException('not_found', 'The requested media item does not exist.', 404);
        }

        $Item->checkPermission('quiqqer.projects.media.view', QUI::getUserBySession());
        $Item->checkPermission('quiqqer.projects.media.' . $permission, QUI::getUserBySession());
        return $Item;
    }

    /** @param array<string, string> $arguments */
    protected static function folder(array $arguments, string $permission = 'view', ?int $id = null): Folder
    {
        $Item = self::item($arguments, $permission, $id);

        if (!$Item instanceof Folder) {
            throw new ApiException('invalid_input', 'The selected media item must be a folder.');
        }

        return $Item;
    }

    /** @return array<string, mixed> */
    protected static function mediaData(MediaFile $Item): array
    {
        return [
            'id' => $Item->getId(), 'project' => $Item->getProject()->getName(),
            'parentId' => $Item->getParentId() ?: null, 'name' => $Item->getAttribute('name'),
            'type' => $Item->getAttribute('type'), 'filename' => basename($Item->getFullPath()),
            'mimeType' => $Item->getAttribute('mime_type'),
            'active' => (bool)$Item->getAttribute('active'), 'visible' => !$Item->getAttribute('hidden'),
            'title' => self::texts($Item->getAttribute('title')),
            'description' => self::texts($Item->getAttribute('description')),
            'alt' => self::texts($Item->getAttribute('alt')), 'url' => $Item->getUrl()
        ];
    }

    private static function texts(mixed $value): object
    {
        $decoded = is_string($value) ? json_decode($value) : null;
        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }

    protected static function filename(string $name): string
    {
        if (
            $name === '' || strlen($name) > 200 || $name !== trim($name)
            || preg_match('/[\\x00-\\x1f\\x7f\\\\\\/]/', $name) || in_array($name, ['.', '..'], true)
        ) {
            throw new ApiException('invalid_input', 'Filenames cannot contain paths or control characters.');
        }

        return $name;
    }

    protected static function checkMove(Item $Item, Folder $Target): void
    {
        if (
            $Item instanceof Folder && (
            $Item->getId() === $Target->getId() || in_array($Item->getId(), $Target->getParentIds(), true)
            )
        ) {
            throw new ApiException('invalid_input', 'A folder cannot be moved or copied into its own descendants.');
        }
    }

    /** @param list<int> $ids
     * @return list<int>
     */
    protected static function order(Folder $Folder, array $ids, User $User): array
    {
        $existing = $Folder->getChildrenIds(['order' => 'priority']);
        $existing = is_array($existing) ? array_map('intval', $existing) : [];

        if (array_diff($ids, $existing) !== []) {
            throw new ApiException('invalid_input', 'Every fileId must be a direct child of this folder.');
        }

        $ordered = [...$ids, ...array_values(array_diff($existing, $ids))];
        $Items = [];

        foreach ($ordered as $id) {
            $Items[] = self::item(['project' => $Folder->getProject()->getName()], 'edit', $id);
        }

        foreach ($Items as $index => $Item) {
            $Item->setAttribute('priority', $index + 1);
            $Item->save($User);
        }

        $Folder->setAttribute('order', 'priority');
        $Folder->save($User);
        return $ordered;
    }
}
