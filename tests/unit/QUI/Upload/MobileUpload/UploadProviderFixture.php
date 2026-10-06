<?php

declare(strict_types=1);

namespace QUITests\Upload\MobileUpload;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\Upload\MobileUpload\Document;
use QUI\Upload\MobileUpload\ProviderInterface;

class UploadProviderFixture implements ProviderInterface
{
    public static bool $allowed = true;
    public static bool $fail = false;
    public static array $received = [];
    public static array $types = ['text/plain'];

    public function authorize(array $context, User $Issuer): string
    {
        if (!self::$allowed) {
            throw new QUI\Exception('Permission removed.', 403);
        }

        return $context['reference'];
    }

    public function getAllowedTypes(array $context): array
    {
        return self::$types;
    }

    public function receive(array $context, User $Issuer, Document $Document): void
    {
        if (self::$fail) {
            throw new \RuntimeException('Provider failed.');
        }

        self::$received[$Document->id] = [
            'content' => file_get_contents($Document->path),
            'name' => $Document->name,
            'issuer' => $Issuer->getUUID(),
            'context' => $context
        ];
    }
}
