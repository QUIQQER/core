<?php

namespace QUI\Workspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Exception;
use QUI\Interfaces\Users\User;
use QUI\Users\Nobody;
use QUI\Utils\Uuid;
use ReflectionProperty;

class ManagerTest extends TestCase
{
    private Connection $Connection;
    private ?Connection $previousConnection;
    private ?QUI\Package\Manager $previousPackageManager;
    private ?QUI\Config $previousCacheConfig;
    private ?\Stash\Pool $previousStash;

    protected function setUp(): void
    {
        $this->previousPackageManager = QUI::$PackageManager;
        $this->previousCacheConfig = CacheManager::$Config;
        $this->previousStash = CacheManager::$Stash;
        $ConnectionProperty = new ReflectionProperty(QUI::class, 'QueryBuilder');
        $this->previousConnection = $ConnectionProperty->getValue();
        $this->Connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);
        $ConnectionProperty->setValue(null, $this->Connection);
        Manager::setup();
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->previousPackageManager;
        CacheManager::$Config = $this->previousCacheConfig;
        CacheManager::$Stash = $this->previousStash;
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $this->previousConnection);
        $this->Connection->close();
    }

    public function testSetup(): void
    {
        $SchemaManager = $this->Connection->createSchemaManager();
        $SchemaManager->dropTable(Manager::table());

        Manager::setup();

        $Table = $SchemaManager->introspectTable(Manager::table());
        $this->assertSame(
            ['id', 'uid', 'title', 'data', 'minHeight', 'minWidth', 'standard'],
            array_map(static fn ($Column) => $Column->getName(), array_values($Table->getColumns()))
        );
        $this->assertSame(['id'], $Table->getPrimaryKey()?->getColumns());
        $this->assertTrue($Table->getColumn('id')->getAutoincrement());
        $this->assertTrue($Table->getColumn('uid')->getNotnull());
        $this->assertSame(50, $Table->getColumn('uid')->getLength());
        $this->assertInstanceOf(TextType::class, $Table->getColumn('data')->getType());

        foreach (['title', 'data', 'minHeight', 'minWidth', 'standard'] as $column) {
            $this->assertFalse($Table->getColumn($column)->getNotnull());
        }

        $User = $this->createUserStub();
        $id = Manager::addWorkspace($User, 'Preserved workspace', '[{"panel":"test"}]', 100, 200);
        $workspace = Manager::getWorkspaceById($id, $User);

        Manager::setup();

        $this->assertSame($workspace, Manager::getWorkspaceById($id, $User));
    }

    public function testSetupMigratesLegacyDataColumnWithoutLosingWorkspaces(): void
    {
        $SchemaManager = $this->Connection->createSchemaManager();
        $Table = $SchemaManager->introspectTable(Manager::table());
        $Table->getColumn('data')->setType(Type::getType('string'));
        $Table->getColumn('data')->setLength(255);
        $SchemaManager->dropTable(Manager::table());
        $SchemaManager->createTable($Table);
        $this->assertNotInstanceOf(
            TextType::class,
            $SchemaManager->introspectTable(Manager::table())->getColumn('data')->getType()
        );
        $User = $this->createUserStub();
        $id = Manager::addWorkspace($User, 'Legacy workspace', '[{"panel":"legacy"}]', 100, 200);
        $workspace = Manager::getWorkspaceById($id, $User);

        Manager::setup();

        $Data = $SchemaManager->introspectTable(Manager::table())->getColumn('data');
        $this->assertInstanceOf(TextType::class, $Data->getType());
        $this->assertFalse($Data->getNotnull());
        $this->assertSame($workspace, Manager::getWorkspaceById($id, $User));
    }

    public function testSetupLeavesTableWithoutDataColumnUnchanged(): void
    {
        $SchemaManager = $this->Connection->createSchemaManager();
        $Table = $SchemaManager->introspectTable(Manager::table());
        $Table->dropColumn('data');
        $SchemaManager->dropTable(Manager::table());
        $SchemaManager->createTable($Table);
        $this->Connection->insert(Manager::table(), ['uid' => Uuid::get(), 'title' => 'Existing workspace']);

        Manager::setup();

        $this->assertFalse($SchemaManager->introspectTable(Manager::table())->hasColumn('data'));
        $this->assertSame(
            ['Existing workspace'],
            $this->Connection->fetchFirstColumn('SELECT title FROM ' . Manager::table())
        );
    }

    public function testCleanup(): void
    {
        $Admin = $this->createConfiguredStub(User::class, ['getUUID' => Uuid::get(), 'isSU' => false]);
        $RegularUser = $this->createConfiguredStub(User::class, ['getUUID' => Uuid::get(), 'isSU' => false]);
        $deletedUserUuid = Uuid::get();
        $previousUsers = QUI::$Users;
        $previousRights = QUI::$Rights;

        try {
            $Users = $this->createMock(QUI\Users\Manager::class);
            $Users->method('isSystemUser')->willReturn(false);
            $Users->method('get')->willReturnCallback(
                static function (string $uuid) use ($Admin, $RegularUser, $deletedUserUuid): User {
                    return match ($uuid) {
                        $Admin->getUUID() => $Admin,
                        $RegularUser->getUUID() => $RegularUser,
                        $deletedUserUuid => throw new Exception('User not found', 404),
                        default => throw new \LogicException('Unexpected user lookup: ' . $uuid)
                    };
                }
            );
            QUI::$Users = $Users;
            $Rights = $this->createMock(QUI\Permissions\Manager::class);
            $Rights->method('getPermissions')->willReturnMap([
                [$Admin, ['quiqqer.admin' => 1]],
                [$RegularUser, ['quiqqer.admin' => 0]]
            ]);
            QUI::$Rights = $Rights;

            foreach ([$Admin->getUUID(), $RegularUser->getUUID(), $deletedUserUuid] as $uuid) {
                foreach (['First workspace', 'Second workspace'] as $title) {
                    $this->Connection->insert(Manager::table(), ['uid' => $uuid, 'title' => $title, 'data' => '[]']);
                }
            }

            $adminWorkspaces = $this->Connection->fetchAllAssociative(
                'SELECT * FROM ' . Manager::table() . ' WHERE uid = ? ORDER BY id',
                [$Admin->getUUID()]
            );

            Manager::cleanup();

            $this->assertSame(
                $adminWorkspaces,
                $this->Connection->fetchAllAssociative('SELECT * FROM ' . Manager::table() . ' ORDER BY id')
            );

            Manager::cleanup();

            $this->assertSame(
                $adminWorkspaces,
                $this->Connection->fetchAllAssociative('SELECT * FROM ' . Manager::table() . ' ORDER BY id')
            );
        } finally {
            QUI::$Users = $previousUsers;
            QUI::$Rights = $previousRights;
        }
    }

    public function testGetWorkspacesByUser(): void
    {
        $User = $this->createUserStub();
        $workspaceId = Manager::addWorkspace($User, 'Own workspace', '[]', 100, 200);
        Manager::addWorkspace($this->createUserStub(), 'Other workspace', '[]', 100, 200);

        $workspaces = Manager::getWorkspacesByUser($User);

        $this->assertCount(1, $workspaces);
        $this->assertEquals($workspaceId, $workspaces[0]['id']);
        $this->assertSame(['Own workspace'], Manager::getWorkspacesTitlesByUser($User));
    }

    public function testNobodyHasNoWorkspaceTitles(): void
    {
        $this->assertSame([], Manager::getWorkspacesTitlesByUser(new Nobody()));
    }

    public function testNobodyCannotAddWorkspace(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('User is no administrator user');

        Manager::addWorkspace(new Nobody(), 'Not allowed', '[]', 100, 200);
    }

    public function testGetWorkspaceByInvalidIdAndUserThrowsException(): void
    {
        $sut = new Manager();
        $testUser = $this->createUserStub();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);
        $sut::getWorkspaceById(99999999, $testUser);
    }

    public function testAddAndGetWorkspace(): void
    {
        $sut = new Manager();
        $testUser = $this->createUserStub();
        $testTitle = 'test_title';
        $testData = 'test_data';
        $testMinHeight = 123;
        $testMinWidth = 456;

        $testWorkspaceId = $sut::addWorkspace(
            User: $testUser,
            title: $testTitle,
            data: $testData,
            minHeight: $testMinHeight,
            minWidth: $testMinWidth
        );
        $testWorkspace = $sut::getWorkspaceById($testWorkspaceId, $testUser);

        $this->assertEquals($testUser->getUUID(), $testWorkspace['uid']);
        $this->assertEquals($testTitle, $testWorkspace['title']);
        $this->assertEquals($testData, $testWorkspace['data']);
        $this->assertEquals($testMinHeight, $testWorkspace['minHeight']);
        $this->assertEquals($testMinWidth, $testWorkspace['minWidth']);

        $sut::deleteWorkspace($testWorkspaceId, $testUser);
    }

    public function testSaveWorkspace(): void
    {
        $sut = new Manager();
        $testUser = $this->createUserStub();
        $testWorkspaceId = $sut::addWorkspace(
            User: $testUser,
            title: 'test_title',
            data: 'test_data',
            minHeight: 123,
            minWidth: 456
        );
        $newTitle = 'test_title_new';
        $newData = '[]';
        $newMinHeight = '789';
        $newMinWidth = '444';

        $sut::saveWorkspace($testUser, $testWorkspaceId, [
            'title' => $newTitle,
            'data' => $newData,
            'minHeight' => $newMinHeight,
            'minWidth' => $newMinWidth
        ]);

        $savedWorkspace = $sut::getWorkspaceById($testWorkspaceId, $testUser);
        $this->assertEquals($testUser->getUUID(), $savedWorkspace['uid']);
        $this->assertEquals($newTitle, $savedWorkspace['title']);
        $this->assertEquals($newData, $savedWorkspace['data']);
        $this->assertEquals($newMinHeight, $savedWorkspace['minHeight']);
        $this->assertEquals($newMinWidth, $savedWorkspace['minWidth']);

        $sut::deleteWorkspace($testWorkspaceId, $testUser);
    }

    public function testSaveWorkspaceWithBigData(): void
    {
        $sut = new Manager();
        $testUser = $this->createUserStub();
        $workspaceId = $sut::addWorkspace($testUser, 'Test workspace', '[]', 100, 200);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not save the workspace. Workspace is to big.');
        $sut::saveWorkspace($testUser, $workspaceId, ['data' => json_encode([str_repeat('a', 30000)])]);
    }

    public function testDeleteWorkspace(): void
    {
        $sut = new Manager();
        $testUser = $this->createUserStub();
        $testWorkspaceId = $sut::addWorkspace(
            User: $testUser,
            title: 'test_title',
            data: 'test_data',
            minHeight: 123,
            minWidth: 456
        );
        $sut::deleteWorkspace($testWorkspaceId, $testUser);

        $this->expectException(Exception::class);
        $sut::getWorkspaceById($testWorkspaceId, $testUser);
    }

    public function testSetStandardWorkspace(): void
    {
        // Arrange
        $sut = new Manager();
        $testUser = $this->createUserStub();
        $testWorkspaceToBecomeStandardId = $sut::addWorkspace(
            User: $testUser,
            title: 'test_title_to_become_standard',
            data: '[]',
            minHeight: 123,
            minWidth: 456
        );
        $testWorkspaceId = $sut::addWorkspace(
            User: $testUser,
            title: 'test_title',
            data: '[]',
            minHeight: 123,
            minWidth: 456
        );

        // Act
        $sut::setStandardWorkspace($testUser, $testWorkspaceId);
        $sut::setStandardWorkspace($testUser, $testWorkspaceToBecomeStandardId);

        // Assert
        $testWorkspace = $sut::getWorkspaceById($testWorkspaceId, $testUser);
        $testWorkspaceToBecomeStandard = $sut::getWorkspaceById($testWorkspaceToBecomeStandardId, $testUser);
        $this->assertEquals(1, $testWorkspaceToBecomeStandard['standard']);
        $this->assertEquals(0, $testWorkspace['standard']);

        // Cleanup
        $sut::deleteWorkspace($testWorkspaceId, $testUser);
        $sut::deleteWorkspace($testWorkspaceToBecomeStandardId, $testUser);
    }

    public function testUserCannotReadForeignWorkspace(): void
    {
        $Owner = $this->createUserStub();
        $workspaceId = Manager::addWorkspace($Owner, 'Private workspace', '[]', 100, 200);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        Manager::getWorkspaceById($workspaceId, $this->createUserStub());
    }

    public function testUserCannotSaveForeignWorkspace(): void
    {
        $Owner = $this->createUserStub();
        $workspaceId = Manager::addWorkspace($Owner, 'Private workspace', '[]', 100, 200);

        try {
            Manager::saveWorkspace($this->createUserStub(), $workspaceId, ['title' => 'Changed']);
            $this->fail('Saving another user\'s workspace must fail.');
        } catch (Exception $Exception) {
            $this->assertSame(404, $Exception->getCode());
        }

        $this->assertSame('Private workspace', Manager::getWorkspaceById($workspaceId, $Owner)['title']);
    }

    public function testUserCannotDeleteForeignWorkspace(): void
    {
        $Owner = $this->createUserStub();
        $workspaceId = Manager::addWorkspace($Owner, 'Private workspace', '[]', 100, 200);

        Manager::deleteWorkspace($workspaceId, $this->createUserStub());

        $this->assertSame('Private workspace', Manager::getWorkspaceById($workspaceId, $Owner)['title']);
    }

    public function testSettingStandardWorkspaceDoesNotChangeOtherUsersWorkspaces(): void
    {
        $Owner = $this->createUserStub();
        $OtherUser = $this->createUserStub();
        $workspaceId = Manager::addWorkspace($Owner, 'Own workspace', '[]', 100, 200);
        $otherWorkspaceId = Manager::addWorkspace($OtherUser, 'Other workspace', '[]', 100, 200);
        Manager::setStandardWorkspace($OtherUser, $otherWorkspaceId);

        Manager::saveWorkspace($Owner, $workspaceId, ['standard' => 1]);

        $this->assertEquals(1, Manager::getWorkspaceById($workspaceId, $Owner)['standard']);
        $this->assertEquals(1, Manager::getWorkspaceById($otherWorkspaceId, $OtherUser)['standard']);
    }

    public function testGetAvailablePanels(): void
    {
        $this->preparePanelCache();
        $PackageManager = $this->createMock(QUI\Package\Manager::class);
        $PackageManager->expects($this->once())->method('getPackageXMLFiles')
            ->with('panels.xml')->willReturn([__DIR__ . '/Fixtures/panels.xml']);
        QUI::$PackageManager = $PackageManager;
        $corePanels = QUI\Utils\Text\XML::getPanelsFromXMLFile(SYS_DIR . 'panels.xml');
        $this->assertNotEmpty($corePanels);
        $expected = array_merge($corePanels, [
            [
                'image' => 'fa fa-star',
                'title' => 'Workspace test panel',
                'text' => 'Panel supplied by a package',
                'require' => 'package/workspace-test/Panel'
            ],
            [
                'image' => '',
                'title' => 'Minimal panel',
                'text' => '',
                'require' => 'package/workspace-test/Minimal'
            ]
        ]);

        $this->assertSame($expected, Manager::getAvailablePanels());
        $this->assertSame($expected, CacheManager::get('quiqqer/package/quiqqer/core/available-panels'));
        $this->assertSame($expected, Manager::getAvailablePanels());
    }

    public function testGetAvailablePanelsReturnsCachedEmptyListWithoutScanningPackages(): void
    {
        $this->preparePanelCache();
        $PackageManager = $this->createMock(QUI\Package\Manager::class);
        $PackageManager->expects($this->never())->method('getPackageXMLFiles');
        QUI::$PackageManager = $PackageManager;
        CacheManager::set('quiqqer/package/quiqqer/core/available-panels', []);

        $this->assertSame([], Manager::getAvailablePanels());
    }

    private function preparePanelCache(): void
    {
        CacheManager::$Stash = new \Stash\Pool(new \Stash\Driver\Ephemeral());
        CacheManager::$Config = $this->createConfiguredStub(QUI\Config::class, ['get' => 0]);
    }

    protected function createUserStub(?string $userUuid = null): User
    {
        if (is_null($userUuid)) {
            $userUuid = Uuid::get();
        }

        return $this->createConfiguredStub(User::class, [
            'getUUID' => $userUuid,
            'canUseBackend' => true,
            'isSU' => true
        ]);
    }
}
