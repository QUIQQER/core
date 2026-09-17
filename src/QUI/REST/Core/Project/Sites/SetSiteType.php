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

final class SetSiteType extends SiteEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/type';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $body = Input::body($Request, ['type' => 'string'], ['type']);
        $Site->setAttribute('type', $body['type']);
        $Site->save($User);
        return JsonResponse::write($Response, ['data' => self::siteData($Site)]);
    }
}
