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

final class CopySite extends SiteEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/copy';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments);
        $body = Input::body($Request, [
            'parentId' => 'integer', 'targetProject' => 'string',
            'targetLang' => 'string', 'createLanguageLink' => 'boolean'
        ], ['parentId']);
        $targetProject = $body['targetProject'] ?? $arguments['project'];
        $targetLang = $body['targetLang'] ?? $arguments['lang'];
        $Target = self::project($targetProject, $targetLang);
        $Parent = self::site(
            ['project' => $targetProject, 'lang' => $targetLang],
            'new',
            self::siteId($body['parentId'])
        );
        $link = $body['createLanguageLink'] ?? false;

        if ($link) {
            $Site->checkPermission('quiqqer.projects.site.edit', $User);

            if ($targetProject !== $arguments['project'] || $targetLang === $arguments['lang']) {
                throw new ApiException('invalid_input', 'Language links require another language of the same project.');
            }
        }

        $Copied = $Site->copy($Parent->getId(), $Target);

        if ($link) {
            $Site->addLanguageLink($targetLang, $Copied->getId());
        }

        $prefix = strstr($Request->getUri()->getPath(), '/quiqqer/core/', true);
        $location = $prefix . '/quiqqer/core/projects/' . rawurlencode($targetProject) . '/';
        $location .= rawurlencode($targetLang) . '/sites/' . $Copied->getId();
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::siteData($Copied)],
            201
        );
    }
}
