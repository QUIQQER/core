<?php

namespace QUI\REST\Core\Project\Sites;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Projects\Site\Edit;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Endpoint;
use QUI\REST\Core\JsonResponse;

final class GetSite extends Endpoint
{
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface {
        $siteId = filter_var($arguments['siteId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($siteId === false) {
            throw new ApiException('invalid_site_id', 'siteId must be a positive integer.');
        }

        $Project = QUI::getProject($arguments['project']);

        if (!in_array($arguments['lang'], $Project->getLanguages(), true)) {
            throw new ApiException('not_found', 'The requested project language does not exist.', 404);
        }

        $Project = QUI::getProject($arguments['project'], $arguments['lang']);
        $Site = new Edit($Project, $siteId);
        $Site->checkPermission('quiqqer.projects.site.view', $User);

        if ($Site->getAttribute('deleted')) {
            throw new ApiException('not_found', 'The requested site does not exist.', 404);
        }

        return JsonResponse::write($Response, ['data' => [
            'id' => $Site->getId(),
            'project' => $Project->getName(),
            'lang' => $Project->getLang(),
            'parentId' => $Site->getParentId(),
            'name' => $Site->getAttribute('name'),
            'title' => $Site->getAttribute('title'),
            'short' => $Site->getAttribute('short'),
            'content' => $Site->getAttribute('content'),
            'type' => $Site->getAttribute('type'),
            'active' => (bool)$Site->getAttribute('active'),
            'url' => $Site->getUrlRewritten(),
            'languageLinks' => $Site->getLangIds()
        ]]);
    }
}
