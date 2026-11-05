<?php

namespace QUITests\System\Console\Tools;

use PHPUnit\Framework\TestCase;
use QUI\Interfaces\System\SystemOutput;
use QUI\System\Console\Tools\Update;
use QUI\System\Console\UpdateConsoleOutput;
use QUI\System\Console\UpdatePackageOutput;

class UpdateOutputTest extends TestCase
{
    public function testComposerProgressRedrawsStayIndented(): void
    {
        $lines = [];
        $Update = $this->recordUpdateOutput($lines);

        Update::onCliOutput("\033[1G\033[2K", $Update);

        $messages = [
            '  0/5 [>---------------------------]   0%',
            "\033[1G\033[2K1/5 [=====>----------------------]  20%",
            "\x08\x085/5 [============================] 100%",
            "\033[32m3/4 [=====================>------]  75%\033[0m"
        ];

        foreach ($messages as $message) {
            Update::onCliOutput($message, $Update);
        }

        self::assertSame([
            '    0/5 [>---------------------------]   0%',
            '    1/5 [=====>----------------------]  20%',
            '    5/5 [============================] 100%',
            '    3/4 [=====================>------]  75%'
        ], $lines);
    }

    public function testComposerWarningsAndSetupStayUnindentedThroughCompletion(): void
    {
        $lines = [];
        $Update = $this->recordUpdateOutput($lines);
        $messages = [
            "\033[43mPackage vendor/legacy is abandoned, you should avoid using it.\033[0m",
            'Generating optimized autoload files',
            '63 packages you are using are looking for funding.',
            'Use the `composer fund` command to find out more!',
            '> Execute QUIQQER setup',
            '> Execute package setups',
            '>> 12:00:00 - run setup for package quiqqer/example',
            '> Import permissions',
            '> Done',
            'No security vulnerability advisories found.'
        ];

        foreach ($messages as $message) {
            Update::onCliOutput($message, $Update);
        }

        self::assertSame($messages, $lines);
    }

    public function testSetupIsUnindentedWhenThereAreNoAbandonedPackages(): void
    {
        foreach (['Generating optimized autoload files', 'Generating autoload files', '> Execute QUIQQER setup'] as $start) {
            $lines = [];
            $Update = $this->recordUpdateOutput($lines);

            Update::onCliOutput($start, $Update);
            Update::onCliOutput('> Import permissions', $Update);

            self::assertSame([$start, '> Import permissions'], $lines);
        }
    }

    public function testBufferedComposerOutputUsesTheSameLayout(): void
    {
        foreach (['writeLn', 'writeExternalLine'] as $method) {
            $lines = [];
            $Output = $this->createMock(SystemOutput::class);
            $Output->method('writeLn')->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
            $PackageOutput = new UpdatePackageOutput(new UpdateConsoleOutput($Output));

            $PackageOutput->$method("\033[1G\033[2K5/5 [============================] 100%");
            $PackageOutput->$method('Generating optimized autoload files');
            $PackageOutput->$method('> Execute QUIQQER setup');
            $PackageOutput->$method('>> 12:00:00 - run setup for package quiqqer/example');

            self::assertSame([
                '    5/5 [============================] 100%',
                'Generating optimized autoload files',
                '> Execute QUIQQER setup',
                '>> 12:00:00 - run setup for package quiqqer/example'
            ], $lines);
        }
    }

    public function testServerFilesRestoreStatusIndentationAndUseTheSixthSection(): void
    {
        $lines = [];
        $Output = $this->createMock(SystemOutput::class);
        $Output->method('writeLn')->willReturnCallback(static function (string $line = '') use (&$lines): void {
            $lines[] = $line;
        });
        $UpdateOutput = new UpdateConsoleOutput($Output);
        $UpdateOutput->setCurrentSection(5);
        $UpdateOutput->writeComposerLine('Generating optimized autoload files');
        $UpdateOutput->success('Composer update completed');
        $UpdateOutput->startUnindentedOutput();
        $UpdateOutput->success('Update executed');
        $UpdateOutput->section('Server files');
        $UpdateOutput->info('Generate server files (.htaccess and NGINX)');
        $UpdateOutput->success('Update finished.');

        self::assertSame([
            'Generating optimized autoload files',
            '[OK] Composer update completed',
            '[OK] Update executed',
            '',
            '[6/6] Server files',
            '  [..] Generate server files (.htaccess and NGINX)',
            '  [OK] Update finished.'
        ], $lines);
    }

    public function testModuleSetupsAreVisibleWithoutVerboseOutput(): void
    {
        $Update = $this->getMockBuilder(Update::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['writeLn'])
            ->getMock();
        $Update->expects(self::once())->method('writeLn')->with(
            self::stringContains('run setup for package quiqqer/example')
        );

        Update::onCliOutput('>> 12:00:00 - run setup for package quiqqer/example', $Update);
    }

    public function testBufferedModuleSetupsAreVisibleWithoutVerboseOutput(): void
    {
        $Output = $this->createMock(SystemOutput::class);
        $Output->expects(self::once())->method('writeLn')->with(
            self::stringContains('run setup for package quiqqer/example')
        );
        $PackageOutput = new UpdatePackageOutput(new UpdateConsoleOutput($Output));

        $PackageOutput->writeLn('>> 12:00:00 - run setup for package quiqqer/example');
    }

    /**
     * @param list<string> $lines
     */
    private function recordUpdateOutput(array &$lines): Update
    {
        $Update = $this->getMockBuilder(Update::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['writeLn'])
            ->getMock();
        $Update->method('writeLn')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return $Update;
    }
}
