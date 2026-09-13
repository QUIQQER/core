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

final class DeleteUserWebAuthnCredential extends UserEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/users/{userId}/webauthn-credentials/{credentialId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $Target = self::user($arguments['userId']);
        $Target->checkEditPermission($User);
        $id = filter_var($arguments['credentialId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ApiException('invalid_input', 'credentialId must be a positive integer.');
        }

        $Repository = new QUI\Users\Auth\WebAuthn\CredentialRepository();
        $credential = $Repository->findById($id);
        $uuid = (string)$Target->getUUID();

        if ($credential === null || ($credential['userUuid'] ?? null) !== $uuid) {
            throw new ApiException('not_found', 'The credential does not belong to this user.', 404);
        }

        $Repository->deleteForUser($id, $uuid);

        if ($Repository->findByUserUuid($uuid) === [] && $Target->hasAuthenticator(QUI\Users\Auth\WebAuthn::class)) {
            $Target->disableAuthenticator(QUI\Users\Auth\WebAuthn::class, $User);
        }

        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
