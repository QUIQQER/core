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

final class SetDefaultUserAddress extends AddressEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/users/{userId}/default-address';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $Target = self::user($arguments['userId']);
        $Target->checkEditPermission($User);
        $body = Input::body($Request, ['addressId' => 'string'], ['addressId']);
        $id = $body['addressId'];

        if (!is_string($id) || $id === '') {
            throw new ApiException('invalid_input', 'addressId must be a non-empty string.');
        }

        $Address = self::address($Target, $id);
        $Target->setAttribute('address', $Address->getUUID());
        $Target->save($User);
        return JsonResponse::write($Response, ['data' => self::addressRepresentation($Target, $Address)]);
    }
}
