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

final class GetUploadSession extends MediaEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/projects/{project}/media/uploads/{uploadId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $data = UploadSessions::withSession(
            $User,
            $arguments['project'],
            $arguments['uploadId'],
            static fn(array $state): array => $state
        );
        return JsonResponse::write($Response, ['data' => $data]);
    }
}
