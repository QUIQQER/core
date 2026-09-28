<?php

declare(strict_types=1);

namespace QUI\System\Console\Tools;

use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Composer\Composer;
use QUI\Package\Manager;
use RuntimeException;
use Throwable;

class SecurityUpdateTest extends TestCase
{
    private string $directory;
    private ?Manager $previousManager;
    private ?QUI\Locale $previousLocale;
    private Composer&MockObject $Composer;
    private Manager&MockObject $Manager;
    private SecurityUpdate&MockObject $Tool;
    private array $messages = [];
    private array $logs = [];
    private array $maintenance = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-security-update-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . 'composer.json', '{"require":{"test/pinned":"1.2.3"}}');
        file_put_contents($this->directory . 'composer.lock', '{"packages":[]}');
        $this->previousManager = QUI::$PackageManager;
        $this->previousLocale = QUI::$Locale;
        $Locale = $this->createMock(QUI\Locale::class);
        $Locale->method('get')->willReturnCallback(static fn ($group, $var): string => $var);
        QUI::$Locale = $Locale;
        $this->Composer = $this->createMock(Composer::class);
        $this->Composer->method('getWorkingDir')->willReturn($this->directory);
        $this->Composer->expects(self::never())->method('update');
        $this->Manager = $this->createMock(Manager::class);
        $this->Manager->method('getComposer')->willReturn($this->Composer);
        $this->Manager->expects(self::never())->method('getInstalledVersions');
        QUI::$PackageManager = $this->Manager;
        $this->Tool = $this->getMockBuilder(SecurityUpdate::class)
            ->onlyMethods(['writeLn', 'setMaintenance', 'isMaintenanceEnabled', 'completeUpdate',
                'writeUpdateLog', 'createMailer'])
            ->getMock();
        $this->Tool->method('writeLn')->willReturnCallback(function (string $message = ''): void {
            $this->messages[] = $message;
        });
        $this->Tool->method('writeUpdateLog')->willReturnCallback(function (string $phase, string $message): void {
            $this->logs[] = $phase . ': ' . $message;
        });
        $this->Tool->method('setMaintenance')->willReturnCallback(function (bool $enabled): void {
            $this->maintenance[] = $enabled;
        });
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->previousManager;
        QUI::$Locale = $this->previousLocale;
        foreach (glob($this->directory . '*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function missingFiles(): array
    {
        return [['composer.json'], ['composer.lock']];
    }

    #[DataProvider('missingFiles')]
    public function testMissingComposerFileStopsBeforeUpdate(string $file): void
    {
        unlink($this->directory . $file);
        $this->Composer->expects(self::never())->method('executeComposer');
        $this->Tool->expects(self::never())->method('setMaintenance');
        $this->Tool->expects(self::never())->method('completeUpdate');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString($file, implode("\n", $this->messages));
        self::assertNotContains('security.update.no.updates.found', $this->messages);
        self::assertFileDoesNotExist($this->directory . $file);
    }

    public function testOldComposerIsRejectedBeforeUpdate(): void
    {
        $this->Composer->expects(self::once())->method('executeComposer')
            ->with('update', ['--help' => true, '--no-interaction' => true, '--no-ansi' => true])
            ->willReturn(['--dry-run']);
        $this->Tool->expects(self::never())->method('setMaintenance');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString('Composer 2.8 or newer', implode("\n", $this->messages));
        $this->assertComposerFilesUnchanged();
    }

    public static function emptyPlans(): array
    {
        return [
            [['Nothing to modify in lock file']],
            [['Nothing to install, update or remove']],
            [['Lock file operations: 0 installs, 0 updates, 0 removals']]
        ];
    }

    #[DataProvider('emptyPlans')]
    public function testNoUpdatesLeaveComposerFilesAndMaintenanceUntouched(array $preview): void
    {
        $this->expectComposer($preview, install: false);
        $this->Tool->expects(self::never())->method('setMaintenance');
        $this->Tool->expects(self::never())->method('completeUpdate');
        $this->Tool->expects(self::never())->method('createMailer');
        self::assertTrue($this->Tool->executeSecurityUpdate());
        self::assertContains('security.update.no.updates.found', $this->messages);
        $this->assertComposerFilesUnchanged();
    }

    public static function failures(): array
    {
        return [[new RuntimeException('Dependency resolution failed')], [new Error('Class NumberComparator not found')]];
    }

    #[DataProvider('failures')]
    public function testFailedDryRunIsReportedWithoutStartingUpdate(Throwable $failure): void
    {
        $this->expectComposer($failure, install: false);
        $this->Tool->expects(self::never())->method('setMaintenance');
        $this->Tool->expects(self::never())->method('completeUpdate');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString($failure->getMessage(), implode("\n", $this->messages));
        self::assertStringContainsString('dry-run: FAILED:', implode("\n", $this->logs));
        self::assertNotContains('security.update.no.updates.found', $this->messages);
        $this->assertComposerFilesUnchanged();
    }

    #[DataProvider('failures')]
    public function testRealUpdateAlsoLimitsPatchesAndKeepsMaintenanceAfterFailure(Throwable $failure): void
    {
        $this->expectComposer($this->updatePlan(), $failure);
        $this->Tool->expects(self::never())->method('completeUpdate');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertSame([true], $this->maintenance);
        self::assertStringContainsString($failure->getMessage(), implode("\n", $this->messages));
        self::assertStringContainsString('installation: FAILED:', implode("\n", $this->logs));
        $this->assertComposerFilesUnchanged();
    }

    public function testSourceRefreshFailureIsReportedBeforeComposer(): void
    {
        $this->Manager->method('refreshServerList')->willThrowException(new Error('Source unavailable'));
        $this->Composer->expects(self::never())->method('executeComposer');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertSame([], $this->maintenance);
        self::assertStringContainsString('preflight: FAILED: Error: Source unavailable', implode("\n", $this->logs));
    }

    public static function unrecognizedPlans(): array
    {
        return [[[]], [['Downloading metadata']], [['Lock file operations: invalid']]];
    }

    #[DataProvider('unrecognizedPlans')]
    public function testUnknownOutputIsNotReportedAsNoUpdates(array $preview): void
    {
        $this->expectComposer($preview, install: false);
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertSame([], $this->maintenance);
        self::assertNotContains('security.update.no.updates.found', $this->messages);
    }

    public static function maintenanceStates(): array
    {
        return [[false, [true, false]], [true, []]];
    }

    #[DataProvider('maintenanceStates')]
    public function testSuccessRestoresPreviousMaintenanceState(bool $enabled, array $changes): void
    {
        $this->Tool->method('isMaintenanceEnabled')->willReturn($enabled);
        $this->expectComposer($this->updatePlan());
        $this->Tool->expects(self::once())->method('completeUpdate');
        self::assertTrue($this->Tool->executeSecurityUpdate());
        self::assertSame($changes, $this->maintenance);
        self::assertStringContainsString('completed successfully', implode("\n", $this->logs));
    }

    public function testCompletionFailureKeepsMaintenanceEnabled(): void
    {
        $this->expectComposer($this->updatePlan());
        $this->Tool->method('completeUpdate')->willThrowException(new Error('Cache service missing'));
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertSame([true], $this->maintenance);
        self::assertStringContainsString('completion: FAILED:', implode("\n", $this->logs));
    }

    public function testFailureMailUsesRecipientAndEscapesCause(): void
    {
        $this->expectComposer(new RuntimeException('<script>bad</script>'), install: false);
        $this->Tool->setArgument('mail', 'admin@example.invalid');
        $Mailer = $this->createMock(QUI\Mail\Mailer::class);
        $Mailer->expects(self::once())->method('addRecipient')->with('admin@example.invalid');
        $Mailer->expects(self::once())->method('setSubject')->with('security.update.console.mail.failure.subject');
        $Mailer->expects(self::once())->method('setBody')->with(self::callback(static function (string $body): bool {
            return str_contains($body, 'security.update.console.mail.failure.body')
                && str_contains($body, '&lt;script&gt;bad&lt;/script&gt;')
                && !str_contains($body, '<script>');
        }));
        $Mailer->expects(self::once())->method('send')->willReturn(true);
        $this->Tool->method('createMailer')->willReturn($Mailer);
        self::assertFalse($this->Tool->executeSecurityUpdate());
    }

    public function testMailFailureDoesNotHideOriginalFailure(): void
    {
        $this->expectComposer(new RuntimeException('Original failure'), install: false);
        $this->Tool->setArgument('m', 'admin@example.invalid');
        $this->Tool->method('createMailer')->willThrowException(new Error('Mailer unavailable'));
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString('Original failure', implode("\n", $this->logs));
        self::assertStringContainsString('notification: FAILED: Mailer unavailable', implode("\n", $this->logs));
    }

    public function testSuccessfulUpdateSendsSuccessMailToShortOptionRecipient(): void
    {
        $this->expectComposer($this->updatePlan());
        $this->Tool->setArgument('m', 'admin@example.invalid');
        $Mailer = $this->createMock(QUI\Mail\Mailer::class);
        $Mailer->expects(self::once())->method('addRecipient')->with('admin@example.invalid');
        $Mailer->expects(self::once())->method('setSubject')->with('security.update.console.mail.subject');
        $Mailer->expects(self::once())->method('setBody')->with('security.update.console.mail.body');
        $Mailer->expects(self::once())->method('send')->willReturn(true);
        $this->Tool->method('createMailer')->willReturn($Mailer);
        self::assertTrue($this->Tool->executeSecurityUpdate());
    }

    public function testRejectedNotificationIsLoggedWithoutChangingUpdateResult(): void
    {
        $this->expectComposer($this->updatePlan());
        $this->Tool->setArgument('mail', 'admin@example.invalid');
        $Mailer = $this->createMock(QUI\Mail\Mailer::class);
        $Mailer->method('send')->willReturn(false);
        $this->Tool->method('createMailer')->willReturn($Mailer);
        self::assertTrue($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString('notification: FAILED:', implode("\n", $this->logs));
    }

    public function testMaintenanceFailurePreventsInstallation(): void
    {
        $this->expectComposer($this->updatePlan(), install: false);
        $this->Tool->method('setMaintenance')->willThrowException(new RuntimeException('Maintenance failed'));
        $this->Tool->expects(self::never())->method('completeUpdate');
        self::assertFalse($this->Tool->executeSecurityUpdate());
        self::assertStringContainsString('maintenance: FAILED:', implode("\n", $this->logs));
    }

    private function updatePlan(): array
    {
        return ['Lock file operations: 0 installs, 1 update, 0 removals'];
    }

    private function expectComposer(array|Throwable $preview, ?Throwable $failure = null, bool $install = true): void
    {
        $calls = 0;
        $this->Composer->expects(self::exactly($install ? 3 : 2))->method('executeComposer')
            ->willReturnCallback(function (string $command, array $options) use (&$calls, $preview, $failure): array {
                self::assertSame('update', $command);
                self::assertTrue($options['--no-interaction']);
                self::assertTrue($options['--no-ansi']);
                if (++$calls === 1) {
                    self::assertTrue($options['--help']);
                    return ['--patch-only'];
                }
                self::assertTrue($options['--patch-only']);
                self::assertTrue($options['--prefer-dist']);
                $this->assertComposerFilesUnchanged();
                if ($calls === 2) {
                    self::assertTrue($options['--dry-run']);
                    if ($preview instanceof Throwable) {
                        throw $preview;
                    }
                    return $preview;
                }
                self::assertArrayNotHasKey('--dry-run', $options);
                if ($failure !== null) {
                    throw $failure;
                }
                return ['Installation completed'];
            });
    }

    private function assertComposerFilesUnchanged(): void
    {
        self::assertSame('{"require":{"test/pinned":"1.2.3"}}', file_get_contents($this->directory . 'composer.json'));
        self::assertSame('{"packages":[]}', file_get_contents($this->directory . 'composer.lock'));
        self::assertFileDoesNotExist($this->directory . 'composer-security-update-backup.json');
    }
}
