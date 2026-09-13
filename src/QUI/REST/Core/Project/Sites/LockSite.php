<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class LockSite extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/lock';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $body = (string)$Request->getBody() === '' ? [] : Input::body($Request, ['token' => 'string']);
        $token = $body['token'] ?? bin2hex(random_bytes(32));

        if (!is_string($token) || preg_match('/^[a-f0-9]{32,128}$/D', $token) !== 1) {
            throw new ApiException('invalid_input', 'token must be a random hexadecimal editing token.');
        }

        $acquired = isset($body['token']) ? $Site->refreshLock($token) : $Site->acquireEditingLock($token);

        if (!$acquired) {
            throw new ApiException('conflict', 'The site lock could not be acquired or refreshed.', 409);
        }

        return JsonResponse::write($Response, ['data' => [
            'token' => $token,
            'expiresIn' => QUI\Lock\EditingLocks::LIFETIME
        ]]);
    }
}
