<?php

declare(strict_types=1);

namespace QUI\Package;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ComposerRepositoriesTest extends TestCase
{
    #[DataProvider('repositorySettings')]
    public function testRefreshOnlyMakesPublicComposerMirrorNonCanonical(
        string $url,
        string $type,
        bool $isPublicMirror,
        bool $packagistEnabled
    ): void {
        $directory = sys_get_temp_dir() . '/quiqqer-composer-repositories-' . bin2hex(random_bytes(8)) . '/';
        mkdir($directory, 0700);

        $options = ['http' => ['timeout' => 30]];
        $Manager = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getServerList', 'getList'])
            ->getMock();
        $Manager->method('getServerList')->willReturn([
            $url => ['type' => $type, 'active' => 1, 'options' => json_encode($options, JSON_THROW_ON_ERROR)],
            'packagist.org' => ['active' => (int)$packagistEnabled]
        ]);
        $Manager->method('getList')->willReturn([]);
        (new ReflectionProperty(Manager::class, 'varDir'))->setValue($Manager, $directory);
        (new ReflectionProperty(Manager::class, 'composer_json'))->setValue($Manager, $directory . 'composer.json');

        $expected = ['type' => $type, 'url' => $url, 'options' => $options];

        if ($isPublicMirror) {
            $expected['canonical'] = false;
        }

        try {
            // Setup regenerates this file, so the repository policy must survive repeated refreshes.
            for ($refresh = 0; $refresh < 2; $refresh++) {
                $Manager->refreshServerList();
                $config = json_decode(
                    file_get_contents($directory . 'composer.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                $repositories = array_values(array_filter(
                    $config['repositories'],
                    static fn(array $repository): bool => ($repository['url'] ?? null) === $url
                ));

                self::assertCount(1, $repositories);
                self::assertEquals($expected, $repositories[0]);
                self::assertSame(
                    !$packagistEnabled,
                    in_array(['packagist.org' => false], $config['repositories'], true)
                );
            }
        } finally {
            if (is_file($directory . 'composer.json')) {
                unlink($directory . 'composer.json');
            }

            rmdir($directory);
        }
    }

    public static function repositorySettings(): iterable
    {
        yield 'public mirror' => ['https://composer.quiqqer.com', 'composer', true, true];
        yield 'public mirror with trailing slash' => ['https://composer.quiqqer.com/', 'composer', true, true];
        yield 'disabled Packagist stays disabled' => ['https://composer.quiqqer.com/', 'composer', true, false];
        yield 'private repository' => ['https://packages.example.org', 'composer', false, true];
        yield 'different path' => ['https://composer.quiqqer.com/private', 'composer', false, true];
        yield 'different host' => ['https://composer.quiqqer.com.example.org', 'composer', false, true];
        yield 'VCS repository' => ['https://composer.quiqqer.com', 'vcs', false, true];
    }
}
