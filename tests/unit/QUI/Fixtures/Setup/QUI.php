<?php

namespace QUITests\Fixtures\Setup;

class QUI
{
    public static function conf(string $section, string $key): string
    {
        return 'php';
    }

    public static function getEvents(): Events
    {
        return new Events();
    }
}
