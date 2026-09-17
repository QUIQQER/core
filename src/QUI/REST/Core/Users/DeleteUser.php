<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class DeleteUser extends UserEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/users/{userId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'delete');

        self::user($arguments['userId'])->delete($User);

        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
