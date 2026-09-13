<?php

namespace QUI\REST\Core;

use OAuth2\Response;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\OAuth\Clients\Handler;
use QUI\OAuth\Metadata;
use QUI\OAuth\RequestFactory;
use QUI\OAuth\Server;

/**
 * Validate the current bearer token instead of relying on a cached session user.
 * The REST authentication middleware remains responsible for rate limits.
 */
final class OAuthAuthentication implements AuthenticationInterface
{
    public function authenticate(ServerRequestInterface $Request, string $scope): User
    {
        if (
            !class_exists(Server::class)
            || !QUI::getPackage('quiqqer/oauth-server')->getConfig()?->getValue('general', 'active')
        ) {
            throw new ApiException('authentication_unavailable', 'REST authentication is not configured.', 503);
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $Request->getHeaderLine('Authorization'), $matches) !== 1) {
            throw new ApiException('unauthenticated', 'A bearer token is required.', 401);
        }

        $token = $matches[1];
        $client = Handler::getOAuthClientByPermanentAccessToken($token);
        $userId = null;

        if ($client === null) {
            $Server = Server::getInstance()->getOAuth2Server();
            $OAuthRequest = RequestFactory::fromPsr($Request);
            $Verification = new Response();

            if (!$Server->verifyResourceRequest($OAuthRequest, $Verification, $scope)) {
                $status = $Verification->getStatusCode() === 403 ? 403 : 401;
                throw new ApiException('invalid_token', 'The bearer token does not authorize this request.', $status);
            }

            $Controller = $Server->getResourceController();

            if (!$Controller instanceof \OAuth2\Controller\ResourceController) {
                throw new ApiException('authentication_unavailable', 'Unsupported authentication controller.', 503);
            }

            $tokenData = $Controller->getToken();
            $resource = $tokenData['resource'] ?? null;

            if (
                is_string($resource) && $resource !== ''
                && !hash_equals($resource, Metadata::resource(QUI\REST\Server::getCurrentInstance()))
            ) {
                throw new ApiException('invalid_token', 'The bearer token belongs to another resource.', 401);
            }

            $client = Handler::getOAuthClientByAccessToken($token);
            $userId = $tokenData['user_id'] ?? null;
        }

        if (!is_array($client)) {
            throw new ApiException('invalid_token', 'The bearer token is invalid.', 401);
        }

        $restrictions = json_decode((string)($client['scope_restrictions'] ?? ''), true);

        if (!is_array($restrictions) || empty($restrictions[$scope]['active'])) {
            throw new ApiException('insufficient_scope', 'The bearer token does not authorize this resource.', 403);
        }

        // Optional method restrictions further narrow the existing path-based scope.
        $methods = $restrictions[$scope]['methods'] ?? null;

        if ($methods !== null && (!is_array($methods) || !in_array($Request->getMethod(), $methods, true))) {
            throw new ApiException('insufficient_scope', 'The bearer token does not authorize this method.', 403);
        }

        $userId ??= $client['user_id'] ?? null;

        if ((!is_int($userId) && !is_string($userId)) || $userId === '') {
            throw new ApiException('invalid_token', 'The bearer token does not identify a user.', 401);
        }

        try {
            return QUI::getUsers()->get($userId);
        } catch (QUI\Exception) {
            throw new ApiException('invalid_token', 'The bearer token does not identify an existing user.', 401);
        }
    }
}
