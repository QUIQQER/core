<?php

namespace QUITests\Fixtures\FrontendEntrypoint;

use stdClass;

final class QUI
{
    public static function getGlobalResponse(): object
    {
        return new stdClass();
    }

    public static function getRequest(): object
    {
        if (getenv('QUIQQER_TEST_ERROR_PHASE') === 'host') {
            return new \Symfony\Component\HttpFoundation\Request(
                server: [
                    'HTTP_HOST' => (string)getenv('QUIQQER_TEST_REQUEST_HOST')
                ]
            );
        }

        return new stdClass();
    }

    public static function getProjectManager(): ProjectManager
    {
        return new ProjectManager();
    }

    public static function getRewrite(): Rewrite
    {
        return new Rewrite();
    }
}
