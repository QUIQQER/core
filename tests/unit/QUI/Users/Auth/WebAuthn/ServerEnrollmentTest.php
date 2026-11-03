<?php

namespace QUI\Users\Auth\WebAuthn;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Ajax;
use QUI\Config;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Exception as PermissionException;
use QUI\Permissions\Manager as PermissionManager;
use QUI\System\Console\Session;
use QUI\Users\AuthenticatorInterface;
use QUI\Users\Auth\Handler;
use QUI\Users\Auth\WebAuthn as WebAuthnAuthenticator;
use QUI\Users\Manager as UserManager;
use ReflectionProperty;
use ReportUri\Passkeys\WebAuthn;
use stdClass;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ServerEnrollmentTest extends TestCase
{
    private const SESSION_CREATE = 'quiqqer.webauthn.create';

    public function testPartialPasswordSessionWithExistingSecondFactorCannotEnroll(): void
    {
        $ExistingAuthenticator = $this->createMock(AuthenticatorInterface::class);
        $ExistingAuthenticator->method('isSecondaryAuthentication')->willReturn(true);
        $User = $this->createUser('victim-uuid', [$ExistingAuthenticator]);
        $Repository = $this->createRepository();
        $Server = new Server($Repository);

        $this->configureEnvironment(
            $User,
            1,
            [WebAuthnAuthenticator::class, $ExistingAuthenticator::class],
            false
        );

        self::assertFalse($Server->authorizeRequiredMfaBootstrap($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));

        $this->expectException(PermissionException::class);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testBootstrapIsRejectedWhenWebAuthnCredentialAlreadyExists(): void
    {
        $User = $this->createUser('victim-uuid');
        $Repository = $this->createRepository([['id' => 1]]);
        $Server = new Server($Repository);

        $this->configureEnvironment($User, 1, [WebAuthnAuthenticator::class], false);

        self::assertFalse($Server->authorizeRequiredMfaBootstrap($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testFullyAuthenticatedUserWithoutEnrollmentAuthorizationIsRejected(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);

        $this->expectException(PermissionException::class);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testEnrollmentIsRejectedWhenWebAuthnIsNotConfiguredForCurrentArea(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getGlobalFrontendAuthenticators')->willReturn([]);
        $Handler->method('getGlobalBackendAuthenticators')->willReturn([]);
        $Handler->method('getGlobalFrontendSecondaryAuthenticators')->willReturn([]);
        $Handler->method('getGlobalBackendSecondaryAuthenticators')->willReturn([]);

        $HandlerInstance = new ReflectionProperty(Handler::class, 'Instance');
        $HandlerInstance->setValue(null, $Handler);

        self::assertFalse($Server->authorizeAuthenticatedEnrollment($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testFullyAuthenticatedUserReceivesShortLivedAuthorizationForSelf(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);

        self::assertTrue($Server->authorizeAuthenticatedEnrollment($User));
        self::assertSame($User, $Server->getAuthorizedEnrollmentUser());

        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
        self::assertSame(Server::ENROLLMENT_PURPOSE, $authorization['purpose']);
        self::assertSame(Server::ENROLLMENT_FLOW_AUTHENTICATED, $authorization['flow']);
        self::assertSame('user-uuid', $authorization['userUuid']);
        self::assertLessThanOrEqual(300, $authorization['expires'] - $authorization['created']);
    }

    public function testOptionalMfaUserIsNotFullyAuthenticatedBeforeSecondaryAuthentication(): void
    {
        $Authenticator = $this->createMock(AuthenticatorInterface::class);
        $Authenticator->method('isSecondaryAuthentication')->willReturn(true);
        $User = $this->createUser('user-uuid', [$Authenticator]);
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 2, [$Authenticator::class], true);
        QUI::getSession()->set('auth-secondary', 0);

        self::assertFalse($Server->isFullyAuthenticatedUser($User));
    }

    public function testRequiredMfaBootstrapReceivesSeparateAuthorization(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 1, [WebAuthnAuthenticator::class], false);

        self::assertTrue($Server->authorizeRequiredMfaBootstrap($User));
        self::assertSame($User, $Server->getAuthorizedEnrollmentUser());

        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
        self::assertSame(Server::ENROLLMENT_FLOW_BOOTSTRAP, $authorization['flow']);
    }

    public function testSessionWithoutCurrentMandatorySecondFactorMustAuthenticateAgain(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());
        $this->configureEnvironment($User, 1, [WebAuthnAuthenticator::class], true);
        self::assertTrue($Server->authorizeAuthenticatedEnrollment($User));
        QUI::getSession()->set('auth-secondary', 0);

        try {
            $Server->getAuthorizedEnrollmentUser();
            self::fail('The old session must not authorize passkey enrollment.');
        } catch (PermissionException $Exception) {
            self::assertSame(401, $Exception->getCode());
        }

        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testBootstrapRequiresSuccessfulPrimaryAuthentication(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 1, [WebAuthnAuthenticator::class], false);
        QUI::getSession()->set('auth-primary', 0);

        self::assertFalse($Server->authorizeRequiredMfaBootstrap($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testBootstrapUsesBackendMfaConfiguration(): void
    {
        if (!defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 1, [WebAuthnAuthenticator::class], false);

        self::assertTrue($Server->authorizeRequiredMfaBootstrap($User));
    }

    public function testAuthenticatedAuthorizationRequiresActualSessionUser(): void
    {
        $User = $this->createUser('user-uuid');
        $Nobody = $this->createUser('');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);

        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->willReturn($User);
        $Users->method('getUserBySession')->willReturn($Nobody);
        $Users->method('isNobodyUser')->willReturn(true);
        QUI::$Users = $Users;

        self::assertFalse($Server->authorizeAuthenticatedEnrollment($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testEnrollmentRequiresWebAuthnPermissionForUser(): void
    {
        $User = $this->createUser('user-uuid');
        $Repository = $this->createRepository();
        $Repository->expects(self::never())->method('create');
        $Server = new Server($Repository);

        $this->configureEnvironment($User, 0, [], true, false);

        self::assertFalse($Server->authorizeAuthenticatedEnrollment($User));
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
    }

    public function testRevokedWebAuthnPermissionBeforeFinishDoesNotStoreCredential(): void
    {
        $User = $this->createUser('user-uuid');
        [$Server, $Repository] = $this->createRegistrationServer();
        $Repository->expects(self::never())->method('create');

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');
        $this->configurePermission(false);

        $this->expectException(PermissionException::class);
        $Server->finishRegistrationForUser($User, []);
    }

    public function testExpiredAuthorizationIsRejected(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);

        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
        $authorization['expires'] = time() - 1;
        QUI::getSession()->set(Server::SESSION_ENROLLMENT, $authorization);

        $this->expectException(PermissionException::class);
        $this->expectExceptionCode(401);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testAuthorizationExpiringDuringFinishDoesNotStoreCredential(): void
    {
        $User = $this->createUser('user-uuid');
        [$Server, $Repository, $WebAuthn] = $this->createRegistrationServer();
        $Repository->expects(self::never())->method('create');

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');

        $WebAuthn->expects(self::once())
            ->method('processCreate')
            ->willReturnCallback(static function (): stdClass {
                $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
                $authorization['expires'] = time() - 1;
                QUI::getSession()->set(Server::SESSION_ENROLLMENT, $authorization);

                return new stdClass();
            });

        $this->expectException(PermissionException::class);
        $Server->finishRegistrationForUser($User, [
            'response' => [
                'clientDataJSON' => '',
                'attestationObject' => ''
            ]
        ]);
    }

    public function testAuthorizationCannotBeTransferredToAnotherSession(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);

        $ForeignSession = new Session();
        $ForeignSession->set('uid', 'user-uuid');
        $ForeignSession->set('auth', 1);
        $ForeignSession->set('auth-primary', 1);
        $ForeignSession->set(Server::SESSION_ENROLLMENT, $authorization);
        QUI::$Session = $ForeignSession;

        $this->expectException(PermissionException::class);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testManipulatedOrUsedAuthorizationIsRejected(): void
    {
        $User = $this->createUser('user-uuid');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);

        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
        $authorization['used'] = true;
        $authorization['userUuid'] = 'other-user-uuid';
        QUI::getSession()->set(Server::SESSION_ENROLLMENT, $authorization);

        $this->expectException(PermissionException::class);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testDirectFinishWithoutAuthorizedBeginStateIsRejected(): void
    {
        $User = $this->createUser('user-uuid');
        $Repository = $this->createRepository();
        $Repository->expects(self::never())->method('create');
        $Server = new Server($Repository);

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);

        $this->expectException(QUI\Users\Auth\Exception::class);
        $Server->finishRegistrationForUser($User, []);
    }

    public function testFinishEndpointRechecksAuthorizationBeforeEnablingWebAuthn(): void
    {
        $User = $this->createUser('user-uuid');
        $User->expects(self::never())->method('enableAuthenticator');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);

        QUI::$Ajax = new Ajax();
        require dirname(__DIR__, 6) . '/admin/ajax/users/authenticator/webauthn/finishRegistration.php';

        $callables = Ajax::getRegisteredCallables();
        $finish = $callables['ajax_users_authenticator_webauthn_finishRegistration']['callable'];

        $this->expectException(QUI\Users\Auth\Exception::class);
        $finish('{"response": {}}', '', 'user-uuid');
    }

    public function testBeginAndFinishMustUseSameEnrollmentFlow(): void
    {
        $User = $this->createUser('user-uuid');
        [$Server, $Repository] = $this->createRegistrationServer();
        $Repository->expects(self::never())->method('create');

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');

        $state = QUI::getSession()->get(self::SESSION_CREATE);
        $state['enrollmentFlow'] = Server::ENROLLMENT_FLOW_BOOTSTRAP;
        QUI::getSession()->set(self::SESSION_CREATE, $state);

        $this->expectException(PermissionException::class);
        $Server->finishRegistrationForUser($User, []);
    }

    public function testBeginAndFinishMustUseSameUser(): void
    {
        $User = $this->createUser('user-uuid');
        $OtherUser = $this->createUser('other-user-uuid');
        [$Server, $Repository] = $this->createRegistrationServer();
        $Repository->expects(self::never())->method('create');

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');

        $this->expectException(PermissionException::class);
        $Server->finishRegistrationForUser($OtherUser, []);
    }

    public function testBeginAndFinishMustUseSameSession(): void
    {
        $User = $this->createUser('user-uuid');
        [$Server, $Repository] = $this->createRegistrationServer();
        $Repository->expects(self::never())->method('create');

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');

        $authorization = QUI::getSession()->get(Server::SESSION_ENROLLMENT);
        $registrationState = QUI::getSession()->get(self::SESSION_CREATE);
        $ForeignSession = new Session();
        $ForeignSession->set('uid', 'user-uuid');
        $ForeignSession->set('auth', 1);
        $ForeignSession->set('auth-primary', 1);
        $ForeignSession->set(Server::SESSION_ENROLLMENT, $authorization);
        $ForeignSession->set(self::SESSION_CREATE, $registrationState);
        QUI::$Session = $ForeignSession;

        $this->expectException(PermissionException::class);
        $Server->finishRegistrationForUser($User, []);
    }

    public function testSuccessfulEnrollmentStoresCredentialAndConsumesAuthorization(): void
    {
        $User = $this->createUser('user-uuid');
        [$Server, $Repository, $WebAuthn] = $this->createRegistrationServer();
        $credential = $this->createCredentialResult();

        $WebAuthn->expects(self::once())
            ->method('processCreate')
            ->willReturn($credential);
        $Repository->expects(self::once())
            ->method('create')
            ->with(
                'user-uuid',
                'user-handle',
                'credential-id',
                'public-key',
                1,
                'aaguid',
                [],
                'Security key',
                false,
                false
            );

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);
        $Server->getRegistrationOptions($User, 'Security key');

        $result = $Server->finishRegistrationForUser($User, [
            'response' => [
                'clientDataJSON' => '',
                'attestationObject' => ''
            ]
        ]);

        self::assertSame('Y3JlZGVudGlhbC1pZA', $result['credentialId']);
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));
        self::assertFalse(QUI::getSession()->get(self::SESSION_CREATE));

        $this->expectException(PermissionException::class);
        $Server->getAuthorizedEnrollmentUser();
    }

    public function testManipulatedUserUuidCannotTargetBeginOrFinishAtAnotherUser(): void
    {
        $User = $this->createUser('user-uuid');
        $User->expects(self::never())->method('enableAuthenticator');
        $Server = new Server($this->createRepository());

        $this->configureEnvironment($User, 0, [], true);
        $Server->authorizeAuthenticatedEnrollment($User);

        QUI::$Ajax = new Ajax();
        require dirname(__DIR__, 6) . '/admin/ajax/users/authenticator/webauthn/beginRegistration.php';
        require dirname(__DIR__, 6) . '/admin/ajax/users/authenticator/webauthn/finishRegistration.php';

        $callables = Ajax::getRegisteredCallables();
        $begin = $callables['ajax_users_authenticator_webauthn_beginRegistration']['callable'];
        $finish = $callables['ajax_users_authenticator_webauthn_finishRegistration']['callable'];

        try {
            $begin('', 'other-user-uuid');
            self::fail('Manipulated begin userUuid was accepted.');
        } catch (PermissionException) {
            self::assertTrue(true);
        }

        $this->expectException(PermissionException::class);
        $finish('{}', '', 'other-user-uuid');
    }

    public function testNewUserRegistrationRemainsSeparateFromExistingUserEnrollment(): void
    {
        $User = $this->createUser('new-user-uuid', [], 'new-user');
        [$Server, $Repository, $WebAuthn] = $this->createRegistrationServer();
        $credential = $this->createCredentialResult();

        $WebAuthn->expects(self::once())
            ->method('processCreate')
            ->willReturn($credential);
        $Repository->expects(self::once())
            ->method('create')
            ->with(
                'new-user-uuid',
                'user-handle',
                'credential-id',
                'public-key',
                1,
                'aaguid',
                [],
                'First passkey',
                false,
                false
            );

        QUI::$Session = new Session();
        $Server->getRegistrationOptionsForNewUser('new-user', 'New User', 'First passkey');

        $state = QUI::getSession()->get(self::SESSION_CREATE);
        self::assertSame('new-user-registration', $state['registrationType']);
        self::assertFalse(QUI::getSession()->get(Server::SESSION_ENROLLMENT));

        $Server->finishRegistrationForUser($User, [
            'response' => [
                'clientDataJSON' => '',
                'attestationObject' => ''
            ]
        ]);

        self::assertFalse(QUI::getSession()->get(self::SESSION_CREATE));
    }

    /**
     * @param list<AuthenticatorInterface> $authenticators
     */
    private function createUser(
        string $uuid,
        array $authenticators = [],
        string $username = 'test-user'
    ): User&MockObject {
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn($uuid);
        $User->method('getUsername')->willReturn($username);
        $User->method('getName')->willReturn($username);
        $User->method('getAuthenticators')->willReturn($authenticators);
        $User->method('getGroups')->willReturn([]);

        return $User;
    }

    /**
     * @param array<int, array<string, mixed>> $credentials
     */
    private function createRepository(array $credentials = []): CredentialRepository&MockObject
    {
        $Repository = $this->createMock(CredentialRepository::class);
        $Repository->method('findByUserUuid')->willReturn($credentials);
        $Repository->method('createUserHandle')->willReturn('user-handle');
        $Repository->method('userHandleToBinary')->willReturn('binary-user-handle');

        return $Repository;
    }

    /**
     * @return array{Server, CredentialRepository&MockObject, WebAuthn&MockObject}
     */
    private function createRegistrationServer(): array
    {
        $Repository = $this->createRepository();
        $WebAuthn = $this->createMock(WebAuthn::class);
        $options = new stdClass();
        $options->publicKey = new stdClass();

        $WebAuthn->method('getCreateArgs')->willReturn($options);
        $WebAuthn->method('getChallenge')->willReturn('challenge');

        return [new Server($Repository, $WebAuthn), $Repository, $WebAuthn];
    }

    private function createCredentialResult(): stdClass
    {
        $credential = new stdClass();
        $credential->credentialId = 'credential-id';
        $credential->credentialPublicKey = 'public-key';
        $credential->signatureCounter = 1;
        $credential->AAGUID = 'aaguid';
        $credential->isBackupEligible = false;
        $credential->isBackedUp = false;

        return $credential;
    }

    /**
     * @param list<class-string<AuthenticatorInterface>> $secondaryAuthenticators
     */
    private function configureEnvironment(
        User $User,
        int $secondaryLoginType,
        array $secondaryAuthenticators,
        bool $fullyAuthenticated,
        bool $webAuthnPermission = true
    ): void {
        $Session = new Session();
        $Session->set('uid', (string)$User->getUUID());
        $Session->set('auth-primary', 1);
        $Session->set('auth-secondary', $fullyAuthenticated ? 1 : 0);
        $Session->set('auth', $fullyAuthenticated ? 1 : 0);
        QUI::$Session = $Session;

        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->willReturn($User);
        $Users->method('getUserBySession')->willReturn($User);
        $Users->method('isNobodyUser')->willReturn(false);
        $Users->method('isUser')->willReturn(true);
        $Users->method('isSystemUser')->willReturn(false);
        QUI::$Users = $Users;

        $this->configurePermission($webAuthnPermission);

        $Config = $this->createMock(Config::class);
        $Config->method('get')
            ->willReturnCallback(static function (
                string $section,
                ?string $key = null
            ) use ($secondaryLoginType): mixed {
                if (
                    $section === 'auth_settings'
                    && in_array($key, ['secondary_frontend', 'secondary_backend'], true)
                ) {
                    return $secondaryLoginType;
                }

                return false;
            });
        QUI::$Conf = $Config;

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getGlobalFrontendAuthenticators')->willReturn([WebAuthnAuthenticator::class]);
        $Handler->method('getGlobalBackendAuthenticators')->willReturn([WebAuthnAuthenticator::class]);
        $Handler->method('getGlobalFrontendSecondaryAuthenticators')->willReturn($secondaryAuthenticators);
        $Handler->method('getGlobalBackendSecondaryAuthenticators')->willReturn($secondaryAuthenticators);

        $HandlerInstance = new ReflectionProperty(Handler::class, 'Instance');
        $HandlerInstance->setValue(null, $Handler);
    }

    private function configurePermission(bool $allowed): void
    {
        $PermissionManager = $this->createMock(PermissionManager::class);
        $PermissionManager->method('getPermissions')->willReturn(
            $allowed
                ? ['quiqqer.auth.QUIUsersAuthWebAuthn' => true]
                : []
        );
        QUI::$Rights = $PermissionManager;
    }
}
