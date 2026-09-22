<?php

namespace QUI\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Groups\Group;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Projects\Media;
use QUI\Projects\Media\File;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Users\Nobody;
use QUI\Users\User;
use ReflectionProperty;

class PermissionTest extends TestCase
{
    private ?UserInterface $previousPermissionUser;
    private ?Manager $previousPermissionManager;

    protected function setUp(): void
    {
        $UserProperty = new ReflectionProperty(Permission::class, 'User');

        $this->previousPermissionUser = $UserProperty->getValue();
        $this->previousPermissionManager = QUI::$Rights;
    }

    protected function tearDown(): void
    {
        $UserProperty = new ReflectionProperty(Permission::class, 'User');
        $UserProperty->setValue(null, $this->previousPermissionUser);

        QUI::$Rights = $this->previousPermissionManager;
    }

    public function testCheckAdminUserUsesExplicitUser(): void
    {
        $SessionUser = $this->createUser(false);
        $ExplicitUser = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();

        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $User): array => $User === $ExplicitUser
                ? ['quiqqer.admin' => true]
                : []
        );

        Permission::setUser($SessionUser);
        QUI::$Rights = $Manager;

        Permission::checkAdminUser($ExplicitUser);

        $this->addToAssertionCount(1);
    }

    public function testCheckSUUsesExplicitUser(): void
    {
        $SessionUser = $this->createUser(false);
        $ExplicitUser = $this->createUser(true);

        Permission::setUser($SessionUser);

        Permission::checkSU($ExplicitUser);

        $this->addToAssertionCount(1);
    }

    public function testCheckPermissionReturnsIntegerValue(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([
            'test.permission' => 12
        ]);
        QUI::$Rights = $Manager;

        $this->assertSame(
            12,
            Permission::checkPermission('test.permission', $User)
        );
    }

    public function testHasPermissionReturnsArrayValue(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([
            'test.permission' => ['first', 'second']
        ]);
        QUI::$Rights = $Manager;

        $this->assertSame(
            ['first', 'second'],
            Permission::hasPermission('test.permission', $User)
        );
    }

    public function testCheckPermissionReturnsTrueForSuperUser(): void
    {
        $this->assertTrue(
            Permission::checkPermission(
                'test.permission',
                $this->createUser(true)
            )
        );
    }

    public function testCheckPermissionReturnsGroupPermission(): void
    {
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => $Object === $Group
                ? ['test.permission' => 'from-group']
                : []
        );
        QUI::$Rights = $Manager;

        $this->assertSame(
            'from-group',
            Permission::checkPermission('test.permission', $User)
        );
    }

    public function testCheckPermissionAcceptsGroupGrantDespiteUserFalse(): void
    {
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => $Object === $User
                ? ['test.permission' => false]
                : ['test.permission' => true]
        );
        QUI::$Rights = $Manager;

        $this->assertTrue(Permission::checkPermission('test.permission', $User));
    }

    public function testHasPermissionAcceptsGroupGrantDespiteUserFalse(): void
    {
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => $Object === $User
                ? ['test.permission' => false]
                : ['test.permission' => true]
        );
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::hasPermission('test.permission', $User)
        );
    }

    public function testCheckPermissionFallsBackToGroupsForNobodyDefaults(): void
    {
        $Group = $this->createMock(Group::class);
        $Nobody = $this->createMock(Nobody::class);
        $Nobody->method('getGroups')->willReturn([$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => $Object === $Nobody
                ? ['test.permission' => false]
                : ['test.permission' => true]
        );
        QUI::$Rights = $Manager;

        self::assertTrue(
            Permission::checkPermission('test.permission', $Nobody)
        );
    }

    public function testHasPermissionReturnsFalseWhenPermissionIsDenied(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([]);
        QUI::$Rights = $Manager;

        $this->assertFalse(
            Permission::hasPermission('test.permission', $User)
        );
    }

    public function testIsSUUsesConfiguredPermissionUser(): void
    {
        Permission::setUser($this->createUser(true));

        $this->assertTrue(Permission::isSU());
    }

    public function testIsAdminReturnsFalseForDeniedUser(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([]);
        QUI::$Rights = $Manager;

        $this->assertFalse(Permission::isAdmin($User));
    }

    public function testCheckSURejectsExplicitNonSuperUser(): void
    {
        $this->expectException(Exception::class);

        Permission::checkSU($this->createUser(false));
    }

    public function testExistsPermissionFindsDirectUserPermission(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([
            'test.permission' => false
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::existsPermission('test.permission', $User)
        );
    }

    public function testExistsPermissionFindsGroupPermission(): void
    {
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => $Object === $Group
                ? ['test.permission' => false]
                : []
        );
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::existsPermission('test.permission', $User)
        );
    }

    public function testExistsPermissionReturnsFalseWhenMissing(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([]);
        QUI::$Rights = $Manager;

        $this->assertFalse(
            Permission::existsPermission('test.permission', $User)
        );
    }

    public function testGetPermissionReturnsStoredValue(): void
    {
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissions')->willReturn([
            'test.permission' => 'stored-value'
        ]);
        QUI::$Rights = $Manager;

        $this->assertSame(
            'stored-value',
            Permission::getPermission('test.permission', $User)
        );
    }

    public function testSitePermissionGettersUseManagerValues(): void
    {
        $Site = $this->createMock(Site::class);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            'test.permission' => 'site-value'
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::existsSitePermission('test.permission', $Site)
        );
        $this->assertSame(
            'site-value',
            Permission::getSitePermission($Site, 'test.permission')
        );
        $this->assertFalse(
            Permission::getSitePermission($Site, 'missing.permission')
        );
    }

    public function testCheckPermissionListLegacyAllowsMissingPermission(): void
    {
        $this->assertTrue(
            Permission::checkPermissionList([], 'missing.permission')
        );
    }

    public function testCheckPermissionListAcceptsBooleanPermission(): void
    {
        $this->assertPermissionListAllowed('bool', true);
    }

    public function testCheckPermissionListAcceptsMatchingGroup(): void
    {
        $this->assertPermissionListAllowed('group', 'g12', [12]);
    }

    public function testCheckPermissionListAcceptsMatchingUser(): void
    {
        $this->assertPermissionListAllowed('user', 'user-uuid');
    }

    public function testCheckPermissionListAcceptsUserList(): void
    {
        $this->assertPermissionListAllowed('users', '1,user-uuid');
    }

    public function testCheckPermissionListAcceptsGroupList(): void
    {
        $this->assertPermissionListAllowed('groups', 'g12', [12]);
    }

    public function testCheckPermissionListAcceptsCombinedUserAndGroupList(): void
    {
        $this->assertPermissionListAllowed(
            'users_and_groups',
            'uuser-uuid,g99',
            [12]
        );
    }

    public function testCheckPermissionListRejectsNonMatchingValue(): void
    {
        $this->expectException(Exception::class);

        $this->checkPermissionList('user', 'other-user');
    }

    public function testCheckPermissionListRejectsEmptyValue(): void
    {
        $this->expectException(Exception::class);

        $this->checkPermissionList('bool', false);
    }

    #[DataProvider('sitePermissionProvider')]
    public function testCheckSitePermissionUsesSiteValue(
        string $requestedPermission,
        string $sitePermission
    ): void {
        $Site = $this->createMock(Site::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            $sitePermission => true
        ]);
        $Manager->method('getPermissionData')->willReturn([
            'type' => 'bool'
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::checkSitePermission(
                $requestedPermission,
                $Site,
                $User
            )
        );
    }

    #[DataProvider('sitePermissionProvider')]
    public function testCheckSitePermissionFallsBackToGlobalValue(
        string $requestedPermission,
        string $sitePermission,
        string $globalPermission
    ): void {
        $Site = $this->createMock(Site::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            $sitePermission => false
        ]);
        $Manager->method('getPermissions')->willReturn([
            $globalPermission => true
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::checkSitePermission(
                $requestedPermission,
                $Site,
                $User
            )
        );
    }

    public function testSiteAllowlistStillRestrictsUsersWithGlobalGroupGrant(): void
    {
        $Site = $this->createMock(Site::class);
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            'quiqqer.projects.site.view' => 'uother-user'
        ]);
        $Manager->method('getPermissionData')->willReturn(['type' => 'users_and_groups']);
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => [
                'quiqqer.projects.sites.view' => $Object === $Group
            ]
        );
        QUI::$Rights = $Manager;

        self::assertTrue(Permission::hasPermission('quiqqer.projects.sites.view', $User));
        self::assertFalse(Permission::hasSitePermission('quiqqer.projects.site.view', $Site, $User));
    }

    public function testEmptySiteAclUsesGroupGrantDespiteUserFalse(): void
    {
        $Site = $this->createMock(Site::class);
        $Group = $this->createMock(Group::class);
        $User = $this->createUser(false, groups: [$Group]);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            'quiqqer.projects.site.view' => false
        ]);
        $Manager->method('getPermissions')->willReturnCallback(
            static fn (object $Object): array => [
                'quiqqer.projects.sites.view' => $Object === $Group
            ]
        );
        QUI::$Rights = $Manager;

        self::assertTrue(Permission::checkSitePermission('quiqqer.projects.site.view', $Site, $User));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sitePermissionProvider(): array
    {
        return [
            'view' => [
                'quiqqer.projects.sites.view',
                'quiqqer.projects.site.view',
                'quiqqer.projects.sites.view'
            ],
            'edit' => [
                'quiqqer.projects.sites.edit',
                'quiqqer.projects.site.edit',
                'quiqqer.projects.sites.edit'
            ],
            'delete' => [
                'quiqqer.projects.sites.del',
                'quiqqer.projects.site.del',
                'quiqqer.projects.sites.del'
            ],
            'new' => [
                'quiqqer.projects.sites.new',
                'quiqqer.projects.site.new',
                'quiqqer.projects.sites.new'
            ]
        ];
    }

    #[DataProvider('projectPermissionProvider')]
    public function testCheckProjectPermissionUsesProjectValue(
        string $requestedPermission,
        string $projectPermission
    ): void {
        $Project = $this->createMock(Project::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getProjectPermissions')->willReturn([
            $projectPermission => true
        ]);
        $Manager->method('getPermissionData')->willReturn([
            'type' => 'bool'
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::checkProjectPermission(
                $requestedPermission,
                $Project,
                $User
            )
        );
    }

    #[DataProvider('projectPermissionProvider')]
    public function testCheckProjectPermissionFallsBackToGlobalValue(
        string $requestedPermission,
        string $projectPermission,
        string $globalPermission
    ): void {
        $Project = $this->createMock(Project::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getProjectPermissions')->willReturn([
            $projectPermission => false
        ]);
        $Manager->method('getPermissions')->willReturn([
            $globalPermission => true
        ]);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::checkProjectPermission(
                $requestedPermission,
                $Project,
                $User
            )
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function projectPermissionProvider(): array
    {
        return [
            'edit' => [
                'quiqqer.projects.edit',
                'quiqqer.project.edit',
                'quiqqer.projects.edit'
            ],
            'destroy' => [
                'quiqqer.projects.destroy',
                'quiqqer.project.destroy',
                'quiqqer.projects.destroy'
            ],
            'configuration' => [
                'quiqqer.projects.setconfig',
                'quiqqer.project.setconfig',
                'quiqqer.projects.setconfig'
            ],
            'custom CSS' => [
                'quiqqer.projects.editCustomCSS',
                'quiqqer.project.editCustomCSS',
                'quiqqer.projects.editCustomCSS'
            ],
            'custom JavaScript' => [
                'quiqqer.projects.editCustomJS',
                'quiqqer.project.editCustomJS',
                'quiqqer.projects.editCustomJS'
            ]
        ];
    }

    public function testCheckSitePermissionSupportsCustomPermission(): void
    {
        $Site = $this->createMock(Site::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            'custom.permission' => true
        ]);
        $Manager->method('getPermissionData')->willReturn(['type' => 'bool']);
        QUI::$Rights = $Manager;

        $this->assertTrue(
            Permission::checkSitePermission('custom.permission', $Site, $User)
        );
    }

    public function testHasSitePermissionReturnsFalseForDeniedPermission(): void
    {
        $Site = $this->createMock(Site::class);
        $User = $this->createUser(false);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getSitePermissions')->willReturn([
            'custom.permission' => false
        ]);
        QUI::$Rights = $Manager;

        $this->assertFalse(
            Permission::hasSitePermission('custom.permission', $Site, $User)
        );
    }

    public function testMediaPermissionChecksUseMediaValues(): void
    {
        $MediaPermissions = new ReflectionProperty(Media::class, 'mediaPermissions');
        $previousValue = $MediaPermissions->getValue();
        $MediaPermissions->setValue(null, true);

        try {
            $MediaItem = $this->createMock(File::class);
            $User = $this->createUser(false);
            $Manager = $this->createPermissionManagerMock();
            $Manager->method('getMediaPermissions')->willReturn([
                'media.permission' => true,
                'denied.permission' => false
            ]);
            $Manager->method('getPermissionData')->willReturn(['type' => 'bool']);
            $Manager->method('getPermissions')->willReturn(['denied.permission' => true]);
            QUI::$Rights = $Manager;

            $this->assertTrue(Permission::hasPermission('denied.permission', $User));
            $this->assertTrue(
                Permission::checkMediaPermission(
                    'media.permission',
                    $MediaItem,
                    $User
                )
            );
            $this->assertFalse(
                Permission::hasMediaPermission(
                    'denied.permission',
                    $MediaItem,
                    $User
                )
            );
        } finally {
            $MediaPermissions->setValue(null, $previousValue);
        }
    }

    public function testDisabledMediaPermissionsAlwaysAllowAccess(): void
    {
        $MediaPermissions = new ReflectionProperty(Media::class, 'mediaPermissions');
        $previousValue = $MediaPermissions->getValue();
        $MediaPermissions->setValue(null, false);

        try {
            $MediaItem = $this->createMock(File::class);
            $User = $this->createUser(false);

            $this->assertTrue(
                Permission::hasMediaPermission('media.permission', $MediaItem, $User)
            );
            $this->assertTrue(
                Permission::checkMediaPermission('media.permission', $MediaItem, $User)
            );
        } finally {
            $MediaPermissions->setValue(null, $previousValue);
        }
    }

    /**
     * @param array<int, Group>|array<int, int|string> $groups
     */
    private function createUser(
        bool $isSuperUser,
        int $id = 1,
        string $uuid = 'user-uuid',
        array $groups = []
    ): User&MockObject {
        $User = $this->createMock(User::class);
        $User->method('isSU')->willReturn($isSuperUser);
        $User->method('getId')->willReturn($id);
        $User->method('getUUID')->willReturn($uuid);
        $User->method('getName')->willReturn('Test User');
        $User->method('getGroups')->willReturnCallback(
            static fn (bool $objects = true): array => $objects ? $groups : array_map(
                static fn (Group|int|string $Group): int|string => $Group instanceof Group ? $Group->getUUID() : $Group,
                $groups
            )
        );

        return $User;
    }

    private function createPermissionManagerMock(): Manager&MockObject
    {
        return $this->createMock(Manager::class);
    }

    /**
     * @param array<int, int|string> $groups
     */
    private function assertPermissionListAllowed(
        string $type,
        string|bool $value,
        array $groups = []
    ): void {
        $this->assertTrue(
            $this->checkPermissionList($type, $value, $groups)
        );
    }

    /**
     * @param array<int, int|string> $groups
     */
    private function checkPermissionList(
        string $type,
        string|bool $value,
        array $groups = []
    ): bool {
        $User = $this->createUser(false, groups: $groups);
        $Manager = $this->createPermissionManagerMock();
        $Manager->method('getPermissionData')->willReturn([
            'type' => $type
        ]);
        QUI::$Rights = $Manager;

        return Permission::checkPermissionList(
            ['test.permission' => $value],
            'test.permission',
            $User
        );
    }
}
