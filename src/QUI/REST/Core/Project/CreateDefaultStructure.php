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

final class CreateDefaultStructure extends ProjectEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/default-structure';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkAdminUser($User);
        $Project = self::project($arguments['project']);
        QUI\Utils\Project::createDefaultStructure($Project);
        return JsonResponse::write($Response, ['data' => self::representation($Project)]);
    }
}
