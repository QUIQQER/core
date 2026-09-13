<?php

namespace QUI\REST\Core\VHost;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListVHosts extends VHostEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/vhosts';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        $items = [];

        foreach ((new QUI\System\VhostManager())->getList() as $host => $data) {
            $items[] = self::data($host, $data);
        }

        $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
        $offset = Input::integerQuery($Request, 'offset', 0, 0, PHP_INT_MAX);
        return JsonResponse::write($Response, [
            'data' => array_slice($items, $offset, $limit),
            'meta' => ['total' => count($items), 'limit' => $limit, 'offset' => $offset]
        ]);
    }
}
