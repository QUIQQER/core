<?php

declare(strict_types=1);

namespace QUI\System\Console\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Composer\Composer;
use QUI\Package\Manager;
use QUI\System\Console\Output;
use RuntimeException;

class SecurityUpdateTest extends TestCase
{
    private string $directory;
    private ?Manager $previousManager;
    private ?QUI\Locale $previousLocale;
    private Composer&MockObject $Composer;
    private SecurityUpdate&MockObject $Tool;
    private Output $Output;
    private array $messages = [];

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
        $this->Composer->method('setOutput')->willReturnCallback(function (Output $Output): void {
            $this->Output = $Output;
        });
        $Manager = $this->createMock(Manager::class);
        $Manager->method('getComposer')->willReturn($this->Composer);
        $Manager->expects(self::never())->method('getInstalledVersions');
        QUI::$PackageManager = $Manager;

        $this->Tool = $this->getMockBuilder(SecurityUpdate::class)
            ->onlyMethods(['write', 'writeLn', 'resetColor', 'setMaintenance'])
            ->getMock();
        $this->Tool->method('writeLn')->willReturnCallback(function (string $message = ''): void {
            $this->messages[] = $message;
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
        $this->Composer->expects(self::never())->method('update');
        $this->Tool->expects(self::never())->method('setMaintenance');

        $this->Tool->execute();

        self::assertStringContainsString($file, implode("\n", $this->messages));
        self::assertNotContains('security.update.no.updates.found', $this->messages);
        self::assertFileDoesNotExist($this->directory . $file);
    }

    public function testOldComposerIsRejectedBeforeUpdate(): void
    {
        $this->Composer->expects(self::once())->method('executeComposer')
            ->with('update', ['--help' => true])->willReturn(['--dry-run']);
        $this->Composer->expects(self::never())->method('update');
        $this->Tool->expects(self::never())->method('setMaintenance');

        $this->Tool->execute();

        self::assertStringContainsString('Composer 2.8 or newer', implode("\n", $this->messages));
        $this->assertComposerFilesUnchanged();
    }

    public function testNoUpdatesLeaveComposerFilesAndMaintenanceUntouched(): void
    {
        $this->expectPatchSupport();
        $this->Composer->expects(self::once())->method('update')
            ->with(['--dry-run' => true, '--patch-only' => true])
            ->willReturnCallback(function (): array {
                $this->Output->writeln('Nothing to modify in lock file');
                return [];
            });
        $this->Tool->expects(self::never())->method('setMaintenance');

        $this->Tool->execute();

        self::assertContains('security.update.no.updates.found', $this->messages);
        $this->assertComposerFilesUnchanged();
    }

    public function testFailedDryRunIsReportedWithoutStartingUpdate(): void
    {
        $this->expectPatchSupport();
        $this->Composer->expects(self::once())->method('update')
            ->willThrowException(new RuntimeException('Dependency resolution failed'));
        $this->Tool->expects(self::never())->method('setMaintenance');

        $this->Tool->execute();

        self::assertStringContainsString('Dependency resolution failed', implode("\n", $this->messages));
        self::assertNotContains('security.update.no.updates.found', $this->messages);
        $this->assertComposerFilesUnchanged();
    }

    public function testRealUpdateAlsoLimitsPatchesAndCleansUpAfterFailure(): void
    {
        $this->expectPatchSupport();
        $calls = 0;
        $this->Composer->expects(self::exactly(2))->method('update')
            ->willReturnCallback(function (array $options) use (&$calls): array {
                $this->assertComposerFilesUnchanged();

                if (++$calls === 1) {
                    self::assertSame(['--dry-run' => true, '--patch-only' => true], $options);
                    $this->Output->writeln('Lock file operations: 0 installs, 1 update, 0 removals');
                    return [];
                }

                self::assertSame(['--patch-only' => true], $options);
                throw new RuntimeException('Update failed');
            });
        $maintenance = [];
        $this->Tool->expects(self::exactly(2))->method('setMaintenance')
            ->willReturnCallback(static function (bool $enabled) use (&$maintenance): void {
                $maintenance[] = $enabled;
            });

        $this->Tool->execute();

        self::assertSame([true, false], $maintenance);
        self::assertStringContainsString('Update failed', implode("\n", $this->messages));
        $this->assertComposerFilesUnchanged();
    }

    private function expectPatchSupport(): void
    {
        $this->Composer->expects(self::once())->method('executeComposer')
            ->with('update', ['--help' => true])->willReturn(['--patch-only']);
    }

    private function assertComposerFilesUnchanged(): void
    {
        self::assertSame(
            '{"require":{"test/pinned":"1.2.3"}}',
            file_get_contents($this->directory . 'composer.json')
        );
        self::assertSame('{"packages":[]}', file_get_contents($this->directory . 'composer.lock'));
        self::assertFileDoesNotExist($this->directory . 'composer-security-update-backup.json');
    }
}
