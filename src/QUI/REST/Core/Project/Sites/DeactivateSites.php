<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class DeactivateSites extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/deactivate';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $body = Input::body($Request, ['siteIds' => 'array'], ['siteIds']);
        $ids = array_map(self::siteId(...), Input::ids($body['siteIds'], 'siteIds'));
        $data = [];

        foreach ($ids as $id) {
            try {
                $Site = self::site($arguments, 'edit', $id);
                $Site->deactivate($User);
                $data[] = ['id' => $id, 'status' => 200, 'data' => self::siteData($Site)];
            } catch (\Throwable $Error) {
                $ErrorResponse = JsonResponse::error(new QUI\REST\Response(), $Error);
                $error = json_decode((string)$ErrorResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $data[] = ['id' => $id, 'status' => $ErrorResponse->getStatusCode(), 'error' => $error['error']];
            }
        }

        return JsonResponse::write($Response, ['data' => $data]);
    }
}
