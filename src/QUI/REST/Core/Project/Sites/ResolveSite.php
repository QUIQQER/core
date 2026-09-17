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

final class ResolveSite extends SiteEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/sites/resolve';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $url = $Request->getQueryParams()['url'] ?? null;

        if (!is_string($url) || $url === '' || strlen($url) > 8192) {
            throw new ApiException('invalid_input', 'Provide a site URL of at most 8192 bytes.');
        }

        $resolved = UrlResolver::resolveUrl($url);
        $Site = self::site([
            'project' => $resolved['project']->getName(),
            'lang' => $resolved['project']->getLang()
        ], 'view', $resolved['site']->getId());
        return JsonResponse::write($Response, ['data' => self::siteData($Site)]);
    }
}
