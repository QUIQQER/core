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

final class ListProjectTemplates extends ProjectEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/project-templates';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $templates = [];

        foreach (QUI::getPackageManager()->searchInstalledPackages(['type' => 'quiqqer-template']) as $package) {
            $templates[] = array_intersect_key($package, array_flip(['name', 'title', 'description', 'version']));
        }

        return JsonResponse::write($Response, ['data' => $templates]);
    }
}
