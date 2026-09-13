<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ListUserAddresses extends AddressEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users/{userId}/addresses';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'view');
        $Target = self::user($arguments['userId']);
        $addresses = [];

        foreach ($Target->getAddressList() as $Address) {
            $addresses[] = self::addressRepresentation($Target, $Address);
        }

        return JsonResponse::write($Response, ['data' => $addresses, 'meta' => ['total' => count($addresses)]]);
    }
}
