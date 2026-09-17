<?php

namespace QUI\REST\Core\Project;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class GetSettings extends ProjectEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/settings';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Project = self::project($arguments['project']);
        Permission::checkProjectPermission('quiqqer.projects.setconfig', $Project, $User);
        return JsonResponse::write($Response, ['data' => self::settings($Project)]);
    }
}
