<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use QUI;
use QUI\OAuth\Clients\Handler;
use QUI\REST\Server;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class OAuthAuthenticationTest extends RestIntegrationTestCase
{
    public function testPermanentTokenUsesItsScopeMethodRestrictionsAndRevocation(): void
    {
        QUI\Update::importDatabase(QUI::getPackage('quiqqer/oauth-server')->getDir() . 'database.xml');
        $Config = QUI::getPackage('quiqqer/oauth-server')->getConfig();
        self::assertNotNull($Config);
        $previous = $Config->getValue('general', 'active');
        $Config->setValue('general', 'active', true);
        $Config->save();
        $clientId = null;

        try {
            $clientId = Handler::createOAuthClient($this->Root, [
                '/quiqqer/core/users' => ['active' => true, 'methods' => ['GET']]
            ], 'Core REST authentication test', true);
            $client = Handler::getOAuthClient($clientId);
            $token = $client['client_secret'];
            $Server = new Server(['basePath' => '/api']);
            (new Provider())->register($Server);
            $Request = (new ServerRequest('GET', '/api/quiqqer/core/users'))
                ->withHeader('Authorization', 'Bearer ' . $token);
            $Response = $Server->getSlim()->handle($Request);
            self::assertSame(200, $Response->getStatusCode(), (string)$Response->getBody());
            self::assertSame(403, $Server->getSlim()->handle($Request->withMethod('POST'))->getStatusCode());
            self::assertSame(403, $Server->getSlim()->handle(
                $Request->withUri($Request->getUri()->withPath('/api/quiqqer/core/groups'))
            )->getStatusCode());

            // Exercise expiring OAuth access tokens independently of permanent credentials.
            $Storage = QUI\OAuth\StorageFactory::create();
            $accessToken = bin2hex(random_bytes(20));
            $Storage->setAccessToken(
                $accessToken,
                $clientId,
                $this->Root->getId(),
                time() + 300,
                '/quiqqer/core/users'
            );
            $AccessRequest = $Request->withHeader('Authorization', 'Bearer ' . $accessToken);
            self::assertSame(200, $Server->getSlim()->handle($AccessRequest)->getStatusCode());
            $Storage->setAccessTokenResource($accessToken, 'https://another-resource.example.invalid/api');
            self::assertSame(401, $Server->getSlim()->handle($AccessRequest)->getStatusCode());
            $Storage->setAccessToken($accessToken, $clientId, $this->Root->getId(), time() - 60, '/quiqqer/core/users');
            self::assertSame(401, $Server->getSlim()->handle($AccessRequest)->getStatusCode());
            Handler::removeOAuthClient($clientId);
            $clientId = null;
            self::assertSame(401, $Server->getSlim()->handle($Request)->getStatusCode());
        } finally {
            if ($clientId !== null) {
                Handler::removeOAuthClient($clientId);
            }

            $Config->setValue('general', 'active', $previous);
            $Config->save();
        }
    }
}
