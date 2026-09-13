<?php

namespace QUI\REST\Core\Project\Media;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListMedia extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface {
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $parentId = Input::integerQuery($Request, 'parentId', 1, 1, PHP_INT_MAX);
        $search = $Request->getQueryParams()['search'] ?? '';

        if (!is_string($search) || strlen($search) > 200) {
            throw new ApiException('invalid_input', 'search must be a string of at most 200 bytes.');
        }

        $Folder = self::folder($arguments, 'view', $parentId);
        $Connection = QUI::getDataBaseConnection();
        $Query = $Connection->createQueryBuilder()->select('id')
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($Folder->getMedia()->getTable()))
            ->where('deleted = 0')->orderBy('id', 'ASC');

        if ($search !== '') {
            $Query->andWhere('(name LIKE :search OR title LIKE :search OR short LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        } else {
            $ids = $Folder->getChildrenIds();

            if (!is_array($ids) || $ids === []) {
                return JsonResponse::write($Response, [
                    'data' => [], 'meta' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]
                ]);
            }

            $Query->andWhere('id IN (:ids)')->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }

        // Count and page only readable items, so inaccessible files do not leak through metadata.
        $total = 0;
        $data = [];
        $Result = $Query->executeQuery();

        while (($id = $Result->fetchOne()) !== false) {
            try {
                $Item = self::item($arguments, 'view', (int)$id);
            } catch (QUI\Permissions\Exception) {
                continue;
            }

            if ($total >= $offset && count($data) < $limit) {
                $data[] = self::mediaData($Item);
            }

            $total++;
        }

        return JsonResponse::write($Response, ['data' => $data, 'meta' => [
            'total' => $total, 'limit' => $limit, 'offset' => $offset
        ]]);
    }
}
