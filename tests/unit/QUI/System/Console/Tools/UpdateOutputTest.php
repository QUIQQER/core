<?php

namespace QUITests\System\Console\Tools;

use PHPUnit\Framework\TestCase;
use QUI\Interfaces\System\SystemOutput;
use QUI\System\Console\Tools\Update;
use QUI\System\Console\UpdateConsoleOutput;
use QUI\System\Console\UpdatePackageOutput;

class UpdateOutputTest extends TestCase
{
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
}
