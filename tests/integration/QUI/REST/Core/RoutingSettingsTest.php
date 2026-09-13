<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use QUI;
use QUI\Projects\ProjectTestHelper;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class RoutingSettingsTest extends RestIntegrationTestCase
{
    public function testForwardingLifecyclePreservesOmittedFields(): void
    {
        $source = '/rest-test-' . bin2hex(random_bytes(5)) . '/deep/path';
        $created = $this->data($this->request('POST', '/forwardings', [
            'source' => $source, 'target' => '/destination', 'httpCode' => 307
        ]), 201);
        $path = '/forwardings/' . $created['id'];

        try {
            self::assertSame($source, $this->data($this->request('GET', $path))['source']);
            $updated = $this->data($this->request('PATCH', $path, ['target' => '/new-destination']));
            self::assertSame(307, $updated['httpCode']);
            self::assertSame(422, $this->request('PATCH', $path, ['httpCode' => 200])->getStatusCode());
            self::assertSame('/new-destination', $this->data($this->request('GET', $path))['target']);
            self::assertSame(409, $this->request('POST', '/forwardings', [
                'source' => $source, 'target' => '/duplicate'
            ])->getStatusCode());
            self::assertSame(204, $this->request('DELETE', $path)->getStatusCode());
            self::assertSame(404, $this->request('GET', $path)->getStatusCode());
        } finally {
            QUI\System\Forwarding::delete($source);
        }
    }

    public function testVHostLifecycleAndLanguageValidation(): void
    {
        $Project = ProjectTestHelper::getProject();
        $host = 'rest-' . bin2hex(random_bytes(5)) . '.example.invalid';
        $path = '/vhosts/' . $host;

        try {
            $created = $this->data($this->request('POST', '/vhosts', [
                'host' => $host, 'project' => $Project->getName(), 'rootLanguage' => 'de', 'wwwRedirect' => 'none'
            ]), 201);
            self::assertSame('de', $created['rootLanguage']);
            $updated = $this->data($this->request('PATCH', $path, ['httpsHost' => $host]));
            self::assertSame('de', $updated['rootLanguage']);
            self::assertSame($host, $updated['httpsHost']);
            self::assertSame(404, $this->request('PATCH', $path, ['rootLanguage' => 'xx'])->getStatusCode());
            self::assertSame('de', $this->data($this->request('GET', $path))['rootLanguage']);
            self::assertSame(204, $this->request('DELETE', $path)->getStatusCode());
            self::assertSame(404, $this->request('GET', $path)->getStatusCode());
        } finally {
            $Manager = new QUI\System\VhostManager();

            if (is_array($Manager->getVhost($host))) {
                $Manager->removeVhost($host);
            }
        }
    }
}
