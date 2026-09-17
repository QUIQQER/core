<?php

namespace QUI;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Users\Auth\VerifiedMail2FA;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session as SymfonySession;

class SessionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSqliteDatabaseSessionsPersistInFilesWithoutBlockingDatabaseTransactions(): void
    {
        $ConnectionProperty = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $previousConnection = $ConnectionProperty->getValue();
        $previousConfig = QUI::$Conf;
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $ConnectionProperty->setValue(null, $Connection);
        $Config = clone $previousConfig;
        $Config->setValue('session', 'type', 'database');
        $Config->setValue('session', 'name', 'QUIQQERSQLITETEST');
        QUI::$Conf = $Config;
        $file = null;

        try {
            Session::setup();
            $Session = new Session();
            $file = VAR_DIR . 'sessions/sess_' . $Session->getId();
            $Session->set('sqlite-session-test', 'persisted value');

            // The application must still be able to use DBAL transactions while the session is open.
            $Connection->transactional(static function () use ($Connection): void {
                $Connection->insert(QUI::getDBTableName('sessions'), [
                    'session_id' => 'database-write-test',
                    'session_value' => 'application data',
                    'session_time' => time(),
                    'session_lifetime' => 300
                ]);
            });
            self::assertSame('application data', $Connection->createQueryBuilder()
                ->select('session_value')
                ->from(QUI::getDBTableName('sessions'))
                ->where('session_id = :id')
                ->setParameter('id', 'database-write-test')
                ->executeQuery()->fetchOne());
            $SymfonySession = $Session->getSymfonySession();
            self::assertInstanceOf(SymfonySession::class, $SymfonySession);
            $SymfonySession->save();

            self::assertFileExists($file);
            self::assertStringContainsString('persisted value', file_get_contents($file));
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
            QUI::$Conf = $previousConfig;
            $ConnectionProperty->setValue(null, $previousConnection);
            $Connection->close();
        }
    }

    public function testRegenerateInvalidatesPreviousSession(): void
    {
        $SymfonySession = $this->createMock(SymfonySession::class);
        $SymfonySession->expects(self::once())
            ->method('migrate')
            ->with(true)
            ->willReturn(true);

        $Session = (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        $SessionProperty = new ReflectionProperty(Session::class, 'Session');
        $SessionProperty->setValue($Session, $SymfonySession);

        self::assertTrue($Session->regenerate());
    }

    #[DataProvider('protectedClientSessionKeyProvider')]
    public function testProtectedClientSessionKeysAreRejected(string $key): void
    {
        self::assertFalse(Session::isClientSessionKeyAllowed($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedClientSessionKeyProvider(): iterable
    {
        yield 'user id' => ['uid'];
        yield 'authentication root' => ['auth'];
        yield 'primary authentication' => ['auth-primary'];
        yield 'authentication prefix' => ['authentication-state'];
        yield 'authentication in progress' => ['inAuthentication'];
        yield 'security hash' => ['secHash'];
        yield 'master login user' => ['session_master_user_id'];
        yield 'master login state' => ['session_master_state'];
        yield 'master login log' => ['session_log_id'];
        yield 'master login log state' => ['session_log_state'];
        yield 'mail MFA login code' => [VerifiedMail2FA::USER_CODE_ATTRIBUTE];
        yield 'mail MFA verification code' => [VerifiedMail2FA::USER_CODE_VERIFYING_ATTRIBUTE];
    }

    #[DataProvider('allowedClientSessionKeyProvider')]
    public function testRegularClientSessionKeysRemainAllowed(string $key): void
    {
        self::assertTrue(Session::isClientSessionKeyAllowed($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedClientSessionKeyProvider(): iterable
    {
        yield 'product view' => ['productView'];
        yield 'product price visibility' => ['QUIQQER_PRODUCTS_HIDE_PRICE'];
        yield 'ERP B2B status' => ['quiqqer.erp.b2b.status'];
        yield 'user language' => ['quiqqer-user-language'];
        yield 'package state' => ['package.custom.state'];
    }
}
