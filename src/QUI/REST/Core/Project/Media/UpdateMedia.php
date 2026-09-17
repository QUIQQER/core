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

final class UpdateMedia extends MediaEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/projects/{project}/media/{fileId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Item = self::item($arguments, 'edit');
        $body = Input::body($Request, [
            'name' => 'string', 'title' => 'object', 'description' => 'object', 'alt' => 'object'
        ]);

        if ($body === []) {
            throw new ApiException('invalid_input', 'Provide at least one media field.');
        }

        if (isset($body['name'])) {
            self::filename($body['name']);
        }

        foreach (['title', 'description', 'alt'] as $field) {
            if (!isset($body[$field])) {
                continue;
            }

            foreach ((array)$body[$field] as $lang => $value) {
                if (!in_array($lang, $Item->getProject()->getLanguages(), true) || !is_string($value)) {
                    throw new ApiException('invalid_input', 'Media texts require project language codes and strings.');
                }
            }
        }

        if (isset($body['name']) && $body['name'] !== $Item->getAttribute('name')) {
            $Item->rename($body['name'], $User);
        }

        unset($body['name']);

        foreach ($body as $field => $value) {
            $Item->setAttribute($field, (array)$value);
        }

        $Item->save($User);
        return JsonResponse::write($Response, ['data' => self::mediaData($Item)]);
    }
}
