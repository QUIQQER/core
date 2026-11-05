<?php

namespace QUI\Users\Auth;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Ajax;
use QUI\System\Console\Session;
use QUI\Users\AuthenticatorInterface;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class GlobalSettingsActivationTest extends TestCase
{
    private Closure $save;
    private User $User;
    private bool $mailVerified = true;

    protected function setUp(): void
    {
        if (!defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        $this->User = $this->createMock(User::class);
        $this->User->method('isSU')->willReturn(true);
        $this->User->method('getUUID')->willReturn('admin-uuid');
        $this->User->method('getAttribute')->with('email')->willReturn('admin@example.test');
        $this->User->method('isAttributeVerified')->willReturnCallback(fn(): bool => $this->mailVerified);

        $Users = $this->createMock(UserManager::class);
        $Users->method('getUserBySession')->willReturn($this->User);
        QUI::$Users = $Users;

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getAuthenticator')->willReturnCallback(
            static function (string $class, User $User): AuthenticatorInterface {
                return new $class($User);
            }
        );
        (new ReflectionProperty(Handler::class, 'Instance'))->setValue(null, $Handler);

        $Session = new Session();
        $Session->set('uid', 'admin-uuid');
        $Session->set('auth', 1);
        $Session->set('auth-primary', 1);
        QUI::$Session = $Session;
        QUI::$Ajax = new Ajax();
        require dirname(__DIR__, 5) . '/admin/ajax/users/authenticator/save.php';
        $this->save = Ajax::getRegisteredCallables()['ajax_users_authenticator_save']['callable'];
    }

    public static function incompleteSetup(): array
    {
        return [
            'no personal second factor' => [false, false, false],
            'flags without personal second factor' => [false, true, true],
            'configured but not confirmed' => [true, false, false],
            'method flag without completed secondary authentication' => [true, false, true],
            'generic secondary flag without method proof' => [true, true, false]
        ];
    }

    #[DataProvider('incompleteSetup')]
    public function testMandatoryModeCannotBeSavedBeforeOwnSelectedFactorIsConfirmed(
        bool $configured,
        bool $secondaryConfirmed,
        bool $methodConfirmed
    ): void {
        $this->User->method('getAuthenticators')->willReturn(
            $configured ? [new VerifiedMail2FA($this->User)] : []
        );
        QUI::getSession()->set('auth-secondary', $secondaryConfirmed ? 1 : 0);
        QUI::getSession()->set('auth-' . VerifiedMail2FA::class, $methodConfirmed ? 1 : 0);
        $before = file_get_contents(ETC_DIR . 'conf.ini.php');

        try {
            ($this->save)(json_encode($this->settings(), JSON_THROW_ON_ERROR));
            self::fail('Mandatory 2FA was saved without a confirmed personal factor.');
        } catch (QUI\Users\Auth\Exception $Exception) {
            self::assertSame(409, $Exception->getCode());
        }

        self::assertSame($before, file_get_contents(ETC_DIR . 'conf.ini.php'));
        self::assertSame(1, QUI::getSession()->get('auth'));
    }

    public function testConfirmedSelectedFactorAllowsMandatoryModeWithoutLoggingOut(): void
    {
        $this->User->method('getAuthenticators')->willReturn([new VerifiedMail2FA($this->User)]);
        QUI::getSession()->set('auth-secondary', 1);
        QUI::getSession()->set('auth-' . VerifiedMail2FA::class, 1);

        ($this->save)(json_encode($this->settings(), JSON_THROW_ON_ERROR));

        self::assertSame(1, (int)QUI::conf('auth_settings', 'secondary_backend'));
        self::assertSame(1, QUI::getSession()->get('auth'));
        self::assertSame(1, QUI::getSession()->get('auth-secondary'));
    }

    public function testConfirmedMethodMustRemainInTheSelectedMethods(): void
    {
        $this->confirmOwnMailFactor();
        $settings = $this->settings();
        $settings['secondary']['backend'] = [WebAuthn::class];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        ($this->save)(json_encode($settings, JSON_THROW_ON_ERROR));
    }

    public function testUnverifiedMailCannotSatisfyTheActivationRequirement(): void
    {
        $this->confirmOwnMailFactor();
        $this->mailVerified = false;

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        ($this->save)(json_encode($this->settings(), JSON_THROW_ON_ERROR));
    }

    public function testProofFromAnotherAccountCannotSatisfyTheActivationRequirement(): void
    {
        $this->confirmOwnMailFactor();
        QUI::getSession()->set('uid', 'another-account');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        ($this->save)(json_encode($this->settings(), JSON_THROW_ON_ERROR));
    }

    public function testFrontendMandatoryModeAlsoRequiresASelectedConfirmedFactor(): void
    {
        $this->confirmOwnMailFactor();
        $settings = $this->settings();
        $settings['secondary_frontend'] = 1;
        $settings['secondary']['frontend'] = [];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        ($this->save)(json_encode($settings, JSON_THROW_ON_ERROR));
    }

    public function testOptionalModeCanBeSavedBeforePersonalSetup(): void
    {
        $settings = $this->settings();
        $settings['secondary_backend'] = 2;

        ($this->save)(json_encode($settings, JSON_THROW_ON_ERROR));

        self::assertSame(2, (int)QUI::conf('auth_settings', 'secondary_backend'));
    }

    private function settings(): array
    {
        return [
            'primary' => [
                'frontend' => [QUIQQER::class],
                'backend' => [QUIQQER::class]
            ],
            'secondary_frontend' => 0,
            'secondary_backend' => 1,
            'secondary' => [
                'frontend' => [],
                'backend' => [VerifiedMail2FA::class]
            ]
        ];
    }

    private function confirmOwnMailFactor(): void
    {
        $this->User->method('getAuthenticators')->willReturn([new VerifiedMail2FA($this->User)]);
        QUI::getSession()->set('auth-secondary', 1);
        QUI::getSession()->set('auth-' . VerifiedMail2FA::class, 1);
    }
}
