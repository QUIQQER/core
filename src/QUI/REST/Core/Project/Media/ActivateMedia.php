<?php

namespace QUI\REST\Core\Project\Media;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ActivateMedia extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/activate';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $body = Input::body($Request, ['fileIds' => 'array'], ['fileIds']);
        $ids = array_map(self::siteId(...), Input::ids($body['fileIds'], 'fileIds'));
        $results = [];

        foreach ($ids as $id) {
            try {
                $Item = self::item($arguments, 'edit', $id);
                $Item->activate($User);
                $results[] = ['id' => $id, 'status' => 200, 'data' => self::mediaData($Item)];
            } catch (\Throwable $Error) {
                $Failed = JsonResponse::error(new QUI\REST\Response(), $Error);
                $error = json_decode((string)$Failed->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $results[] = ['id' => $id, 'status' => $Failed->getStatusCode(), 'error' => $error['error']];
            }
        }

        return JsonResponse::write($Response, ['data' => $results]);
    }
}
