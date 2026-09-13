<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListSites extends SiteEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/{lang}/sites';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Project = self::project($arguments['project'], $arguments['lang']);
        $parentId = Input::integerQuery($Request, 'parentId', 1, 1, PHP_INT_MAX);
        $Parent = self::site($arguments, 'view', $parentId);
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $search = $Request->getQueryParams()['search'] ?? '';

        if (!is_string($search) || mb_strlen($search) > 200) {
            throw new ApiException('invalid_input', 'search must contain at most 200 characters.');
        }

        $Query = QUI::getDataBaseConnection()->createQueryBuilder();
        $Query->select('id')->from(QUI\Utils\Doctrine::quoteIdentifier($Project->table()))
            ->where('deleted = :deleted')->setParameter('deleted', 0)->orderBy('id', 'ASC');

        if ($search !== '') {
            $Query->andWhere($Query->expr()->or(
                'name LIKE :search',
                'title LIKE :search',
                'short LIKE :search',
                'content LIKE :search'
            ))->setParameter('search', '%' . $search . '%');
        } else {
            $ids = $Parent->getChildrenIds(['active' => '0&1']);

            if (!is_array($ids) || $ids === []) {
                return JsonResponse::write($Response, [
                    'data' => [], 'meta' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]
                ]);
            }

            $Query->andWhere('id IN (:ids)')->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        }

        $Count = clone $Query;
        $total = (int)$Count->select('COUNT(*)')->resetOrderBy()->executeQuery()->fetchOne();
        $rows = $Query->setFirstResult($offset)->setMaxResults($limit)->executeQuery()->fetchFirstColumn();
        $data = [];

        foreach ($rows as $id) {
            try {
                $data[] = self::siteData(self::site($arguments, 'view', (int)$id));
            } catch (QUI\Permissions\Exception) {
                // Keep inaccessible site content out of collection responses.
            }
        }

        return JsonResponse::write($Response, [
            'data' => $data,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
