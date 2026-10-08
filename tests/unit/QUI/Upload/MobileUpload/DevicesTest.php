<?php

declare(strict_types=1);

namespace QUITests\Upload\MobileUpload;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Upload\MobileUpload\Devices;
use QUI\Upload\MobileUpload\DeviceCookie;

final class DevicesTest extends TestCase
{
    private Connection $Connection;
    private Devices $Devices;
    private string $token;

    protected function setUp(): void
    {
        $this->Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->Connection->executeStatement('CREATE TABLE users (uuid TEXT PRIMARY KEY, extra TEXT)');

        foreach (['owner', 'other'] as $user) {
            $this->Connection->insert('users', ['uuid' => $user, 'extra' => '{"unrelated":"preserved"}']);
        }

        $this->Devices = new Devices($this->Connection, 'users');
        $this->token = bin2hex(random_bytes(32));
    }

    public function testPersistentRegistrationIsUserScopedAndDoesNotExposeVerifiers(): void
    {
        $this->Devices->register('owner', $this->token, 'Phone', 'Browser', 300);
        $stored = $this->Connection->fetchOne('SELECT extra FROM users WHERE uuid = ?', ['owner']);
        self::assertStringNotContainsString($this->token, $stored);
        self::assertStringContainsString(hash('sha256', $this->token), $stored);
        self::assertSame('preserved', json_decode($stored, true)['unrelated']);

        $Reloaded = new Devices($this->Connection, 'users');
        self::assertSame('Phone', $Reloaded->find('owner', $this->token)['name']);
        self::assertNull($Reloaded->find('other', $this->token));
        $listing = json_encode($Reloaded->listing('owner'));
        self::assertStringNotContainsString($this->token, $listing);
        self::assertStringNotContainsString(hash('sha256', $this->token), $listing);
        self::assertStringNotContainsString('registeredStep', $listing);
    }

    public function testRenameTouchAndRevokeOnlyAffectSelectedDevice(): void
    {
        $this->Devices->register('owner', $this->token, 'Phone', 'Browser', 300);
        $otherToken = bin2hex(random_bytes(32));
        $this->Devices->register('owner', $otherToken, 'Tablet', 'Browser', 301);
        $id = $this->Devices->find('owner', $this->token)['id'];
        $this->Devices->edit('owner', $id, 'New name');
        $this->Devices->touch('owner', $this->token, 400);
        self::assertSame('New name', $this->Devices->find('owner', $this->token)['name']);
        self::assertSame(400, $this->Devices->find('owner', $this->token)['lastUsedAt']);
        $this->Devices->edit('owner', $id, null);
        self::assertNull($this->Devices->find('owner', $this->token));
        self::assertNotNull($this->Devices->find('owner', $otherToken));
        $this->expectException(QUI\Exception::class);
        $this->Devices->touch('owner', $this->token, 500);
    }

    public function testOrdinarySaveCannotAddDevicesOrRestoreRevokedDevices(): void
    {
        $this->Devices->register('owner', $this->token, 'Phone', 'Browser', 300);
        $staleDevices = $this->Devices->get('owner');
        $id = $this->Devices->find('owner', $this->token)['id'];
        $this->Devices->edit('owner', $id, null);

        $this->Devices->preserveOnSave('owner', [
            'unrelated' => 'updated',
            Devices::ATTRIBUTE => $staleDevices
        ], function (array $extra): void {
            $this->Connection->update('users', ['extra' => json_encode($extra)], ['uuid' => 'owner']);
        });

        self::assertSame([], $this->Devices->get('owner'));
        $stored = json_decode($this->Connection->fetchOne('SELECT extra FROM users WHERE uuid = ?', ['owner']), true);
        self::assertSame('updated', $stored['unrelated']);
    }

    public function testCannotEditAnotherUsersDevice(): void
    {
        $this->Devices->register('owner', $this->token, 'Phone', 'Browser', 300);
        $id = $this->Devices->find('owner', $this->token)['id'];
        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(404);
        $this->Devices->edit('other', $id, null);
    }

    public function testVerificationRequiresExistingBrowserCookie(): void
    {
        $previousCookies = $_COOKIE;

        try {
            $_COOKIE = [DeviceCookie::NAME => $this->token];
            self::assertSame($this->token, DeviceCookie::get());
            $_COOKIE = [];
            $this->expectException(QUI\Exception::class);
            $this->expectExceptionCode(428);
            DeviceCookie::get();
        } finally {
            $_COOKIE = $previousCookies;
        }
    }
}
