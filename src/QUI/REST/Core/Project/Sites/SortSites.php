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

final class SortSites extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/sort';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Parent = self::site($arguments, 'edit');
        $body = Input::body($Request, [
            'siteIds' => 'array', 'offset' => 'integer', 'sortType' => 'string'
        ], ['siteIds']);
        $ids = array_map(self::siteId(...), Input::ids($body['siteIds'], 'siteIds'));
        $children = $Parent->getChildrenIds(['active' => '0&1']);

        if (!is_array($children) || array_diff($ids, array_map('intval', $children)) !== []) {
            throw new ApiException('invalid_input', 'Every siteId must be a direct child of this site.');
        }

        $offset = $body['offset'] ?? 0;

        if (!is_int($offset) || $offset < 0 || $offset > 2147483547) {
            throw new ApiException('invalid_input', 'offset is outside the supported range.');
        }

        // Resolve every child before changing the order, including its edit permission.
        $Children = array_map(static fn(int $id) => self::site($arguments, 'edit', $id), $ids);

        if (isset($body['sortType'])) {
            $Parent->setAttribute('order_type', $body['sortType']);
        }

        foreach ($Children as $Child) {
            $Child->setAttribute('order_field', ++$offset);
            $Child->save($User);
        }

        $Parent->save($User);
        return JsonResponse::write($Response, ['data' => ['siteIds' => $ids]]);
    }
}
