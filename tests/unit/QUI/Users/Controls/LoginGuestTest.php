<?php

declare(strict_types=1);

namespace QUITests\QUI\Users\Controls;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Users\Controls\Login;
use QUI\Users\Nobody;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginGuestTest extends TestCase
{
    public function testNobodyReceivesLoginFormInsteadOfAlreadyLoggedInMessage(): void
    {
        $this->assertLoginFormForGuest(QUI::getUsers()->getNobody());
    }

    public function testNobodySubclassReceivesLoginFormEvenWithItsOwnSessionUuid(): void
    {
        $Guest = new class extends Nobody {
            public function getUUID(): string | int
            {
                return 'phpunit-guest-session';
            }
        };

        $this->assertLoginFormForGuest($Guest);
    }

    public function testAuthenticatedSessionStillReceivesAlreadyLoggedInMessage(): void
    {
        $User = $this->createStub(QUI\Interfaces\Users\User::class);
        $User->method('getUUID')->willReturn('phpunit-authenticated-session');
        $User->method('getName')->willReturn('Signed in customer');
        $Control = $this->getMockBuilder(Login::class)
            ->onlyMethods(['next'])
            ->getMock();
        $Control->expects(self::never())->method('next');
        $body = QUI::getUsers()->withSessionUser($User, static fn (): string => $Control->getBody());

        self::assertStringContainsString('q-message-information', $body);
        self::assertStringContainsString('Signed in customer', $body);
    }

    public function testAuthenticatedUsernameCannotInjectHtmlIntoStatusMessage(): void
    {
        $User = $this->createStub(QUI\Interfaces\Users\User::class);
        $User->method('getUUID')->willReturn('phpunit-authenticated-session');
        $User->method('getName')->willReturn('<img src=x onerror=alert(1)>');
        $Control = new Login();

        $body = QUI::getUsers()->withSessionUser($User, static fn (): string => $Control->getBody());

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
        self::assertStringNotContainsString('<img', $body);
    }

    private function assertLoginFormForGuest(Nobody $Guest): void
    {
        $Login = $this->getMockBuilder(Login::class)
            ->onlyMethods(['next'])
            ->getMock();
        $Login->expects(self::once())->method('next')->willReturn([QUI\Users\Auth\QUIQQER::class]);
        $body = QUI::getUsers()->withSessionUser($Guest, static fn (): string => $Login->getBody());

        self::assertStringContainsString('name="username"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringNotContainsString('q-message-information', $body);
    }
}
