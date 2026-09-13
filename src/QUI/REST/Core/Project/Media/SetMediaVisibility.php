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

final class SetMediaVisibility extends MediaEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/media/{fileId}/visibility';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'edit');
        $body = Input::body($Request, ['visible' => 'boolean'], ['visible']);

        if ($body['visible']) {
            $Item->setVisible();
        } else {
            $Item->setHidden();
        }

        $Item->save($User);
        return JsonResponse::write($Response, ['data' => self::mediaData($Item)]);
    }
}
