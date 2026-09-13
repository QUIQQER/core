<?php

namespace QUI\REST\Core\Groups;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class CreateGroup extends GroupEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/groups';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'create');
        $body = Input::body($Request, ['name' => 'string', 'parentId' => 'string'], ['name', 'parentId']);

        if (!is_string($body['name']) || trim($body['name']) === '' || mb_strlen($body['name']) > 50) {
            throw new ApiException('invalid_input', 'name must contain between 1 and 50 characters.');
        }

        $Created = self::group($body['parentId'])->createChild(trim($body['name']), $User);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . rawurlencode((string)$Created->getUUID());
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::groupRepresentation($Created)],
            201
        );
    }
}
