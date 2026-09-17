<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\System\Update\RunRepository;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class SystemTest extends RestIntegrationTestCase
{
    public function testPreparedUpdatesCanBeMonitoredAndCancelledWithoutStartingProcesses(): void
    {
        $Repository = new RunRepository(rtrim(VAR_DIR, '/') . '/update/runs/');
        $prepared = $this->data($this->request('POST', '/system/updates/prepare'), 202);
        self::assertTrue($prepared['created']);
        self::assertFalse($prepared['started']);
        $id = $prepared['run']['id'];

        try {
            $Again = $this->data($this->request('POST', '/system/updates/prepare'), 202);
            self::assertFalse($Again['created']);
            self::assertSame($id, $Again['run']['id']);
            self::assertArrayNotHasKey('token', $Again);
            $status = $this->data($this->request('GET', '/system/updates/' . $id));
            self::assertSame($id, $status['run']['id']);
            self::assertStringNotContainsString($prepared['token'], json_encode($status));
            self::assertContains($id, array_column($this->data($this->request('GET', '/system/updates/active')), 'id'));
            $cancelled = $this->data($this->request('POST', '/system/updates/' . $id . '/cancel'));
            self::assertSame('cancelled', $cancelled['run']['status']);
            self::assertFalse($cancelled['signalSent']);
            self::assertSame([], $this->data($this->request('GET', '/system/updates/active')));
        } finally {
            $Repository->delete($id);
        }
    }

    public function testSystemInfoAndUnknownCacheArea(): void
    {
        $info = $this->data($this->request('GET', '/system/info'));
        self::assertSame(PHP_VERSION, $info['php']['version']);
        self::assertGreaterThan(0, $info['packageCount']);
        self::assertArrayNotHasKey('password', $info['database']);
        self::assertSame(422, $this->request('POST', '/system/cache/clear', ['areas' => ['invalid']])->getStatusCode());
        self::assertSame(422, $this->request('GET', '/system/updates/not-an-id')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/system/updates/' . str_repeat('0', 32))->getStatusCode());
    }
}
