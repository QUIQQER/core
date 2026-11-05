<?php

namespace QUI\Users\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class Mail2FAActivationPolicyTest extends TestCase
{
    public static function unverifiedAccounts(): array
    {
        return [
            'new setup' => [false],
            'previously enabled without verification' => [true]
        ];
    }

    #[DataProvider('unverifiedAccounts')]
    public function testEvenSuperusersCannotEnableMail2FAWithoutVerifiedMail(bool $alreadyEnabled): void
    {
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSU', 'getAttribute', 'isAttributeVerified', 'save'])
            ->getMock();
        $User->method('isSU')->willReturn(true);
        $User->method('getAttribute')->with('email')->willReturn('unverified@example.test');
        $User->method('isAttributeVerified')->willReturn(false);
        $User->expects(self::never())->method('save');

        if ($alreadyEnabled) {
            (new ReflectionProperty(User::class, 'authenticator'))->setValue($User, [VerifiedMail2FA::class]);
        }

        $Users = $this->createMock(UserManager::class);
        $Users->method('isUser')->willReturn(true);
        QUI::$Users = $Users;

        $Handler = $this->createMock(Handler::class);
        $Handler->method('getAvailableAuthenticators')->willReturn([VerifiedMail2FA::class]);
        (new ReflectionProperty(Handler::class, 'Instance'))->setValue(null, $Handler);

        $this->expectException(QUI\Users\Exception::class);
        $this->expectExceptionCode(403);
        $User->enableAuthenticator(VerifiedMail2FA::class);
    }
}
