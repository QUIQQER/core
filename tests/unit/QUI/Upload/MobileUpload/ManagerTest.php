<?php

declare(strict_types=1);

namespace QUITests\Upload\MobileUpload;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User;
use QUI\Upload\MobileUpload\Document;
use QUI\Upload\MobileUpload\Files;
use QUI\Upload\MobileUpload\Manager;
use QUI\Upload\MobileUpload\ProviderInterface;
use QUI\Upload\MobileUpload\Providers;
use QUI\Upload\MobileUpload\Session;
use QUI\Upload\MobileUpload\Store;

require_once __DIR__ . '/UploadProviderFixture.php';

final class ManagerTest extends TestCase
{
    private string $directory;
    private Manager $Manager;
    private Store $Store;
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
        $Providers = new Providers(static fn (): array => [UploadProviderFixture::class]);
        $this->Manager = new Manager($this->Store, $Providers);

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
        self::assertEqualsWithDelta(time() + 1800, $first['expiresAt'], 2);

        $second = $this->create();
        $this->reject(410, fn () => $this->Manager->info($first['id'], $first['token']));
        self::assertSame('Contract-123', $this->Manager->info($second['id'], $second['token'])['label']);

        $this->Manager->manage($first['id'], $first['generation'], $this->Issuer, true);
        self::assertSame('Contract-123', $this->Manager->info($second['id'], $second['token'])['label']);
    }

    public function testUploadUsesProviderTypesAndCurrentMediaLimitWithoutLoggingIn(): void
    {
        $session = $this->create();
        $path = $this->file('A plain text file, not a PDF.');
        $uploadId = bin2hex(random_bytes(32));
        $info = $this->Manager->info($session['id'], $session['token']);

        self::assertSame(['text/plain'], $info['allowedTypes']);
        self::assertSame(1024, $info['maxBytes']);

        $this->Manager->upload($session['id'], $session['token'], $uploadId, $path, 'notes.txt');
        $this->Manager->upload($session['id'], $session['token'], $uploadId, $path, 'notes.txt');

        self::assertCount(1, UploadProviderFixture::$received);
        self::assertSame('A plain text file, not a PDF.', array_values(UploadProviderFixture::$received)[0]['content']);
        self::assertInstanceOf(QUI\Users\Nobody::class, QUI::getUserBySession());

        $this->maxBytes = 8;
        $this->reject(413, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $path,
            'notes.txt'
        ));
    }

    public function testProviderRejectionDoesNotConsumeUploadAndCanBeRetried(): void
    {
        $session = $this->create();
        $path = $this->file('retry me');
        $id = bin2hex(random_bytes(32));
        UploadProviderFixture::$fail = true;

        $this->reject(0, fn () => $this->Manager->upload($session['id'], $session['token'], $id, $path, 'retry.txt'));
        self::assertSame(0, $this->Manager->manage($session['id'], $session['generation'], $this->Issuer)['count']);

        UploadProviderFixture::$fail = false;
        $this->Manager->upload($session['id'], $session['token'], $id, $path, 'retry.txt');
        self::assertCount(1, UploadProviderFixture::$received);
    }

    public function testRevocationClosureAndExpiredAccessRejectUploads(): void
    {
        $session = $this->create();
        $this->Manager->close($session['id'], $session['token']);
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token']));

        $session = $this->create();
        $this->Manager->manage($session['id'], $session['generation'], $this->Issuer, true);
        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token']));

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

        $this->reject(410, fn () => $this->Manager->info($session['id'], $session['token']));
    }

    public function testPermissionsActivityAndProviderRegistrationAreRechecked(): void
    {
        $session = $this->create();
        UploadProviderFixture::$allowed = false;
        $this->reject(403, fn () => $this->Manager->info($session['id'], $session['token']));

        UploadProviderFixture::$allowed = true;
        $this->active = false;
        $this->reject(403, fn () => $this->Manager->info($session['id'], $session['token']));

        $this->active = true;
        $Manager = new Manager($this->Store, new Providers(static fn (): array => []));
        $this->reject(403, fn () => $Manager->info($session['id'], $session['token']));
    }

    public function testTokenCannotBeUsedForAnotherContextAndInvalidTypesAreRejected(): void
    {
        $first = $this->create();
        $this->context['request'] = 'request-2';
        $second = $this->create();
        $this->reject(410, fn () => $this->Manager->info($second['id'], $first['token']));

        $path = $this->file('%PDF-1.7 not a text file');
        $this->reject(415, fn () => $this->Manager->upload(
            $first['id'],
            $first['token'],
            bin2hex(random_bytes(32)),
            $path,
            'document.pdf'
        ));

        self::assertSame([], UploadProviderFixture::$received);
    }

    public function testConfiguredCountLimitAndConflictingRetryAreRejected(): void
    {
        $this->maxFiles = 1;
        $session = $this->create();
        $path = $this->file('first');
        $id = bin2hex(random_bytes(32));
        $this->Manager->upload($session['id'], $session['token'], $id, $path, 'first.txt');

        $this->reject(409, fn () => $this->Manager->upload($session['id'], $session['token'], $id, $path, 'changed.txt'));
        $this->reject(413, fn () => $this->Manager->upload(
            $session['id'],
            $session['token'],
            bin2hex(random_bytes(32)),
            $path,
            'second.txt'
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

    private function create(): array
    {
        return $this->Manager->create(UploadProviderFixture::class, $this->context, $this->Issuer);
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
