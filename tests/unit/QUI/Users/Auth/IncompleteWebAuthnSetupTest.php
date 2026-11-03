<?php

namespace QUI\Users\Auth;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Permissions\Manager as PermissionManager;
use QUI\System\Console\Session;
use QUI\Users\Auth\WebAuthn\CredentialRepository;
use QUI\Users\Auth\WebAuthn\Server;
use QUI\Users\Controls\Login;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class IncompleteWebAuthnSetupTest extends TestCase
{
    public function testEmptyPasskeySetupReturnsToRequiredEnrollment(): void
    {
        $User = $this->configureEnvironment(false);

        self::assertNull((new Login())->next());
        self::assertFalse(QUI\Users\Utils::has2FAAuthenticator($User));
        self::assertTrue((new Server())->authorizeRequiredMfaBootstrap($User));
    }

    public function testPasskeyPopupIncludesTheSessionAccountWithoutAUserPanel(): void
    {
        $Config = QUI::$Conf;
        $User = $this->configureEnvironment(false);
        QUI::$Conf = $Config;
        $Settings = new Controls\Settings\WebAuthn(['user' => $User]);

        self::assertStringContainsString('data-user-uuid="setup-user"', $Settings->getBody());
    }

    public function testExistingPasskeyStillRequiresSecondaryAuthentication(): void
    {
        $User = $this->configureEnvironment(true);

        self::assertSame([WebAuthn::class], (new Login())->next());
        self::assertTrue(QUI\Users\Utils::has2FAAuthenticator($User));
        self::assertFalse((new Server())->authorizeRequiredMfaBootstrap($User));
    }

    public function testEmptyPasskeyCannotBeUsedToMakeMfaMandatory(): void
    {
        $User = $this->configureEnvironment(false);
        QUI::getSession()->set('auth', 1);
        QUI::getSession()->set('auth-secondary', 1);
        QUI::getSession()->set('auth-' . WebAuthn::class, 1);

        $this->expectException(Exception::class);
        Settings::checkRequiredSecondFactors($User, [
            'secondary_backend' => 1,
            'secondary' => [
                'backend' => [WebAuthn::class]
            ]
        ]);
    }

    public function testAnotherExistingFactorStillPreventsEnrollment(): void
    {
        $User = $this->configureEnvironment(false, true);

        self::assertSame([VerifiedMail2FA::class], (new Login())->next());
        self::assertTrue(QUI\Users\Utils::has2FAAuthenticator($User));
        self::assertFalse((new Server())->authorizeRequiredMfaBootstrap($User));
    }

    public function testPartialMailSetupCannotReplaceAnExistingPasskey(): void
    {
        $this->configureEnvironment(true);
        $this->expectException(QUI\Permissions\Exception::class);
        VerifiedMail2FA::sendEnableMailToSessionUser();
    }

    public function testEmptyPasskeySetupStillAllowsMailCodeVerification(): void
    {
        $this->configureEnvironment(false);

        // The absent mail code is rejected after checking eligibility, without a permission error.
        self::assertFalse(VerifiedMail2FA::verifyMailByUser('123456'));
    }

    public function testOptionalMfaDoesNotCountPrimaryAuthenticatorsAsSecondFactors(): void
    {
        $User = $this->configureEnvironment(false);
        $User->method('getAuthenticators')->willReturn([new QUIQQER()]);

        self::assertFalse(QUI\Users\Utils::has2FAAuthenticator($User));
    }

    private function configureEnvironment(bool $hasCredential, bool $hasMail = false): User
    {
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
        $table = CredentialRepository::table();
        $quotedTable = $Connection->quoteIdentifier($table);
        $Connection->executeStatement(
            'CREATE TABLE ' . $quotedTable . ' ('
            . 'id INTEGER PRIMARY KEY, userUuid VARCHAR(36), credentialId TEXT, '
            . 'signCount INTEGER, backupEligible INTEGER, backedUp INTEGER, created INTEGER, lastUsed INTEGER)'
        );

        if ($hasCredential) {
            $Connection->insert($table, [
                'userUuid' => 'setup-user',
                'credentialId' => base64_encode('credential'),
                'created' => time()
            ]);
        }

        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('setup-user');
        $authenticators = [new WebAuthn($User)];

        if ($hasMail) {
            $authenticators[] = new VerifiedMail2FA($User);
        }

        $User->method('getAuthenticators')->willReturn($authenticators);
        $User->method('getGroups')->willReturn([]);

        $Session = new Session();
        $Session->set('uid', 'setup-user');
        $Session->set('auth-primary', 1);
        QUI::$Session = $Session;

        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->willReturn($User);
        $Users->method('isUser')->willReturn(true);
        $Users->method('isNobodyUser')->willReturn(true);
        QUI::$Users = $Users;

        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(static function (string $section, ?string $key = null): mixed {
            if ($section === 'auth_settings') {
                return 1;
            }

            if (in_array($section, ['auth_frontend_secondary', 'auth_backend_secondary'], true)) {
                return [WebAuthn::class => 1, VerifiedMail2FA::class => 1];
            }

            return false;
        });
        QUI::$Conf = $Config;

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getGlobalFrontendSecondaryAuthenticators')
            ->willReturn([WebAuthn::class, VerifiedMail2FA::class]);
        $Handler->method('getGlobalBackendSecondaryAuthenticators')
            ->willReturn([WebAuthn::class, VerifiedMail2FA::class]);
        (new ReflectionProperty(Handler::class, 'Instance'))->setValue(null, $Handler);

        $Permissions = $this->createMock(PermissionManager::class);
        $Permissions->method('getPermissions')->willReturn([
            'quiqqer.auth.QUIUsersAuthWebAuthn' => true,
            'quiqqer.auth.QUIUsersAuthVerifiedMail2FA' => true
        ]);
        QUI::$Rights = $Permissions;

        return $User;
    }
}
