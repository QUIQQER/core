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

final class CreateImageVariant extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media/{fileId}/variants';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Image = self::item($arguments);

        if (!$Image instanceof QUI\Projects\Media\Image) {
            throw new ApiException('invalid_input', 'The selected file must be an image.');
        }

        $body = Input::body($Request, ['maxWidth' => 'integer', 'maxHeight' => 'integer']);

        if ($body === []) {
            throw new ApiException('invalid_input', 'Provide at least one target dimension.');
        }

        foreach ($body as $value) {
            if ($value < 1 || $value > 4000) {
                throw new ApiException('invalid_input', 'Image dimensions must be between 1 and 4000.');
            }
        }

        $width = $body['maxWidth'] ?? false;
        $height = $body['maxHeight'] ?? false;
        $path = $Image->createSizeCache($width, $height);

        if ($path === false) {
            throw new ApiException('conflict', 'The image variant could not be created. Check media activation.', 409);
        }

        $dimensions = $Image->getResizeSize($width, $height);
        return JsonResponse::write($Response, ['data' => [
            'url' => $Image->getSizeCacheUrl($width, $height), 'width' => (int)$dimensions['width'],
            'height' => (int)$dimensions['height'], 'sizeBytes' => filesize($path) ?: 0
        ]]);
    }
}
