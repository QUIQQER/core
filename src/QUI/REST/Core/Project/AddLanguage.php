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

final class AddLanguage extends ProjectEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/languages';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Project = self::project($arguments['project']);
        Permission::checkProjectPermission('quiqqer.projects.setconfig', $Project, $User);
        $body = Input::body($Request, ['lang' => 'string'], ['lang']);
        $languages = self::languages($body['lang'], []);
        $Project = QUI\Projects\Manager::addLanguage($Project->getName(), $languages[0]);
        return JsonResponse::write($Response, ['data' => $Project->getLanguages()]);
    }
}
