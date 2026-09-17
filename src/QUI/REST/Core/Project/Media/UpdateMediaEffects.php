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

final class UpdateMediaEffects extends MediaEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}/media/{fileId}/effects';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'edit');

        if (!$Item instanceof QUI\Projects\Media\Folder && !$Item instanceof QUI\Projects\Media\Image) {
            throw new ApiException('invalid_input', 'Effects require an image or a folder.');
        }
        $body = Input::body($Request, ['effects' => 'object', 'recursive' => 'boolean'], ['effects']);
        $effects = Effects::merge($arguments['project'], $Item->getEffects(), (array)$body['effects']);
        $recursive = $body['recursive'] ?? false;

        if ($recursive && !$Item instanceof QUI\Projects\Media\Folder) {
            throw new ApiException('invalid_input', 'Recursive effects require a folder.');
        }

        $Item->setEffects($effects);
        $Item->save($User);
        $children = [];

        if ($recursive) {
            $queue = [$Item];

            while ($Folder = array_shift($queue)) {
                foreach ($Folder->getChildren() as $Child) {
                    if (!$Child instanceof QUI\Projects\Media\Folder && !$Child instanceof QUI\Projects\Media\Image) {
                        continue;
                    }

                    try {
                        $Child->checkPermission('quiqqer.projects.media.edit', $User);
                        $Child->setEffects($effects);
                        $Child->save($User);
                        $children[] = ['id' => $Child->getId(), 'status' => 200];

                        if ($Child instanceof QUI\Projects\Media\Folder) {
                            $queue[] = $Child;
                        }
                    } catch (\Throwable $Error) {
                        $Failed = JsonResponse::error(new QUI\REST\Response(), $Error);
                        $error = json_decode((string)$Failed->getBody(), true, 512, JSON_THROW_ON_ERROR);
                        $children[] = [
                            'id' => $Child->getId(), 'status' => $Failed->getStatusCode(), 'error' => $error['error']
                        ];
                    }
                }
            }
        }

        return JsonResponse::write($Response, ['data' => [
            'effects' => (object)$Item->getEffects(), 'children' => $children
        ]]);
    }
}
