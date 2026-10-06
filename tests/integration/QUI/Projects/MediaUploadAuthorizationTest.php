<?php

declare(strict_types=1);

namespace QUI\Projects;

use QUI;
use QUI\Ajax;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Permissions\Manager as PermissionManager;
use QUI\Permissions\Permission;
use QUI\Projects\Media\Folder;
use QUI\Projects\Media\Item;
use QUI\Projects\Media\MobileUploadProvider;
use QUI\Upload\MobileUpload\Document;
use QUI\QDOM;
use QUI\Security\CsrfToken;
use QUI\System\Console\Session as ConsoleSession;
use QUI\Users\Manager as UserManager;
use QUI\Users\User;
use ReflectionProperty;
use Throwable;

final class MediaUploadAuthorizationTest extends ProjectAuthorizationTestCase
{
    private const TEST_PREFIX = 'media-upload-auth-';

    private Ajax $Ajax;
    private Media $Media;
    private Project $Project;
    private User $Root;
    private Folder $TargetFolder;
    private User $User;
    private mixed $previousAjax;
    private mixed $previousManagerSession;
    private mixed $previousMediaPermissions;
    private mixed $previousPermissionUser;
    private mixed $previousSession;
    private ReflectionProperty $managerSessionProperty;
    private ReflectionProperty $mediaPermissionsProperty;
    private ReflectionProperty $permissionUserProperty;
    private string $temporaryFile;
    private array $previousProjectConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfDatabaseIsUnavailable();

        $this->managerSessionProperty = new ReflectionProperty(QUI::getUsers(), 'Session');
        $this->mediaPermissionsProperty = new ReflectionProperty(Media::class, 'mediaPermissions');
        $this->permissionUserProperty = new ReflectionProperty(Permission::class, 'User');
        $this->previousAjax = QUI::$Ajax;
        $this->previousManagerSession = $this->managerSessionProperty->getValue(QUI::getUsers());
        $this->previousMediaPermissions = $this->mediaPermissionsProperty->getValue();
        $this->previousPermissionUser = $this->permissionUserProperty->getValue();
        $this->previousSession = QUI::$Session;
        $this->mediaPermissionsProperty->setValue(null, true);

        $this->Root = $this->getRootUser();
        $this->setActor($this->Root);
        $this->cleanupUsers();

        $this->Project = ProjectTestHelper::getProject();
        $this->previousProjectConfig = $this->Project->getConfig();
        $this->Media = $this->Project->getMedia();
        $this->User = $this->createBackendUploader();
        $this->createMediaFixture();

