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

final class UpdateUserAddress extends AddressEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/users/{userId}/addresses/{addressId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $Target = self::user($arguments['userId']);
        $Target->checkEditPermission($User);
        $body = self::addressInput($Request);
        $Address = self::address($Target, $arguments['addressId']);
        self::updateAddress($Address, $body, $User);
        return JsonResponse::write($Response, ['data' => self::addressRepresentation($Target, $Address)]);
    }
}
