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

final class ListDemoDataSets extends ProjectEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/project-templates/demo-data';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkAdminUser($User);
        $template = $Request->getQueryParams()['template'] ?? null;

        if (!is_string($template) || $template === '') {
            throw new ApiException('invalid_input', 'template is required.');
        }

        return JsonResponse::write($Response, ['data' => QUI\Utils\Project::getDemoDataSetsForTemplate($template)]);
    }
}
