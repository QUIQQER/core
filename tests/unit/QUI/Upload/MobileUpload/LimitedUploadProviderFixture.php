<?php

declare(strict_types=1);

namespace QUITests\Upload\MobileUpload;

use QUI\Interfaces\Users\User;
use QUI\Upload\MobileUpload\LimitsProviderInterface;

final class LimitedUploadProviderFixture extends UploadProviderFixture implements LimitsProviderInterface
{
    public function getLimits(array $context, User $Issuer): array
    {
        return [
            'maxBytes' => 3,
            'maxFiles' => 1
        ];
    }
}
