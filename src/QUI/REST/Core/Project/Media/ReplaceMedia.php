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

final class ReplaceMedia extends MediaEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/media/{fileId}/content';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'edit');

        if ($Item instanceof QUI\Projects\Media\Folder) {
            throw new ApiException('invalid_input', 'Only file content can be replaced.');
        }

        $type = strtolower(trim(explode(';', $Request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'application/octet-stream') {
            throw new ApiException('unsupported_media_type', 'Use application/octet-stream for file content.', 415);
        }

        $name = self::filename(basename($Item->getFullPath()));
        $Replaced = IncomingFile::consume($Request->getBody(), $name, fn(string $path) =>
            $Item->getMedia()->replace($Item->getId(), $path, $User));
        return JsonResponse::write($Response, ['data' => self::mediaData($Replaced)]);
    }
}
