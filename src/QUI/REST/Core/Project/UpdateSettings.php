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

final class UpdateSettings extends ProjectEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}/settings';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Project = self::project($arguments['project']);
        Permission::checkProjectPermission('quiqqer.projects.setconfig', $Project, $User);
        $fields = [];

        foreach (QUI\Projects\Manager::getProjectConfigDefinitions($Project) as $name => $definition) {
            $fields[$name] = self::settingType($definition['type']);
        }

        $body = Input::body($Request, $fields);

        if ($body === []) {
            throw new ApiException('invalid_input', 'At least one setting is required.');
        }

        QUI\Projects\Manager::setConfigForProject($Project->getName(), $body);
        return JsonResponse::write($Response, ['data' => self::settings(self::project($Project->getName()))]);
    }
}
