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

final class ListSiteTypes extends SiteEndpoint
{
    public const METHOD = 'GET';
    public const PATH = '/site-types';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        $types = [];

        foreach (QUI::getPackageManager()->getAvailableSiteTypes() as $package => $entries) {
            $entries = isset($entries['type']) ? [$entries] : $entries;

            foreach ($entries as $entry) {
                if (is_array($entry) && !empty($entry['type'])) {
                    $types[] = [
                        'type' => $entry['type'], 'package' => $package,
                        'title' => $entry['text'] ?? '', 'icon' => $entry['icon'] ?? '',
                        'childrenType' => $entry['childrenType'] ?? null,
                        'childrenNavHide' => $entry['childrenNavHide'] ?? null
                    ];
                }
            }
        }

        return JsonResponse::write($Response, ['data' => $types]);
    }
}
