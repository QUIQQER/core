<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI\Interfaces\Users\User;

/** Optional destination-specific limits, for example the selected media project's settings. */
interface LimitsProviderInterface extends ProviderInterface
{
    /**
     * @param array<string, string> $context
     * @return array{maxBytes: int, maxFiles: int}
     */
    public function getLimits(array $context, User $Issuer): array;
}
