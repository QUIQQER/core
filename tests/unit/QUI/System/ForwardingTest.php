<?php

declare(strict_types=1);

namespace QUITests\System;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Config;
use QUI\Log\Logger;
use QUI\System\Forwarding;
use Symfony\Component\HttpFoundation\Request;

class ForwardingTest extends TestCase
{
    private const CONFIG_KEY = 'etc/forwarding.ini.php';

    private string $configFile;

    private bool $hadPreviousConfig;

    private ?Config $PreviousConfig = null;

    private MonologLogger $PreviousLogger;

    private TestHandler $LogHandler;

    private Config $LogConfig;

    /**
     * @var array<string, mixed>
     */
    private array $previousLogLevels;

    protected function setUp(): void
    {
        $this->PreviousLogger = Logger::getLogger();
        $this->LogHandler = new TestHandler();
        Logger::$Logger = new MonologLogger('forwarding-test', [$this->LogHandler]);
        $LogConfig = QUI\Log\Config::getPackageConfig();
        self::assertNotNull($LogConfig);
        $this->LogConfig = $LogConfig;
        $this->previousLogLevels = $LogConfig->get('log_levels');
        $LogConfig->setValue('log_levels', 'error', 1);

        $configFile = tempnam(sys_get_temp_dir(), 'quiqqer-forwarding-');
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
        Logger::$Logger = $this->PreviousLogger;
        $this->LogConfig->setSection('log_levels', $this->previousLogLevels);

        if ($this->hadPreviousConfig && $this->PreviousConfig instanceof Config) {
            QUI::$Configs[self::CONFIG_KEY] = $this->PreviousConfig;
        } else {
            unset(QUI::$Configs[self::CONFIG_KEY]);
        }

        if (is_file($this->configFile)) {
            unlink($this->configFile);
        }
    }

    public function testCreateUpdateListAndSingleDeleteLifecycle(): void
    {
        Forwarding::create('https://example.test/old', 'https://example.test/new', 302);

        self::assertSame([
            'https://example.test/old' => [
                'target' => 'https://example.test/new',
                'code' => 302
            ]
        ], Forwarding::getList()->toArray());

        Forwarding::update('https://example.test/old', '/new-target', 307);
        self::assertSame([
            'target' => '/new-target',
            'code' => 307
        ], Forwarding::getList()->toArray()['https://example.test/old']);

        Forwarding::delete('https://example.test/old');
        self::assertSame([], Forwarding::getList()->toArray());
    }

    public function testEmptyHttpCodeUsesPermanentRedirectDefault(): void
    {
        Forwarding::create('/old', '/new', '');

        self::assertSame(301, Forwarding::getList()->toArray()['/old']['code']);
        self::assertSame([], $this->LogHandler->getRecords());
    }

    public function testDuplicateCreateIsRejected(): void
    {
        Forwarding::create('/duplicate', '/first');

        $this->expectException(QUI\Exception::class);
        Forwarding::create('/duplicate', '/second');
    }

    public function testUpdateOfUnknownSourceIsRejected(): void
    {
        $this->expectException(QUI\Exception::class);
        $this->expectExceptionCode(404);
        Forwarding::update('/missing', '/target');
    }

    public function testMultipleForwardingsCanBeDeletedTogether(): void
    {
        Forwarding::create('/one', '/target-one');
        Forwarding::create('/two', '/target-two');

        Forwarding::delete(['/one', '/two']);

        self::assertSame([], Forwarding::getList()->toArray());
    }

    public function testNonMatchingRequestIsNotForwarded(): void
    {
        Forwarding::create('https://example.test/match', '/target');

        Forwarding::forward(Request::create('https://example.test/no-match'));

        self::assertTrue(true);
    }

    public function testExactRequestCanBeResolvedWithoutRedirecting(): void
    {
        Forwarding::create('https://example.test/exact', '/target', 302);

        self::assertSame([
            'target' => '/target',
            'code' => 302
        ], Forwarding::resolve(Request::create('https://example.test/exact')));
    }

    public function testRequestWithTrailingSlashUsesTrimmedRule(): void
    {
        Forwarding::create('https://example.test', '/target', 301);

        self::assertSame([
            'target' => '/target',
            'code' => 301
        ], Forwarding::resolve(Request::create('https://example.test/')));
    }

    public function testWildcardRuleCanBeResolvedWithoutRedirecting(): void
    {
        Forwarding::create('https://example.test/legacy/*', '/target', 308);

        self::assertSame([
            'target' => '/target',
            'code' => 308
        ], Forwarding::resolve(Request::create('https://example.test/legacy/article')));
    }

