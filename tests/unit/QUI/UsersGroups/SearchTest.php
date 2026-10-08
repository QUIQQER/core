<?php

namespace QUI\UsersGroups;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use ReflectionProperty;

class SearchTest extends TestCase
{
    public function testGroupSearchReturnsListsOrCountsWithoutMixingTheirTypes(): void
    {
        $Property = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $OriginalConnection = $Property->getValue();
        $Connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        try {
            $Property->setValue(null, $Connection);
            $table = QUI\Groups\Manager::table();
            $quotedTable = $Connection->getDatabasePlatform()->quoteSingleIdentifier($table);
            $Connection->executeStatement('CREATE TABLE ' . $quotedTable . ' (uuid TEXT, name TEXT)');
            $Connection->insert($table, [
                'uuid' => 'group-alpha',
                'name' => 'Alpha'
            ]);

            $params = [
                'searchUsers' => false,
                'searchGroups' => true,
                'groups' => [
                    'searchFields' => ['name' => true],
                    'select' => ['name' => true]
                ]
            ];

            self::assertSame([
                'users' => [],
                'groups' => [[
                    'name' => 'Alpha',
                    'uuid' => 'group-alpha',
                    'type' => 'group',
                    'id' => 'group-alpha'
                ]]
            ], Search::search('Alpha', $params));
            self::assertSame(['users' => 0, 'groups' => 1], Search::search('Alpha', $params, true));
            self::assertSame(['users' => [], 'groups' => []], Search::search('Missing', $params));
            self::assertSame(['users' => 0, 'groups' => 0], Search::search('Missing', $params, true));
        } finally {
            $Property->setValue(null, $OriginalConnection);
            $Connection->close();
        }
    }
}
