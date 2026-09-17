<?php

namespace QUI\REST\Core;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $status = 422
    ) {
        parent::__construct($message, $status);
    }
}
