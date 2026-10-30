<?php

namespace QUI\Groups;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use Throwable;

class GroupDeletionTest extends TestCase
{
    private const TEST_PREFIX = 'core-group-deletion-test-';

    private ?Manager $previousManager;

    protected function setUp(): void
    {
        $this->previousManager = QUI::$Groups;

        try {
            QUI::getDataBaseConnection()->executeQuery(
                'SELECT 1 FROM ' . QUI\Utils\Doctrine::quoteIdentifier(Manager::table())
            )->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER database is not available: ' . $Exception->getMessage());
        }

        self::cleanupFixtures();
        QUI::$Groups = new Manager();
    }

    protected function tearDown(): void
    {
        self::cleanupFixtures();
        QUI::$Groups = $this->previousManager;
    }

    public function testDeletionRemovesUuidAndLegacyMembershipsThroughoutTheHierarchy(): void
    {
        $Groups = QUI::getGroups();
        $SystemUser = QUI::getUsers()->getSystemUser();
        $Root = $Groups->get(QUI::conf('globals', 'root'));
        $Parent = $Root->createChild(self::TEST_PREFIX . 'parent', $SystemUser);
        $Child = $Parent->createChild(self::TEST_PREFIX . 'child', $SystemUser);
        $Grandchild = $Child->createChild(self::TEST_PREFIX . 'grandchild', $SystemUser);
        $Unrelated = $Root->createChild(self::TEST_PREFIX . 'unrelated', $SystemUser);
        $similarId = $Parent->getId() . '1';
        $memberships = [
            'uuid' => [
                ',' . $Parent->getUUID() . ',' . $Child->getUUID() . ',' . $Grandchild->getUUID()
                    . ',' . $Unrelated->getUUID() . ',0,1,',
                ',' . $Unrelated->getUUID() . ',0,1,'
            ],
            'numeric' => [
                ',' . $Parent->getId() . ',' . $Child->getId() . ',' . $Grandchild->getId()
                    . ',' . $Unrelated->getId() . ',' . $similarId . ',',
                ',' . $Unrelated->getId() . ',' . $similarId . ','
            ],
            'mixed' => [
                ',' . $Parent->getId() . ',' . $Parent->getUUID() . ',' . $Child->getUUID()
                    . ',' . $Grandchild->getId() . ',' . $Unrelated->getUUID() . ',',
                ',' . $Unrelated->getUUID() . ','
            ],
            'last' => [',' . $Parent->getUUID() . ',', ','],
            'raw-uuid' => [$Child->getUUID(), ''],
            'raw-numeric' => [(string)$Grandchild->getId(), ''],
            'unaffected' => [',0,1,' . $Unrelated->getUUID() . ',', ',0,1,' . $Unrelated->getUUID() . ',']
        ];
        $users = [];

        foreach ($memberships as $name => [$before, $after]) {
            $users[$this->insertUser($name, $before)] = $after;
        }

        // A previous nonrecursive traversal must not hide deeper descendants during deletion.
        self::assertSame([$Child->getUUID()], $Parent->getChildrenIds());

        foreach ([$Parent, $Child, $Grandchild] as $Group) {
            self::assertSame($Group, $Groups->get($Group->getId()));
            self::assertSame($Group->getName(), $Groups->getGroupNameById($Group->getUUID()));
        }

        $Parent->delete();

        foreach ($users as $uuid => $expected) {
            $stored = QUI::getDataBaseConnection()->fetchOne(
                'SELECT usergroup FROM ' . QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table())
                    . ' WHERE uuid = ?',
                [$uuid]
            );

            self::assertSame($expected, $stored);
        }

        foreach ([$Parent, $Child, $Grandchild] as $Group) {
            foreach ([$Group->getId(), $Group->getUUID()] as $id) {
                self::assertSame([], $Groups->getGroupData($id));
                $this->assertGroupNotFound($id);
            }

            $this->assertGroupCacheIsEmpty('quiqqer/groups/group/' . $Group->getUUID());
        }

        self::assertSame($Unrelated->getName(), $Groups->getGroupNameById($Unrelated->getUUID()));
    }

    #[DataProvider('missingIdentifiers')]
    public function testUnknownGroupIdentifiesTheMissingGroup(int | string $id, bool $direct): void
    {
        $this->assertGroupNotFound($id, $direct);
    }

    public static function missingIdentifiers(): array
    {
        $uuid = 'ffffffff-ffff-4fff-8fff-ffffffff1573';

        return [
            'uuid via manager' => [$uuid, false],
            'numeric via manager' => [-1573, false],
            'uuid via constructor' => [$uuid, true],
            'numeric via constructor' => [-1573, true]
        ];
    }

    public function testEmptyIdentifierStillReportsMissingInput(): void
    {
        foreach ([false, true] as $direct) {
            try {
                if ($direct) {
                    new Group('');
                } else {
                    QUI::getGroups()->get('');
                }

                self::fail('An empty group identifier was accepted.');
            } catch (QUI\Exception $Exception) {
                self::assertSame(
                    QUI::getLocale()->get('quiqqer/core', 'exception.lib.qui.manager.no.groupid'),
                    $Exception->getMessage()
                );
            }
        }
    }

    public function testVirtualGroupsStillResolveWithNumericAndStringIdentifiers(): void
    {
        foreach ([0, '0', 1, '1'] as $id) {
            self::assertSame((string)$id, QUI::getGroups()->get($id)->getUUID());
        }
    }

    private function assertGroupNotFound(int | string $id, bool $direct = false): void
    {
        try {
            if ($direct) {
                new Group($id);
            } else {
                QUI::getGroups()->get($id);
            }

            self::fail('A nonexistent group was resolved: ' . $id);
        } catch (QUI\Exception $Exception) {
            self::assertSame(404, $Exception->getCode());
            self::assertSame(
                QUI::getLocale()->get('quiqqer/core', 'exception.lib.qui.group.not.found', [
                    'groupId' => $id
                ]),
                $Exception->getMessage()
            );
            self::assertStringContainsString((string)$id, $Exception->getMessage());
        }
    }

    private function assertGroupCacheIsEmpty(string $key): void
    {
        try {
            QUI\Cache\Manager::get($key);
            self::fail('A deleted group is still cached: ' . $key);
        } catch (QUI\Cache\Exception) {
            self::addToAssertionCount(1);
        }
    }

    private function insertUser(string $name, string $memberships): string
    {
        $Connection = QUI::getDataBaseConnection();
        $table = QUI\Utils\Doctrine::quoteIdentifier(QUI\Users\Manager::table());
        $id = (int)$Connection->fetchOne('SELECT MAX(id) FROM ' . $table) + 1;
        $uuid = QUI\Utils\Uuid::get();

        $Connection->insert($table, [
            'id' => $id,
            'uuid' => $uuid,
            'username' => self::TEST_PREFIX . $name,
            'usergroup' => $memberships
        ]);

        return $uuid;
    }

    private static function cleanupFixtures(): void
    {
        foreach ([Manager::table() => 'name', QUI\Users\Manager::table() => 'username'] as $table => $column) {
            QUI::getDataBaseConnection()->createQueryBuilder()
                ->delete(QUI\Utils\Doctrine::quoteIdentifier($table))
                ->where($column . ' LIKE :prefix')
                ->setParameter('prefix', self::TEST_PREFIX . '%')
                ->executeStatement();
        }

        QUI\Cache\Manager::clearGroupsCache();
    }
}
