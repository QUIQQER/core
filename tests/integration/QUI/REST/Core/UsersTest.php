<?php

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\REST\Server;

class UsersTest extends TestCase
{
    private User $Root;
    /** @var list<string> */
    private array $createdUsers = [];

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

    private function request(string $method, string $path, ?array $body = null, ?User $Actor = null): ResponseInterface
    {
        $Authentication = $this->createMock(AuthenticationInterface::class);
        $Authentication->method('authenticate')->willReturn($Actor ?? $this->Root);
        $Server = new Server(['basePath' => '/api']);
        (new Provider($Authentication))->register($Server);
        $Request = new ServerRequest($method, '/api/quiqqer/core' . $path);

        if ($body !== null) {
            $Request = $Request->withHeader('Content-Type', 'application/json');
            $Request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $Server->getSlim()->handle($Request);
    }

    private function data(ResponseInterface $Response, int $status = 200): array
    {
        self::assertSame($status, $Response->getStatusCode(), (string)$Response->getBody());
        return json_decode((string)$Response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    private function createUser(): array
    {
        $name = 'rest-user-' . bin2hex(random_bytes(6));
        $Response = $this->request('POST', '/users', ['username' => $name, 'firstName' => 'REST']);
        $data = $this->data($Response, 201);
        $this->createdUsers[] = $data['uuid'];
        self::assertSame('/api/quiqqer/core/users/' . $data['uuid'], $Response->getHeaderLine('Location'));
        return $data;
    }

    public function testUserLifecycleAndRepeatedActivation(): void
    {
        $data = $this->createUser();
        $id = $data['uuid'];
        self::assertFalse($data['active']);
        self::assertArrayNotHasKey('password', $data);
        $Target = QUI::getUsers()->get($id);
        QUI::getUsers()->withSessionUser(
            $this->Root,
            fn() => $Target->setPassword('rest-integration-password-123!', $this->Root)
        );

        $updated = $this->data($this->request('PATCH', '/users/' . $id, ['lastName' => 'Updated']));
        self::assertSame('Updated', $updated['lastName']);
        self::assertSame('REST', $updated['firstName']);

        $activated = $this->data($this->request('POST', '/users/activate', ['userIds' => [$id]]));
        self::assertSame(200, $activated[0]['status']);
        self::assertTrue($activated[0]['data']['active']);
        self::assertTrue($activated[0]['changed']);
        $again = $this->data($this->request('POST', '/users/activate', ['userIds' => [$id]]));
        self::assertSame(200, $again[0]['status']);
        self::assertFalse($again[0]['changed']);

        $deactivated = $this->data($this->request('POST', '/users/deactivate', ['userIds' => [$id, 'missing-rest-user']]));
        self::assertSame(200, $deactivated[0]['status']);
        self::assertSame(404, $deactivated[1]['status']);
        self::assertFalse($deactivated[0]['data']['active']);

        $loaded = $this->data($this->request('GET', '/users/' . $id));
        self::assertSame('Updated', $loaded['lastName']);
        $Response = $this->request('DELETE', '/users/' . $id);
        self::assertSame(204, $Response->getStatusCode(), (string)$Response->getBody());
        self::assertSame('', (string)$Response->getBody());
        $this->createdUsers = [];
        self::assertSame(404, $this->request('GET', '/users/' . $id)->getStatusCode());
    }

    public function testRejectsSecurityFieldsWithoutChangingProfile(): void
    {
        $data = $this->createUser();
        $Response = $this->request('PATCH', '/users/' . $data['uuid'], ['firstName' => 'Unsafe', 'su' => true]);
        self::assertSame(422, $Response->getStatusCode());
        self::assertSame('REST', QUI::getUsers()->get($data['uuid'])->getAttribute('firstname'));
    }

    public function testUserWithoutRestPermissionCannotReadOrChangeUsers(): void
    {
        $data = $this->createUser();
        $Actor = QUI::getUsers()->get($data['uuid']);
        QUI::getUsers()->withSessionUser(
            $this->Root,
            fn() => $Actor->setPassword('rest-integration-password-123!', $this->Root)
        );
        QUI::getUsers()->withSessionUser($this->Root, fn() => $Actor->activate('', $this->Root));

        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $Response = $this->request($method, '/users/' . $data['uuid'], ['firstName' => 'Denied'], $Actor);
            self::assertSame(403, $Response->getStatusCode(), (string)$Response->getBody());
        }
    }
}
