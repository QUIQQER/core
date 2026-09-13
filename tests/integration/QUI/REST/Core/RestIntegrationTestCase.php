<?php

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\REST\Server;

abstract class RestIntegrationTestCase extends TestCase
{
    protected User $Root;
    /** @var list<string> */
    protected array $createdUsers = [];

    protected function setUp(): void
    {
        $this->Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        self::assertTrue($this->Root->isSU());
    }

    protected function tearDown(): void
    {
        Permission::withUser($this->Root, function (): void {
            QUI::getUsers()->withSessionUser($this->Root, function (): void {
                foreach ($this->createdUsers as $id) {
                    try {
                        QUI::getUsers()->get($id)->delete($this->Root);
                    } catch (QUI\Users\Exception) {
                    }
                }
            });
        });
    }

    protected function request(string $method, string $path, ?array $body = null, ?User $Actor = null): ResponseInterface
    {
        $Authentication = $this->createMock(AuthenticationInterface::class);
        $Authentication->method('authenticate')->willReturn($Actor ?? $this->Root);
        $Server = new Server(['basePath' => '/api']);
        (new Provider($Authentication))->register($Server);
        $Request = new ServerRequest($method, '/api/quiqqer/core' . $path);
        parse_str($Request->getUri()->getQuery(), $query);
        $Request = $Request->withQueryParams($query);

        if ($body !== null) {
            $Request = $Request->withHeader('Content-Type', 'application/json');
            $Request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $Server->getSlim()->handle($Request);
    }

    protected function data(ResponseInterface $Response, int $status = 200): array
    {
        self::assertSame($status, $Response->getStatusCode(), (string)$Response->getBody());
        return json_decode((string)$Response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    protected function createUser(): array
    {
        $name = 'rest-user-' . bin2hex(random_bytes(6));
        $Response = $this->request('POST', '/users', ['username' => $name, 'firstName' => 'REST']);
        $data = $this->data($Response, 201);
        $this->createdUsers[] = $data['uuid'];
        self::assertSame('/api/quiqqer/core/users/' . $data['uuid'], $Response->getHeaderLine('Location'));
        return $data;
    }
}
