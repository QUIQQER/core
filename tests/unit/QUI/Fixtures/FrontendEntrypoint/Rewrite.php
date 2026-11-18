<?php

namespace QUITests\Fixtures\FrontendEntrypoint;

final class Rewrite
{
    public function exec(): void
    {
        if (getenv('QUIQQER_TEST_ERROR_PHASE') === 'host') {
            \QUI::getRequest()->getHost();
        }

        if (getenv('QUIQQER_TEST_ERROR_PHASE') === 'routing') {
            self::fail();
        }
    }

    public function getProject(): never
    {
        self::fail();
    }

    private static function fail(): never
    {
        $class = getenv('QUIQQER_TEST_ERROR_CLASS');
        throw new $class('private error details');
    }
}
