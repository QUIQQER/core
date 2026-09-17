<?php

declare(strict_types=1);

namespace QUITests\Projects\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Exception;
use QUI\Projects\Site\Utils;

class NameValidationTest extends TestCase
{
    public static function validNames(): array
    {
        return [
            'Chinese privacy policy' => ['隐私政策'],
            'Japanese privacy policy' => ['プライバシーポリシー'],
            'Japanese kanji and hiragana' => ['私たち'],
            'Japanese page name' => ['寿司の日'],
            'Supplementary CJK character' => ['𠮷野家'],
            'Latin name' => ['über-uns'],
            'ASCII minimum' => ['abc'],
            'Unicode minimum' => ['日本語'],
            'ASCII maximum' => [str_repeat('a', 200)],
            'Unicode maximum' => [str_repeat('私', 200)],
            'Supplementary Unicode maximum' => [str_repeat('𠮷', 200)]
        ];
    }

    #[DataProvider('validNames')]
    public function testAcceptsValidUnicodeNames(string $name): void
    {
        self::assertTrue(Utils::checkName($name));
    }

    public static function invalidNames(): array
    {
        $cases = [
            'Empty' => ['', 701],
            'One ASCII character' => ['a', 701],
            'Two ASCII characters' => ['ab', 701],
            'One Unicode character' => ['私', 701],
            'Two Unicode characters' => ['中文', 701],
            'Too many ASCII characters' => [str_repeat('a', 201), 704],
            'Too many Unicode characters' => [str_repeat('私', 201), 704],
            'Invalid UTF-8' => ["page-\xC3\x28", 702],
            'Truncated UTF-8' => ["page-\xE7\xA7", 702]
        ];

        $forbiddenSigns = [
            '.', ',', ':', ';', '#', '`', '!', '§', '$', '%', '&', '/', '?', '<', '>', '=',
            "'", '"', '@', '_', ']', '[', '+'
        ];

        foreach ($forbiddenSigns as $sign) {
            $cases['Forbidden ' . $sign] = ['日本' . $sign . '語', 702];
        }

        return $cases;
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $name, int $code): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode($code);

        Utils::checkName($name);
    }
}
