<?php

namespace QUI\REST\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use Throwable;

abstract class Endpoint
{
    public const METHOD = 'GET';
    public const PATH = '';

    public function __construct(private readonly AuthenticationInterface $Authentication)
    {
    }

    /** @param array<string, string> $arguments */
    final public function __invoke(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments
    ): ResponseInterface {
        try {
            $User = $this->Authentication->authenticate($Request, Provider::PREFIX . static::PATH);

            if (
                QUI::getUsers()->isNobodyUser($User)
                || QUI::getUsers()->isSystemUser($User)
                || !$User->isActive()
            ) {
                throw new ApiException('unauthenticated', 'An active user account is required.', 401);
            }

            return Permission::withUser($User, function () use ($Request, $Response, $arguments, $User) {
                Permission::checkPermission('quiqqer.core.rest.canUse', $User);

                return QUI::getUsers()->withSessionUser(
                    $User,
                    fn() => $this->handle($Request, $Response, $arguments, $User)
                );
            });
        } catch (Throwable $Error) {
            return JsonResponse::error($Response, $Error);
        }
    }

    /** @param array<string, string> $arguments */
    abstract protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface;
}
