<?php

namespace QUI\REST\Core\VHost;

use Psr\Http\Message\ServerRequestInterface;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\Project\ProjectEndpoint;
use QUI\System\VhostManager;

abstract class VHostEndpoint extends ProjectEndpoint
{
    protected const FIELDS = [
        'project' => 'string', 'rootLanguage' => 'string', 'pathLanguages' => 'array',
        'template' => 'string', 'error' => 'string', 'httpsHost' => 'string', 'wwwRedirect' => 'string'
    ];

    protected static function authorize(User $User): void
    {
        Permission::checkPermission('quiqqer.core.rest.vhosts.canUse', $User);
    }

    /** @return array<string, mixed> */
    protected static function vhost(string $host): array
    {
        $data = (new VhostManager())->getVhost($host);

        if (!is_array($data)) {
            throw new ApiException('not_found', 'The VHost does not exist.', 404);
        }

        return self::data($host, $data);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function data(string $host, array $data): array
    {
        return [
            'host' => $host, 'project' => $data['project'] ?? '', 'rootLanguage' => $data['lang'] ?? '',
            'pathLanguages' => VhostManager::parsePathLanguages($data[VhostManager::PATH_LANGUAGES_CONFIG_KEY] ?? ''),
            'template' => $data['template'] ?? '', 'error' => $data['error'] ?? '',
            'httpsHost' => $data['httpshost'] ?? '', 'wwwRedirect' => $data[VhostManager::WWW_REDIRECT_CONFIG_KEY] ?? ''
        ];
    }

    protected static function host(string $host): string
    {
        $host = strtolower(trim($host));

        if (
            strlen($host) > 253
            || !preg_match('/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*'
                . '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?::[0-9]{1,5})?$/D', $host)
        ) {
            throw new ApiException('invalid_input', 'Provide an ASCII hostname with an optional port.');
        }

        return $host;
    }

    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    protected static function config(array $data): array
    {
        $Project = self::project($data['project'], $data['rootLanguage']);
        $languages = $data['pathLanguages'] ?? [];

        foreach ($languages as $lang) {
            if (!is_string($lang) || !in_array($lang, $Project->getLanguages(), true)) {
                throw new ApiException('invalid_input', 'Every path language must belong to the project.');
            }
        }

        if (!in_array($data['wwwRedirect'] ?? '', ['', 'www', 'nonwww', 'none'], true)) {
            throw new ApiException('invalid_input', 'Invalid wwwRedirect mode.');
        }

        $httpsHost = $data['httpsHost'] ?? '';

        if ($httpsHost !== '') {
            $httpsHost = self::host($httpsHost);
        }

        return [
            'project' => $Project->getName(), 'lang' => $Project->getLang(),
            VhostManager::PATH_LANGUAGES_CONFIG_KEY => implode(',', array_unique($languages)),
            'template' => $data['template'] ?? '', 'error' => $data['error'] ?? '',
            'httpshost' => $httpsHost, VhostManager::WWW_REDIRECT_CONFIG_KEY => $data['wwwRedirect'] ?? ''
        ];
    }
}
