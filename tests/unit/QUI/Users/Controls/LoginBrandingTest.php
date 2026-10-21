<?php

declare(strict_types=1);

namespace QUITests\QUI\Users\Controls;

use PHPUnit\Framework\TestCase;
use QUI\Exception;
use QUI\Projects\Media;
use QUI\Projects\Media\Image;
use QUI\Projects\Project;
use QUI\Users\Controls\Login;

final class LoginBrandingTest extends TestCase
{
    public function testMissingProjectUsesQuiqqerBranding(): void
    {
        self::assertSame([
            'logo' => URL_BIN_DIR . 'quiqqer_logo.svg',
            'alt' => 'QUIQQER'
        ], Login::getBranding(null));
    }

    public function testUnconfiguredLogoDoesNotLoadProjectMedia(): void
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getConfig')->with('logo')->willReturn('');
        $Project->expects(self::never())->method('getMedia');

        self::assertSame(Login::getBranding(null), Login::getBranding($Project));
    }

    public function testConfiguredLogoIsCachedForTheExpiredSessionDialog(): void
    {
        $Image = $this->createMock(Image::class);
        $Image->method('getSizeCacheUrl')->willReturn('/media/cache/project-logo.svg');
        $Media = $this->createMock(Media::class);
        $Media->method('getLogoImage')->willReturn($Image);
        $Project = $this->createMock(Project::class);
        $Project->method('getConfig')->with('logo')->willReturn('image.php?qui=project/1');
        $Project->method('getName')->willReturn('IBAN-TEST');
        $Project->method('getMedia')->willReturn($Media);

        self::assertSame([
            'logo' => '/media/cache/project-logo.svg',
            'alt' => 'IBAN-TEST'
        ], Login::getBranding($Project));
    }

    public function testUnavailableImageFallsBackToQuiqqer(): void
    {
        $Media = $this->createMock(Media::class);
        $Media->method('getLogoImage')->willReturn(null);
        $Project = $this->createMock(Project::class);
        $Project->method('getConfig')->willReturn('image.php?qui=project/1');
        $Project->method('getMedia')->willReturn($Media);

        self::assertSame(Login::getBranding(null), Login::getBranding($Project));
    }

    public function testMediaErrorsCannotPreventOpeningTheBackend(): void
    {
        $Project = $this->createMock(Project::class);
        $Project->method('getConfig')->willReturn('image.php?qui=project/1');
        $Project->method('getMedia')->willThrowException(new Exception('Image unavailable'));

        self::assertSame(Login::getBranding(null), Login::getBranding($Project));
    }
}
