<?php

declare(strict_types=1);

namespace QUI\MCP;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class McpPermissionDefinitionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function permissionProvider(): iterable
    {
        yield 'users' => ['quiqqer.core.mcp.users.canUse'];
        yield 'groups' => ['quiqqer.core.mcp.groups.canUse'];
        yield 'permissions' => ['quiqqer.core.mcp.permissions.canUse'];
        yield 'forwardings' => ['quiqqer.core.mcp.manageForwardings'];
    }

    #[DataProvider('permissionProvider')]
    public function testMcpPermissionIsRootOnlyByDefault(string $permission): void
    {
        $Document = new DOMDocument();
        self::assertTrue($Document->load(dirname(__DIR__, 4) . '/permissions.xml'));

        $XPath = new DOMXPath($Document);
        $Nodes = $XPath->query('//permission[@name="' . $permission . '"]');

        self::assertNotFalse($Nodes);
        self::assertCount(1, $Nodes);

        $Permission = $Nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $Permission);
        self::assertSame('0', $Permission->getElementsByTagName('defaultvalue')->item(0)?->textContent);
        self::assertSame('1', $Permission->getElementsByTagName('rootPermission')->item(0)?->textContent);
        self::assertSame('0', $Permission->getElementsByTagName('everyonePermission')->item(0)?->textContent);
        self::assertSame('0', $Permission->getElementsByTagName('guestPermission')->item(0)?->textContent);
    }

    #[DataProvider('permissionProvider')]
    public function testMcpPermissionHasGermanAndEnglishLocale(string $permission): void
    {
        foreach (['de', 'en'] as $language) {
            $Document = new DOMDocument();
            self::assertTrue($Document->load(
                dirname(__DIR__, 4) . '/locale/' . $language . '.xml'
            ));

            $XPath = new DOMXPath($Document);
            $Nodes = $XPath->query('//locale[@name="permission.' . $permission . '"]');

            self::assertNotFalse($Nodes);
            self::assertCount(1, $Nodes);
            self::assertNotSame('', trim((string)$Nodes->item(0)?->textContent));
        }
    }
}
