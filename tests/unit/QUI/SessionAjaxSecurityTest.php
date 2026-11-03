<?php

namespace QUI;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI\Users\Auth\VerifiedMail2FA;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionAjaxSecurityTest extends TestCase
{
    #[DataProvider('mailMfaSessionKeyProvider')]
    public function testSessionAjaxEndpointsRejectMailMfaCodes(string $ajaxFunction, string $key): void
    {
        \QUI::$Ajax = new Ajax();

        require dirname(__DIR__, 3) . '/admin/ajax/session/get.php';
        require dirname(__DIR__, 3) . '/admin/ajax/session/set.php';
        require dirname(__DIR__, 3) . '/admin/ajax/session/remove.php';

        $callable = Ajax::getRegisteredCallables()[$ajaxFunction]['callable'];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(403);

        if ($ajaxFunction === 'ajax_session_set') {
            $callable($key, '"attacker-controlled"');
            return;
        }

        $callable($key);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mailMfaSessionKeyProvider(): iterable
    {
        foreach (
            [
                'login code' => VerifiedMail2FA::USER_CODE_ATTRIBUTE,
                'verification code' => VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE,
                'verification context' => 'quiqqer.security.mail2fa.setup',
                'passkey enrollment' => 'quiqqer.webauthn.enrollment',
                'passkey registration challenge' => 'quiqqer.webauthn.create',
                'passkey login challenge' => 'quiqqer.webauthn.get'
            ] as $keyName => $key
        ) {
            yield 'get rejects ' . $keyName => ['ajax_session_get', $key];
            yield 'set rejects ' . $keyName => ['ajax_session_set', $key];
            yield 'remove rejects ' . $keyName => ['ajax_session_remove', $key];
        }
    }
}
