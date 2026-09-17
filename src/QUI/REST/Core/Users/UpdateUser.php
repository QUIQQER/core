<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class UpdateUser extends UserEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/users/{userId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $Target = self::user($arguments['userId']);
        $attributes = self::profile($Request);

        if (
            isset($attributes['username']) && $attributes['username'] !== $Target->getUsername()
            && QUI::getUsers()->usernameExists($attributes['username'])
        ) {
            throw new ApiException('conflict', 'The username is already in use.', 409);
        }

        $Target->setAttributes($attributes);
        $Target->save($User);
        return JsonResponse::write($Response, ['data' => self::representation($Target)]);
    }
}
