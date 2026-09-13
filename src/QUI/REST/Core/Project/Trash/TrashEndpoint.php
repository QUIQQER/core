<?php

namespace QUI\REST\Core\Project\Trash;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Projects\Media\Item;
use QUI\Projects\Site\Edit;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;
use QUI\REST\Core\Project\Media\MediaEndpoint;

abstract class TrashEndpoint extends MediaEndpoint
{
    protected const MEDIA = false;
    protected const ACTION = 'list';

    /** @param array<string, string> $arguments
     * @return list<int>
     */
    protected static function deletedIds(array $arguments): array
    {
        $Project = self::project($arguments['project'], $arguments['lang'] ?? null);
        $table = static::MEDIA ? $Project->getMedia()->getTable() : $Project->table();
        $Connection = QUI::getDataBaseConnection();
        $ids = $Connection->createQueryBuilder()->select('id')
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
            ->where('deleted = 1')->orderBy('id', 'ASC')->executeQuery()->fetchFirstColumn();
        return array_map('intval', $ids);
    }

    /** @param array<string, string> $arguments */
    protected static function deleted(array $arguments, int $id): Edit|Item
    {
        $Project = self::project($arguments['project'], $arguments['lang'] ?? null);

        if (static::MEDIA) {
            $Media = $Project->getMedia();
            $Connection = QUI::getDataBaseConnection();
            $row = $Connection->createQueryBuilder()->select('*')
                ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($Media->getTable()))
                ->where('id = :id')->andWhere('deleted = 1')->setParameter('id', $id)
                ->executeQuery()->fetchAssociative();

            if ($row === false) {
                throw new ApiException('not_found', 'The file does not exist in the trash.', 404);
            }

            $Target = $Media->parseResultToItem($row);

            if (!$Target instanceof Item) {
                throw new ApiException('invalid_input', 'The media item cannot be managed.');
            }

            $Target->checkPermission('quiqqer.projects.media.view', QUI::getUserBySession());
        } else {
            $Target = new Edit($Project, $id);
            $Target->checkPermission('quiqqer.projects.site.view', QUI::getUserBySession());
        }

        if (!$Target->getAttribute('deleted')) {
            throw new ApiException('not_found', 'The resource does not exist in the trash.', 404);
        }

        return $Target;
    }

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface {
        Permission::checkAdminUser($User);

        if (static::ACTION === 'list') {
            return $this->listing($Request, $Response, $arguments);
        }

        $field = static::MEDIA ? 'fileIds' : 'siteIds';
        $fields = static::ACTION === 'clear' ? [] : [$field => 'array'];
        $required = array_keys($fields);

        if (static::ACTION === 'restore') {
            $fields['parentId'] = 'integer';
            $required[] = 'parentId';
        }

        $body = static::ACTION === 'clear' && (string)$Request->getBody() === ''
            ? [] : Input::body($Request, $fields, $required);
        $ids = static::ACTION === 'clear' ? self::deletedIds($arguments)
            : array_map(self::siteId(...), Input::ids($body[$field], $field));
        $Project = self::project($arguments['project'], $arguments['lang'] ?? null);
        $Parent = null;

        if (static::ACTION === 'restore') {
            $Parent = static::MEDIA
                ? self::folder($arguments, 'upload', self::siteId($body['parentId']))
                : self::site($arguments, 'new', self::siteId($body['parentId']));
        }

        $results = [];

        foreach ($ids as $id) {
            try {
                $Target = self::deleted($arguments, $id);

                if (static::ACTION === 'restore' && $Parent instanceof QUI\Projects\Media\Folder) {
                    $Restored = $Project->getMedia()->getTrash()->restore($id, $Parent, $User);
                    $data = ['previousId' => $id, 'id' => $Restored->getId()];
                } elseif (static::ACTION === 'restore') {
                    $Target->checkPermission('quiqqer.projects.site.edit', $User);
                    $Project->getTrash()->restore($Project, [$id], $Parent->getId());
                    $data = ['id' => $id];
                } else {
                    if ($Target instanceof Edit) {
                        $Target->destroy();
                    } else {
                        $Target->destroy($User);
                    }
                    $data = ['id' => $id];
                }

                $results[] = ['id' => $id, 'status' => 200, 'data' => $data];
            } catch (\Throwable $Error) {
                $Failed = JsonResponse::error(new QUI\REST\Response(), $Error);
                $error = json_decode((string)$Failed->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $results[] = ['id' => $id, 'status' => $Failed->getStatusCode(), 'error' => $error['error']];
            }
        }

        return JsonResponse::write($Response, ['data' => $results]);
    }

    /** @param array<string, string> $arguments */
    private function listing(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments
    ): ResponseInterface {
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $total = 0;
        $items = [];

        foreach (self::deletedIds($arguments) as $id) {
            try {
                $Target = self::deleted($arguments, $id);
            } catch (QUI\Permissions\Exception) {
                continue;
            }

            if ($total >= $offset && count($items) < $limit) {
                $items[] = [
                    'id' => $id, 'name' => $Target->getAttribute('name'),
                    'type' => $Target->getAttribute('type'), 'deletedAt' => $Target->getAttribute('deleted_at')
                ];
            }

            $total++;
        }

        return JsonResponse::write($Response, ['data' => $items, 'meta' => [
            'total' => $total, 'limit' => $limit, 'offset' => $offset
        ]]);
    }
}
