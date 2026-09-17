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

final class SendUserPasswordReset extends UserEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/users/{userId}/password/reset';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'send_mail');
        $Target = self::user($arguments['userId']);
        $email = $Target->getAttribute('email');

        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('invalid_input', 'The user has no valid email address.');
        }

        QUI\Users\Auth\Handler::getInstance()->sendPasswordResetVerificationMail($Target);
        return JsonResponse::write($Response, ['data' => ['sent' => true]]);
    }
}
