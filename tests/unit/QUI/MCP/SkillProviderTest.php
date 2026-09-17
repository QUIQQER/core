<?php

namespace QUI\MCP;

use PHPUnit\Framework\TestCase;
use QUI\AI\MCP\Skill\SkillRepository;

require_once dirname(__DIR__, 3) . '/stubs/QUI/AI/MCP/Skill/SkillRepository.php';
require_once dirname(__DIR__, 3) . '/stubs/QUI/AI/MCP/Skill/SkillProviderInterface.php';

class SkillProviderTest extends TestCase
{
    public function testPackageQualityUpgradeSkillIsRegistered(): void
    {
        $skills = $this->registeredSkillFiles();
        $workflow = $skills['quiqqer_developer_workflow']['content'];
        $skill = $skills['quiqqer_package_quality_upgrade'];

        $this->assertStringContainsString('quiqqer_package_quality_upgrade', $workflow);
        $this->assertMatchesRegularExpression('/^description: .*PHPStan 2 at level 8.*$/m', $skill['metadata']);
        $this->assertStringContainsString('Do not switch branches', $skill['content']);
        $this->assertStringContainsString('min: 80200', $skill['content']);
        $this->assertStringContainsString('max: 80509', $skill['content']);
        $this->assertStringContainsString('QUI::getDataBaseConnection()', $skill['content']);
        $this->assertStringContainsString('./tools/phpunit', $skill['content']);
    }

    public function testFrontendSkillsAreRegistered(): void
    {
        $skills = $this->registeredSkillFiles();
        $workflow = $skills['quiqqer_developer_workflow']['content'];

        $this->assertStringContainsString('quiqqer_frontend_css_variables', $workflow);
        $this->assertStringContainsString('quiqqer_frontend_javascript', $workflow);
        $this->assertStringContainsString('quiqqer_frontend_accessibility', $workflow);
        $this->assertStringContainsString('--_q-controlConf-', $skills['quiqqer_frontend_css_variables']['content']);
        $this->assertStringContainsString('data-name', $skills['quiqqer_frontend_javascript']['content']);
        $this->assertStringContainsString('aria-hidden', $skills['quiqqer_frontend_accessibility']['content']);
    }

    public function testSecureCodingSkillIsRegistered(): void
    {
        $skills = $this->registeredSkillFiles();
        $workflow = $skills['quiqqer_developer_workflow']['content'];
        $skill = $skills['quiqqer_secure_coding']['content'];

        $this->assertStringContainsString('quiqqer_secure_coding', $workflow);
        $this->assertStringContainsString("|escape:'html'", $skill);
        $this->assertStringContainsString('prepared statements', $skill);
        $this->assertStringContainsString('parameter binding', $skill);
    }

    /** @return array<string, array{metadata: string, content: string}> */
    private function registeredSkillFiles(): array
    {
        $names = [
            'quiqqer_developer_workflow',
            'quiqqer_extension_points',
            'quiqqer_package_quality_upgrade',
            'quiqqer_frontend_css_variables',
            'quiqqer_frontend_javascript',
            'quiqqer_frontend_accessibility',
            'quiqqer_secure_coding'
        ];
        $files = [];
        // Record the provider's real registrations without relying on the optional package's parser.
        $Repository = $this->createMock(SkillRepository::class);
        $Repository->expects(self::exactly(count($names)))
            ->method('addFromMarkdownFile')
            ->with(self::callback(static function (string $file) use (&$files): bool {
                $files[] = $file;
                return true;
            }));

        (new SkillProvider())->registerSkills($Repository);

        $skills = [];
        foreach ($files as $file) {
            self::assertFileIsReadable($file);
            $markdown = file_get_contents($file);
            self::assertIsString($markdown);
            self::assertSame(1, preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $markdown, $parts), $file);
            $name = pathinfo($file, PATHINFO_FILENAME);
            self::assertArrayNotHasKey($name, $skills, 'Each skill must be registered only once.');
            self::assertMatchesRegularExpression('/^name: ' . preg_quote($name, '/') . '$/m', $parts[1]);
            self::assertMatchesRegularExpression('/^description: \S[^\r\n]*$/m', $parts[1]);
            self::assertMatchesRegularExpression('/^category: developer$/m', $parts[1]);
            self::assertNotSame('', trim($parts[2]));
            $skills[$name] = ['metadata' => $parts[1], 'content' => $parts[2]];
        }

        self::assertEqualsCanonicalizing($names, array_keys($skills));

        return $skills;
    }
}
