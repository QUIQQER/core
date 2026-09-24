<?php

namespace QUI\Users\Auth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Users\Manager;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class LoginFailureLoggingTest extends TestCase
{
    private Connection $Connection;
    private TestHandler $Logs;

    protected function setUp(): void
    {
        $this->Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->Connection);
        $this->Connection->executeStatement(
            'CREATE TABLE ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::table())
            . ' (id INTEGER PRIMARY KEY, uuid VARCHAR(36), username VARCHAR(255),'
            . ' email VARCHAR(255), password VARCHAR(255))'
        );

        QUI::$Session = new QUI\System\Console\Session();
        QUI::$Users = new Manager();
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        QUI::$Conf->setValue('globals', 'emaillogin', true);

        $this->Logs = new TestHandler();
        QUI\Log\Logger::$Logger = new Logger('login-failure-test', [$this->Logs]);
        QUI\Log\Config::getPackageConfig()->setValue('log_levels', 'error', 1);
        QUI\Log\Config::getPackageConfig()->setValue('log_levels', 'warning', 1);
    }

    public static function missingIdentities(): array
    {
        return [
            'email login' => ['missing@example.invalid', true],
            'email login disabled' => ['missing@example.invalid', false],
            'username' => ['missing-user', true]
        ];
    }

    #[DataProvider('missingIdentities')]
    public function testUnknownIdentityThrowsWithoutErrorLogs(string $username, bool $emailLogin): void
    {
        QUI::$Conf->setValue('globals', 'emaillogin', $emailLogin);
        $Authenticator = new QUIQQER($username);

        try {
            QUI::getUsers()->authenticate($Authenticator, ['username' => $username, 'password' => 'wrong']);
            self::fail('Unknown identities must fail authentication.');
        } catch (QUI\Users\UserAuthException $Exception) {
            self::assertSame(401, $Exception->getCode());
            self::assertSame(Manager::AUTH_ERROR_AUTH_ERROR, $Exception->getAttribute('reason'));
        }

        self::assertFalse($this->Logs->hasErrorRecords());
        $records = $this->Logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('auth', $records[0]->context['filename']);
    }

    public function testDirectAuthenticatorCallerCanCatchMissingUser(): void
    {
        try {
            (new QUIQQER('missing@example.invalid'))->auth('wrong');
            self::fail('The authenticator must throw a user exception.');
        } catch (QUI\Users\Exception $Exception) {
            self::assertSame(404, $Exception->getCode());
        }

        self::assertSame([], $this->Logs->getRecords());
    }

    public static function existingIdentities(): array
    {
        return [
            'email' => ['user@example.invalid', 'test-user', 'user@example.invalid', 'correct-password', true],
            'username fallback' => ['name@example.invalid', 'name@example.invalid', '', 'correct-password', true],
            'wrong password' => ['user@example.invalid', 'test-user', 'user@example.invalid', 'wrong', false]
        ];
    }

    #[DataProvider('existingIdentities')]
    public function testExistingIdentityAuthentication(
        string $identity,
        string $username,
        string $email,
        string $password,
        bool $success
    ): void {
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('test-uuid');
        $User->method('getUsername')->willReturn($username);
        $Users = $this->getMockBuilder(Manager::class)->onlyMethods(['get'])->getMock();
        $Users->method('get')->with('test-uuid')->willReturn($User);
        QUI::$Users = $Users;
        $this->Connection->insert(Manager::table(), [
            'id' => 42,
            'uuid' => 'test-uuid',
            'username' => $username,
            'email' => $email,
            'password' => password_hash('correct-password', PASSWORD_DEFAULT)
        ]);

        try {
            self::assertTrue($Users->authenticate(new QUIQQER($identity), [
                'username' => $identity,
                'password' => $password
            ]));
            self::assertTrue($success);
        } catch (QUI\Users\UserAuthException $Exception) {
            self::assertFalse($success);
            self::assertSame(Manager::AUTH_ERROR_AUTH_ERROR, $Exception->getAttribute('reason'));
        }

        self::assertFalse($this->Logs->hasErrorRecords());
    }

    public static function databaseOperations(): array
    {
        return [['getUserByName'], ['getUserByMail'], ['authenticate'], ['getUser'], ['auth']];
    }

    #[DataProvider('databaseOperations')]
    public function testDatabaseFailuresAreLoggedOnceAndThrownWithoutDetails(string $operation): void
    {
        $this->Connection->createSchemaManager()->dropTable(Manager::table());
        $Authenticator = new QUIQQER('missing@example.invalid');

        if ($operation === 'auth') {
            $User = $this->createMock(User::class);
            $User->method('getUUID')->willReturn('test-uuid');
            (new ReflectionProperty(QUIQQER::class, 'User'))->setValue($Authenticator, $User);
        }

        try {
            match ($operation) {
                'getUserByName' => QUI::getUsers()->getUserByName('missing'),
                'getUserByMail' => QUI::getUsers()->getUserByMail('missing@example.invalid'),
                'authenticate' => QUI::getUsers()->authenticate($Authenticator, [
                    'username' => 'missing@example.invalid', 'password' => 'wrong'
                ]),
                'getUser' => $Authenticator->getUser(),
                'auth' => $Authenticator->auth('wrong')
            };
            self::fail('Database failures must remain catchable.');
        } catch (QUI\Database\Exception $Exception) {
            self::assertSame(500, $Exception->getCode());
            self::assertSame(['quiqqer/core', 'exception.login.fail'], $Exception->getContext()['locale']);
            self::assertStringNotContainsString('SQL', $Exception->getMessage());
            self::assertStringNotContainsString(Manager::table(), $Exception->getMessage());
        }

        $records = $this->Logs->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $records[0]->context['exception']);
    }
}
