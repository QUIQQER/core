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

final class ListUserAuthenticators extends UserEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/users/{userId}/authenticators';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'view');
        $Target = self::user($arguments['userId']);
        $Handler = QUI\Users\Auth\Handler::getInstance();
        $authenticators = [];

        foreach ($Handler->getAvailableAuthenticators() as $name) {
            $Authenticator = $Handler->getAuthenticator($name, $Target);
            $authenticators[] = [
                'id' => $name,
                'title' => $Authenticator->getTitle($Target->getLocale()),
                'description' => $Authenticator->getDescription($Target->getLocale()),
                'enabled' => $Target->hasAuthenticator($name),
                'primary' => $Authenticator->isPrimaryAuthentication(),
                'secondary' => $Authenticator->isSecondaryAuthentication(),
                'satisfiesSecondary' => $Authenticator->satisfiesSecondaryAuthentication()
            ];
        }

        $credentials = [];
        $Repository = new QUI\Users\Auth\WebAuthn\CredentialRepository();

        foreach ($Repository->findByUserUuid((string)$Target->getUUID()) as $credential) {
            $credentials[] = array_intersect_key($credential, array_flip([
                'id', 'name', 'aaguid', 'transports', 'backupEligible', 'backedUp', 'created', 'lastUsed'
            ]));
        }

        return JsonResponse::write($Response, ['data' => [
            'authenticators' => $authenticators,
            'webauthnCredentials' => $credentials
        ]]);
    }
}
