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

final class SetUserPassword extends UserEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/users/{userId}/password';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $body = Input::body($Request, ['password' => 'string', 'forceChange' => 'boolean'], ['password']);
        $password = $body['password'];

        if (!is_string($password) || $password === '' || strlen($password) > 4096) {
            throw new ApiException('invalid_input', 'password must contain between 1 and 4096 bytes.');
        }

        $Target = self::user($arguments['userId']);
        $Target->setPassword($password, $User);
        $Target->setAttribute('quiqqer.set.new.password', $body['forceChange'] ?? false);
        $Target->save($User);
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
