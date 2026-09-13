<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class CreateUser extends UserEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/users';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'create');
        $attributes = self::profile($Request, true);

        if (QUI::getUsers()->usernameExists($attributes['username'])) {
            throw new ApiException('conflict', 'The username is already in use.', 409);
        }

        $Created = QUI::getUsers()->createChildWithAttributes($attributes, $User);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . rawurlencode((string)$Created->getUUID());
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::representation($Created)],
            201
        );
    }
}
