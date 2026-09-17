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

final class UpdateSite extends SiteEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $body = self::siteInput($Request);
        $Site->setAttributes($body);
        $Site->save($User);
        return JsonResponse::write($Response, ['data' => self::siteData($Site)]);
    }
}
