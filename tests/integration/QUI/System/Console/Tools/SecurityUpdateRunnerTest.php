<?php

declare(strict_types=1);

namespace QUI\System\Console\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Composer\Composer;
use QUI\Package\Manager;
use Symfony\Component\Process\Process;

class SecurityUpdateRunnerTest extends TestCase
{
    private string $directory;
    private ?Manager $previousManager;
    private string $previousDirectory;
    private string|false $previousHome;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-security-runner-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/composer.json', '{}');
        file_put_contents($this->directory . '/composer.lock', '{"packages":[]}');
        copy(__DIR__ . '/Fixtures/security-update-composer.php', $this->directory . '/composer.phar');
        $this->previousManager = QUI::$PackageManager;
        $this->previousDirectory = getcwd();
        $this->previousHome = getenv('COMPOSER_HOME');
    }

    protected function tearDown(): void
    {
        QUI::$PackageManager = $this->previousManager;
        chdir($this->previousDirectory);
        putenv($this->previousHome === false ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $this->previousHome);
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function scenarios(): array
    {
        return [
            'successful preview and installation' => ['success', true, [true, false], true],
            'no updates' => ['no-updates', true, [], false],
            'nonzero status after change summary' => ['failed-preview', false, [], false],
            'installation failure' => ['failed-installation', false, [true], true]
        ];
    }

    #[DataProvider('scenarios')]
    public function testRealCliAdapterChecksStatusAndCapturesStderr(
        string $scenario,
        bool $success,
        array $expectedMaintenance,
        bool $installationStarted
    ): void {
        file_put_contents($this->directory . '/scenario', $scenario);
        $Composer = new Composer($this->directory);
        $Composer->setMode(Composer::MODE_CLI);
        $Manager = $this->createMock(Manager::class);
        $Manager->method('getComposer')->willReturn($Composer);
        QUI::$PackageManager = $Manager;
        $Tool = $this->getMockBuilder(SecurityUpdate::class)
            ->onlyMethods(['setMaintenance', 'isMaintenanceEnabled', 'completeUpdate'])
            ->getMock();
        $maintenance = [];
        $Tool->method('setMaintenance')->willReturnCallback(static function (bool $enabled) use (&$maintenance): void {
            $maintenance[] = $enabled;
        });
        $Tool->expects($success && $installationStarted ? self::once() : self::never())->method('completeUpdate');
        self::assertSame($success, $Tool->executeSecurityUpdate());
        self::assertSame($expectedMaintenance, $maintenance);
        self::assertSame($installationStarted, is_file($this->directory . '/installation-started'));
        $calls = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES)
        );
        self::assertCount($installationStarted ? 3 : 2, $calls);
        self::assertContains('--dry-run', $calls[1]);
        self::assertContains('--patch-only', $calls[1]);
        self::assertContains('--no-interaction', $calls[1]);
        if ($installationStarted) {
            self::assertNotContains('--dry-run', $calls[2]);
            self::assertContains('--patch-only', $calls[2]);
        }
        self::assertSame('{}', file_get_contents($this->directory . '/composer.json'));
        self::assertSame('{"packages":[]}', file_get_contents($this->directory . '/composer.lock'));
        $log = file_get_contents(VAR_DIR . 'log/update-' . date('Y-m-d') . '.log');
        self::assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[security-update\]/', $log);
        if ($scenario === 'failed-preview') {
            self::assertStringContainsString('[dry-run] FAILED:', $log);
            self::assertStringContainsString('Dependency resolution failed after preview', $log);
        }
        if ($scenario === 'failed-installation') {
            self::assertStringContainsString('[installation] FAILED:', $log);
            self::assertStringContainsString('NumberComparator not found', $log);
        }
    }

    #[DataProvider('scenarios')]
    public function testConsoleExitStatusReportsFailureToCron(
        string $scenario,
        bool $success,
        array $expectedMaintenance,
        bool $installationStarted
    ): void {
        file_put_contents($this->directory . '/scenario', $scenario);
        $Process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/security-update-console.php', $this->directory]);
        $Process->setTimeout(30);
        $Process->run();
        self::assertSame($success ? 0 : 1, $Process->getExitCode(), $Process->getErrorOutput());
        self::assertSame($success ? 'command completed' : '', $Process->getOutput());
        self::assertSame($installationStarted, is_file($this->directory . '/installation-started'));
    }
}
