<?php

declare(strict_types=1);

namespace QUITests\QUI\System;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\System\Console;
use QUI\Users\Manager;
use QUI\Users\SystemUser;
use RuntimeException;
use SimpleXMLElement;

use function bin2hex;
use function dirname;
use function preg_match_all;
use function random_bytes;
use function simplexml_load_file;
use function trim;

use const PHP_EOL;

require_once __DIR__ . '/PasswordResetTestConsole.php';

final class ConsolePasswordResetTest extends TestCase
{
    private ?Manager $PreviousUsers;

    protected function setUp(): void
    {
        $this->PreviousUsers = QUI::$Users;
    }

    protected function tearDown(): void
    {
        QUI::$Users = $this->PreviousUsers;
    }

    public function testExistingUserCanBeResetByUsername(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->expects(self::never())->method('get');
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $this->expectPasswordChange($User, $SystemUser);

        QUI::$Users = $Users;
        $generatedPassword = bin2hex(random_bytes(18));
        $Console = new PasswordResetTestConsole(['alice', 'y', 'y'], $generatedPassword);

        self::assertSame(Console::PASSWORD_RESET_EXIT_SUCCESS, $Console->runPasswordReset());

        $output = $Console->output;
        self::assertStringContainsString('alice', $output);
        self::assertStringContainsString($uuid, $output);
        self::assertStringContainsString(
            QUI::getLocale()->get('quiqqer/core', 'console.tool.passwordreset.prompt.identifier') . ' ',
            $output
        );
        self::assertSame(2, preg_match_all('/\(y\/[Nn]\) /', $output));
        self::assertStringContainsString(PHP_EOL . $generatedPassword . PHP_EOL, $output);
        self::assertStringEndsWith($generatedPassword . PHP_EOL, $output);
    }

    public function testLocalePromptsDeclareSafeDefaultAndDoNotInlineThePassword(): void
    {
        foreach (['en', 'de'] as $language) {
            self::assertStringEndsWith(
                '(y/N)',
                $this->getLocaleText($language, 'console.tool.passwordreset.prompt.confirm')
            );
            self::assertStringEndsWith(
                '(y/N)',
                $this->getLocaleText($language, 'console.tool.passwordreset.prompt.confirm2')
            );
            self::assertStringNotContainsString(
                '[password]',
                $this->getLocaleText($language, 'console.tool.passwordreset.success')
            );
            self::assertStringContainsString(
                '--no-interaction',
                $this->getLocaleText($language, 'console.tool.passwordreset.identifier.required')
            );
            self::assertStringContainsString(
                '--password-stdin',
                $this->getLocaleText($language, 'console.tool.passwordreset.password.stdin.required')
            );
        }
    }

