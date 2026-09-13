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

final class RemoveLanguageLink extends SiteEndpoint
{
    public const METHOD = 'DELETE';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/language-links/{targetLang}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $lang = $arguments['targetLang'];

        if ($lang === $arguments['lang'] || !in_array($lang, $Site->getProject()->getLanguages(), true)) {
            throw new ApiException('invalid_input', 'Select a different project language.');
        }

        $Site->removeLanguageLink($lang);
        return $Response->withStatus(204)->withHeader('Cache-Control', 'no-store');
    }
}
