<?php

declare(strict_types=1);

namespace QUI\Package;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class StoreMessagingTest extends TestCase
{
    public function testStoreMessagesRequireTheConfiguredOriginFrameAndAllowedMethod(): void
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('Node.js is required for the package store JavaScript regression tests.');
        }

        $command = [
            $node,
            '--test',
            dirname(__DIR__, 3) . '/javascript/PackageStoreMessaging.test.cjs'
        ];
        $Process = new Process($command, timeout: 30);
        $Process->run();

        self::assertSame(0, $Process->getExitCode(), $Process->getOutput() . $Process->getErrorOutput());
    }
}
