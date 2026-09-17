<?php

namespace QUI\Utils;

use DOMNode;
use PHPUnit\Framework\TestCase;
use QUI\Editor\Utils as EditorUtils;
use QUI\Utils\Text\XML;
use QUI\Utils\XML\Settings;

class XmlRootCompatibilityTest extends TestCase
{
    public function testLegacyAndQuiqqerRootsProduceTheSameDeclarations(): void
    {
        $cases = [
            ['getConsoleToolsFromXml', '<console><tool exec="Vendor::run"/></console>'],
            ['getDataBaseFromXml', '<database><global><table name="sample">'
                . '<field type="integer">id</field><primary>id</primary></table></global></database>'],
            ['getEventsFromXml', '<events><event on="save" fire="Vendor::save" priority="20"/></events>'],
            ['getMenuItemsXml', '<menu><item name="sample" parent="/">Sample</item></menu>'],
            ['getPermissionsFromXml', '<permissions><permission name="sample" type="bool">'
                . '<defaultvalue>0</defaultvalue></permission></permissions>'],
            ['getTypesFromXml', '<site><types><type type="types/sample"/></types></site>'],
            ['getSiteEventsFromXml', '<site><types><type type="types/sample">'
                . '<event on="save" fire="Vendor::save"/></type></types></site>'],
            ['getLayoutsFromXml', '<site><layouts><layout type="sample"/></layouts></site>'],
            ['getTemplateEnginesFromXml', '<template_engines><engine '
                . 'class_name="Vendor\\Engine">sample</engine></template_engines>'],
            ['getWysiwygEditorsFromXml', '<editors><editor name="sample" '
                . 'component="package/vendor/editor/bin/Editor"/></editors>'],
            ['getWidgetsFromXml', '<widgets><widget><title>Sample</title></widget></widgets>'],
            ['getWidgetFromXml', '<widgets><widget><title>Sample</title></widget></widgets>'],
            ['getTabsFromXml', '<user><window><tab name="legacy"><text>Sample</text></tab></window></user>']
        ];

        foreach ($cases as [$method, $xml]) {
            $this->compareRoots($xml, static fn(string $file) => XML::$method($file), $method);
        }
    }

    public function testLocaleTextAndMetadataSurviveTheWrapper(): void
    {
        $this->compareRoots(
            '<locales><groups name="vendor/package" datatype="php,js"><locale name="sample" '
                . 'html="true" priority="20"><de><![CDATA[ <b>Text</b> ]]></de>'
                . '<fr><![CDATA[ ]]></fr><en/></locale></groups></locales>',
            static fn(string $file) => XML::getLocaleGroupsFromDom(XML::getDomFromXml($file)),
            'locales'
        );
    }

    public function testUserAndGroupSettingsKeepTheirConfiguredPaths(): void
    {
        foreach (['user', 'group'] as $entity) {
            $Settings = new Settings();
            $Settings->setXMLPath('//' . $entity . '/window');

            $this->compareRoots(
                '<' . $entity . '><window><categories><category name="sample"><text>Sample</text>'
                    . '<settings><input conf="vendor.option" type="text"/></settings>'
                    . '</category></categories></window></' . $entity . '>',
                static fn(string $file) => $Settings->getPanel([$file]),
                $entity
            );
        }
    }

    public function testEditorDefinitionsKeepToolbarDeclarations(): void
    {
        $this->compareRoots(
            '<editors><editor name="sample" component="package/vendor/editor/bin/Editor">'
                . '<toolbars><toolbar name="basic" src="OPT_DIR/vendor/editor/basic.json"/>'
                . '</toolbars></editor></editors>',
            EditorUtils::getWysiwygEditorDefinitionsFromXml(...),
            'editor toolbars'
        );
    }

    public function testLegacyXmlToolbarsRemainReadableWithTheWrapper(): void
    {
        $this->compareRoots(
            '<toolbar><line><group><button>Bold</button><separator/><button>Italic</button>'
                . '</group></line></toolbar>',
            static function (string $file): array {
                // Force both versions through the reader instead of reusing the first cached result.
                \QUI\Cache\Manager::clear('settings/editor/xml');

                return \QUI\Editor\Manager::parseXmlFileToArray($file);
            },
            'legacy XML toolbar'
        );
    }

    private function compareRoots(string $xml, callable $reader, string $label): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'quiqqer-root-');
        $this->assertNotFalse($temporary);
        unlink($temporary);
        $file = $temporary . '.xml';

        try {
            file_put_contents($file, $xml);
            $legacy = $this->normalize($reader($file));
            $this->assertNotEmpty($legacy, $label);

            file_put_contents(
                $file,
                '<quiqqer xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
                    . 'xsi:noNamespaceSchemaLocation="https://example.org/schema.xsd">' . $xml . '</quiqqer>'
            );
            $this->assertSame($legacy, $this->normalize($reader($file)), $label);
        } finally {
            unlink($file);
        }
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \Traversable && !($value instanceof DOMNode)) {
            return $this->normalize(iterator_to_array($value));
        }

        if ($value instanceof DOMNode) {
            $attributes = [];

            foreach ($value->attributes ?? [] as $attribute) {
                $attributes[$attribute->nodeName] = $attribute->nodeValue;
            }

            $children = [];

            foreach ($value->childNodes as $child) {
                $children[] = $this->normalize($child);
            }

            return [$value->nodeName, $attributes, $value->nodeValue, $children];
        }

        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }
}
