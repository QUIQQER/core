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

final class ListProjects extends ProjectEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $data = [];

        foreach (QUI::getProjectManager()->getProjects(true) as $Project) {
            $data[] = self::representation($Project);
        }

        return JsonResponse::write($Response, ['data' => $data, 'meta' => ['total' => count($data)]]);
    }
}
