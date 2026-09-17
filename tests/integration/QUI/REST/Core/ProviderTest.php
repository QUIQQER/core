<?php

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Projects\ProjectIntegrationTestCase;
use QUI\REST\Server;
use ReflectionProperty;

class ProviderTest extends ProjectIntegrationTestCase
{
    private function server(bool $active = true): Server
    {
        $User = $this->createMock(User::class);
        $User->method('isSU')->willReturn(true);
        $User->method('isActive')->willReturn($active);
        $Authentication = $this->createMock(AuthenticationInterface::class);
        $Authentication->method('authenticate')->willReturn($User);
        $Server = new Server(['basePath' => '/test-api']);
        (new Provider($Authentication))->register($Server);

        return $Server;
    }

    public function testReadsSiteThroughNewPathWithConfiguredBasePath(): void
    {
        $project = self::getTestProjectName();
        $Server = $this->server();
        $Property = new ReflectionProperty(Permission::class, 'User');
        $Previous = $Property->getValue();
        $Response = $Server->getSlim()->handle(new ServerRequest(
            'GET',
            '/test-api/quiqqer/core/projects/' . $project . '/de/sites/1'
        ));

        self::assertSame(200, $Response->getStatusCode(), (string)$Response->getBody());
        self::assertSame('application/json; charset=utf-8', $Response->getHeaderLine('Content-Type'));
        $body = json_decode((string)$Response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['data']['id']);
        self::assertSame($project, $body['data']['project']);
        self::assertSame('de', $body['data']['lang']);
        self::assertSame($Previous, $Property->getValue());
    }

    public function testMissingLanguageDoesNotFallBackToDefaultLanguage(): void
    {
        $Response = $this->server()->getSlim()->handle(new ServerRequest(
            'GET',
            '/test-api/quiqqer/core/projects/' . self::getTestProjectName() . '/xx/sites/1'
        ));

        self::assertSame(404, $Response->getStatusCode());
    }

    public function testInvalidSiteIdAndInactiveUserAreRejected(): void
    {
        $path = '/test-api/quiqqer/core/projects/demo/de/sites/invalid';
        $Response = $this->server()->getSlim()->handle(new ServerRequest('GET', $path));
        self::assertSame(422, $Response->getStatusCode());
        self::assertStringContainsString('invalid_site_id', (string)$Response->getBody());

        $Response = $this->server(false)->getSlim()->handle(new ServerRequest('GET', $path));
        self::assertSame(401, $Response->getStatusCode());
    }

    public function testOldRouteIsNoLongerRegistered(): void
    {
        $Server = $this->server();
        $Route = $Server->getSlim()->getRouteResolver()->computeRoutingResults(
            '/test-api/projects/demo/de/1',
            'GET'
        );

        self::assertSame(0, $Route->getRouteStatus());
    }

    public function testOpenApiMatchesRegisteredMethodsAndPaths(): void
    {
        $Provider = new Provider();
        $specification = json_decode(file_get_contents($Provider->getOpenApiDefinitionFile()), true);
        $documented = [];

        foreach ($specification['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method) . ' ' . $path;
            }
        }

        $registered = [];

        foreach ($this->server()->getSlim()->getRouteCollector()->getRoutes() as $Route) {
            foreach ($Route->getMethods() as $method) {
                $registered[] = $method . ' ' . $Route->getPattern();
            }
        }

        sort($registered);
        sort($documented);
        self::assertSame($registered, $documented);
        self::assertStringContainsString(
            '<rest src="\\QUI\\REST\\Core\\Provider"/>',
            file_get_contents(dirname(__DIR__, 5) . '/package.xml')
        );
    }

    public function testMissingBearerTokenCannotUseAnExistingSession(): void
    {
        $Server = new Server(['basePath' => '/test-api']);
        (new Provider())->register($Server);
        $Response = $Server->getSlim()->handle(new ServerRequest(
            'GET',
            '/test-api/quiqqer/core/projects/demo/de/sites/1'
        ));

        self::assertContains($Response->getStatusCode(), [401, 503]);
    }

    public function testPermissionContextIsRestoredWhenAnOperationThrows(): void
    {
        $Property = new ReflectionProperty(Permission::class, 'User');
        $Previous = $Property->getValue();
        $Actor = $this->createMock(User::class);

        try {
            Permission::withUser($Actor, function () use ($Actor, $Property): void {
                self::assertSame($Actor, $Property->getValue());
                throw new \RuntimeException('test failure');
            });
            self::fail('The exception must propagate.');
        } catch (\RuntimeException $Error) {
            self::assertSame('test failure', $Error->getMessage());
        }

        self::assertSame($Previous, $Property->getValue());
    }
}
