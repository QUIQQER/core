<?php

namespace QUI\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use QUI;
use QUI\Groups\Group;
use QUI\Users\User;

require_once __DIR__ . '/SqlitePermissionTestCase.php';
require_once __DIR__ . '/SqliteAccessibleManager.php';

class PermissionGrantTest extends SqlitePermissionTestCase
{
    #[DataProvider('booleanGrants')]
    public function testBooleanAccessUsesAnyPersistedGrant(
        bool|int|string|null $userValue,
        array $groupValues,
        bool $expected
    ): void {
        $permission = 'quiqqer.projects.sites.view';
        $this->createPermissionSchema();
        $this->Connection->insert(Manager::table(), [
            'name' => $permission,
            'type' => 'bool',
            'area' => 'global',
            'defaultvalue' => '0'
        ]);

        $userPermissions = $userValue === null ? [] : [$permission => $userValue];
        $this->Connection->insert(Manager::table() . '2users', [
            'user_id' => 'grant-test-user',
            'permissions' => json_encode($userPermissions)
        ]);

        $groups = [];

        foreach ($groupValues as $index => $value) {
            $uuid = 'grant-test-group-' . $index;
            $this->Connection->insert(Manager::table() . '2groups', [
                'group_id' => $uuid,
                'permissions' => json_encode($value === null ? [] : [$permission => $value])
            ]);
            $Group = $this->createMock(Group::class);
            $Group->method('getUUID')->willReturn($uuid);
            $groups[] = $Group;
        }

        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUUID', 'getGroups', 'isSU', 'getId', 'getName'])
            ->getMock();
        $User->method('getUUID')->willReturn('grant-test-user');
        $User->method('getId')->willReturn(123);
        $User->method('getName')->willReturn('Grant test');
        $User->method('isSU')->willReturn(false);
        $User->method('getGroups')->willReturn($groups);
        $Manager = new SqliteAccessibleManager();
        QUI::$Rights = $Manager;

        // Direct stored values remain separate from effective access.
        self::assertSame($userPermissions, $Manager->getUserPermissionData($User));
        self::assertSame($expected, $User->getPermission($permission));
        self::assertSame($expected, (bool)Permission::hasPermission($permission, $User));
        self::assertFalse(Permission::hasPermission('quiqqer.admin', $User));

        if ($expected) {
            self::assertTrue((bool)Permission::checkPermission($permission, $User));
        } else {
            $this->expectException(Exception::class);
            $this->expectExceptionCode(403);
            Permission::checkPermission($permission, $User);
        }
    }

    public static function booleanGrants(): array
    {
        return [
            'direct grant' => [true, [false], true],
            'group grant despite user false' => [false, [true], true],
            'later group grants' => [false, [false, true], true],
            'earlier group grants' => [false, [true, false], true],
            'no direct setting' => [null, [true], true],
            'stored zero' => [0, [true], true],
            'stored string zero' => ['0', ['1'], true],
            'direct string grant' => ['1', ['0'], true],
            'all false' => [false, [false, false], false],
            'all string zero' => ['0', ['0'], false],
            'no groups' => [false, [], false],
            'no assignments' => [null, [null], false]
        ];
    }

    public function testIntegerLimitsKeepDirectValuesAndExplicitAggregation(): void
    {
        $permission = 'test.request.limit';
        $this->createPermissionSchema();
        $this->Connection->insert(Manager::table(), [
            'name' => $permission,
            'type' => 'int',
            'area' => 'global',
            'defaultvalue' => '0'
        ]);
        $this->Connection->insert(Manager::table() . '2users', [
            'user_id' => 'limit-test-user',
            'permissions' => json_encode([$permission => 0])
        ]);
        $Group = $this->createMock(Group::class);
        $Group->method('hasPermission')->with($permission)->willReturn(100);
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('limit-test-user');
        $User->method('getGroups')->willReturn([$Group]);
        $User->method('hasPermission')->with($permission)->willReturn('0');
        $Manager = new SqliteAccessibleManager();
        QUI::$Rights = $Manager;

        self::assertSame(0, $Manager->getUserPermission($User, $permission));
        self::assertSame(100, $Manager->getUserPermission($User, $permission, 'maxInteger'));
        self::assertSame(0, $Manager->getUserPermission($User, $permission, 'minInteger'));
    }
}
