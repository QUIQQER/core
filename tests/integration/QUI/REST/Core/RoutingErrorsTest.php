<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class RoutingErrorsTest extends RestIntegrationTestCase
{
    public function testUnsupportedMethodsReturnAllowAndTheCommonErrorEnvelope(): void
    {
        $Response = $this->request('PUT', '/users');
        self::assertSame(405, $Response->getStatusCode());
        self::assertStringContainsString('GET', $Response->getHeaderLine('Allow'));
        self::assertStringContainsString('POST', $Response->getHeaderLine('Allow'));
        $error = json_decode((string)$Response->getBody(), true);
        self::assertSame('method_not_allowed', $error['error']['code']);
        $Response = $this->request('GET', '/unknown-resource');
        self::assertSame(404, $Response->getStatusCode());
        $error = json_decode((string)$Response->getBody(), true);
        self::assertSame('not_found', $error['error']['code']);
    }
}
