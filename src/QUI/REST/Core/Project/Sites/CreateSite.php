<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class CreateSite extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $body = self::siteInput($Request, true);
        $Parent = self::site($arguments, 'new', self::siteId($body['parentId']));
        unset($body['parentId']);
        $attributes = array_intersect_key($body, array_flip(['name', 'title', 'short', 'content']));
        $id = $Parent->createChild($attributes, [], $User);
        $Child = self::site($arguments, 'edit', $id);
        $Child->setAttributes($body);
        $Child->save($User);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . $id;
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::siteData($Child)],
            201
        );
    }
}
