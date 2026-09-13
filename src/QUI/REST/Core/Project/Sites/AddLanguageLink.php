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

final class AddLanguageLink extends SiteEndpoint
{
    public const METHOD = 'PUT';
    public const PATH = '/projects/{project}/{lang}/sites/{siteId}/language-links/{targetLang}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $Site = self::site($arguments, 'edit');
        $body = Input::body($Request, ['siteId' => 'integer'], ['siteId']);
        $target = $arguments;
        $target['lang'] = $arguments['targetLang'];
        $Target = self::site($target, 'edit', self::siteId($body['siteId']));

        if ($arguments['lang'] === $arguments['targetLang']) {
            throw new ApiException('invalid_input', 'Select a different target language.');
        }

        $Site->addLanguageLink($arguments['targetLang'], $Target->getId());
        return JsonResponse::write($Response, ['data' => self::siteData($Site)]);
    }
}
