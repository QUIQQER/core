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

final class RenameProject extends ProjectEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkSU($User);
        $Project = self::project($arguments['project']);
        $body = Input::body($Request, ['name' => 'string'], ['name']);
        Lifecycle::availableName($body['name']);
        QUI\Projects\Manager::rename($Project->getName(), $body['name']);
        Lifecycle::permissions($Project->getName(), $body['name']);
        return JsonResponse::write($Response, ['data' => self::representation(self::project($body['name']))]);
    }
}
