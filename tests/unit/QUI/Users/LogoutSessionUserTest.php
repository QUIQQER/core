<?php

declare(strict_types=1);

namespace QUITests\QUI\Users;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\System\Console\Session;
use QUI\Users\Manager;
use QUI\Users\User;
use ReflectionProperty;

final class LogoutSessionUserTest extends TestCase
{
    public function testLogoutClearsTheCachedIdentityBeforeResponseTokensAreCreated(): void
    {
        $PreviousUsers = QUI::$Users;
        $PreviousSession = QUI::$Session;
        $PreviousEvents = QUI::$Events;
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUUID'])
            ->getMock();
        $User->method('getUUID')->willReturn('signed-in-user');
        $Nobody = $this->createMock(QUI\Users\Nobody::class);
        $Nobody->method('getUUID')->willReturn('');
        $Users = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkUserSession', 'getNobody'])
            ->getMock();
        $Users->method('checkUserSession')->willThrowException(new QUI\Exception('No session', 401));
        $Users->method('getNobody')->willReturn($Nobody);
        (new ReflectionProperty(Manager::class, 'Session'))->setValue($Users, $User);
        $Session = new Session();
        $Session->set('uid', 'signed-in-user');
        $Session->set('auth', 1);
        $Events = $this->createMock(QUI\Events\Manager::class);
        $Events->method('fireEvent')->willReturnCallback(
            static function (string $name) use ($Users, $Nobody): array {
                if ($name === 'userLogout') {
                    self::assertSame($Nobody, $Users->getUserBySession());
                }

                return [];
            }
        );
        QUI::$Users = $Users;
        QUI::$Session = $Session;
        QUI::$Events = $Events;

        try {
            self::assertSame($User, QUI::getUserBySession());
            $User->logout();

            self::assertFalse($Session->get('uid'));
            self::assertSame('', QUI::getUserBySession()->getUUID());
        } finally {
            QUI::$Users = $PreviousUsers;
            QUI::$Session = $PreviousSession;
            QUI::$Events = $PreviousEvents;
        }
    }

    public function testLoggingOutAnotherUserPreservesTheCurrentSession(): void
    {
        $PreviousUsers = QUI::$Users;
        $PreviousSession = QUI::$Session;
        $PreviousEvents = QUI::$Events;
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUUID'])
            ->getMock();
        $User->method('getUUID')->willReturn('other-user');
        $CurrentUser = $this->createMock(UserInterface::class);
        $CurrentUser->method('getUUID')->willReturn('current-user');
        $Users = $this->createMock(Manager::class);
        $Users->method('getUserBySession')->willReturn($CurrentUser);
        $Users->expects(self::never())->method('resetSessionUser');
        $Session = $this->createMock(QUI\Session::class);
        $Session->expects(self::never())->method('destroy');
        QUI::$Users = $Users;
        QUI::$Session = $Session;
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);

        try {
            $User->logout();
        } finally {
            QUI::$Users = $PreviousUsers;
            QUI::$Session = $PreviousSession;
            QUI::$Events = $PreviousEvents;
        }
    }
}
