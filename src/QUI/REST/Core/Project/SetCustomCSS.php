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

final class SetCustomCSS extends ProjectEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/custom-css';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Project = self::project($arguments['project']);
        Permission::checkProjectPermission('quiqqer.projects.editCustomCSS', $Project, $User);
        $body = Input::body($Request, ['css' => 'string'], ['css']);
        $Project->setCustomCSS($body['css']);
        return JsonResponse::write($Response, ['data' => ['css' => $Project->getCustomCSS()]]);
    }
}
