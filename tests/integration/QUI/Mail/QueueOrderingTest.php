<?php

namespace QUI\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use ReflectionProperty;

class QueueOrderingTest extends TestCase
{
    private Connection $previousConnection;
    private Connection $Connection;
    private bool $previousMailSendingDisabled;

    protected function setUp(): void
    {
        $this->previousConnection = QUI::getDataBaseConnection();
        $this->Connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->Connection);
        $this->previousMailSendingDisabled = Mailer::$DISABLE_MAIL_SENDING;
        Mailer::$DISABLE_MAIL_SENDING = false;
        Queue::setup();
    }

    protected function tearDown(): void
    {
        Mailer::$DISABLE_MAIL_SENDING = $this->previousMailSendingDisabled;
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->previousConnection);
        $this->Connection->close();
    }

    public function testSendsOldestEligibleMailWithoutCreationDateColumn(): void
    {
        $table = QUI\Utils\Doctrine::quoteIdentifier(Queue::table());

        foreach (
            [
                30 => Queue::STATUS_ERROR,
                20 => Queue::STATUS_ADDED,
                10 => Queue::STATUS_SENT,
                5 => Queue::STATUS_SENDING,
                2 => Queue::STATUS_CANCELED
            ] as $id => $status
        ) {
            $this->Connection->insert($table, [
                'id' => $id,
                'status' => $status
            ]);
        }

        $Queue = new class extends Queue {
            /** @var list<int> */
            public array $sentIds = [];

            protected function sendMail(array $params): bool
            {
                $this->sentIds[] = (int)$params['id'];

                return true;
            }
        };

        self::assertTrue($Queue->send());
        self::assertSame([20], $Queue->sentIds);
        self::assertTrue($Queue->send());
        self::assertSame([20, 30], $Queue->sentIds);
        self::assertTrue($Queue->send());
        self::assertSame([20, 30], $Queue->sentIds);
        self::assertSame(
            [2, 5, 10],
            array_map(
                'intval',
                $this->Connection->createQueryBuilder()
                    ->select('id')
                    ->from($table)
                    ->orderBy('id', 'ASC')
                    ->executeQuery()
                    ->fetchFirstColumn()
            )
        );
    }
}
