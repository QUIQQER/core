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

final class DeleteVHost extends VHostEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/vhosts/{host}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        self::vhost($arguments['host']);
        (new QUI\System\VhostManager())->removeVhost($arguments['host']);
        return $Response->withStatus(204);
    }
}
