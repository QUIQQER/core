<?php

namespace QUI\REST\Core\Forwarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class GetForwarding extends ForwardingEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/forwardings/{forwardingId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        return JsonResponse::write($Response, ['data' => self::forwarding($arguments['forwardingId'])]);
    }
}
