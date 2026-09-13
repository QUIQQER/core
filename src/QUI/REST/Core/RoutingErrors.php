<?php

namespace QUI\REST\Core;

use Psr\Http\Message\ServerRequestInterface;
use QUI\REST\Server;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;

final class RoutingErrors
{
    public static function register(Server $Server): void
    {
        $Middleware = $Server->getSlimErrorMiddleware();
        $NotFound = $Middleware->getErrorHandler(HttpNotFoundException::class);
        $MethodNotAllowed = $Middleware->getErrorHandler(HttpMethodNotAllowedException::class);
        $prefix = rtrim($Server->getSlim()->getBasePath(), '/') . Provider::PREFIX;
        $Handler = static function (
            ServerRequestInterface $Request,
            \Throwable $Error,
            bool $display,
            bool $log,
            bool $details
        ) use (
            $Server,
            $NotFound,
            $MethodNotAllowed,
            $prefix
) {
            $path = $Request->getUri()->getPath();

            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                $Previous = $Error instanceof HttpMethodNotAllowedException ? $MethodNotAllowed : $NotFound;
                return $Previous($Request, $Error, $display, $log, $details);
            }

            $Response = $Server->getSlim()->getResponseFactory()->createResponse();

            if ($Error instanceof HttpMethodNotAllowedException) {
                $Response = $Response->withHeader('Allow', implode(', ', $Error->getAllowedMethods()));
                return JsonResponse::error($Response, new ApiException(
                    'method_not_allowed',
                    'The HTTP method is not supported for this resource.',
                    405
                ));
            }

            return JsonResponse::error($Response, new ApiException('not_found', 'The resource does not exist.', 404));
        };
        $Middleware->setErrorHandler([HttpNotFoundException::class, HttpMethodNotAllowedException::class], $Handler);
    }
}
