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

final class GetSystemInfo extends \QUI\REST\Core\Endpoint
{
    public const METHOD = 'GET';
    public const PATH = '/system/info';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkPermission('quiqqer.core.rest.system.viewInfo', $User);
        $Connection = QUI::getDataBaseConnection();
        $packages = [];

        foreach (QUI::getPackageManager()->getInstalled() as $package) {
            if (!isset($package['name']) || !is_string($package['name'])) {
                continue;
            }

            $packages[] = [
                'name' => $package['name'], 'version' => (string)($package['version'] ?? ''),
                'type' => (string)($package['type'] ?? '')
            ];
        }

        usort($packages, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        $identity = strtolower($Connection->getDatabasePlatform()::class . ' ' . $Connection->getServerVersion());
        $database = match (true) {
            str_contains($identity, 'mariadb') => 'MariaDB',
            str_contains($identity, 'mysql') => 'MySQL',
            str_contains($identity, 'postgres') => 'PostgreSQL',
            str_contains($identity, 'sqlite') => 'SQLite',
            default => 'unknown'
        };
        $software = $Request->getServerParams()['SERVER_SOFTWARE'] ?? 'unknown';
        return JsonResponse::write($Response, ['data' => [
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI],
            'database' => ['name' => $database, 'version' => $Connection->getServerVersion()],
            'webServer' => ['software' => is_string($software) ? $software : 'unknown'],
            'quiqqer' => ['version' => QUI::getPackageManager()->getVersion()],
            'packageCount' => count($packages), 'packages' => $packages
        ]]);
    }
}
