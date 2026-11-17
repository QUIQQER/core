<?php

declare(strict_types=1);

namespace QUI\Package;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use QUI\Cache\LongTermCache;
use QUI\Composer\Composer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PackageDetailsIntegrationTest extends TestCase
{
    private string $directory;
    private string $packageName;
    private string $originalDirectory;
    private string|false $originalComposerHome;
    private string|false $originalDisableNetwork;

    protected function setUp(): void
    {
        parent::setUp();

        $id = bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/quiqqer-package-details-' . $id;
        $this->packageName = 'phpunit/details-' . $id;
        $this->originalDirectory = (string)getcwd();
        $this->originalComposerHome = getenv('COMPOSER_HOME');
        $this->originalDisableNetwork = getenv('COMPOSER_DISABLE_NETWORK');
        putenv('COMPOSER_DISABLE_NETWORK=1');

        mkdir($this->directory . '/vendor/composer', 0700, true);
        mkdir($this->directory . '/vendor/' . $this->packageName, 0700, true);
        $this->writeJson($this->directory . '/composer.json', [
            'name' => 'phpunit/details-project',
            'version' => '1.0.0',
            'require' => [
                $this->packageName => '1.2.3'
            ],
            'repositories' => [
                ['packagist.org' => false]
            ]
        ]);
        $this->writeJson($this->directory . '/vendor/composer/installed.json', [
            'packages' => [
                [
                    'name' => $this->packageName,
                    'version' => '1.2.3',
                    'version_normalized' => '1.2.3.0',
                    'type' => 'library',
                    'description' => 'Details: keep https://example.test:8443/details',
                    'require' => [
                        'php' => '>=8.2 <9.0',
                        'ext-json' => '*',
                        'vendor/runtime' => '^1.0'
                    ],
                    'require-dev' => [
                        'phpunit/phpunit' => '^10.5'
                    ],
                    'suggest' => [
                        'ext-intl' => 'Optional: improve formatting'
                    ],
                    'provide' => [
                        'vendor/virtual' => '1.0'
                    ],
                    'install-path' => '../' . $this->packageName
                ],
                [
                    'name' => 'phpunit/unrelated-package',
                    'version' => '9.9.9',
                    'type' => 'library'
                ]
            ],
            'dev' => false,
            'dev-package-names' => []
        ]);
        copy(VAR_DIR . 'composer/composer.phar', $this->directory . '/composer.phar');
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        putenv(
            $this->originalComposerHome === false
                ? 'COMPOSER_HOME'
                : 'COMPOSER_HOME=' . $this->originalComposerHome
        );
        putenv(
            $this->originalDisableNetwork === false
                ? 'COMPOSER_DISABLE_NETWORK'
                : 'COMPOSER_DISABLE_NETWORK=' . $this->originalDisableNetwork
        );
        LongTermCache::clear('packages/cache/show/v2/' . $this->packageName);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    #[DataProvider('composerModes')]
    public function testShowReadsRealComposerDetailsInBothRunners(int $mode): void
    {
        $Composer = new Composer($this->directory);
        $Composer->setMode($mode);
        $Composer->mute();
        $Manager = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getComposer', 'checkComposer'])
            ->getMock();
        $Manager->method('getComposer')->willReturn($Composer);
        $Manager->method('checkComposer');

        $details = $Manager->show($this->packageName);

        self::assertSame($this->packageName, $details['name']);
        self::assertSame('Details: keep https://example.test:8443/details', $details['description']);
        self::assertSame(['* 1.2.3'], $details['versions']);
        self::assertSame([
            'php >=8.2 <9.0',
            'ext-json *',
            'vendor/runtime ^1.0'
        ], $details['require']);
    }

    /** @return array<string, array{int}> */
    public static function composerModes(): array
    {
        return [
            'CLI' => [Composer::MODE_CLI],
            'Web' => [Composer::MODE_WEB]
        ];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        file_put_contents($path, $json);
    }
}
