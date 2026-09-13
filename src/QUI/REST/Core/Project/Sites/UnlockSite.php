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

final class UnlockSite extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/unlock';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $body = Input::body($Request, ['token' => 'string', 'force' => 'boolean']);

        if (!empty($body['force'])) {
            $Site->unlockWithRights();
        } else {
            $token = $body['token'] ?? null;

            if (!is_string($token) || preg_match('/^[a-f0-9]{32,128}$/D', $token) !== 1) {
                throw new ApiException('invalid_input', 'The editing token is required.');
            }

            $Site->releaseEditingLock($token);
        }

        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
