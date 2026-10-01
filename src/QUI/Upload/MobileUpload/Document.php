<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

/**
 * A validated upload, available only for the duration of the provider's receive() call.
 */
final class Document
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $name
    ) {
    }
}
