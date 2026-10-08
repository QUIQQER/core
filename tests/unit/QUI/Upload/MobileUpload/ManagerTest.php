<?php

declare(strict_types=1);

namespace QUITests\Upload\MobileUpload;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Upload\MobileUpload\Document;
use QUI\Upload\MobileUpload\Devices;
use Doctrine\DBAL\DriverManager;
use QUI\Upload\MobileUpload\Files;
use QUI\Upload\MobileUpload\Manager;
use QUI\Upload\MobileUpload\ProviderInterface;
use QUI\Upload\MobileUpload\Providers;
use QUI\Upload\MobileUpload\Session;
use QUI\Upload\MobileUpload\Store;

require_once __DIR__ . '/UploadProviderFixture.php';
require_once __DIR__ . '/LimitedUploadProviderFixture.php';

final class ManagerTest extends TestCase
{
    private string $directory;
    private Manager $Manager;
    private Store $Store;
    private Devices $Devices;
    private string $deviceToken;
    private int $now;
    private User $Issuer;
    private mixed $previousUsers;
    private mixed $previousProject;
    private int $maxBytes = 1024;
    private int $maxFiles = 0;
    private bool $active = true;
    private array $context = ['reference' => 'Contract-123', 'request' => 'request-1'];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mobile-upload-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);

        $this->previousUsers = QUI::$Users;
        $this->previousProject = QUI\Projects\Manager::$Standard;
        $this->Issuer = $this->createMock(QUI\Users\User::class);
        $this->Issuer->method('getUUID')->willReturn('test-upload-issuer');
        $this->Issuer->method('isSU')->willReturn(true);
        $this->Issuer->method('isActive')->willReturnCallback(fn (): bool => $this->active);
        $this->Issuer->method('getPermission')->willReturnCallback(
            fn (string $permission): int => $permission === 'quiqqer.upload.maxUploadCount' ? $this->maxFiles : 4096
        );

        $Users = $this->createMock(QUI\Users\Manager::class);
        $Users->method('get')->willReturn($this->Issuer);
        $Users->method('getUserBySession')->willReturn(new QUI\Users\Nobody());
        QUI::$Users = $Users;

        $Project = $this->createMock(QUI\Projects\Project::class);
        $Project->method('getConfig')->willReturnCallback(fn (): int => $this->maxBytes);
        $Project->method('getLang')->willReturn('en');
        QUI\Projects\Manager::$Standard = $Project;

        $this->Store = new Store($this->directory . '/sessions');
        $Providers = new Providers(static fn (): array => [
            UploadProviderFixture::class,
            LimitedUploadProviderFixture::class
        ]);
        $Connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $Connection->executeStatement('CREATE TABLE users (uuid TEXT PRIMARY KEY, extra TEXT)');
        $Connection->insert('users', ['uuid' => 'test-upload-issuer', 'extra' => '{}']);
        $this->Devices = new Devices($Connection, 'users');
        $this->deviceToken = bin2hex(random_bytes(32));
        $this->now = intdiv(time(), 30) * 30;
        $this->Manager = new Manager($this->Store, $Providers, $this->Devices, fn (): int => $this->now);

        UploadProviderFixture::$allowed = true;
        UploadProviderFixture::$fail = false;
        UploadProviderFixture::$received = [];
        UploadProviderFixture::$types = ['text/plain'];
    }

    protected function tearDown(): void
    {
        QUI::$Users = $this->previousUsers;
        QUI\Projects\Manager::$Standard = $this->previousProject;

        $Iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($Iterator as $File) {
            $File->isDir() ? rmdir($File->getPathname()) : unlink($File->getPathname());
        }

        rmdir($this->directory);
    }

    public function testAccessStoresOnlyHashAndNewLinkReplacesOldOne(): void
    {
        $first = $this->create();
        $json = file_get_contents($this->Store->path($first['id']) . '.json');

        self::assertStringNotContainsString($first['token'], $json);
        self::assertStringContainsString(hash('sha256', $first['token']), $json);
        self::assertSame($this->now + 1800, $first['expiresAt']);

        $second = $this->create();
        $this->reject(410, fn () => $this->Manager->info($first['id'], $first['token'], $this->deviceToken));
        self::assertSame('Contract-123', $this->Manager->info($second['id'], $second['token'], $this->deviceToken)['label']);

        $this->Manager->manage($first['id'], $first['generation'], $this->Issuer, true);
        self::assertSame('Contract-123', $this->Manager->info($second['id'], $second['token'], $this->deviceToken)['label']);
    }

    public function testUploadUsesProviderTypesAndCurrentMediaLimitWithoutLoggingIn(): void
    {
        $session = $this->create();
        $path = $this->file('A plain text file, not a PDF.');
        $uploadId = bin2hex(random_bytes(32));
        $info = $this->Manager->info($session['id'], $session['token'], $this->deviceToken);

        self::assertSame(['text/plain'], $info['allowedTypes']);
        self::assertSame(1024, $info['maxBytes']);

        $this->Manager->upload($session['id'], $session['token'], $uploadId, $path, 'notes.txt', $this->deviceToken);
        $this->Manager->upload($session['id'], $session['token'], $uploadId, $path, 'notes.txt', $this->deviceToken);

        self::assertCount(1, UploadProviderFixture::$received);
        self::assertSame('A plain text file, not a PDF.', array_values(UploadProviderFixture::$received)[0]['content']);
        self::assertInstanceOf(QUI\Users\Nobody::class, QUI::getUserBySession());

        $this->maxBytes = 8;
        $this->reject(413, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $path,
            'notes.txt',
            $this->deviceToken
        ));
    }

    public function testProviderRejectionDoesNotConsumeUploadAndCanBeRetried(): void
    {
        $session = $this->create();
        $path = $this->file('retry me');
        $id = bin2hex(random_bytes(32));
        UploadProviderFixture::$fail = true;

        $this->reject(0, fn () => $this->Manager->upload($session['id'], $session['token'], $id, $path, 'retry.txt', $this->deviceToken));
        self::assertSame(0, $this->Manager->manage($session['id'], $session['generation'], $this->Issuer)['count']);

        UploadProviderFixture::$fail = false;
        $this->Manager->upload($session['id'], $session['token'], $id, $path, 'retry.txt', $this->deviceToken);
        self::assertCount(1, UploadProviderFixture::$received);
    }

    public function testRevocationClosureAndExpiredAccessRejectUploads(): void
    {
        $session = $this->create();
        $this->Manager->close($session['id'], $session['token'], $this->deviceToken);
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));

        $session = $this->create();
        $this->Manager->manage($session['id'], $session['generation'], $this->Issuer, true);
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));

        $session = $this->create();
        $this->Store->locked($session['id'], static function (Session $Old): array {
            return [new Session(
                $Old->id,
                $Old->generation,
                $Old->tokenHash,
                $Old->provider,
                $Old->context,
                $Old->issuer,
                time() - 1
            ), null];
        });

        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));
    }

    public function testPermissionsActivityAndProviderRegistrationAreRechecked(): void
    {
        $session = $this->create();
        UploadProviderFixture::$allowed = false;
        $this->reject(403, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));

        UploadProviderFixture::$allowed = true;
        $this->active = false;
        $this->reject(403, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));

        $this->active = true;
        $Manager = new Manager($this->Store, new Providers(static fn (): array => []), $this->Devices);
        $this->reject(403, fn () => $Manager->info($session['id'], $session['token'], $this->deviceToken));
    }

    public function testTokenCannotBeUsedForAnotherContextAndInvalidTypesAreRejected(): void
    {
        $first = $this->create();
        $this->context['request'] = 'request-2';
        $second = $this->create();
        $this->reject(410, fn () => $this->Manager->info($second['id'], $first['token'], $this->deviceToken));

        $path = $this->file('%PDF-1.7 not a text file');
        $this->reject(415, fn () => $this->Manager->upload(
            $first['id'],
            $first['token'],
            bin2hex(random_bytes(32)),
            $path,
            'document.pdf',
            $this->deviceToken
        ));

        self::assertSame([], UploadProviderFixture::$received);
    }

    public function testConfiguredCountLimitAndConflictingRetryAreRejected(): void
    {
        $this->maxFiles = 1;
        $session = $this->create();
        $path = $this->file('first');
        $id = bin2hex(random_bytes(32));
        $this->Manager->upload($session['id'], $session['token'], $id, $path, 'first.txt', $this->deviceToken);

        $this->reject(409, fn () => $this->Manager->upload($session['id'], $session['token'], $id, $path, 'changed.txt', $this->deviceToken));
        $this->reject(413, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $path,
            'second.txt',
            $this->deviceToken
        ));
    }

    public function testMediaSettingOverridesUserLimitAndEmptySettingUsesUserLimit(): void
    {
        self::assertSame(1024, Files::limits($this->Issuer)['maxBytes']);
        $this->maxBytes = 0;
        self::assertSame(4096, Files::limits($this->Issuer)['maxBytes']);

        $path = $this->file('arbitrary text');
        self::assertSame('text/plain', Files::validate($path, 0, [])['mime']);
    }

    public function testQrLinkAloneDoesNotRevealUploadDetailsOrPermitUploads(): void
    {
        $session = $this->create(false);
        $info = $this->Manager->info($session['id'], $session['token'], $this->deviceToken);
        self::assertSame('register', $info['stage']);
        self::assertArrayNotHasKey('code', $info);
        self::assertArrayNotHasKey('label', $info);
        self::assertArrayNotHasKey('allowedTypes', $info);

        $this->reject(403, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $this->file('blocked'),
            'blocked.txt',
            $this->deviceToken
        ));
        $this->reject(403, fn () => $this->Manager->close($session['id'], $session['token'], $this->deviceToken));
        self::assertSame([], UploadProviderFixture::$received);
    }

    public function testEnrollmentRequiresAnotherTimeWindowAndUploadLastsThirtyMinutesFromUnlock(): void
    {
        $session = $this->create(false);
        $state = $this->Manager->manage($session['id'], $session['generation'], $this->Issuer);
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $state['code']);

        $registered = $this->verify($session, 'register', $state['code']);
        self::assertSame('unlock', $registered['stage']);
        self::assertSame($this->now + 30, $registered['retryAt']);
        self::assertCount(1, $this->Devices->listing('test-upload-issuer'));
        $this->reject(409, fn () => $this->verify($session, 'unlock', $state['code']));

        $this->now += 30;
        $this->reject(422, fn () => $this->verify($session, 'unlock', $state['code']));
        $info = $this->verify($session, 'unlock');
        self::assertSame('upload', $info['stage']);
        self::assertSame($this->now + 1800, $info['expiresAt']);
        self::assertArrayNotHasKey('code', $info);

        $this->now += 1799;
        self::assertSame('upload', $this->Manager->info($session['id'], $session['token'], $this->deviceToken)['stage']);
        $this->now++;
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));
    }

    public function testKnownDeviceStillNeedsCodeForEachNewUploadContext(): void
    {
        $first = $this->create();
        $this->context['request'] = 'another-request';
        $second = $this->create(false);
        $info = $this->Manager->info($second['id'], $second['token'], $this->deviceToken);
        self::assertSame('unlock', $info['stage']);
        self::assertSame('upload', $this->verify($second, 'unlock')['stage']);
        self::assertSame('upload', $this->Manager->info($first['id'], $first['token'], $this->deviceToken)['stage']);

        $otherDevice = bin2hex(random_bytes(32));
        self::assertSame('register', $this->Manager->info($second['id'], $second['token'], $otherDevice)['stage']);
        $this->reject(403, fn () => $this->Manager->upload(
            $second['id'],
            $second['token'],
            bin2hex(random_bytes(32)),
            $this->file('blocked'),
            'blocked.txt',
            $otherDevice
        ));
    }

    public function testRevokingDeviceInvalidatesExistingGrantAndReregistrationDoesNotRestoreIt(): void
    {
        $session = $this->create();
        $device = $this->Devices->listing('test-upload-issuer')[0];
        $this->Devices->edit('test-upload-issuer', $device['id'], null);
        $this->reject(403, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $this->file('blocked'),
            'blocked.txt',
            $this->deviceToken
        ));

        $this->now += 30;
        self::assertSame('unlock', $this->verify($session, 'register')['stage']);
        self::assertNotSame($device['id'], $this->Devices->listing('test-upload-issuer')[0]['id']);
    }

    public function testWrongCodesAreLimitedAcrossBrowserCookiesAndCodeRotations(): void
    {
        $session = $this->create(false);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->deviceToken = bin2hex(random_bytes(32));
            $this->reject(422, fn () => $this->verify($session, 'register', 'invalid'));
        }

        $this->reject(429, fn () => $this->verify($session, 'register'));
        $this->now += 30;
        $this->reject(429, fn () => $this->verify($session, 'register'));
        $this->now += 270;
        self::assertSame('unlock', $this->verify($session, 'register')['stage']);
    }

    public function testUsedCodeCannotRegisterAnotherDeviceAndUploadGrantCannotBeExtendedByReplay(): void
    {
        $session = $this->create();
        $state = $this->Manager->manage($session['id'], $session['generation'], $this->Issuer);
        $this->reject(409, fn () => $this->verify($session, 'unlock', $state['code']));

        $this->deviceToken = bin2hex(random_bytes(32));
        $this->reject(409, fn () => $this->verify($session, 'register', $state['code']));
        self::assertCount(1, $this->Devices->listing('test-upload-issuer'));
    }

    public function testExistingBearerOnlySessionsAreRejectedAfterUpgrade(): void
    {
        $session = $this->create(false);
        $this->Store->locked($session['id'], static function (Session $Session): array {
            $Session->securityVersion = 0;

            return [$Session, null];
        });
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token'], $this->deviceToken));
    }

    public function testDestinationLimitsAreDisplayedAndEnforced(): void
    {
        $session = $this->create(provider: LimitedUploadProviderFixture::class);
        $info = $this->Manager->info($session['id'], $session['token'], $this->deviceToken);
        self::assertSame(3, $info['maxBytes']);
        self::assertSame(1, $info['maxFiles']);
        $path = $this->file('too large');
        $this->reject(413, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $path,
            'notes.txt',
            $this->deviceToken
        ));
        self::assertSame([], UploadProviderFixture::$received);
    }

    private function create(bool $unlock = true, string $provider = UploadProviderFixture::class): array
    {
        $session = $this->Manager->create($provider, $this->context, $this->Issuer);

        if (!$unlock) {
            return $session;
        }

        if ($this->Devices->find('test-upload-issuer', $this->deviceToken) === null) {
            $this->verify($session, 'register');
            $this->now += 30;
        }

        $this->verify($session, 'unlock');
        $session['expiresAt'] = $this->now + 1800;

        return $session;
    }

    private function verify(array $session, string $action, ?string $code = null): array
    {
        $state = $this->Manager->manage($session['id'], $session['generation'], $this->Issuer);

        return $this->Manager->verify(
            $session['id'],
            $session['token'],
            $this->deviceToken,
            $code ?? $state['code'],
            $action,
            'Test phone',
            'Test browser'
        );
    }

    private function file(string $content): string
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(8));
        file_put_contents($path, $content);

        return $path;
    }

    private function reject(int $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected rejection.');
        } catch (QUI\Exception | \RuntimeException $Exception) {
            self::assertSame($code, $Exception->getCode());
        }
    }
}
