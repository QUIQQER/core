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

final class UploadMedia extends MediaEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/media';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $type = strtolower(trim(explode(';', $Request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'multipart/form-data') {
            throw new ApiException('unsupported_media_type', 'Use multipart/form-data with parentId and file.', 415);
        }

        $body = $Request->getParsedBody();
        $files = $Request->getUploadedFiles();

        if (!is_array($body) || array_diff(array_keys($body), ['parentId']) !== [] || !isset($body['parentId'])) {
            throw new ApiException('invalid_input', 'Provide parentId as the only form field.');
        }

        if (
            count($files) !== 1 || !isset($files['file'])
            || !$files['file'] instanceof \Psr\Http\Message\UploadedFileInterface
        ) {
            throw new ApiException('invalid_input', 'Provide exactly one uploaded file named file.');
        }

        $File = $files['file'];

        if ($File->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException('invalid_upload', 'The file upload failed.', 400);
        }

        $name = self::filename($File->getClientFilename() ?? '');
        $Parent = self::folder($arguments, 'upload', self::siteId($body['parentId']));
        $Uploaded = IncomingFile::consume($File->getStream(), $name, fn(string $path) =>
            $Parent->uploadFile($path, QUI\Projects\Media\Folder::FILE_OVERWRITE_NONE, $User));
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . $Uploaded->getId();
        return JsonResponse::write($Response->withHeader('Location', $location), [
            'data' => self::mediaData($Uploaded)
        ], 201);
    }
}
