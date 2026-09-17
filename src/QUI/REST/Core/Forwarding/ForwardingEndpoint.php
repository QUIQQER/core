<?php

namespace QUI\REST\Core\Forwarding;

use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Endpoint;
use QUI\System\Forwarding;

abstract class ForwardingEndpoint extends Endpoint
{
    protected static function authorize(User $User): void
    {
        Permission::checkPermission('quiqqer.core.rest.forwardings.canUse', $User);
    }

    /** @return array{id: string, source: string, target: string, httpCode: int} */
    protected static function forwarding(string $id): array
    {
        foreach (Forwarding::getList()->toArray() as $source => $data) {
            if (hash_equals(hash('sha256', $source), $id)) {
                return self::data($source, $data);
            }
        }

        throw new ApiException('not_found', 'The forwarding does not exist.', 404);
    }

    /** @param array<string, mixed> $data
     * @return array{id: string, source: string, target: string, httpCode: int}
     */
    protected static function data(string $source, array $data): array
    {
        return [
            'id' => hash('sha256', $source), 'source' => $source,
            'target' => (string)($data['target'] ?? ''), 'httpCode' => (int)($data['code'] ?? 301)
        ];
    }

    protected static function code(int $code): int
    {
        if (!in_array($code, [301, 302, 303, 307, 308], true)) {
            throw new ApiException('invalid_input', 'Unsupported redirect HTTP status.');
        }

        return $code;
    }

    protected static function url(string $value, bool $empty = false): string
    {
        $value = trim($value);

        if ((!$empty && $value === '') || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new ApiException('invalid_input', 'Invalid forwarding source or target.');
        }

        return $value;
    }
}
