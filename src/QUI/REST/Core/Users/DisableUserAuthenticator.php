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

final class DisableUserAuthenticator extends UserEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/users/{userId}/authenticators/{authenticator}/disable';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $Target = self::user($arguments['userId']);
        $Target->checkEditPermission($User);
        $name = $arguments['authenticator'];
        $Handler = QUI\Users\Auth\Handler::getInstance();

        if (!in_array($name, $Handler->getAvailableAuthenticators(), true)) {
            throw new ApiException('not_found', 'Unknown authenticator.', 404);
        }

        $Authenticator = $Handler->getAuthenticator($name, $Target);

        if (!$Authenticator->isSecondaryAuthentication()) {
            throw new ApiException('invalid_input', 'Only secondary authenticators can be disabled.');
        }

        $Target->disableAuthenticator($name, $User);
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
