<?php

namespace QUI\System;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CiPreparationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-ci-preparation-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);

        foreach (['sudo', 'sleep'] as $command) {
            $path = $this->directory . '/' . $command;
            copy(__DIR__ . '/Fixtures/ci-preparation-command', $path);
            chmod($path, 0700);
        }

        file_put_contents($this->directory . '/calls', '');
        file_put_contents($this->directory . '/attempt', '0');
    }

    protected function tearDown(): void
    {
        \QUI\Utils\System\File::deleteDir($this->directory);
    }

    public function testStopsCronBeforeCheckingForWorkers(): void
    {
        $Process = $this->prepare('clear');
        $Process->mustRun();

        self::assertSame("sudo -n service cron stop\n", file_get_contents($this->directory . '/calls'));
    }

    public function testWaitsForBothCronSessionsAndExistingQuiqqerWorkers(): void
    {
        $Process = $this->prepare('wait');
        $Process->mustRun();
        $calls = file($this->directory . '/calls', FILE_IGNORE_NEW_LINES);

        self::assertSame(2, count(array_keys($calls, 'sleep 1', true)));
        self::assertSame('2', trim(file_get_contents($this->directory . '/attempt')));
    }

    public function testRefusesToContinueIfWorkersDoNotFinish(): void
    {
        $Process = $this->prepare('busy');
        $Process->run();

        self::assertSame(1, $Process->getExitCode());
        self::assertStringContainsString('Cron workers are still active', $Process->getErrorOutput());
    }

    public function testStopFailurePreventsFurtherPreparation(): void
    {
        $Process = $this->prepare('stop-fails');
        $Process->run();

        self::assertSame(5, $Process->getExitCode());
        self::assertSame("sudo -n service cron stop\n", file_get_contents($this->directory . '/calls'));
    }

    public function testCannotStopCronOutsideCi(): void
    {
        $Process = $this->prepare('clear');
        $Process->setEnv(array_merge($Process->getEnv(), ['GITLAB_CI' => false]));
        $Process->run();

        self::assertSame(1, $Process->getExitCode());
        self::assertSame('', file_get_contents($this->directory . '/calls'));
    }

    public function testRefusesToContinueWithoutProcessInformation(): void
    {
        $Process = $this->prepare('clear');
        \QUI\Utils\System\File::deleteDir($this->directory . '/proc');
        $Process->run();

        self::assertSame(1, $Process->getExitCode());
        self::assertStringContainsString('Cannot inspect cron workers', $Process->getErrorOutput());
    }

    private function prepare(string $mode): Process
    {
        file_put_contents($this->directory . '/mode', $mode);
        mkdir($this->directory . '/proc');

        if (in_array($mode, ['wait', 'busy'], true)) {
            mkdir($this->directory . '/proc/100');
            file_put_contents($this->directory . '/proc/100/comm', "CRON\n");
        }

        // A worker that outlives its CRON session must also finish before setup.
        mkdir($this->directory . '/proc/200');
        file_put_contents($this->directory . '/proc/200/comm', "php\n");
        file_put_contents(
            $this->directory . '/proc/200/cmdline',
            "php\0/var/www/html/console\0" . ($mode === 'wait' ? 'cron' : 'unrelated-command') . "\0"
        );
        mkdir($this->directory . '/proc/300');
        file_put_contents($this->directory . '/proc/300/comm', "php\n");
        file_put_contents($this->directory . '/proc/300/cmdline', '');

        return new Process(
            ['bash', dirname(__DIR__, 3) . '/Support/prepare-ci.sh', $this->directory . '/proc'],
            env: [
                'GITLAB_CI' => 'true',
                'PATH' => $this->directory . PATH_SEPARATOR . getenv('PATH'),
                'QUIQQER_TEST_CI_PREPARATION_DIRECTORY' => $this->directory
            ],
            timeout: 30
        );
    }
}
