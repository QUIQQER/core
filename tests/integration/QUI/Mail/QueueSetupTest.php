<?php

namespace QUI\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;
use QUI;
use ReflectionProperty;

class QueueSetupTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Mailer::$DISABLE_MAIL_SENDING = $this->previousMailSendingDisabled;
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->previousConnection);
        $this->Connection->close();
    }

    public function testSetupUpgradesExistingQueueWithoutLosingDataOrCustomSchema(): void
    {
        $SchemaManager = $this->Connection->createSchemaManager();
        $LegacyTable = new Table(Queue::table());
        $LegacyTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $LegacyTable->addColumn('subject', 'string', ['length' => 200]);
        $LegacyTable->addColumn('body', 'text', ['notnull' => false]);
        $LegacyTable->addColumn('custom_metadata', 'string', ['notnull' => false]);
        $LegacyTable->setPrimaryKey(['id']);
        $LegacyTable->addIndex(['subject'], 'queue_subject');
        $SchemaManager->createTable($LegacyTable);
        $table = QUI\Utils\Doctrine::quoteIdentifier(Queue::table());
        $this->Connection->insert($table, [
            'id' => 17,
            'subject' => 'Existing queued mail',
            'body' => 'Keep this body',
            'custom_metadata' => 'Keep this metadata'
        ]);

        Queue::setup();

        $Queue = new class extends Queue {
            /** @var list<int> */
            public array $queuedIds = [];

            public function sendById(int $id): bool
            {
                $this->queuedIds[] = $id;

                return true;
            }
        };

        // Exercise the cron's read path while replacing actual mail delivery.
        $Queue->sendAll();
        self::assertSame([17], $Queue->queuedIds);

        $row = $this->Connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = 17');
        self::assertIsArray($row);
        self::assertSame('Existing queued mail', $row['subject']);
        self::assertSame('Keep this body', $row['body']);
        self::assertSame('Keep this metadata', $row['custom_metadata']);
        self::assertSame(Queue::STATUS_ADDED, (int)$row['status']);
        self::assertSame(0, (int)$row['retry']);
        self::assertNull($row['lastsend']);
        self::assertNull($row['errors']);

        $MigratedTable = $SchemaManager->introspectTable(Queue::table());
        self::assertSame(200, $MigratedTable->getColumn('subject')->getLength());
        self::assertTrue($MigratedTable->hasIndex('queue_subject'));
        self::assertTrue($MigratedTable->hasColumn('custom_metadata'));
        self::assertTrue($MigratedTable->getColumn('id')->getAutoincrement());

        Queue::setup();

        self::assertSame($row, $this->Connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = 17'));
        self::assertCount(
            count($MigratedTable->getColumns()),
            $SchemaManager->introspectTable(Queue::table())->getColumns()
        );
    }

    public function testSetupPreservesExistingMailStatusAndRetryHistory(): void
    {
        Queue::setup();
        $table = QUI\Utils\Doctrine::quoteIdentifier(Queue::table());
        $this->Connection->insert($table, [
            'id' => 23,
            'status' => Queue::STATUS_CANCELED,
            'retry' => 7,
            'lastsend' => 1234567890,
            'errors' => 'Previous mail failure'
        ]);
        $SchemaManager = $this->Connection->createSchemaManager();
        $CurrentTable = $SchemaManager->introspectTable(Queue::table());
        $TargetTable = clone $CurrentTable;
        $TargetTable->dropColumn('replyto');
        $Diff = $SchemaManager->createComparator()->compareTables($CurrentTable, $TargetTable);
        $SchemaManager->alterTable($Diff);

        Queue::setup();

        $row = $this->Connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = 23');
        self::assertIsArray($row);
        self::assertSame(Queue::STATUS_CANCELED, (int)$row['status']);
        self::assertSame(7, (int)$row['retry']);
        self::assertSame(1234567890, (int)$row['lastsend']);
        self::assertSame('Previous mail failure', $row['errors']);
        self::assertArrayHasKey('replyto', $row);
        self::assertNull($row['replyto']);
    }
}
