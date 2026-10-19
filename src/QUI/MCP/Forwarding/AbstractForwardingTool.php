<?php

namespace QUI\MCP\Forwarding;

use QUI;
use QUI\AI\MCP\Server;
use QUI\MCP\AbstractTool;
use QUI\Permissions\Permission;
use QUI\System\Forwarding;

abstract class AbstractForwardingTool extends AbstractTool
{
    protected const MANAGE_FORWARDINGS_PERMISSION = 'quiqqer.core.mcp.manageForwardings';

    protected static function checkForwardingPermission(): void
    {
        self::checkCorePermission();
        Permission::checkPermission(
            self::MANAGE_FORWARDINGS_PERMISSION,
            Server::getRequestUser()
        );
    }

    protected static function normalizeSource(string $source): string
    {
        Forwarding::validateSource($source);

        return trim($source);
    }

    protected static function normalizeTarget(string $target): string
    {
        return trim($target);
    }

    protected static function normalizeHttpCode(int | string $httpCode): int
    {
        if (is_string($httpCode) && !ctype_digit($httpCode)) {
            throw new QUI\Exception('The forwarding HTTP status code must be an integer.');
        }

        $httpCode = (int)$httpCode;

        if (!in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            throw new QUI\Exception('Unsupported forwarding HTTP status code: ' . $httpCode);
        }

        return $httpCode;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{source: string, target: string, httpCode: int}
     */
    protected static function parseForwarding(string $source, array $data): array
    {
        return [
            'source' => $source,
            'target' => is_scalar($data['target'] ?? null) ? (string)$data['target'] : '',
            'httpCode' => (int)($data['code'] ?? 301)
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getForwardingOrFail(string $source): array
    {
        $forwardings = Forwarding::getList(true)->toArray();

        if (!isset($forwardings[$source]) || !is_array($forwardings[$source])) {
            throw new QUI\Exception('Forwarding not found: ' . $source, 404);
        }

        return $forwardings[$source];
    }

    /**
     * @param array<array-key, mixed> $sources
     * @return array<int, string>
     */
    protected static function normalizeSources(array $sources): array
    {
        if ($sources === []) {
            throw new QUI\Exception('At least one forwarding source must be provided.');
        }

        $result = [];

        foreach ($sources as $source) {
            if (!is_string($source)) {
                throw new QUI\Exception('Every forwarding source must be a string.');
            }

            $source = self::normalizeSource($source);
            $result[$source] = $source;
        }

        return array_values($result);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getForwardingInputProperties(): array
    {
        return [
            'source' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'URL or QUIQQER StringHelper pattern with * or ? wildcards. '
                    . 'Square brackets, control characters and invalid INI section names are not supported.'
            ],
            'target' => [
                'type' => 'string',
                'description' => 'Redirect target. An empty value redirects to the QUIQQER base URL.'
            ],
            'httpCode' => [
                'type' => 'integer',
                'enum' => [301, 302, 303, 307, 308],
                'default' => 301
            ]
        ];
    }
}
