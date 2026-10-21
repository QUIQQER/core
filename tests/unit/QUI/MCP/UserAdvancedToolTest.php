<?php

declare(strict_types=1);

namespace QUI\MCP;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\MCP\Users\AbstractUserTool;
use QUI\MCP\Users\InviteUser;
use ReflectionMethod;

class UserAdvancedToolTest extends TestCase
{
    public function testAddressAttributesAreWhitelisted(): void
    {
        $Method = new ReflectionMethod(AbstractUserTool::class, 'filterAddressAttributes');
        $result = $Method->invoke(null, [
            'firstname' => 'Ada',
            'street_no' => 'Main Street 1',
            'country' => 'DE',
            'state' => 'Baden-Württemberg',
            'unknown' => 'ignored',
            'city' => ['invalid']
        ]);

        self::assertSame([
            'firstname' => 'Ada',
            'street_no' => 'Main Street 1',
            'country' => 'DE',
            'state' => 'Baden-Württemberg'
        ], $result['attributes']);
        self::assertSame(['unknown', 'city'], $result['ignored']);
    }

    public function testAddressSchemaAndRepresentationIncludeState(): void
    {
        $schema = (new ReflectionMethod(AbstractUserTool::class, 'getAddressAttributesSchema'))->invoke(null);
        self::assertArrayHasKey('state', $schema['properties']);
        $Address = $this->createMock(QUI\Users\Address::class);
        $Address->method('getAttribute')->willReturnCallback(static fn (string $name) => $name === 'state' ? 'CA' : '');
        $User = $this->createMock(QUI\Users\User::class);
        $User->method('getStandardAddress')->willReturn($Address);
        $result = (new ReflectionMethod(AbstractUserTool::class, 'parseAddress'))->invoke(null, $User, $Address);
        self::assertSame('CA', $result['attributes']['state']);
    }

    public function testAddressCollectionsAreNormalizedAndDeduplicated(): void
    {
        $MailMethod = new ReflectionMethod(AbstractUserTool::class, 'normalizeAddressMails');
        $PhoneMethod = new ReflectionMethod(AbstractUserTool::class, 'normalizeAddressPhones');

        self::assertSame(
            ['ada@example.invalid'],
            $MailMethod->invoke(null, ['ada@example.invalid', 'ada@example.invalid'])
        );
        self::assertSame([
            ['type' => 'mobile', 'no' => '+49 123'],
            ['type' => 'fax', 'no' => '+49 456']
        ], $PhoneMethod->invoke(null, [
            ['type' => 'mobile', 'no' => ' +49 123 '],
            ['type' => 'mobile', 'no' => '+49 123'],
            ['type' => 'fax', 'no' => '+49 456']
        ]));
    }

    public function testInvalidAddressMailIsRejected(): void
    {
        $Method = new ReflectionMethod(AbstractUserTool::class, 'normalizeAddressMails');

        $this->expectException(QUI\Exception::class);
        $Method->invoke(null, ['not-an-email']);
    }

    public function testInvalidAddressPhoneIsRejected(): void
    {
        $Method = new ReflectionMethod(AbstractUserTool::class, 'normalizeAddressPhones');

        $this->expectException(QUI\Exception::class);
        $Method->invoke(null, [['type' => 'pager', 'no' => '123']]);
    }

    public function testWebAuthnCredentialsDoNotExposeSecrets(): void
    {
        $Method = new ReflectionMethod(AbstractUserTool::class, 'parseWebAuthnCredential');
        $result = $Method->invoke(null, [
            'id' => 17,
            'name' => 'Security key',
            'aaguid' => 'aaguid',
            'transports' => ['usb'],
            'backupEligible' => true,
            'backedUp' => false,
            'created' => 123,
            'lastUsed' => 456,
            'credentialId' => 'secret credential',
            'publicKey' => 'secret key',
            'userHandle' => 'secret handle'
        ]);

        self::assertSame(17, $result['id']);
        self::assertSame('Security key', $result['name']);
        self::assertSame(['usb'], $result['transports']);
        self::assertArrayNotHasKey('credentialId', $result);
        self::assertArrayNotHasKey('publicKey', $result);
        self::assertArrayNotHasKey('userHandle', $result);
    }

    public function testInvitationGroupsRejectInvalidIdentifiersBeforeCreatingAUser(): void
    {
        $Method = new ReflectionMethod(InviteUser::class, 'resolveInviteGroups');

        $this->expectException(QUI\Exception::class);
        $Method->invoke(null, [['invalid']]);
    }
}
