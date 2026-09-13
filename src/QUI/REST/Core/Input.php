<?php

namespace QUI\REST\Core;

use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class Input
{
    /**
     * @param array<string, string> $fields
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $Request, array $fields, array $required = []): array
    {
        $contentType = strtolower(trim(explode(';', $Request->getHeaderLine('Content-Type'))[0]));

        if ($contentType !== 'application/json') {
            throw new ApiException('unsupported_media_type', 'Use Content-Type: application/json.', 415);
        }

        $body = (string)$Request->getBody();

        if (strlen($body) > 1048576) {
            throw new ApiException('payload_too_large', 'The JSON body exceeds 1 MiB.', 413);
        }

        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('invalid_json', 'The request body is not valid JSON.', 400);
        }

        if (!$decoded instanceof stdClass) {
            throw new ApiException('invalid_input', 'The request body must be a JSON object.');
        }

        $values = get_object_vars($decoded);

        foreach ($required as $name) {
            if (!array_key_exists($name, $values)) {
                throw new ApiException('invalid_input', 'Missing field: ' . $name . '.');
            }
        }

        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                throw new ApiException('invalid_input', 'Unknown field: ' . $name . '.');
            }

            $valid = match ($fields[$name]) {
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'array' => is_array($value),
                'object' => $value instanceof stdClass,
                default => false
            };

            if (!$valid) {
                throw new ApiException('invalid_input', 'Invalid type for field: ' . $name . '.');
            }
        }

        return $values;
    }

    public static function integerQuery(ServerRequestInterface $Request, string $name, int $default, int $min, int $max): int
    {
        $value = $Request->getQueryParams()[$name] ?? $default;

        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('invalid_input', $name . ' must be an integer.');
        }

        $value = (int)$value;

        if ($value < $min || $value > $max) {
            throw new ApiException('invalid_input', $name . ' is outside the supported range.');
        }

        return $value;
    }

    /** @return list<string|int> */
    public static function ids(mixed $value, string $name): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100) {
            throw new ApiException('invalid_input', $name . ' must contain between 1 and 100 IDs.');
        }

        $result = [];

        foreach ($value as $id) {
            if ((!is_string($id) && !is_int($id)) || (is_string($id) && trim($id) === '') || (is_int($id) && $id < 1)) {
                throw new ApiException('invalid_input', $name . ' contains an invalid ID.');
            }

            $result[(string)$id] = $id;
        }

        return array_values($result);
    }
}
