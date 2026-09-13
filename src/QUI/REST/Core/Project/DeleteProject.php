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

final class DeleteProject extends ProjectEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/projects/{project}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkSU($User);
        $Project = self::project($arguments['project']);
        QUI\Projects\Manager::deleteProject($Project);
        Lifecycle::permissions($Project->getName(), null);
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
