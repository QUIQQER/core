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

final class CreateUserAddress extends AddressEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/users/{userId}/addresses';

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
        $Address = $Target->addAddress([], $User);
        self::updateAddress($Address, $body, $User);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . rawurlencode((string)$Address->getUUID());
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::addressRepresentation($Target, $Address)],
            201
        );
    }
}
