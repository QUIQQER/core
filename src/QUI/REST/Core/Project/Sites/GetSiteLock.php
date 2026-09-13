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

final class GetSiteLock extends SiteEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/lock';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments);
        $key = 'site:' . $arguments['project'] . '_' . $arguments['lang'] . '_' . $Site->getId();
        $status = QUI\Lock\Locker::editing()->status($key);
        return JsonResponse::write($Response, ['data' => [
            'locked' => $status !== null,
            'owner' => $status['owner'] ?? null,
            'expires' => $status['expires'] ?? null
        ]]);
    }
}
