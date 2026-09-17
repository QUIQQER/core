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

final class DeleteUserAddress extends AddressEndpoint
{
    public const METHOD = 'DELETE';
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
        $Address = self::address($Target, $arguments['addressId']);

        if ($Address->getUUID() === $Target->getStandardAddress()->getUUID()) {
            throw new ApiException('conflict', 'The default address cannot be deleted.', 409);
        }

        $Address->delete();
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
