<?php

declare(strict_types=1);

namespace QUI\Package;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Cache\LongTermCache;
use QUI\Composer\CLI;
use QUI\Composer\Composer;
use QUI\Composer\Web;

final class PackageDetailsTest extends TestCase
{
    /**
     * @param list<string> $requirementsOutput
     * @param list<string>|null $expectedRequirements
     */
    #[DataProvider('requirements')]
    public function testShowParsesMetadataAndRequirementsAndCachesTheResult(
        array $requirementsOutput,
        ?array $expectedRequirements,
        bool $tagged,
        bool $web
    ): void {
        $packageName = 'phpunit/details-' . bin2hex(random_bytes(8));
        $output = [
            '<warning>Warning: Composer diagnostic</warning>',
            'name     : ' . $packageName,
            'descrip. : Package description: https://example.test:8443/details',
            'versions : * 2.0.0, 1.0.0'
        ];

        if ($tagged) {
            $output[1] = '<info>name</info>     : ' . $packageName;
            $output[2] = '<info>descrip.</info> : Package description: https://example.test:8443/details';
            $output[3] = '<info>versions</info> : <info>* 2.0.0</info>, 1.0.0';
        }

        $output = array_merge($output, $requirementsOutput);
        $Composer = $this->createMock(Composer::class);
        $Runner = $this->createMock($web ? Web::class : CLI::class);
        $Composer->method('getRunner')->willReturn($Runner);
        $options = [
            '--no-ansi' => true,
            '--no-interaction' => true
        ];

        if ($web) {
            $options['package'] = $packageName;
        } else {
            $options['packages'] = [$packageName];
        }

        $Composer->expects(self::never())->method('show');
        $Composer->expects(self::once())->method('executeComposer')->with('show', $options)->willReturn($output);
        $Manager = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getComposer', 'checkComposer'])
            ->getMock();
        $Manager->method('getComposer')->willReturn($Composer);
        $Manager->expects(self::once())->method('checkComposer');
        $expected = [
            'name' => $packageName,
            'description' => 'Package description: https://example.test:8443/details',
            'versions' => [
                '* 2.0.0',
                '1.0.0'
            ]
        ];

        if ($expectedRequirements !== null) {
            $expected['require'] = $expectedRequirements;
        }

        try {
            LongTermCache::set('packages/cache/show/' . $packageName, []);
            self::assertSame($expected, $Manager->show($packageName));
            self::assertSame($expected, $Manager->show($packageName));
        } finally {
            LongTermCache::clear('packages/cache/show/' . $packageName);
            LongTermCache::clear('packages/cache/show/v2/' . $packageName);
        }
    }

    /** @return array<string, array{list<string>, list<string>|null, bool, bool}> */
    public static function requirements(): array
    {
        return [
            'plain CLI output with requirements at the end' => [
                [
                    '',
                    'requires',
                    'php >=8.2 <9.0',
                    'quiqqer/core ^2'
                ],
                [
                    'php >=8.2 <9.0',
                    'quiqqer/core ^2'
                ],
                false,
                false
            ],
            'web output has embedded newlines and further sections' => [
                [
                    "\nrequires",
                    'php >=8.2 <9.0',
                    'quiqqer/core ^2',
                    "\nrequires (dev)",
                    'phpunit/phpunit ^10.5',
                    "\nsuggests",
                    'ext-intl Optional: improve formatting',
                    "\nprovides",
                    'vendor/virtual 1.0'
                ],
                [
                    'php >=8.2 <9.0',
                    'quiqqer/core ^2'
                ],
                false,
                true
            ],
            'formatted metadata and sections' => [
                [
                    '<info>requires</info>',
                    'php <comment>>=8.2 <9.0</comment>',
                    '<info>requires (dev)</info>',
                    'phpunit/phpunit <comment>^10.5</comment>'
                ],
                ['php >=8.2 <9.0'],
                true,
                true
            ],
            'next section without a blank line is excluded' => [
                [
                    'requires',
                    'php ^8.2',
                    'suggests',
                    'ext-intl Optional extension'
                ],
                ['php ^8.2'],
                false,
                false
            ],
            'empty requirements do not include development dependencies' => [
                [
                    'requires',
                    'requires (dev)',
                    'phpunit/phpunit ^10.5'
                ],
                [],
                false,
                true
            ],
            'package without runtime requirements' => [
                [
                    'requires (dev)',
                    'phpunit/phpunit ^10.5',
                    '',
                    'suggests',
                    'ext-intl Optional extension'
                ],
                null,
                false,
                false
            ],
            'metadata only' => [
                [],
                null,
                false,
                true
            ]
        ];
    }
}
