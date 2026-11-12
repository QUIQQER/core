<?php

declare(strict_types=1);

namespace QUITests;

use PHPUnit\Framework\TestCase;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Rewrite;
use RuntimeException;

final class RewriteSiteResolutionTest extends TestCase
{
    public function testStartPageCanRenderALoginWithoutResolvingItselfAgain(): void
    {
        $Site = $this->createMock(Site::class);
        $Project = $this->createMock(Project::class);
        $Rewrite = $this->createPartialMock(Rewrite::class, ['getProject']);
        $Rewrite->expects(self::exactly(2))->method('getProject')->willReturn($Project);
        $Project->expects(self::exactly(2))->method('firstChild')->willReturnCallback(
            static function () use ($Rewrite, $Site): Site {
                // A login template asks for the current site while the start page is being built.
                self::assertNull($Rewrite->getSite());

                return $Site;
            }
        );

        self::assertSame($Site, $Rewrite->getSite());
        self::assertSame($Site, $Rewrite->getSite());
    }

    public function testFailedStartPageResolutionCanBeRetried(): void
    {
        $Site = $this->createMock(Site::class);
        $Project = $this->createMock(Project::class);
        $Rewrite = $this->createPartialMock(Rewrite::class, ['getProject']);
        $Rewrite->method('getProject')->willReturn($Project);
        $attempts = 0;

        $Project->expects(self::exactly(2))->method('firstChild')->willReturnCallback(
            static function () use (&$attempts, $Site): Site {
                if ($attempts++ === 0) {
                    throw new RuntimeException('Start page is unavailable.');
                }

                return $Site;
            }
        );

        try {
            $Rewrite->getSite();
            self::fail('The start page error must propagate.');
        } catch (RuntimeException $Exception) {
            self::assertSame('Start page is unavailable.', $Exception->getMessage());
        }

        self::assertSame($Site, $Rewrite->getSite());
    }

    public function testExplicitSiteRemainsAvailableDuringStartPageResolution(): void
    {
        $Site = $this->createMock(Site::class);
        $Project = $this->createMock(Project::class);
        $Rewrite = $this->createPartialMock(Rewrite::class, ['getProject']);
        $Rewrite->expects(self::once())->method('getProject')->willReturn($Project);
        $Project->expects(self::once())->method('firstChild')->willReturnCallback(
            static function () use ($Rewrite, $Site): Site {
                $Rewrite->setSite($Site);
                self::assertSame($Site, $Rewrite->getSite());

                return $Site;
            }
        );

        self::assertSame($Site, $Rewrite->getSite());
        self::assertSame($Site, $Rewrite->getSite());
    }
}