    public function testExistingUserCanBeResetByUuid(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('get')->with($uuid)->willReturn($User);
        $Users->expects(self::never())->method('getUserByName');
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $this->expectPasswordChange($User, $SystemUser);

        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            (new PasswordResetTestConsole([$uuid, 'y', 'y']))->runPasswordReset()
        );
    }

    public function testProvidedUsernameSkipsIdentifierPrompt(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->expects(self::never())->method('get');
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $this->expectPasswordChange($User, $SystemUser);

        QUI::$Users = $Users;
        $Console = new PasswordResetTestConsole(['y', 'y']);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            $Console->runPasswordReset('alice')
        );
        self::assertStringNotContainsString('Username or UUID:', $Console->output);
        self::assertSame(2, preg_match_all('/\(y\/[Nn]\) /', $Console->output));
    }

    public function testProvidedUuidSkipsIdentifierPrompt(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('get')->with($uuid)->willReturn($User);
        $Users->expects(self::never())->method('getUserByName');
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $this->expectPasswordChange($User, $SystemUser);

        QUI::$Users = $Users;
        $Console = new PasswordResetTestConsole(['y', 'y']);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            $Console->runPasswordReset($uuid)
        );
        self::assertStringNotContainsString('Username or UUID:', $Console->output);
        self::assertSame(2, preg_match_all('/\(y\/[Nn]\) /', $Console->output));
    }

    public function testNoInteractionSkipsAllPrompts(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $this->expectPasswordChange($User, $SystemUser);

        QUI::$Users = $Users;
        $generatedPassword = bin2hex(random_bytes(18));
        $Console = new PasswordResetTestConsole([], $generatedPassword);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            $Console->runPasswordReset('alice', true)
        );
        self::assertStringNotContainsString('Username or UUID:', $Console->output);
        self::assertStringNotContainsString('(y/N)', $Console->output);
        self::assertStringContainsString(PHP_EOL . $generatedPassword . PHP_EOL, $Console->output);
    }

    public function testNoInteractionRequiresIdentifier(): void
    {
        $Users = $this->createMock(Manager::class);
        $Users->expects(self::never())->method('getUserByName');
        $Users->expects(self::never())->method('get');
        QUI::$Users = $Users;

        $Console = new PasswordResetTestConsole([]);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_CANCELLED,
            $Console->runPasswordReset(null, true)
        );
        self::assertNotEmpty($Console->output);
        self::assertStringNotContainsString('Username or UUID:', $Console->output);
        self::assertStringNotContainsString('(y/N)', $Console->output);
    }

    public function testPasswordStdinSetsExactPasswordWithoutPrintingIt(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        $password = ' ' . bin2hex(random_bytes(18)) . ' ';
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $User->expects(self::once())->method('setPassword')->with(
            self::callback(static fn(string $value): bool => $value === $password),
            $SystemUser
        );

        QUI::$Users = $Users;
        $Console = new PasswordResetTestConsole([], null, $password);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            $Console->runPasswordReset('alice', true, true)
        );
        self::assertStringNotContainsString($password, $Console->output);
        self::assertStringNotContainsString('The new password is:', $Console->output);
        self::assertStringNotContainsString('(y/N)', $Console->output);
    }

    public function testPasswordStdinWorksWithInteractiveConfirmations(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        $password = bin2hex(random_bytes(18));
        [$Users, $User, $SystemUser] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $User->expects(self::once())->method('setPassword')->with(
            self::callback(static fn(string $value): bool => $value === $password),
            $SystemUser
        );

        QUI::$Users = $Users;
        $Console = new PasswordResetTestConsole(['y', 'y'], null, $password);

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_SUCCESS,
            $Console->runPasswordReset('alice', false, true)
        );
        self::assertStringNotContainsString($password, $Console->output);
        self::assertStringNotContainsString('Username or UUID:', $Console->output);
        self::assertSame(2, preg_match_all('/\(y\/[Nn]\) /', $Console->output));
    }

    public function testPasswordStdinRejectsEmptyPassword(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        [$Users, $User] = $this->createUserManager('alice', $uuid);

        $Users->expects(self::once())->method('getUserByName')->with('alice')->willReturn($User);
        $Users->expects(self::never())->method('getSystemUser');
        $User->expects(self::never())->method('setPassword');

        QUI::$Users = $Users;
        $Console = new PasswordResetTestConsole([], null, '');

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_CANCELLED,
            $Console->runPasswordReset('alice', true, true)
        );
        self::assertNotEmpty($Console->output);
        self::assertStringNotContainsString('(y/N)', $Console->output);
    }

    public function testUnknownUserReturnsDocumentedStatus(): void
    {
        $Users = $this->createMock(Manager::class);
        $Users->method('getUserByName')->willThrowException(new QUI\Users\Exception('not found', 404));
        $Users->expects(self::never())->method('getSystemUser');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_USER_NOT_FOUND,
            (new PasswordResetTestConsole(['missing-user']))->runPasswordReset()
        );
    }

    public function testUnknownUuidReturnsDocumentedStatus(): void
    {
        $uuid = '9c506425-4d2f-46bb-8901-8a6dd718a6d1';
        $Users = $this->createMock(Manager::class);
        $Users->method('get')->with($uuid)->willThrowException(new QUI\Users\Exception('not found', 404));
        $Users->expects(self::never())->method('getUserByName');
        $Users->expects(self::never())->method('getSystemUser');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_USER_NOT_FOUND,
            (new PasswordResetTestConsole([$uuid]))->runPasswordReset()
        );
    }

    public function testEmptyIdentifierReturnsCancelledStatus(): void
    {
        $Users = $this->createMock(Manager::class);
        $Users->expects(self::never())->method('getUserByName');
        $Users->expects(self::never())->method('get');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_CANCELLED,
            (new PasswordResetTestConsole(['']))->runPasswordReset()
        );
    }

    public function testDecliningFirstConfirmationDoesNotChangePassword(): void
    {
        [$Users, $User] = $this->createUserManager('alice', '9c506425-4d2f-46bb-8901-8a6dd718a6d1');
        $Users->method('getUserByName')->willReturn($User);
        $Users->expects(self::never())->method('getSystemUser');
        $User->expects(self::never())->method('setPassword');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_CANCELLED,
            (new PasswordResetTestConsole(['alice', 'n']))->runPasswordReset()
        );
    }

    public function testDecliningSecondConfirmationDoesNotChangePassword(): void
    {
        [$Users, $User] = $this->createUserManager('alice', '9c506425-4d2f-46bb-8901-8a6dd718a6d1');
        $Users->method('getUserByName')->willReturn($User);
        $Users->expects(self::never())->method('getSystemUser');
        $User->expects(self::never())->method('setPassword');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_CANCELLED,
            (new PasswordResetTestConsole(['alice', 'y', 'n']))->runPasswordReset()
        );
    }

    public function testUnexpectedLookupFailureReturnsRuntimeStatus(): void
    {
        $Users = $this->createMock(Manager::class);
        $Users->method('getUserByName')->willThrowException(new RuntimeException('lookup failed'));
        $Users->expects(self::never())->method('getSystemUser');
        QUI::$Users = $Users;

        self::assertSame(
            Console::PASSWORD_RESET_EXIT_RUNTIME_FAILURE,
            (new PasswordResetTestConsole(['alice']))->runPasswordReset()
        );
    }

    public function testPasswordUpdateFailureReturnsRuntimeStatusWithoutPasswordInErrorOutput(): void
    {
        [$Users, $User, $SystemUser] = $this->createUserManager(
            'alice',
            '9c506425-4d2f-46bb-8901-8a6dd718a6d1'
        );
        $Users->method('getUserByName')->willReturn($User);
        $Users->method('getSystemUser')->willReturn($SystemUser);
        $User->method('setPassword')->willThrowException(new RuntimeException('update failed'));
        QUI::$Users = $Users;

        $generatedPassword = bin2hex(random_bytes(18));
        $Console = new PasswordResetTestConsole(['alice', 'y', 'y'], $generatedPassword);

        self::assertSame(Console::PASSWORD_RESET_EXIT_RUNTIME_FAILURE, $Console->runPasswordReset());
        self::assertNotEmpty($Console->output);
        self::assertStringNotContainsString($generatedPassword, $Console->output);
    }

    /**
     * @return array{MockObject&Manager, MockObject&User, MockObject&SystemUser}
     */
    private function createUserManager(string $username, string $uuid): array
    {
        $Users = $this->createMock(Manager::class);
        $User = $this->createMock(User::class);
        $SystemUser = $this->createMock(SystemUser::class);

        $User->method('getUsername')->willReturn($username);
        $User->method('getUUID')->willReturn($uuid);

        return [$Users, $User, $SystemUser];
    }

    private function expectPasswordChange(MockObject&User $User, MockObject&SystemUser $SystemUser): void
    {
        $User->expects(self::once())->method('setPassword')->with(
            self::callback(static fn(string $password): bool => $password !== ''),
            $SystemUser
        );
    }

    private function getLocaleText(string $language, string $localeName): string
    {
        $localeFile = dirname(__DIR__, 4) . '/locale/' . $language . '.console.xml';
        $Xml = simplexml_load_file($localeFile);

        self::assertInstanceOf(SimpleXMLElement::class, $Xml);

        $result = $Xml->xpath('//locale[@name="' . $localeName . '"]/' . $language);

        self::assertIsArray($result);
        self::assertCount(1, $result);

        return trim((string)$result[0]);
    }
}
