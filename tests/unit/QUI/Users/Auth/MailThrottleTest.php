<?php

namespace QUI\Users\Auth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Locale;
use QUI\Config;
use QUI\Mail\Manager as MailManager;
use QUI\Package\Manager as PackageManager;
use QUI\Package\Package;
use QUI\Permissions\Manager as PermissionManager;
use QUI\Security\Throttle;
use QUI\System\Console\Session;
use QUI\Users\Attribute\Verifiable\MailAttribute;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use QUI\Verification\Interface\VerificationFactoryInterface;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MailThrottleTest extends TestCase
{
    private Connection $Connection;

    protected function setUp(): void
    {
        // Resolve the project while the complete fixture database is still installed.
        QUI::getRewrite()->getProject();
        $this->Connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        $ConnectionProperty = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $ConnectionProperty->setValue(null, $this->Connection);

        $table = Throttle::table();
        $quotedTable = $this->Connection->getDatabasePlatform()->quoteIdentifier($table);

        $this->Connection->executeStatement(
            'CREATE TABLE ' . $quotedTable . ' ('
            . 'throttleKey VARCHAR(64) PRIMARY KEY, '
            . 'package VARCHAR(255) NOT NULL, '
            . 'subjectKey VARCHAR(64) NOT NULL, '
            . 'reservationId VARCHAR(32) NOT NULL, '
            . 'expiresAt BIGINT NOT NULL'
            . ')'
        );

        $_SERVER['HTTP_HOST'] = 'example.test';
    }

    public function testPasswordResetMailUsesPersistentUserThrottle(): void
    {
        $User = $this->createConcreteUser('password-user');
        Throttle::acquireForUser($User, 'quiqqer/core', 'users.password-reset-mail', 60);

        $VerificationFactory = $this->createMock(VerificationFactoryInterface::class);
        $VerificationFactory->expects(self::never())->method('createLinkVerification');

        $this->configureVerificationPackage();

        (new Handler($VerificationFactory))->sendPasswordResetVerificationMail($User);
    }

    public function testPasswordResetReleasesThrottleAfterFailure(): void
    {
        $User = $this->createConcreteUser('password-user');
        $VerificationFactory = $this->createMock(VerificationFactoryInterface::class);
        $VerificationFactory->expects(self::exactly(2))
            ->method('createLinkVerification')
            ->willThrowException(new QUI\Exception('Verification creation failed.'));

        $this->configureVerificationPackage();
        $Handler = new Handler($VerificationFactory);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $Handler->sendPasswordResetVerificationMail($User);
            } catch (QUI\Exception) {
            }
        }
    }

    private function configureVerificationPackage(): void
    {
        $Package = $this->createMock(Package::class);
        $Package->method('getVersion')->willReturn('3.0.0');

        $PackageManager = $this->createMock(PackageManager::class);
        $PackageManager->method('isInstalled')
            ->with('quiqqer/verification')
            ->willReturn(true);
        $PackageManager->method('getInstalledPackage')
            ->with('quiqqer/verification')
            ->willReturn($Package);
        QUI::$PackageManager = $PackageManager;
    }

    public function testAuthMailCannotBeRequestedAgainWithANewSession(): void
    {
        $User = $this->createConcreteUser('auth-user');
        $this->configureVerifiedMailDependencies($User, 1);

        $FirstSession = $this->createSession('auth-user');
        QUI::$Session = $FirstSession;
        VerifiedMail2FA::sendAuthMailToSessionUser();

        $SecondSession = $this->createSession('auth-user');
        QUI::$Session = $SecondSession;
        VerifiedMail2FA::sendAuthMailToSessionUser();

        self::assertNotEmpty($FirstSession->get(VerifiedMail2FA::USER_CODE_ATTRIBUTE));
        self::assertFalse($SecondSession->get(VerifiedMail2FA::USER_CODE_ATTRIBUTE));
    }

    public function testEnableMailCannotBeRequestedAgainWithANewSession(): void
    {
        $User = $this->createConcreteUser('enable-user');
        $this->configureVerifiedMailDependencies($User, 1);

        $FirstSession = $this->createSession('enable-user');
        QUI::$Session = $FirstSession;
        VerifiedMail2FA::sendEnableMailToSessionUser();

        $SecondSession = $this->createSession('enable-user');
        QUI::$Session = $SecondSession;
        VerifiedMail2FA::sendEnableMailToSessionUser();

        self::assertNotEmpty($FirstSession->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE));
        self::assertFalse($SecondSession->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE));
    }

    public function testAuthAndEnableMailUseSeparateThrottleBuckets(): void
    {
        $User = $this->createConcreteUser('shared-user');
        $this->configureVerifiedMailDependencies($User, 2);
        QUI::$Session = $this->createSession('shared-user');

        VerifiedMail2FA::sendAuthMailToSessionUser();
        VerifiedMail2FA::sendEnableMailToSessionUser();

        self::assertNotEmpty(QUI::getSession()->get(VerifiedMail2FA::USER_CODE_ATTRIBUTE));
        self::assertNotEmpty(QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE));
    }

    public function testRequiredBackendSetupVerifiesMailWithoutActivating2FA(): void
    {
        if (!defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        $User = $this->prepareMailSetup();
        $User->expects(self::once())->method('setStatusToVerifiableAttribute')
            ->with('user@example.test', MailAttribute::class, QUI\Users\Attribute\AttributeVerificationStatus::VERIFIED);
        $User->expects(self::once())->method('save');
        $User->expects(self::never())->method('enableAuthenticator');

        VerifiedMail2FA::sendEnableMailToSessionUser();
        $code = QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE);

        self::assertTrue(VerifiedMail2FA::verifyMailByUser($code));
        self::assertFalse(QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE));
        self::assertSame(0, QUI::getSession()->get('auth-secondary'));
        self::assertSame(0, QUI::getSession()->get('auth'));
        self::assertFalse(VerifiedMail2FA::verifyMailByUser($code), 'A setup code must not be reusable.');
    }

    public static function invalidSetupContexts(): array
    {
        return [
            'wrong account' => ['userUuid', 'someone-else'],
            'changed address' => ['email', 'another@example.test'],
            'expired code' => ['expires', 1],
            'missing timestamp' => ['expires', null]
        ];
    }

    #[DataProvider('invalidSetupContexts')]
    public function testMailSetupRejectsInvalidCodeContext(string $key, mixed $value): void
    {
        $User = $this->prepareMailSetup();
        $User->expects(self::never())->method('setStatusToVerifiableAttribute');
        $User->expects(self::never())->method('enableAuthenticator');

        VerifiedMail2FA::sendEnableMailToSessionUser();
        $Session = QUI::getSession();
        $code = $Session->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE);
        $context = $Session->get('quiqqer.security.mail2fa.setup');
        $context[$key] = $value;
        $Session->set('quiqqer.security.mail2fa.setup', $context);

        self::assertFalse(VerifiedMail2FA::verifyMailByUser($code));
        self::assertFalse($Session->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE));
    }

    public function testMailSetupStopsAfterFiveWrongCodes(): void
    {
        $User = $this->prepareMailSetup();
        $User->expects(self::never())->method('enableAuthenticator');
        VerifiedMail2FA::sendEnableMailToSessionUser();
        $code = QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE);
        $wrongCode = $code === '000000' ? '111111' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertFalse(VerifiedMail2FA::verifyMailByUser($wrongCode));
        }

        self::assertFalse(VerifiedMail2FA::verifyMailByUser($code));
    }

    public function testMailSetupNeverAcceptsAMissingCode(): void
    {
        $User = $this->prepareMailSetup(0);
        $User->expects(self::never())->method('enableAuthenticator');

        foreach ([false, null, '', [], '000000'] as $code) {
            self::assertFalse(VerifiedMail2FA::verifyMailByUser($code));
        }
    }

    public function testMailSetupRequiresPrimaryAuthentication(): void
    {
        $User = $this->prepareMailSetup(0);
        $User->expects(self::never())->method('enableAuthenticator');
        QUI::getSession()->set('auth-primary', 0);

        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(401);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testMailSetupCannotReplaceAnExistingSecondFactor(): void
    {
        $User = $this->prepareMailSetup(0);
        $User->method('getAuthenticators')->willReturn([new VerifiedMail2FA($User)]);
        $User->expects(self::never())->method('enableAuthenticator');

        $this->expectException(QUI\Permissions\Exception::class);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testOptionalMfaDoesNotPermitSetupFromAPartialSession(): void
    {
        $this->prepareMailSetup(0, 2);
        $this->expectException(QUI\Permissions\Exception::class);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testMailSetupRechecksRevokedPermission(): void
    {
        $User = $this->prepareMailSetup();
        $User->expects(self::never())->method('enableAuthenticator');
        VerifiedMail2FA::sendEnableMailToSessionUser();
        $code = QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE);

        $Permissions = $this->createMock(PermissionManager::class);
        $Permissions->method('getPermissions')->willReturn([]);
        QUI::$Rights = $Permissions;

        $this->expectException(QUI\Permissions\Exception::class);
        VerifiedMail2FA::verifyMailByUser($code);
    }

    public function testCrossOriginMailSetupIsRejected(): void
    {
        $this->prepareMailSetup(0);
        $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(403);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testMailSetupRequiresAGloballyConfiguredAuthenticator(): void
    {
        $this->prepareMailSetup(0);
        $Handler = $this->createMock(Handler::class);
        $Handler->method('getGlobalFrontendSecondaryAuthenticators')->willReturn([]);
        $Handler->method('getGlobalBackendSecondaryAuthenticators')->willReturn([]);
        (new ReflectionProperty(Handler::class, 'Instance'))->setValue(null, $Handler);

        $this->expectException(QUI\Permissions\Exception::class);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testMailActivationRequiresPreviouslyVerifiedAddress(): void
    {
        $User = $this->createConcreteUser('setup-user', false);
        $this->prepareMailSetup(0, 1, $User);
        $User->expects(self::never())->method('enableAuthenticator');
        $User->expects(self::never())->method('setStatusToVerifiableAttribute');

        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(403);
        VerifiedMail2FA::enableByUser();
    }

    public function testVerifiedMailCanBeActivatedInASeparateStep(): void
    {
        $User = $this->prepareMailSetup(0);
        $User->expects(self::once())->method('enableAuthenticator')->with(VerifiedMail2FA::class);
        $User->expects(self::never())->method('setStatusToVerifiableAttribute');

        self::assertTrue(VerifiedMail2FA::enableByUser());
        self::assertSame(0, QUI::getSession()->get('auth-secondary'));
    }

    public function testSessionFromBeforeMandatoryMfaRequestsFreshLoginInsteadOfPermissionDenial(): void
    {
        $User = $this->prepareMailSetup(0);
        $User->expects(self::never())->method('enableAuthenticator');
        QUI::getSession()->set('auth', 1);

        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(401);
        VerifiedMail2FA::enableByUser();
    }

    public function testUnverifiedAccountMustCompleteVerificationBeforeExplicitActivation(): void
    {
        $verified = false;
        $activations = 0;
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('setup-user');
        $User->method('getAttribute')->with('email')->willReturn('user@example.test');
        $User->method('isAttributeVerified')->willReturnCallback(static function () use (&$verified): bool {
            return $verified;
        });
        $User->method('setStatusToVerifiableAttribute')->willReturnCallback(static function () use (&$verified): void {
            $verified = true;
        });
        $User->method('enableAuthenticator')->willReturnCallback(static function () use (&$activations): void {
            $activations++;
        });
        $this->prepareMailSetup(1, 1, $User);

        try {
            VerifiedMail2FA::enableByUser();
            self::fail('Mail 2FA must not activate before address verification.');
        } catch (QUI\Users\Exception $Exception) {
            self::assertSame(403, $Exception->getCode());
        }

        VerifiedMail2FA::sendEnableMailToSessionUser();
        $code = QUI::getSession()->get(VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE);
        self::assertTrue(VerifiedMail2FA::verifyMailByUser($code));
        self::assertTrue($verified);
        self::assertSame(0, $activations);
        self::assertTrue(VerifiedMail2FA::enableByUser());
        self::assertSame(1, $activations);
        self::assertSame(0, QUI::getSession()->get('auth'));
        self::assertSame(0, QUI::getSession()->get('auth-secondary'));
    }

    public function testBackendActivationPopupOffersMailVerification(): void
    {
        if (!defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        $User = $this->createMock(User::class);
        $User->method('getAttribute')->with('email')->willReturn('user@example.test');
        $User->method('isAttributeVerified')->willReturn(false);
        $this->configureVerifiedMailDependencies($User, 0);

        $Settings = new Controls\Settings\VerifiedMail2FA([
            'user' => $User,
            'activationMode' => true
        ]);

        $html = $Settings->getBody();
        self::assertStringContainsString('data-name="send-code"', $html);
        self::assertStringContainsString('data-name="quiqqer-mail2fa-auth-settings-code"', $html);
        self::assertStringContainsString('data-name="enable"', $html);
    }

    private function prepareMailSetup(
        int $expectedMails = 1,
        int $secondaryLoginType = 1,
        ?User $User = null
    ): User {
        $User ??= $this->createConcreteUser('setup-user');
        $this->configureVerifiedMailDependencies($User, $expectedMails, false);
        QUI::$Session = $this->createSession('setup-user');
        QUI::getSession()->set('auth', 0);
        QUI::getSession()->set('auth-secondary', 0);

        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(
            static function (string $section, ?string $key = null) use ($secondaryLoginType): mixed {
                return $section === 'auth_settings' ? $secondaryLoginType : false;
            }
        );
        QUI::$Conf = $Config;

        return $User;
    }

    private function createConcreteUser(string $uuid, bool $verified = true): User
    {
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn($uuid);
        $User->method('getAttribute')->with('email')->willReturn('user@example.test');
        $User->method('isAttributeVerified')
            ->with('user@example.test', MailAttribute::class)
            ->willReturn($verified);

        return $User;
    }

    private function createSession(string $uuid): Session
    {
        $Session = new Session();
        $Session->set('uid', $uuid);
        $Session->set('auth-primary', 1);
        $Session->set('auth-secondary', 1);
        $Session->set('auth', 1);

        return $Session;
    }

    private function configureVerifiedMailDependencies(
        User $User,
        int $expectedMails,
        bool $fullyAuthenticated = true
    ): void {
        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->with($User->getUUID())->willReturn($User);
        $Users->method('getUserBySession')->willReturn($User);
        $Users->method('isNobodyUser')->willReturn(!$fullyAuthenticated);
        $Users->method('isUser')->willReturn(true);
        QUI::$Users = $Users;

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getGlobalFrontendSecondaryAuthenticators')->willReturn([VerifiedMail2FA::class]);
        $Handler->method('getGlobalBackendSecondaryAuthenticators')->willReturn([VerifiedMail2FA::class]);
        (new ReflectionProperty(Handler::class, 'Instance'))->setValue(null, $Handler);

        $Permissions = $this->createMock(PermissionManager::class);
        $Permissions->method('getPermissions')->willReturn(['quiqqer.auth.QUIUsersAuthVerifiedMail2FA' => true]);
        QUI::$Rights = $Permissions;

        $Mailer = $this->createMock(MailManager::class);
        $Mailer->expects(self::exactly($expectedMails))->method('send');
        QUI::$MailManager = $Mailer;

        $Locale = $this->createMock(Locale::class);
        $Locale->method('get')->willReturn('translated mail text');
        QUI::$Locale = $Locale;
    }
}
