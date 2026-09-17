<?php

namespace QUI\REST\Core;

use Psr\Http\Message\ResponseInterface;
use QUI;
use Throwable;

final class JsonResponse
{
    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $Response, array $payload, int $status = 200): ResponseInterface
    {
        $Response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $Response->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function error(ResponseInterface $Response, Throwable $Error): ResponseInterface
    {
        $status = 500;
        $code = 'internal_error';
        $message = 'The request could not be completed.';

        if ($Error instanceof ApiException) {
            $status = $Error->getCode();
            $code = $Error->errorCode;
            $message = $Error->getMessage();
        } elseif ($Error instanceof QUI\Exception) {
            $status = match ($Error->getCode()) {
                401 => 401,
                403, 440 => 403,
                404, 705, 804, 806, 1105, 1115 => 404,
                409, 1101, 1102, 1103, 1104, 1108, 1110 => 409,
                400, 422, 1109, 1111, 1119 => 422,
                default => 500
            };

            if ($Error instanceof QUI\Permissions\Exception) {
                $status = 403;
            }

            if ($Error instanceof QUI\Lock\Exception && $Error->getCode() === 703) {
                $status = 409;
            }

            [$code, $message] = match ($status) {
                401 => ['unauthenticated', 'Authentication is required.'],
                403 => ['permission_denied', 'You do not have permission to perform this operation.'],
                404 => ['not_found', 'The requested resource does not exist.'],
                409 => ['conflict', 'The operation conflicts with the current resource state.'],
                422 => ['invalid_input', 'The supplied data is invalid.'],
                default => [$code, $message]
            };
        }

        if ($status === 500) {
            QUI\System\Log::addError((string)$Error);
        }

        if ($status === 401) {
            $Response = $Response->withHeader('WWW-Authenticate', 'Bearer');
        }

        return self::write($Response, ['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