        $this->Ajax = new Ajax();
        QUI::$Ajax = $this->Ajax;
        require dirname(__DIR__, 4) . '/admin/ajax/media/upload.php';
        require dirname(__DIR__, 4) . '/admin/ajax/media/createMobileUpload.php';
        require dirname(__DIR__, 4) . '/admin/ajax/project/get/config.php';
        require dirname(__DIR__, 4) . '/admin/ajax/media/folder/create.php';
    }

    protected function tearDown(): void
    {
        if (!isset($this->managerSessionProperty)) {
            parent::tearDown();
            return;
        }

        $cleanupFailure = null;

        try {
            $this->cleanupMediaFixtures();
            $this->cleanupUsers();
        } catch (Throwable $Exception) {
            $cleanupFailure = $Exception;
        } finally {
            if (isset($this->previousProjectConfig)) {
                (new ReflectionProperty(Project::class, 'config'))->setValue($this->Project, $this->previousProjectConfig);
            }
            $this->managerSessionProperty->setValue(QUI::getUsers(), $this->previousManagerSession);
            $this->mediaPermissionsProperty->setValue(null, $this->previousMediaPermissions);
            $this->permissionUserProperty->setValue(null, $this->previousPermissionUser);
            QUI::$Session = $this->previousSession;
            QUI::$Ajax = $this->previousAjax;
            parent::tearDown();
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }

    public function testFolderUploadRejectsMissingDestinationPermission(): void
    {
        $this->setTargetUploadPermission($this->Root);
        $this->setActor($this->User);

        $this->expectException(QUI\Permissions\Exception::class);

        $this->TargetFolder->uploadFile($this->temporaryFile, Folder::FILE_OVERWRITE_TRUE, $this->User);
    }

    public function testMobileUploadDefaultsToEnabledAndImportsOnlyOnceWithoutOverwriting(): void
    {
        $this->setTargetUploadPermission($this->User);
        $this->setActor($this->User);
        $config = $this->Project->getConfig();
        unset($config['media_allowQrUpload']);
        (new ReflectionProperty(Project::class, 'config'))->setValue($this->Project, $config);
        $Provider = new MobileUploadProvider();
        $context = $this->mobileContext();
        self::assertStringContainsString($this->Project->getName(), $Provider->authorize($context, $this->User));

        $existing = $this->TargetFolder->uploadFile($this->temporaryFile, Folder::FILE_OVERWRITE_NONE, $this->User);
        $Document = new Document(bin2hex(random_bytes(32)), $this->temporaryFile, basename($this->temporaryFile));
        $Provider->receive($context, $this->User, $Document);
        $Provider->receive($context, $this->User, $Document);
        $children = $this->TargetFolder->getChildren();
        self::assertCount(2, $children);
        self::assertSame('media upload authorization test', file_get_contents($existing->getFullPath()));
        $name = pathinfo($this->temporaryFile, PATHINFO_FILENAME) . '_1.txt';
        $Uploaded = $this->TargetFolder->getChildByName(pathinfo($name, PATHINFO_FILENAME));
        self::assertSame($name, basename($Uploaded->getFullPath()));
        self::assertSame($Document->id, $Uploaded->getAttribute(MobileUploadProvider::RECEIPT)['id']);
        self::assertSame($this->User->getUUID(), $Uploaded->getAttribute('c_user'));

        file_put_contents($this->temporaryFile, 'changed retry');
        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(409);
        $Provider->receive($context, $this->User, $Document);
    }

    public function testMobileUploadFlagAjaxDefaultsToEnabledAndPreservesExplicitDisable(): void
    {
        $this->setActor($this->User);
        $config = $this->Project->getConfig();
        unset($config['media_allowQrUpload']);
        (new ReflectionProperty(Project::class, 'config'))->setValue($this->Project, $config);
        $params = [
            '_csrf' => CsrfToken::get(),
            'project' => $this->Project->getName(),
            'param' => 'media_allowQrUpload'
        ];

        $response = $this->Ajax->callRequestFunction('ajax_project_get_config', $params);
        self::assertArrayNotHasKey('Exception', $response);
        self::assertSame(1, $response['result']);

        $this->setMobileConfig(['media_allowQrUpload' => 0]);
        $response = $this->Ajax->callRequestFunction('ajax_project_get_config', $params);
        self::assertArrayNotHasKey('Exception', $response);
        self::assertSame(0, $response['result']);
    }

    public function testDisablingProjectBlocksExistingMobileUploadAccess(): void
    {
        $this->setTargetUploadPermission($this->User);
        $this->setActor($this->User);
        $Provider = new MobileUploadProvider();
        $Provider->authorize($this->mobileContext(), $this->User);
        $this->setMobileConfig(['media_allowQrUpload' => 0]);

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(403);
        $Provider->receive(
            $this->mobileContext(),
            $this->User,
            new Document(bin2hex(random_bytes(32)), $this->temporaryFile, 'denied.txt')
        );
    }

    public function testMobileUploadRechecksDestinationPermission(): void
    {
        $this->setTargetUploadPermission($this->User);
        $Provider = new MobileUploadProvider();
        $Provider->authorize($this->mobileContext(), $this->User);
        $this->setTargetUploadPermission($this->Root);

        $this->expectException(QUI\Permissions\Exception::class);
        $Provider->receive(
            $this->mobileContext(),
            $this->User,
            new Document(bin2hex(random_bytes(32)), $this->temporaryFile, 'denied.txt')
        );
    }

    public function testMobileUploadUsesDestinationProjectSizeLimit(): void
    {
        $this->setTargetUploadPermission($this->User);
        $this->setMobileConfig(['media_maxUploadFileSize' => 3]);
        $Provider = new MobileUploadProvider();
        self::assertSame(3, $Provider->getLimits($this->mobileContext(), $this->User)['maxBytes']);

        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(413);
        $Provider->receive(
            $this->mobileContext(),
            $this->User,
            new Document(bin2hex(random_bytes(32)), $this->temporaryFile, 'too-large.txt')
        );
    }

    public function testMobileUploadHonorsAllowedFilenamePatterns(): void
    {
        $this->setTargetUploadPermission($this->User);
        QUI::getPermissionManager()->setPermissions($this->User, [
            'quiqqer.upload.allowedEndings' => '*.pdf'
        ], $this->Root);
        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(415);
        (new MobileUploadProvider())->receive(
            $this->mobileContext(),
            $this->User,
            new Document(bin2hex(random_bytes(32)), $this->temporaryFile, 'denied.txt')
        );
    }

    public function testMobileUploadEndpointRequiresPostAndRespectsProjectSwitch(): void
    {
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->setActor($this->User);
        $params = [
            '_csrf' => CsrfToken::get(),
            'project' => $this->Project->getName(),
            'parentid' => $this->TargetFolder->getId()
        ];

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $response = $this->Ajax->callRequestFunction('ajax_media_createMobileUpload', $params);
            self::assertSame(405, $response['Exception']['code']);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $this->setMobileConfig(['media_allowQrUpload' => 0]);
            $response = $this->Ajax->callRequestFunction('ajax_media_createMobileUpload', $params);
            self::assertSame(403, $response['Exception']['code']);
        } finally {
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }
    }

    private function mobileContext(): array
    {
        return ['project' => $this->Project->getName(), 'folderId' => (string)$this->TargetFolder->getId()];
    }

    private function setMobileConfig(array $values): void
    {
        (new ReflectionProperty(Project::class, 'config'))->setValue(
            $this->Project,
            array_merge($this->Project->getConfig(), $values)
        );
    }

    public function testFolderUploadAllowsAuthorizedDestination(): void
    {
        $this->setTargetUploadPermission($this->User);
        $this->setActor($this->User);

        $UploadedFile = $this->TargetFolder->uploadFile(
            $this->temporaryFile,
            Folder::FILE_OVERWRITE_TRUE,
            $this->User
        );

        self::assertSame($this->TargetFolder->getId(), $UploadedFile->getParentId());
        self::assertSame('media upload authorization test', file_get_contents($UploadedFile->getFullPath()));
    }

    public function testAjaxUploadRejectsBeforeCreatingNestedDestination(): void
    {
        $this->setTargetUploadPermission($this->Root);
        $response = $this->callAjaxUpload('nested/restricted.txt');

        self::assertArrayHasKey('Exception', $response);
        self::assertFalse($this->TargetFolder->childWithNameExists('nested'));
    }

    public function testAjaxUploadAllowsAuthorizedNestedDestination(): void
    {
        $this->setTargetUploadPermission($this->User);
        $response = $this->callAjaxUpload('nested/allowed.txt');

        self::assertArrayNotHasKey('Exception', $response, json_encode($response, JSON_PRETTY_PRINT));
        self::assertArrayHasKey('result', $response);

        $NestedFolder = $this->TargetFolder->getChildByName('nested');
        $uploadedName = $response['result']['name'] ?? null;

        self::assertInstanceOf(Folder::class, $NestedFolder);
        self::assertIsString($uploadedName, json_encode($response, JSON_PRETTY_PRINT));
        self::assertTrue($NestedFolder->childWithNameExists($uploadedName));
    }

    public function testAjaxUploadRejectsBeforeCreatingFolderBelowRestrictedChild(): void
    {
        $this->setTargetUploadPermission($this->User);

        $RestrictedFolder = ProjectTestHelper::runAsSystemUser(
            fn (): Folder => $this->TargetFolder->createFolder('restricted')
        );

        $this->setActor($this->Root);
        QUI::getPermissionManager()->setMediaPermissions($RestrictedFolder, [
            'quiqqer.projects.media.upload' => [$this->Root]
        ], $this->Root);

        $response = $this->callAjaxUpload('restricted/unauthorized/rejected.txt');

        $this->assertPermissionDenied($response);
        self::assertFalse($RestrictedFolder->childWithNameExists('unauthorized'));
    }

    public function testCreateFolderRejectsExplicitUserWithoutUploadPermission(): void
    {
        $this->setTargetFolderPermissions($this->Root, $this->User);

        try {
            $this->TargetFolder->createFolder('restricted-upload', $this->User);
            self::fail('Folder was created without the parent upload permission.');
        } catch (QUI\Permissions\Exception $Exception) {
            self::assertSame(403, $Exception->getCode());
        }

        $this->assertFolderWasNotCreated('restricted-upload');
    }

    public function testCreateFolderRejectsExplicitUserWithoutEditPermission(): void
    {
        $this->setTargetFolderPermissions($this->User, $this->Root);

        try {
            $this->TargetFolder->createFolder('restricted-edit', $this->User);
            self::fail('Folder was created without the parent edit permission.');
        } catch (QUI\Permissions\Exception $Exception) {
            self::assertSame(403, $Exception->getCode());
        }

        $this->assertFolderWasNotCreated('restricted-edit');
    }

    public function testLegacyFolderCreateAjaxRejectsRestrictedParent(): void
    {
        $this->setTargetFolderPermissions($this->Root, $this->Root);
        $response = $this->callAjaxFolderCreate('legacy-restricted');

        $this->assertPermissionDenied($response);
        $this->assertFolderWasNotCreated('legacy-restricted');
    }

    public function testLegacyFolderCreateAjaxAllowsAuthorizedParent(): void
    {
        $this->setTargetFolderPermissions($this->User, $this->User);
        $response = $this->callAjaxFolderCreate('legacy-allowed');

        self::assertArrayNotHasKey('Exception', $response, json_encode($response, JSON_PRETTY_PRINT));
        $Folder = $this->TargetFolder->getChildByName('legacy-allowed');

        self::assertInstanceOf(Folder::class, $Folder);
        self::assertSame($this->TargetFolder->getId(), $Folder->getParentId());
        self::assertDirectoryExists(rtrim($this->TargetFolder->getFullPath(), '/') . '/legacy-allowed');
    }

    public function testAjaxOverwriteRejectsMissingTargetEditPermission(): void
    {
        $TargetFile = $this->createOverwriteTarget();
        $this->setTargetUploadPermission($this->User);
        $this->setOverwriteTargetPermissions($TargetFile, $this->Root, $this->User);

        $response = $this->callAjaxUpload(basename($this->temporaryFile));

        $this->assertPermissionDenied($response);
        $this->assertOverwriteTargetIsUnchanged($TargetFile);
    }

    public function testFolderOverwriteRejectsMissingTargetDeletePermission(): void
    {
        $TargetFile = $this->createOverwriteTarget();
        $this->setTargetUploadPermission($this->User);
        $this->setOverwriteTargetPermissions($TargetFile, $this->User, $this->Root);
        $this->setActor($this->User);

        try {
            $this->TargetFolder->uploadFile(
                $this->temporaryFile,
                Folder::FILE_OVERWRITE_TRUE,
                $this->User
            );
            self::fail('Overwriting a protected media file must be rejected.');
        } catch (QUI\Permissions\Exception $Exception) {
            self::assertSame(403, $Exception->getCode());
        }

        $this->assertOverwriteTargetIsUnchanged($TargetFile);
    }

    public function testFolderOverwriteAllowsAuthorizedTarget(): void
    {
        $TargetFile = $this->createOverwriteTarget();
        $this->setTargetUploadPermission($this->User);
        $this->setOverwriteTargetPermissions($TargetFile, $this->User, $this->User);
        $this->setActor($this->User);

        $UploadedFile = $this->TargetFolder->uploadFile(
            $this->temporaryFile,
            Folder::FILE_OVERWRITE_TRUE,
            $this->User
        );

        self::assertNotSame($TargetFile->getId(), $UploadedFile->getId());
        self::assertSame(
            'replacement media content',
            file_get_contents($UploadedFile->getFullPath())
        );
    }

    private function callAjaxUpload(string $relativePath): array
    {
        $File = new QDOM();
        $File->setAttribute('filepath', $this->temporaryFile);
        $File->setAttribute('params', ['filepath' => $relativePath]);
        $this->setActor($this->User);

        return $this->Ajax->callRequestFunction('ajax_media_upload', [
            '_csrf' => CsrfToken::get(),
            'project' => $this->Project->getName(),
            'parentid' => $this->TargetFolder->getId(),
            'File' => $File
        ]);
    }

    private function callAjaxFolderCreate(string $folderName): array
    {
        $this->setActor($this->User);

        return $this->Ajax->callRequestFunction('ajax_media_folder_create', [
            '_csrf' => CsrfToken::get(),
            'project' => $this->Project->getName(),
            'parentid' => $this->TargetFolder->getId(),
            'newfolder' => $folderName
        ]);
    }

    private function createBackendUploader(): User
    {
        $username = self::TEST_PREFIX . bin2hex(random_bytes(5));
        $System = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChildWithAttributes([
            'username' => $username,
            'email' => $username . '@example.invalid'
        ], $System);

        self::assertInstanceOf(User::class, $User);

        QUI::getPermissionManager()->setPermissions($User, [
            'quiqqer.admin' => true
        ], $this->Root);
        $this->grantGlobalMediaUploadPermission($User);
        $User->setPassword(self::TEST_PREFIX . bin2hex(random_bytes(8)), $System);
        $User->activate('', $System);

        return $User;
    }

    private function grantGlobalMediaUploadPermission(User $User): void
    {
        $Connection = QUI::getDataBaseConnection();
        $table = PermissionManager::table() . '2users';
        $permissions = $Connection->createQueryBuilder()
            ->select('permissions')
            ->from(QUI\Utils\Doctrine::quoteIdentifier($table))
            ->where('user_id = :userId')
            ->setParameter('userId', $User->getUUID())
            ->executeQuery()
            ->fetchOne();
        $permissions = is_string($permissions) ? json_decode($permissions, true) : [];

        if (!is_array($permissions)) {
            $permissions = [];
        }

        $permissions['quiqqer.projects.media.upload'] = true;
        $Connection->update($table, [
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)
        ], [
            'user_id' => $User->getUUID()
        ]);
    }

    private function createMediaFixture(): void
    {
        $this->temporaryFile = sys_get_temp_dir() . '/' . self::TEST_PREFIX . bin2hex(random_bytes(5)) . '.txt';
        file_put_contents($this->temporaryFile, 'media upload authorization test');

        ProjectTestHelper::runAsSystemUser(function (): void {
            $this->TargetFolder = $this->Media->firstChild()->createFolder(
                self::TEST_PREFIX . 'target-' . bin2hex(random_bytes(4))
            );
        });
    }

    private function setTargetUploadPermission(User $AllowedUser): void
    {
        $this->setActor($this->Root);
        QUI::getPermissionManager()->setMediaPermissions($this->TargetFolder, [
            'quiqqer.projects.media.upload' => [$AllowedUser]
        ], $this->Root);
    }

    private function setTargetFolderPermissions(User $UploadUser, User $EditUser): void
    {
        $this->setActor($this->Root);
        QUI::getPermissionManager()->setMediaPermissions($this->TargetFolder, [
            'quiqqer.projects.media.upload' => [$UploadUser],
            'quiqqer.projects.media.edit' => [$EditUser]
        ], $this->Root);
    }

    private function createOverwriteTarget(): Item
    {
        file_put_contents($this->temporaryFile, 'protected media content');

        $TargetFile = ProjectTestHelper::runAsSystemUser(function (): Item {
            $TargetFile = $this->TargetFolder->uploadFile($this->temporaryFile);
            $TargetFile->activate();

            return $TargetFile;
        });

        self::assertInstanceOf(Item::class, $TargetFile);
        file_put_contents($this->temporaryFile, 'replacement media content');

        return $TargetFile;
    }

    private function setOverwriteTargetPermissions(Item $TargetFile, User $EditUser, User $DeleteUser): void
    {
        $this->setActor($this->Root);
        QUI::getPermissionManager()->setMediaPermissions($TargetFile, [
            'quiqqer.projects.media.edit' => [$EditUser],
            'quiqqer.projects.media.del' => [$DeleteUser]
        ], $this->Root);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertPermissionDenied(array $response): void
    {
        self::assertArrayHasKey('Exception', $response);
        self::assertSame(403, $response['Exception']['code'] ?? null);
        self::assertSame(QUI\Permissions\Exception::class, $response['Exception']['type'] ?? null);
    }

    private function assertFolderWasNotCreated(string $folderName): void
    {
        self::assertFalse($this->TargetFolder->childWithNameExists($folderName));
        self::assertDirectoryDoesNotExist(rtrim($this->TargetFolder->getFullPath(), '/') . '/' . $folderName);
    }

    private function assertOverwriteTargetIsUnchanged(Item $TargetFile): void
    {
        $StoredFile = $this->Media->get($TargetFile->getId());

        self::assertFalse($StoredFile->isDeleted());
        self::assertTrue($StoredFile->isActive());
        self::assertSame('protected media content', file_get_contents($StoredFile->getFullPath()));
        self::assertSame(
            'replacement media content',
            file_get_contents($this->temporaryFile)
        );
    }

    private function cleanupMediaFixtures(): void
    {
        if (!isset($this->Media)) {
            return;
        }

        ProjectTestHelper::runAsSystemUser(function (): void {
            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $rows = $Connection->createQueryBuilder()
                ->select('id')
                ->from($Platform->quoteSingleIdentifier($this->Media->getTable()))
                ->where($Platform->quoteSingleIdentifier('name') . ' LIKE :prefix')
                ->setParameter('prefix', self::TEST_PREFIX . '%')
                ->executeQuery()
                ->fetchAllAssociative();

            if (isset($this->TargetFolder)) {
                try {
                    $this->Media->get($this->TargetFolder->getId())->delete();
                } catch (QUI\Exception) {
                }
            }

            foreach ($rows as $row) {
                $Connection->delete(PermissionManager::table() . '2media', [
                    'project' => $this->Project->getName(),
                    'id' => (int)$row['id']
                ]);
            }
        });

        if (isset($this->temporaryFile) && file_exists($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    private function cleanupUsers(): void
    {
        $this->setActor(QUI::getUsers()->getSystemUser());
        $Connection = QUI::getDataBaseConnection();
        $permissionTable = PermissionManager::table() . '2users';
        $users = $Connection->createQueryBuilder()
            ->select('uuid')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(UserManager::table()))
            ->where('username LIKE :prefix')
            ->setParameter('prefix', self::TEST_PREFIX . '%')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($users as $user) {
            $uuid = (string)$user['uuid'];
            $Connection->delete($permissionTable, ['user_id' => $uuid]);

            try {
                QUI::getUsers()->get($uuid)->delete(QUI::getUsers()->getSystemUser());
            } catch (QUI\Users\Exception $Exception) {
                if ($Exception->getCode() !== 404) {
                    throw $Exception;
                }
            }
        }
    }

    private function setActor(UserInterface $User): void
    {
        $Session = new ConsoleSession();
        $Session->set('uid', (string)$User->getUUID());
        $Session->set('username', $User->getUsername());
        $Session->set('auth', 1);
        $Session->set('auth-primary', 1);
        $Session->set('auth-secondary', 1);

        QUI::$Session = $Session;
        $this->managerSessionProperty->setValue(QUI::getUsers(), $User);
        $this->permissionUserProperty->setValue(null, null);
    }

    private function getRootUser(): User
    {
        $Root = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));

        self::assertInstanceOf(User::class, $Root);
        self::assertTrue($Root->isSU(), 'The local fixture root user must be an SU.');

        return $Root;
    }

    private function skipIfDatabaseIsUnavailable(): void
    {
        try {
            QUI::getDataBaseConnection()->executeQuery('SELECT 1')->free();
            $this->getRootUser();
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER database fixtures are unavailable: ' . $Exception->getMessage());
        }
    }
}
