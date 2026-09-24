<?php

namespace QUI\Users;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Log\Logger;
use QUI\Projects\Manager as ProjectManager;
use QUI\Projects\Media;
use QUI\Projects\Media\Image;
use QUI\Projects\Media\Utils;
use QUI\Projects\Project;
use ReflectionProperty;

class UserAvatarTest extends TestCase
{
    private ?QUI\Events\Manager $OriginalEvents;
    private ?ProjectManager $OriginalProjectManager;
    private ?Project $OriginalStandard;
    private array $originalProjects;
    private array $originalUrlCache;
    private MonologLogger $OriginalLogger;
    private QUI\Config $LogConfig;
    private array $originalLogLevels;
    private TestHandler $Handler;

    protected function setUp(): void
    {
        $this->OriginalEvents = QUI::$Events;
        $this->OriginalProjectManager = QUI::$ProjectManager;
        $this->OriginalStandard = ProjectManager::$Standard;
        $this->originalProjects = ProjectManager::$projects;
        $this->originalUrlCache = (new ReflectionProperty(Utils::class, 'urlItemCache'))->getValue();
        (new ReflectionProperty(Utils::class, 'urlItemCache'))->setValue(null, []);
        QUI::$Events = $this->createMock(QUI\Events\Manager::class);
        QUI::$ProjectManager = new ProjectManager();

        $this->OriginalLogger = Logger::getLogger();
        $this->Handler = new TestHandler();
        Logger::$Logger = new MonologLogger('avatar-test', [$this->Handler]);
        $LogConfig = QUI\Log\Config::getPackageConfig();
        self::assertNotNull($LogConfig);
        $this->LogConfig = $LogConfig;
        $this->originalLogLevels = $LogConfig->get('log_levels');
        $LogConfig->setValue('log_levels', 'info', 1);
        $LogConfig->setValue('log_levels', 'error', 1);
    }

    protected function tearDown(): void
    {
        QUI::$Events = $this->OriginalEvents;
        QUI::$ProjectManager = $this->OriginalProjectManager;
        ProjectManager::$Standard = $this->OriginalStandard;
        ProjectManager::$projects = $this->originalProjects;
        (new ReflectionProperty(Utils::class, 'urlItemCache'))->setValue(null, $this->originalUrlCache);
        Logger::$Logger = $this->OriginalLogger;
        $this->LogConfig->setSection('log_levels', $this->originalLogLevels);
    }

    public static function avatarFallbacks(): iterable
    {
        foreach (['valid', 'missing', 'empty', 'invalid'] as $avatar) {
            foreach (['valid', 'missing', 'empty', 'invalid'] as $placeholder) {
                $expectedImage = $avatar === 'valid' ? 'avatar' : ($placeholder === 'valid' ? 'placeholder' : null);
                $expectedLevel = $expectedImage !== null ? null : ($placeholder === 'empty' ? Level::Info : Level::Error);

                yield $avatar . ' avatar, ' . $placeholder . ' placeholder' => [
                    $avatar, $placeholder, $expectedImage, $expectedLevel
                ];
            }
        }
    }

    #[DataProvider('avatarFallbacks')]
    public function testAvatarFallbackAndLogging(
        string $avatar,
        string $placeholder,
        ?string $expectedImage,
        ?Level $expectedLevel
    ): void {
        $Avatar = $this->createMock(Image::class);
        $Placeholder = $this->createMock(Image::class);
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('avatar-test');
        $placeholderUrl = $this->imageUrl($placeholder, 2);
        $Project->method('getConfig')->with('placeholder')->willReturn($placeholderUrl);

        $Media = $this->getMockBuilder(Media::class)
            ->setConstructorArgs([$Project])
            ->onlyMethods(['get'])
            ->getMock();
        $Media->method('get')->willReturnCallback(static function (int $id) use ($Avatar, $Placeholder): Image {
            return match ($id) {
                1 => $Avatar,
                2 => $Placeholder,
                default => throw new QUI\Exception('Media file with ID "7307" not found')
            };
        });
        $Project->method('getMedia')->willReturn($Media);
        ProjectManager::$Standard = $Project;
        ProjectManager::$projects['avatar-test']['_standard'] = $Project;

        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $User->method('getId')->willReturn(42);
        $User->setAttribute('avatar', $this->imageUrl($avatar, 1));
        $Expected = match ($expectedImage) {
            'avatar' => $Avatar,
            'placeholder' => $Placeholder,
            default => null
        };

        self::assertSame($Expected, $User->getAvatar());
        $records = $this->Handler->getRecords();

        if ($expectedLevel === null) {
            self::assertSame([], $records);
            return;
        }

        self::assertCount(1, $records);
        self::assertSame($expectedLevel, $records[0]->level);
        self::assertStringContainsString('user avatar placeholder image', $records[0]->message);
        self::assertSame(42, $records[0]->context['userId']);
        self::assertSame('avatar-test', $records[0]->context['project']);

        if ($expectedLevel === Level::Error) {
            self::assertSame($placeholderUrl, $records[0]->context['placeholder']);
        }
    }

    public function testEventAvatarTakesPrecedenceWithoutLogging(): void
    {
        $Image = $this->createMock(Image::class);
        $User = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAttribute'])
            ->getMock();
        $User->expects(self::never())->method('getAttribute');
        QUI::$Events->expects(self::once())->method('fireEvent')
            ->with('userGetAvatar', [$User])->willReturn([null, $Image]);

        self::assertSame($Image, $User->getAvatar());
        self::assertSame([], $this->Handler->getRecords());
    }

    private function imageUrl(string $state, int $id): string
    {
        return match ($state) {
            'valid' => 'image.php?project=avatar-test&id=' . $id,
            'missing' => 'image.php?project=avatar-test&id=7307',
            'invalid' => 'invalid-image-url',
            default => ''
        };
    }
}
