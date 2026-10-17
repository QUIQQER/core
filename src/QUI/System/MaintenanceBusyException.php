<?php

namespace QUI\System;

use RuntimeException;

class MaintenanceBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A setup, installation or system update is already active. Try again later.');
    }
}
