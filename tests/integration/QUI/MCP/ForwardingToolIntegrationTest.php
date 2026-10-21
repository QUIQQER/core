<?php

declare(strict_types=1);

namespace QUI\MCP;

use Mcp\Server\Builder;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\AI\MCP\Server;
use QUI\Config;
use QUI\MCP\Forwarding\CreateForwarding;
use QUI\MCP\Forwarding\DeleteForwardings;
use QUI\MCP\Forwarding\GetForwarding;
use QUI\MCP\Forwarding\ListForwardings;
use QUI\MCP\Forwarding\UpdateForwarding;
use QUI\Permissions\Permission;
use ReflectionProperty;
use Throwable;

class ForwardingToolIntegrationTest extends TestCase
{
    private const CONFIG_KEY = 'etc/forwarding.ini.php';

    private string $configFile;

    private bool $hadPreviousConfig;

    private ?Config $PreviousConfig = null;

    protected function setUp(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'quiqqer-mcp-forwarding-');
        self::assertNotFalse($configFile);
        $this->configFile = $configFile;
        $this->hadPreviousConfig = array_key_exists(self::CONFIG_KEY, QUI::$Configs);

        if ($this->hadPreviousConfig) {
            $this->PreviousConfig = QUI::$Configs[self::CONFIG_KEY];
        }

        QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);
    }

    protected function tearDown(): void
    {
        if ($this->hadPreviousConfig && $this->PreviousConfig instanceof Config) {
            QUI::$Configs[self::CONFIG_KEY] = $this->PreviousConfig;
        } else {
            unset(QUI::$Configs[self::CONFIG_KEY]);
        }

        if (is_file($this->configFile)) {
            unlink($this->configFile);
        }
    }

    public function testForwardingToolLifecycle(): void
    {
        self::skipIfSuperUserIsUnavailable();

        self::runAsRootUser(function (): void {
            $first = self::invokeTool(new CreateForwarding(), [
                'https://example.test/old',
                'https://example.test/new',
                302
            ]);
            self::assertTrue($first['created']);
            self::assertSame(302, $first['forwarding']['httpCode']);

            self::invokeTool(new CreateForwarding(), ['/second', '/target', 301]);
            $list = self::invokeTool(new ListForwardings(), []);
            self::assertSame(2, $list['count']);
            self::assertSame(
                ['/second', 'https://example.test/old'],
                array_column($list['forwardings'], 'source')
            );

            $get = self::invokeTool(new GetForwarding(), ['https://example.test/old']);
            self::assertSame('https://example.test/new', $get['forwarding']['target']);

            $updated = self::invokeTool(new UpdateForwarding(), [
                'https://example.test/old',
                '/updated',
                308
            ]);
            self::assertTrue($updated['updated']);
            self::assertSame('/updated', $updated['forwarding']['target']);
            self::assertSame(308, $updated['forwarding']['httpCode']);

            $deleted = self::invokeTool(new DeleteForwardings(), [[
                'https://example.test/old',
                '/second'
            ]]);
            self::assertSame(2, $deleted['deleted']);
            self::assertCount(2, $deleted['forwardings']);
            self::assertSame(0, self::invokeTool(new ListForwardings(), [])['count']);
        });
    }

    public function testUnsafeCreateAndUpdateReturnErrorsWithoutChangingExistingRules(): void
    {
        self::skipIfSuperUserIsUnavailable();

        self::runAsRootUser(function (): void {
            self::invokeTool(new CreateForwarding(), ['/existing', '/original', 302]);
            $before = file_get_contents($this->configFile);

            foreach ([new CreateForwarding(), new UpdateForwarding()] as $Tool) {
                $result = self::invokeToolResult($Tool, [
                    'https://example.test/film-production[?]*',
                    'https://example.net/film-production-company-names',
                    301
                ]);
                self::assertInstanceOf(CallToolResult::class, $result);
                self::assertSame($before, file_get_contents($this->configFile));
            }

            $result = self::invokeToolResult(new UpdateForwarding(), [
                '/existing',
                "/target\nwith-line-break",
                301
            ]);
            self::assertInstanceOf(CallToolResult::class, $result);
            self::assertSame($before, file_get_contents($this->configFile));

            QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);
            $list = self::invokeTool(new ListForwardings(), []);
            self::assertSame([
                ['source' => '/existing', 'target' => '/original', 'httpCode' => 302]
            ], $list['forwardings']);
        });
    }

    public function testTargetFragmentSurvivesPersistingAndReloading(): void
    {
        self::skipIfSuperUserIsUnavailable();

        self::runAsRootUser(function (): void {
            $target = 'https://example.net/names?language=en#film';
            $created = self::invokeTool(new CreateForwarding(), ['https://example.test/film', $target, 301]);
            self::assertSame($target, $created['forwarding']['target']);
            QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);
            $stored = self::invokeTool(new GetForwarding(), ['https://example.test/film']);
            self::assertSame($target, $stored['forwarding']['target']);
        });
    }

    public function testCorruptFileIsAnErrorForListingLookupAndMutations(): void
    {
        self::skipIfSuperUserIsUnavailable();

        self::runAsRootUser(function (): void {
            self::invokeTool(new CreateForwarding(), ['/existing', '/original']);
            $broken = "[broken[?]*]\ntarget=\"/target\"\n";
            file_put_contents($this->configFile, $broken);

            $calls = [
                [new ListForwardings(), []],
                [new GetForwarding(), ['/existing']],
                [new CreateForwarding(), ['/new', '/target']],
                [new UpdateForwarding(), ['/existing', '/target']],
                [new DeleteForwardings(), [['/existing']]]
            ];

            foreach ($calls as [$Tool, $arguments]) {
                self::assertInstanceOf(CallToolResult::class, self::invokeToolResult($Tool, $arguments));
                self::assertSame($broken, file_get_contents($this->configFile));
            }
        });
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function invokeTool(ToolInterface $Tool, array $arguments): array
    {
        $result = self::invokeToolResult($Tool, $arguments);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>|CallToolResult
     */
    private static function invokeToolResult(ToolInterface $Tool, array $arguments): array|CallToolResult
    {
        $Builder = new Builder();
        $Tool->register($Builder);
        $tools = (new ReflectionProperty(Builder::class, 'tools'))->getValue($Builder);
        $Handler = $tools[0]['handler'] ?? $tools[0]['callback'] ?? null;

        self::assertIsCallable($Handler);
        return $Handler(...$arguments);
    }

    private static function skipIfSuperUserIsUnavailable(): void
    {
        try {
            $RootUser = QUI::getUsers()->get(QUI::conf('globals', 'rootuser'));
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER user database is unavailable: ' . $Exception->getMessage());
        }

        if (!$RootUser->isSU()) {
            self::markTestSkipped('QUIQQER database has no usable super-user fixture.');
        }
    }

    private static function runAsRootUser(callable $Callback): mixed
    {
        $Users = QUI::getUsers();
        $RootUser = $Users->get(QUI::conf('globals', 'rootuser'));
        $SessionProperty = new ReflectionProperty($Users, 'Session');
        $PermissionProperty = new ReflectionProperty(Permission::class, 'User');
        $RequestUserProperty = new ReflectionProperty(Server::class, 'RequestUser');
        $PreviousSessionUser = $SessionProperty->getValue($Users);
        $PreviousPermissionUser = $PermissionProperty->getValue();
        $PreviousRequestUser = $RequestUserProperty->getValue();

        $SessionProperty->setValue($Users, $RootUser);
        $PermissionProperty->setValue(null, $RootUser);
        $RequestUserProperty->setValue(null, $RootUser);

        try {
            return $Callback();
        } finally {
            $SessionProperty->setValue($Users, $PreviousSessionUser);
            $PermissionProperty->setValue(null, $PreviousPermissionUser);
            $RequestUserProperty->setValue(null, $PreviousRequestUser);
        }
    }
}
