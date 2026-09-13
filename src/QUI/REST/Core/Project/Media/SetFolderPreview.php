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

final class SetFolderPreview extends MediaEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/media/{fileId}/preview';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Folder = self::folder($arguments, 'edit');
        $body = Input::body($Request, ['fileId' => 'integer'], ['fileId']);
        $Image = self::item($arguments, 'edit', self::siteId($body['fileId']));

        if (!$Image instanceof QUI\Projects\Media\Image) {
            throw new ApiException('invalid_input', 'The selected file must be an image.');
        }

        self::order($Folder, [$Image->getId()], $User);
        return JsonResponse::write($Response, ['data' => self::mediaData($Image)]);
    }
}