    public function testRedirectResponseUsesConfiguredTargetAndCode(): void
    {
        $Response = Forwarding::createRedirectResponse([
            'target' => '/new-location',
            'code' => 308
        ]);

        self::assertSame('/new-location', $Response->getTargetUrl());
        self::assertSame(308, $Response->getStatusCode());
    }

    public function testRedirectResponseUsesCoreDefaultsForEmptyValues(): void
    {
        $Response = Forwarding::createRedirectResponse([
            'target' => '',
            'code' => 0
        ]);

        self::assertSame(URL_DIR, $Response->getTargetUrl());
        self::assertSame(301, $Response->getStatusCode());
        self::assertSame([], $this->LogHandler->getRecords());
    }

    #[DataProvider('allowedHttpCodes')]
    public function testAllowedHttpCodesCanBeCreatedUpdatedAndExecuted(int|string $code): void
    {
        Forwarding::create('/old', '/created', $code);
        self::assertSame((int)$code, Forwarding::getList()->toArray()['/old']['code']);

        Forwarding::update('/old', '/updated', $code);
        $Response = Forwarding::createRedirectResponse(Forwarding::getList()->toArray()['/old']);

        self::assertSame((int)$code, $Response->getStatusCode());
        self::assertSame('/updated', $Response->headers->get('Location'));
        self::assertSame([], $this->LogHandler->getRecords());
    }

    #[DataProvider('invalidHttpCodes')]
    public function testInvalidCreateLogsErrorAndPersistsPermanentRedirect(int|string $code): void
    {
        Forwarding::create('/invalid', '/target', $code);
        QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);

        self::assertSame([
            'target' => '/target',
            'code' => '301'
        ], Forwarding::getList()->toArray()['/invalid']);
        $this->assertFallbackWasLogged($code);
    }

    #[DataProvider('invalidHttpCodes')]
    public function testInvalidUpdateLogsErrorAndPersistsPermanentRedirect(int|string $code): void
    {
        Forwarding::create('/existing', '/original', 302);
        Forwarding::update('/existing', '/changed', $code);
        QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);

        self::assertSame([
            'target' => '/changed',
            'code' => '301'
        ], Forwarding::getList()->toArray()['/existing']);
        $this->assertFallbackWasLogged($code);
    }

    #[DataProvider('invalidHttpCodes')]
    public function testManuallyConfiguredInvalidRuleLogsErrorAndUsesPermanentRedirect(int|string $code): void
    {
        $Config = QUI::$Configs[self::CONFIG_KEY];
        $Config->setValue('https://example.test/invalid', 'target', '/target');
        $Config->setValue('https://example.test/invalid', 'code', $code);
        $Config->save();
        QUI::$Configs[self::CONFIG_KEY] = new Config($this->configFile);

        $rule = Forwarding::resolve(Request::create('https://example.test/invalid'));
        self::assertNotNull($rule);
        $Response = Forwarding::createRedirectResponse($rule);

        self::assertSame(301, $Response->getStatusCode());
        self::assertSame('/target', $Response->headers->get('Location'));
        $this->assertFallbackWasLogged($code);
    }

    private function assertFallbackWasLogged(int|string $code): void
    {
        $records = $this->LogHandler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('Unsupported forwarding HTTP status; using 301.', $records[0]->message);
        self::assertSame((string)$code, (string)$records[0]->context['httpCode']);
        $trace = $records[0]->context['trace'];
        self::assertNotEmpty($trace);
        self::assertSame(Forwarding::class, $trace[0]['class']);
        self::assertArrayHasKey('file', $trace[0]);
        self::assertArrayHasKey('line', $trace[0]);

        foreach ($trace as $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayNotHasKey('object', $frame);
        }
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function allowedHttpCodes(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $code) {
            yield 'integer ' . $code => [$code];
            yield 'string ' . $code => [(string)$code];
        }
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function invalidHttpCodes(): iterable
    {
        foreach ([200, 300, 304, 305, 306, 404, 500, -1, 999] as $code) {
            yield 'integer ' . $code => [$code];
            yield 'string ' . $code => [(string)$code];
        }

        yield 'trailing text' => ['301abc'];
        yield 'fractional number' => ['302.5'];
        yield 'scientific notation' => ['3.01e2'];
        yield 'nonnumeric input' => ['invalid'];
    }
}
