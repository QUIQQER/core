<?php

namespace QUI\REST\Core;

use QUI;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class UsersTest extends RestIntegrationTestCase
{
    public function testUnfilteredListExcludesInternalAccounts(): void
    {
        $Response = $this->request('GET', '/users?limit=100');
        $users = $this->data($Response);
        $ids = array_column($users, 'id');
        self::assertNotContains(QUI::getUsers()->getNobody()->getId(), $ids);
        self::assertNotContains(QUI::getUsers()->getSystemUser()->getId(), $ids);
        self::assertContains($this->Root->getId(), $ids);
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

    public function testAddressesBelongToTheirUserAndDefaultAddressIsProtected(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $path = '/users/' . $owner['uuid'] . '/addresses';
        $address = $this->data($this->request('POST', $path, [
            'firstName' => 'Delivery',
            'city' => 'Berlin',
            'mails' => ['rest@example.invalid'],
            'phones' => [['type' => 'tel', 'no' => '0123456']]
        ]), 201);
        $id = $address['uuid'];
        self::assertSame('Berlin', $address['city']);
        self::assertSame(404, $this->request('GET', '/users/' . $other['uuid'] . '/addresses/' . $id)->getStatusCode());
        $updated = $this->data($this->request('PATCH', $path . '/' . $id, ['city' => 'Cologne']));
        self::assertSame('Cologne', $updated['city']);
        self::assertSame(['rest@example.invalid'], $updated['mails']);
        $default = $this->data($this->request('PUT', '/users/' . $owner['uuid'] . '/default-address', ['addressId' => $id]));
        self::assertTrue($default['default']);
        self::assertSame(409, $this->request('DELETE', $path . '/' . $id)->getStatusCode());
    }

    public function testInvalidAddressDataDoesNotCreateAnAddress(): void
    {
        $owner = $this->createUser();
        $User = QUI::getUsers()->get($owner['uuid']);
        $before = count($User->getAddressList());
        $Response = $this->request('POST', '/users/' . $owner['uuid'] . '/addresses', [
            'firstName' => 'Invalid',
            'phones' => [['type' => 'fax', 'no' => '']]
        ]);
        self::assertSame(422, $Response->getStatusCode());
        self::assertCount($before, $User->getAddressList());
    }

    public function testPasswordResponseDoesNotContainThePassword(): void
    {
        $owner = $this->createUser();
        $Response = $this->request('PUT', '/users/' . $owner['uuid'] . '/password', [
            'password' => 'rest-test-secret-123!',
            'forceChange' => true
        ]);
        self::assertSame(204, $Response->getStatusCode(), (string)$Response->getBody());
        self::assertSame('', (string)$Response->getBody());
        self::assertTrue((bool)QUI::getUsers()->get($owner['uuid'])->getAttribute('quiqqer.set.new.password'));
    }
}
