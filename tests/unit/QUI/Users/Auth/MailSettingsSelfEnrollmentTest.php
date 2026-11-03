<?php

namespace QUI\Users\Auth;

use Closure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Ajax;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MailSettingsSelfEnrollmentTest extends TestCase
{
    private User $Target;
    private Closure $settings;

    protected function setUp(): void
    {
        if (!defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        QUI::getRewrite()->getProject();
        $this->Target = $this->createMock(User::class);
        $this->Target->method('getUUID')->willReturn('target-uuid');
        $this->Target->method('getAttribute')->with('email')->willReturn('unverified@example.test');
        $this->Target->method('isAttributeVerified')->willReturn(false);
        $this->Target->method('isSU')->willReturn(true);
        $this->Target->expects(self::never())->method('enableAuthenticator');
        $this->Target->expects(self::never())->method('save');

        QUI::$Ajax = new Ajax();
        require dirname(__DIR__, 5) . '/admin/ajax/users/authenticator/settings.php';
        $this->settings = Ajax::getRegisteredCallables()['ajax_users_authenticator_settings']['callable'];
    }

    public function testOwnMailVerificationCanOpenBeforeAuthenticatorActivation(): void
    {
        $this->Target->expects(self::never())->method('getAuthenticator');
        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->willReturn($this->Target);
        $Users->method('getUserBySession')->willReturn($this->Target);
        $Users->method('isUser')->willReturn(true);
        QUI::$Users = $Users;

        $html = ($this->settings)('target-uuid', VerifiedMail2FA::class);

        self::assertStringContainsString('data-name="mail-verification"', $html);
        self::assertStringContainsString('data-name="verify-mail"', $html);
        self::assertStringContainsString('data-name="mail-activation" hidden', $html);
    }

    public function testAnotherAccountCannotUseTheSelfVerificationPath(): void
    {
        $Actor = $this->createMock(User::class);
        $Actor->method('getUUID')->willReturn('actor-uuid');
        $this->Target->expects(self::once())->method('getAuthenticator')
            ->with(VerifiedMail2FA::class)
            ->willThrowException(new QUI\Users\Exception('Authenticator not enabled.', 404));
        $Users = $this->createMock(UserManager::class);
        $Users->method('get')->willReturn($this->Target);
        $Users->method('getUserBySession')->willReturn($Actor);
        QUI::$Users = $Users;

        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(404);
        ($this->settings)('target-uuid', VerifiedMail2FA::class);
    }
}
