<?php

namespace QUI\System;

use PHPUnit\Framework\TestCase;

class MaintenanceOperationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quiqqer-maintenance-info-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory . 'locks', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . 'locks/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory . 'locks');
        rmdir($this->directory);
    }

    public function testUnlockedMetadataFromAnEndedProcessIsIgnored(): void
    {
        $data = [
            'operation' => 'Old setup',
            'pid' => getmypid(),
            'startedAt' => time()
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        file_put_contents($this->directory . 'locks/maintenance-operation-stale.json', $json);

        self::assertSame([], MaintenanceOperation::active($this->directory));
    }

    public function testEachActiveParticipantIsReportedUntilItReleasesItsGuard(): void
    {
        $Owner = MaintenanceOperation::start($this->directory, 'System update', str_repeat('a', 32));
        $Child = MaintenanceOperation::start($this->directory, 'Package setup', null);

        self::assertNotNull($Owner);
        self::assertNotNull($Child);

        try {
            self::assertCount(2, MaintenanceOperation::active($this->directory));
            $Owner->close();
            $active = MaintenanceOperation::active($this->directory);

            self::assertCount(1, $active);
            self::assertSame('Package setup', $active[0]['operation']);
        } finally {
            $Owner->close();
            $Child->close();
        }

        self::assertSame([], glob($this->directory . 'locks/*'));
        self::assertSame([], MaintenanceOperation::active($this->directory));
    }

    public function testMalformedActiveMetadataIsIgnored(): void
    {
        $handle = fopen($this->directory . 'locks/maintenance-operation-malformed.json', 'w+');
        self::assertIsResource($handle);

        try {
            self::assertTrue(flock($handle, LOCK_EX));
            fwrite($handle, '{"operation":"setup","pid":"invalid"}');
            fflush($handle);

            self::assertSame([], MaintenanceOperation::active($this->directory));
        } finally {
            fclose($handle);
        }
    }
}
