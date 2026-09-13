<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListUsers extends UserEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'view');
        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        $search = $Request->getQueryParams()['search'] ?? '';

        if (!is_string($search) || mb_strlen($search) > 200) {
            throw new ApiException('invalid_input', 'search must be a string of at most 200 characters.');
        }

        $Manager = QUI::getUsers();
        $Connection = QUI::getDataBaseConnection();
        $Query = $Connection->createQueryBuilder()->select('uuid')
            ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier(QUI\Users\Manager::table()))
            ->where('id NOT IN (:internalIds)')
            ->setParameter('internalIds', [
                $Manager->getNobody()->getId(), $Manager->getSystemUser()->getId()
            ], \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ->orderBy('username', 'ASC')->addOrderBy('id', 'ASC');

        if (trim($search) !== '') {
            $Query->andWhere('(uuid LIKE :search OR email LIKE :search OR username LIKE :search'
                . ' OR firstname LIKE :search OR lastname LIKE :search)')
                ->setParameter('search', '%' . trim($search) . '%');
        }

        $Count = clone $Query;
        $total = (int)$Count->select('COUNT(*)')->resetOrderBy()->executeQuery()->fetchOne();
        $ids = $Query->setFirstResult($offset)->setMaxResults($limit)->executeQuery()->fetchFirstColumn();
        $data = [];

        foreach ($ids as $id) {
            $data[] = self::representation(self::user((string)$id));
        }

        return JsonResponse::write($Response, [
            'data' => $data,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
