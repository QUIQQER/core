<?php

namespace QUI\REST\Core\System;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class ClearCache extends \QUI\REST\Core\Endpoint
{
    public const METHOD = 'POST';
    public const PATH = '/system/cache/clear';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkPermission('quiqqer.core.rest.system.clearCache', $User);
        $body = Input::body($Request, ['areas' => 'array'], ['areas']);
        $areas = $body['areas'];
        $allowed = [
            'compile', 'templates', 'complete', 'settings', 'quiqqer', 'projects',
            'groups', 'users', 'permissions', 'media', 'packages', 'longterm'
        ];

        if ($areas === [] || count($areas) > count($allowed)) {
            throw new ApiException('invalid_input', 'Provide one or more cache areas.');
        }

        foreach ($areas as $area) {
            if (!is_string($area) || !in_array($area, $allowed, true)) {
                throw new ApiException('invalid_input', 'Unknown cache area.');
            }
        }

        foreach (array_unique($areas) as $area) {
            switch ($area) {
                case 'compile':
                    QUI\Utils\System\File::unlink(VAR_DIR . 'cache/compile');
                    break;

                case 'templates':
                    QUI\Cache\Manager::clearTemplateCache();
                    break;

                case 'complete':
                    QUI\Cache\Manager::clearAll();
                    break;

                case 'settings':
                    QUI\Cache\Manager::clearSettingsCache();
                    break;

                case 'quiqqer':
                    QUI\Cache\Manager::clearCompleteQuiqqerCache();
                    break;

                case 'projects':
                    QUI\Cache\Manager::clearProjectsCache();
                    break;

                case 'groups':
                    QUI\Cache\Manager::clearGroupsCache();
                    break;

                case 'users':
                    QUI\Cache\Manager::clearUsersCache();
                    break;

                case 'permissions':
                    QUI\Cache\Manager::clearPermissionsCache();
                    break;

                case 'media':
                    QUI\Cache\Manager::clearMediaCache();
                    break;

                case 'packages':
                    QUI\Cache\Manager::clearPackagesCache();
                    break;

                case 'longterm':
                    QUI\Cache\LongTermCache::clear();
                    break;
            }
        }

        return JsonResponse::write($Response, ['data' => ['cleared' => array_values(array_unique($areas))]]);
    }
}
