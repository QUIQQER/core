<?php

declare(strict_types=1);

namespace QUITests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\Types;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Session;
use QUI\Utils\Text\XML;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class SessionSchemaTest extends TestCase
{
    private Connection $Connection;

    private ?Connection $PreviousConnection;

    private string $tableName;

    protected function setUp(): void
    {
        $Property = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $this->PreviousConnection = $Property->getValue();
        $this->Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $Property->setValue(null, $this->Connection);
        $this->tableName = QUI::getDBTableName('sessions');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->PreviousConnection);
        $this->Connection->close();
    }

    public function testFreshSetupCreatesBinarySessionStorage(): void
    {
        Session::setup();
        $Table = $this->Connection->createSchemaManager()->introspectTable($this->tableName);

        self::assertInstanceOf(BlobType::class, $Table->getColumn('session_value')->getType());
        self::assertSame(255, $Table->getColumn('session_id')->getLength());
        self::assertSame(['session_id'], $Table->getPrimaryKey()?->getColumns());
        $this->assertBinarySessionRoundTrip();
    }

    #[DataProvider('legacyColumnTypes')]
    public function testMigrationExpiresOldSessionsAndPreservesOtherSchema(?string $type): void
    {
        $SchemaManager = $this->Connection->createSchemaManager();
        $Table = new Table($this->tableName);
        $Table->addColumn('session_id', 'string', ['length' => 128, 'default' => '']);

        if ($type !== null) {
            $Table->addColumn('session_value', $type, ['notnull' => false]);
        }

        $Table->addColumn('session_time', 'integer', ['default' => 0]);
        $Table->addColumn('session_lifetime', 'integer', ['default' => 0]);
        $Table->addColumn('custom_flag', 'string', ['length' => 32, 'notnull' => false]);
        $Table->setPrimaryKey(['session_id']);
        $Table->addIndex(['session_time'], 'session_time_index');
        $SchemaManager->createTable($Table);
        $this->Connection->insert($this->tableName, ['session_id' => 'old-session']);
        $Before = $SchemaManager->introspectTable($this->tableName);

        Session::setup();

        $After = $SchemaManager->introspectTable($this->tableName);
        self::assertInstanceOf(BlobType::class, $After->getColumn('session_value')->getType());
        self::assertSame([], $this->Connection->createQueryBuilder()
            ->select('session_id')->from($this->tableName)->executeQuery()->fetchFirstColumn());

        if ($Before->hasColumn('session_value')) {
            $Before->dropColumn('session_value');
        }

        $After->dropColumn('session_value');
        self::assertTrue($SchemaManager->createComparator()->compareTables($Before, $After)->isEmpty());
        $this->assertBinarySessionRoundTrip();
    }

    public function testRepeatedSetupAndXmlImportKeepBinarySessionData(): void
    {
        Session::setup();
        $payload = "\x00\xff\xfe" . str_repeat('binary session', 6000);
        $this->Connection->insert($this->tableName, [
            'session_id' => 'preserved-session',
            'session_value' => $payload,
            'session_time' => time(),
            'session_lifetime' => 3600
        ], ['session_value' => Types::BLOB]);

        $definitions = XML::getDataBaseFromXml(dirname(__DIR__, 3) . '/database.xml');
        $sessions = array_values(array_filter(
            $definitions['globals'],
            static fn (array $table): bool => $table['suffix'] === 'sessions'
        ));
        self::assertCount(1, $sessions);

        for ($run = 0; $run < 2; $run++) {
            Session::setup();
            XML::importDataBase(['globals' => $sessions]);

            self::assertInstanceOf(
                BlobType::class,
                $this->Connection->createSchemaManager()->introspectTable($this->tableName)
                    ->getColumn('session_value')->getType()
            );
            self::assertSame($payload, $this->Connection->createQueryBuilder()
                ->select('session_value')->from($this->tableName)
                ->where('session_id = :id')->setParameter('id', 'preserved-session')
                ->executeQuery()->fetchOne());
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function legacyColumnTypes(): iterable
    {
        yield 'legacy text column' => ['text'];
        yield 'interrupted migration with missing column' => [null];
    }

    private function assertBinarySessionRoundTrip(): void
    {
        $PDO = $this->Connection->getNativeConnection();
        self::assertInstanceOf(PDO::class, $PDO);
        $Handler = new PdoSessionHandler($PDO, [
            'db_table' => $this->tableName,
            'db_id_col' => 'session_id',
            'db_data_col' => 'session_value',
            'db_time_col' => 'session_time',
            'db_lifetime_col' => 'session_lifetime',
            'lock_mode' => PdoSessionHandler::LOCK_NONE
        ]);
        $payload = "binary|s:3:\"\x00\xff\xfe\";" . str_repeat('x', 70000);

        try {
            self::assertTrue($Handler->open('', 'schema-test'));
            self::assertTrue($Handler->write('binary-round-trip', $payload));
            Session::setup();
            self::assertSame($payload, $Handler->read('binary-round-trip'));
        } finally {
            $Handler->close();
        }
    }
}
