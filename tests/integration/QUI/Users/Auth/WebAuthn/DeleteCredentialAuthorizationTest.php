<?php

declare(strict_types=1);

namespace QUI\Users\Auth;

use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Ajax;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Permissions\Permission;
use QUI\System\Console\Session as ConsoleSession;
use QUI\Users\Auth\WebAuthn\CredentialRepository;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use ReflectionProperty;
use Throwable;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeleteCredentialAuthorizationTest extends TestCase
{
    private const AJAX_FUNCTION = 'ajax_users_authenticator_webauthn_deleteCredential';
    private const CLEANUP_AJAX_FUNCTION = 'ajax_users_authenticator_webauthn_cleanupEmpty';
    private const SETTINGS_AJAX_FUNCTION = 'ajax_users_authenticator_webauthn_settings';
    private const TEST_PREFIX = 'cwa-fix-';

    private mixed $previousSession;
    private mixed $previousAjax;
    private mixed $previousManagerSession;
    private mixed $previousPermissionUser;

    /** @var array<string, User> */
    private array $users = [];

    private ReflectionProperty $managerSessionProperty;
    private ReflectionProperty $permissionUserProperty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfDatabaseIsUnavailable();

        $this->managerSessionProperty = new ReflectionProperty(QUI::getUsers(), 'Session');
        $this->permissionUserProperty = new ReflectionProperty(Permission::class, 'User');
        $this->previousSession = QUI::$Session;
        $this->previousAjax = QUI::$Ajax;
        $this->previousManagerSession = $this->managerSessionProperty->getValue(QUI::getUsers());
        $this->previousPermissionUser = $this->permissionUserProperty->getValue();

        QUI::$Ajax = new Ajax();
        require_once OPT_DIR . 'quiqqer/core/admin/ajax/users/authenticator/webauthn/deleteCredential.php';
        require_once OPT_DIR . 'quiqqer/core/admin/ajax/users/authenticator/webauthn/cleanupEmpty.php';
        require_once OPT_DIR . 'quiqqer/core/admin/ajax/users/authenticator/webauthn/settings.php';

        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        self::assertTrue($Root->isSU(), 'The local fixture root user must be an SU.');

        $this->setActor($Root, $this->fullyAuthenticatedSession($Root));
        $this->users['owner'] = $this->createUser('owner', false, false);
        $this->users['other'] = $this->createUser('other', false, false);
        $this->users['backend-no-edit'] = $this->createUser('backend-no-edit', true, false);
        $this->users['backend-edit'] = $this->createUser('backend-edit', true, true);
        $this->users['user-edit'] = $this->createUser('user-edit', false, true);
    }

    protected function tearDown(): void
    {
        $cleanupFailure = null;

        try {
            $this->cleanupFixtures();
        } catch (Throwable $Exception) {
            $cleanupFailure = $Exception;
        } finally {
            $this->managerSessionProperty->setValue(QUI::getUsers(), $this->previousManagerSession);
            $this->permissionUserProperty->setValue(null, $this->previousPermissionUser);
            QUI::$Session = $this->previousSession;
            QUI::$Ajax = $this->previousAjax;
        }

        parent::tearDown();

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }

    public function testAjaxRegistrationRequiresUserAndUnauthenticatedDeletionIsRejected(): void
    {
        $permissionsProperty = new ReflectionProperty(Ajax::class, 'permissions');
        $permissions = $permissionsProperty->getValue();

        self::assertSame('Permission::checkUser', $permissions[self::AJAX_FUNCTION] ?? null);
        Ajax::checkPermissions(self::AJAX_FUNCTION);

        $credentialId = $this->createCredential($this->users['owner']);
        $beforeStatus = $this->isWebAuthnStored($this->users['owner']);
        $this->setActor(null, []);

        $response = $this->invokeDelete($credentialId, $this->users['owner']->getUUID());

        $this->assertRejectedWithoutStateChange(
            $response,
            $credentialId,
            $this->users['owner'],
            $beforeStatus
        );
    }

    public function testPrimaryOnlyOwnerCannotDeleteMultipleOrLastCredential(): void
    {
        $Owner = $this->users['owner'];
        $firstId = $this->createCredential($Owner);
        $secondId = $this->createCredential($Owner);
        $this->setActor(null, [
            'uid' => $Owner->getUUID(),
            'username' => $Owner->getUsername(),
            'inAuthentication' => 1,
            'auth-primary' => 1,
            'auth-secondary' => 0,
            'auth' => 0
        ]);

        $firstResponse = $this->invokeDelete($firstId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($firstResponse, $firstId, $Owner, true);
        self::assertNotNull((new CredentialRepository())->findById($secondId));

        (new CredentialRepository())->deleteForUser($firstId, (string)$Owner->getUUID());
        $secondResponse = $this->invokeDelete($secondId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($secondResponse, $secondId, $Owner, true);

        self::assertSame(1, QUI::getSession()->get('auth-primary'));
        self::assertSame(0, QUI::getSession()->get('auth-secondary'));
        self::assertSame(0, QUI::getSession()->get('auth'));
        self::assertSame($Owner->getUUID(), QUI::getSession()->get('uid'));
    }

    public function testSessionUidAloneIsInsufficientForOwnerDeletion(): void
    {
        $Owner = $this->users['owner'];
        $credentialId = $this->createCredential($Owner);
        $this->setActor(null, ['uid' => $Owner->getUUID()]);

        self::assertFalse(QUI::getSession()->get('auth'));
        self::assertFalse(QUI::getSession()->get('auth-primary'));
        self::assertFalse(QUI::getSession()->get('auth-secondary'));

        $response = $this->invokeDelete($credentialId, $Owner->getUUID());

        $this->assertRejectedWithoutStateChange($response, $credentialId, $Owner, true);
    }

    public function testFullyAuthenticatedOwnerCanDeleteOneOfMultipleAndLastCredential(): void
    {
        $Owner = $this->users['owner'];
        $Other = $this->users['other'];
        $firstId = $this->createCredential($Owner);
        $secondId = $this->createCredential($Owner);
        $this->setActor($Owner, $this->fullyAuthenticatedSession($Owner));

        $firstResponse = $this->invokeDelete($firstId, '');
        self::assertArrayNotHasKey('Exception', $firstResponse);
        self::assertTrue($firstResponse['result']['hasCredentials']);
        self::assertNull((new CredentialRepository())->findById($firstId));
        self::assertNotNull((new CredentialRepository())->findById($secondId));
        self::assertTrue($this->isWebAuthnStored($Owner));

        $this->setActor($Other, $this->fullyAuthenticatedSession($Other));
        $foreignResponse = $this->invokeDelete($secondId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($foreignResponse, $secondId, $Owner, true);

        $this->setActor($Owner, $this->fullyAuthenticatedSession($Owner));
        $lastResponse = $this->invokeDelete($secondId, $Owner->getUUID());
        self::assertArrayNotHasKey(
            'Exception',
            $lastResponse,
            json_encode($lastResponse['Exception'] ?? [], JSON_THROW_ON_ERROR)
        );
        self::assertFalse($lastResponse['result']['hasCredentials']);
        self::assertNull((new CredentialRepository())->findById($secondId));
        self::assertFalse($this->isWebAuthnStored($Owner));
    }

    public function testBackendPermissionMatrixSuAndSystemUser(): void
    {
        $Owner = $this->users['owner'];
        $BackendNoEdit = $this->users['backend-no-edit'];
        $UserEdit = $this->users['user-edit'];
        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        $System = QUI::getUsers()->getSystemUser();

        self::assertTrue((bool)Permission::checkPermission('quiqqer.admin', $BackendNoEdit));
        self::assertFalse((bool)Permission::hasPermission('quiqqer.admin.users.edit', $BackendNoEdit));
        Permission::checkAdminUser($BackendNoEdit);
        self::assertFalse((bool)Permission::hasPermission('quiqqer.admin', $UserEdit));
        self::assertTrue((bool)Permission::checkPermission('quiqqer.admin.users.edit', $UserEdit));

        $credentialId = $this->createCredential($Owner);
        $this->setActor($BackendNoEdit, $this->fullyAuthenticatedSession($BackendNoEdit));
        $noEditResponse = $this->invokeDelete($credentialId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($noEditResponse, $credentialId, $Owner, true);

        $this->setActor($UserEdit, $this->fullyAuthenticatedSession($UserEdit));
        $editResponse = $this->invokeDelete($credentialId, $Owner->getUUID());
        self::assertArrayNotHasKey(
            'Exception',
            $editResponse,
            json_encode($editResponse['Exception'] ?? [], JSON_THROW_ON_ERROR)
        );
        self::assertNull((new CredentialRepository())->findById($credentialId));

        $suCredentialId = $this->createCredential($Owner);
        $this->setActor($Root, $this->fullyAuthenticatedSession($Root));
        $suResponse = $this->invokeDelete($suCredentialId, $Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $suResponse);
        self::assertNull((new CredentialRepository())->findById($suCredentialId));

        $systemCredentialId = $this->createCredential($Owner);
        $beforeStatus = $this->isWebAuthnStored($Owner);
        $this->setActor($System, $this->fullyAuthenticatedSession($System));
        $systemResponse = $this->invokeDelete($systemCredentialId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($systemResponse, $systemCredentialId, $Owner, $beforeStatus);
    }

    public function testCredentialOwnershipBindingWithAndWithoutUserUuid(): void
    {
        $Owner = $this->users['owner'];
        $Other = $this->users['other'];
        $BackendNoEdit = $this->users['backend-no-edit'];
        $UserEdit = $this->users['user-edit'];
        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));

        $mismatchedId = $this->createCredential($Owner);
        $beforeStatus = $this->isWebAuthnStored($Owner);
        $this->setActor($Root, $this->fullyAuthenticatedSession($Root));
        $mismatchResponse = $this->invokeDelete($mismatchedId, $Other->getUUID());
        $this->assertRejectedWithoutStateChange($mismatchResponse, $mismatchedId, $Owner, $beforeStatus);

        $omittedUuidId = $this->createCredential($Owner);
        $this->setActor($BackendNoEdit, $this->fullyAuthenticatedSession($BackendNoEdit));
        $omittedUuidResponse = $this->invokeDelete($omittedUuidId, '');
        $this->assertRejectedWithoutStateChange($omittedUuidResponse, $omittedUuidId, $Owner, true);

        $this->setActor($UserEdit, $this->fullyAuthenticatedSession($UserEdit));
        $authorizedOmittedUuidResponse = $this->invokeDelete($omittedUuidId, '');
        self::assertArrayNotHasKey('Exception', $authorizedOmittedUuidResponse);
        self::assertNull((new CredentialRepository())->findById($omittedUuidId));

        $matchingUuidId = $this->createCredential($Owner);
        $matchingUuidResponse = $this->invokeDelete($matchingUuidId, $Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $matchingUuidResponse);
        self::assertNull((new CredentialRepository())->findById($matchingUuidId));
    }

    public function testForeignCredentialMetadataRequiresBackendUserEditPermission(): void
    {
        $Owner = $this->users['owner'];
        $BackendNoEdit = $this->users['backend-no-edit'];
        $BackendEdit = $this->users['backend-edit'];
        $UserEdit = $this->users['user-edit'];
        $credentialName = 'Settings authorization credential';

        $this->createCredential($Owner, $credentialName);

        $this->setActor($Owner, $this->fullyAuthenticatedSession($Owner));
        $ownerResponse = $this->invokeSettings($Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $ownerResponse);
        self::assertStringContainsString($credentialName, $ownerResponse['result']);
        $ownerCleanupResponse = $this->invokeCleanup($Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $ownerCleanupResponse);
        self::assertTrue($ownerCleanupResponse['result']['hasCredentials']);

        $this->setActor($BackendNoEdit, $this->fullyAuthenticatedSession($BackendNoEdit));
        $backendOnlyResponse = $this->invokeSettings($Owner->getUUID());
        self::assertArrayHasKey('Exception', $backendOnlyResponse);
        self::assertStringNotContainsString($credentialName, json_encode($backendOnlyResponse, JSON_THROW_ON_ERROR));
        self::assertArrayHasKey('Exception', $this->invokeCleanup($Owner->getUUID()));

        $this->setActor($UserEdit, $this->fullyAuthenticatedSession($UserEdit));
        $editOnlyResponse = $this->invokeSettings($Owner->getUUID());
        self::assertArrayHasKey('Exception', $editOnlyResponse);
        self::assertStringNotContainsString($credentialName, json_encode($editOnlyResponse, JSON_THROW_ON_ERROR));
        self::assertArrayHasKey('Exception', $this->invokeCleanup($Owner->getUUID()));

        $this->setActor($BackendEdit, $this->fullyAuthenticatedSession($BackendEdit));
        $authorizedResponse = $this->invokeSettings($Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $authorizedResponse);
        self::assertStringContainsString($credentialName, $authorizedResponse['result']);
        $authorizedCleanupResponse = $this->invokeCleanup($Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $authorizedCleanupResponse);
        self::assertTrue($authorizedCleanupResponse['result']['hasCredentials']);
    }

    public function testForeignEmptyCredentialCleanupRequiresBackendUserEditPermission(): void
    {
        $Owner = $this->users['owner'];
        $BackendNoEdit = $this->users['backend-no-edit'];
        $BackendEdit = $this->users['backend-edit'];

        $this->setWebAuthnStored($Owner, true);
        $this->setActor($BackendNoEdit, $this->fullyAuthenticatedSession($BackendNoEdit));

        $rejectedResponse = $this->invokeCleanup($Owner->getUUID());

        self::assertArrayHasKey('Exception', $rejectedResponse);
        self::assertArrayNotHasKey('result', $rejectedResponse);
        self::assertTrue($this->isWebAuthnStored($Owner));

        $this->setActor($BackendEdit, $this->fullyAuthenticatedSession($BackendEdit));

        $authorizedResponse = $this->invokeCleanup($Owner->getUUID());

        self::assertArrayNotHasKey(
            'Exception',
            $authorizedResponse,
            json_encode($authorizedResponse['Exception'] ?? [], JSON_THROW_ON_ERROR)
        );
        self::assertFalse($authorizedResponse['result']['hasCredentials']);
        self::assertFalse($this->isWebAuthnStored($Owner));
    }

    public function testCleanupRestoresAuthenticatorWhenCredentialAppearsDuringUserSave(): void
    {
        $Owner = $this->users['owner'];
        $credentialCreated = false;
        $credentialId = null;
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(UserManager::table()),
            ['su' => 1],
            ['uuid' => $Owner->getUUID()]
        );
        $Owner->refresh();
        self::assertTrue($Owner->isSU());
        $this->setWebAuthnStored($Owner, true);
        $this->setActor($Owner, $this->fullyAuthenticatedSession($Owner));
        $listener = function (UserInterface $SavedUser) use (
            $Owner,
            &$credentialCreated,
            &$credentialId
        ): void {
            if ($credentialCreated || $SavedUser->getUUID() !== $Owner->getUUID()) {
                return;
            }

            $credentialCreated = true;
            $credentialId = $this->createCredential($Owner, 'Concurrent registration credential', false);
        };

        QUI::getEvents()->addEvent('onUserSaveEnd', $listener);

        try {
            $response = $this->invokeCleanup($Owner->getUUID());
        } finally {
            QUI::getEvents()->removeEvent('onUserSaveEnd', $listener);
        }

        self::assertArrayNotHasKey(
            'Exception',
            $response,
            json_encode($response['Exception'] ?? [], JSON_THROW_ON_ERROR)
        );
        self::assertTrue($response['result']['hasCredentials']);
        self::assertTrue($credentialCreated);
        self::assertIsInt($credentialId);
        self::assertNotNull((new CredentialRepository())->findById($credentialId));
        self::assertTrue($this->isWebAuthnStored($Owner));
    }

    public function testMissingCredentialDoesNotLeakCredentialStateWithoutAuthentication(): void
    {
        $Owner = $this->users['owner'];
        $existingId = $this->createCredential($Owner);
        $missingId = $existingId + 1000000;
        $this->setActor(null, []);

        $responseWithUser = $this->invokeDelete($missingId, $Owner->getUUID());
        $this->assertRejectedWithoutStateChange($responseWithUser, $existingId, $Owner, true);

        $responseWithoutUser = $this->invokeDelete($missingId, '');
        self::assertArrayHasKey('Exception', $responseWithoutUser);
        self::assertNotNull((new CredentialRepository())->findById($existingId));
        self::assertTrue($this->isWebAuthnStored($Owner));

        $this->setActor($Owner, $this->fullyAuthenticatedSession($Owner));
        $authorizedResponse = $this->invokeDelete($missingId, $Owner->getUUID());
        self::assertArrayNotHasKey('Exception', $authorizedResponse);
        self::assertTrue($authorizedResponse['result']['hasCredentials']);
    }

    private function createUser(string $role, bool $backend, bool $canEditUsers): User
    {
        $username = self::TEST_PREFIX . $role . '-' . bin2hex(random_bytes(5));
        $System = QUI::getUsers()->getSystemUser();
        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        $User = QUI::getUsers()->createChildWithAttributes([
            'username' => $username,
            'email' => $username . '@example.invalid'
        ], $System);

        self::assertInstanceOf(User::class, $User);
        QUI::getPermissionManager()->setPermissions($User, [
            'quiqqer.admin' => $backend,
            'quiqqer.admin.users.edit' => $canEditUsers
        ], $Root);

        $User->setPassword('Codex-WebAuthn-fix-' . bin2hex(random_bytes(8)), $System);
        $User->activate('', $System);

        return $User;
    }

    private function createCredential(
        User $User,
        string $name = 'Authorization test credential',
        bool $enableAuthenticator = true
    ): int {
        if ($enableAuthenticator && !$this->isWebAuthnStored($User)) {
            $this->setWebAuthnStored($User, true);
        }

        $Repository = new CredentialRepository();
        $rawId = random_bytes(32);
        $Repository->create(
            (string)$User->getUUID(),
            $Repository->createUserHandle(),
            $rawId,
            'authorization-test-public-key',
            0,
            null,
            ['internal'],
            $name,
            false,
            false
        );

        $credential = $Repository->findByCredentialId($rawId);
        self::assertIsArray($credential);

        return (int)$credential['id'];
    }

    /** @param array<string, mixed> $sessionValues */
    private function setActor(?UserInterface $User, array $sessionValues): void
    {
        $Session = new ConsoleSession();

        foreach ($sessionValues as $key => $value) {
            $Session->set($key, $value);
        }

        QUI::$Session = $Session;
        $this->managerSessionProperty->setValue(
            QUI::getUsers(),
            $User ?? QUI::getUsers()->getNobody()
        );
        $this->permissionUserProperty->setValue(null, null);
    }

    /** @return array<string, int|string> */
    private function fullyAuthenticatedSession(UserInterface $User): array
    {
        return [
            'uid' => (string)$User->getUUID(),
            'username' => $User->getUsername(),
            'auth' => 1,
            'auth-primary' => 1,
            'auth-secondary' => 1
        ];
    }

    /** @return array<string, mixed> */
    private function invokeDelete(int $id, string $userUuid): array
    {
        return QUI::getAjax()->callRequestFunction(self::AJAX_FUNCTION, [
            'id' => $id,
            'userUuid' => $userUuid
        ]);
    }

    /** @return array<string, mixed> */
    private function invokeSettings(string $userUuid): array
    {
        return QUI::getAjax()->callRequestFunction(self::SETTINGS_AJAX_FUNCTION, [
            'userUuid' => $userUuid
        ]);
    }

    /** @return array<string, mixed> */
    private function invokeCleanup(string $userUuid): array
    {
        return QUI::getAjax()->callRequestFunction(self::CLEANUP_AJAX_FUNCTION, [
            'userUuid' => $userUuid
        ]);
    }

    /** @param array<string, mixed> $response */
    private function assertRejectedWithoutStateChange(
        array $response,
        int $credentialId,
        User $Owner,
        bool $expectedAuthenticatorStatus
    ): void {
        self::assertArrayHasKey('Exception', $response);
        self::assertNotNull((new CredentialRepository())->findById($credentialId));
        self::assertSame($expectedAuthenticatorStatus, $this->isWebAuthnStored($Owner));
    }

    private function isWebAuthnStored(User $User): bool
    {
        $stored = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('authenticator')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(UserManager::table()))
            ->where('uuid = :uuid')
            ->setParameter('uuid', $User->getUUID())
            ->executeQuery()
            ->fetchOne();
        $authenticators = is_string($stored) ? json_decode($stored, true) : [];

        return is_array($authenticators) && in_array(WebAuthn::class, $authenticators, true);
    }

    private function setWebAuthnStored(User $User, bool $enabled): void
    {
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(UserManager::table()),
            ['authenticator' => json_encode($enabled ? [WebAuthn::class] : [], JSON_THROW_ON_ERROR)],
            ['uuid' => $User->getUUID()]
        );
        $User->refresh();
    }

    private function cleanupFixtures(): void
    {
        $System = QUI::getUsers()->getSystemUser();
        $this->setActor($System, $this->fullyAuthenticatedSession($System));
        $Connection = QUI::getDataBaseConnection();
        $permissionTable = QUI::getPermissionManager()::table() . '2users';

        foreach ($this->users as $User) {
            $uuid = (string)$User->getUUID();
            $Connection->delete(
                QUI\Utils\Doctrine::quoteIdentifier(CredentialRepository::table()),
                ['userUuid' => $uuid]
            );
            $Connection->delete($permissionTable, ['user_id' => $uuid]);

            try {
                QUI::getUsers()->get($uuid)->delete($System);
            } catch (QUI\Users\Exception $Exception) {
                if ($Exception->getCode() !== 404) {
                    throw $Exception;
                }
            }
        }

        $remainingCredentials = (int)$Connection->createQueryBuilder()
            ->select('COUNT(id)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(CredentialRepository::table()))
            ->where('userUuid IN (:uuids)')
            ->setParameter('uuids', array_map(
                static fn(User $User): string => (string)$User->getUUID(),
                $this->users
            ), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchOne();
        $remainingUsers = (int)$Connection->createQueryBuilder()
            ->select('COUNT(id)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(UserManager::table()))
            ->where('username LIKE :prefix')
            ->setParameter('prefix', self::TEST_PREFIX . '%')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, $remainingCredentials, 'Credential fixtures were not fully removed.');
        self::assertSame(0, $remainingUsers, 'User fixtures were not fully removed.');
    }

    private function skipIfDatabaseIsUnavailable(): void
    {
        try {
            QUI::getDataBaseConnection()->executeQuery('SELECT 1')->free();
            QUI::getDataBaseConnection()->executeQuery(
                'SELECT 1 FROM ' . QUI\Utils\Doctrine::quoteIdentifier(CredentialRepository::table()) . ' LIMIT 1'
            )->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER WebAuthn database fixtures are unavailable: ' . $Exception->getMessage());
        }
    }
}
