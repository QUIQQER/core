<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI\Interfaces\Users\User;

/**
 * Implementations are selected by trusted application code, never by the public upload request.
 */
interface ProviderInterface
{
    /**
     * Recheck current permissions and context; return only a short, public reference.
     *
     * @param array<string, string> $context
     */
    public function authorize(array $context, User $Issuer): string;

    /**
     * @param array<string, string> $context
     * @return list<string> Accepted MIME types; an empty list permits any file type.
     */
    public function getAllowedTypes(array $context): array;

    /**
     * Persist the uploaded file before returning. Must be idempotent for Document::id.
     *
     * @param array<string, string> $context
     */
    public function receive(array $context, User $Issuer, Document $Document): void;
}
