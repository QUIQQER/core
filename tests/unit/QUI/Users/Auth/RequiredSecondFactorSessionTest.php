<?php

namespace QUI\Users\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\System\Console\Session;
use QUI\Users\Manager;
use QUI\Users\User;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RequiredSecondFactorSessionTest extends TestCase
{
    public static function areas(): array
    {
        return ['frontend' => [false], 'backend' => [true]];
    }

    #[DataProvider('areas')]
    public function testMandatory2FARejectsPasswordOnlySessionsEvenForSuperusers(bool $backend): void
    {
        $Manager = $this->configureEnvironment($backend, false);
        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(401);
        $Manager->checkUserSession();
    }

    #[DataProvider('areas')]
    public function testMandatory2FAAcceptsCompletedSecondaryAuthentication(bool $backend): void
    {
        $Manager = $this->configureEnvironment($backend, true);
        $Manager->checkUserSession();
        self::assertSame(1, QUI::getSession()->get('auth-secondary'));
    }

    private function configureEnvironment(bool $backend, bool $secondaryAuthenticated): Manager
    {
        if ($backend && !defined('QUIQQER_BACKEND')) {
            define('QUIQQER_BACKEND', true);
        }

        $Session = new Session();
        $Session->set('uid', 'required-mfa-user');
        $Session->set('auth-primary', 1);
        $Session->set('auth-secondary', $secondaryAuthenticated ? 1 : 0);
        $Session->set('inAuthentication', 1);
        QUI::$Session = $Session;

        $Config = $this->createMock(Config::class);
        $Config->method('get')->willReturnCallback(
            static function (string $section, ?string $key = null) use ($backend): mixed {
                if ($section === 'auth_settings') {
                    return $key === ($backend ? 'secondary_backend' : 'secondary_frontend') ? 1 : 0;
                }

                return false;
            }
        );
        QUI::$Conf = $Config;

        $User = $this->createMock(User::class);
        $User->method('isSU')->willReturn(true);
        $User->method('isActive')->willReturn(true);
        $Manager = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $Manager->method('get')->willReturn($User);

        return $Manager;
    }
}
