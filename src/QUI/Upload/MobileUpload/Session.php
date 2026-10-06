<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

/**
 * A bounded upload capability; contains no plaintext bearer token.
 */
final class Session
{
    /**
     * @var array<string, array{digest: string, name: string}>
     */
    public array $receipts = [];

    public bool $closed = false;
    public int $bytes = 0;
    public int $rateStart = 0;
    public int $rateCount = 0;

    public string $code = '';
    public int $codeStep = -1;
    public int $usedCodeStep = -1;
    public int $failedCodes = 0;
    public int $blockedUntil = 0;

    /** @var array<string, int> Device ID => upload expiry. */
    public array $grants = [];

    // Old bearer-only sessions are deliberately invalidated during deployment.
    public int $securityVersion = 2;

    /**
     * @param class-string<ProviderInterface> $provider
     * @param array<string, string> $context
     */
    public function __construct(
        public readonly string $id,
        public readonly string $generation,
        public readonly string $tokenHash,
        public readonly string $provider,
        public readonly array $context,
        public readonly string $issuer,
        public int $expiresAt
    ) {
    }

    public static function decode(string $json): self
    {
        /**
         * @var array{
         *     id: string,
         *     generation: string,
         *     tokenHash: string,
         *     provider: class-string<ProviderInterface>,
         *     context: array<string, string>,
         *     issuer: string,
         *     expiresAt: int,
         *     receipts: array<string, array{digest: string, name: string}>,
         *     closed: bool,
         *     bytes: int,
         *     rateStart: int,
         *     rateCount: int,
         *     securityVersion?: int,
         *     code?: string,
         *     codeStep?: int,
         *     usedCodeStep?: int,
         *     failedCodes?: int,
         *     blockedUntil?: int,
         *     grants?: array<string, int>
         * } $data
         */
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        $Session = new self(
            $data['id'],
            $data['generation'],
            $data['tokenHash'],
            $data['provider'],
            $data['context'],
            $data['issuer'],
            $data['expiresAt']
        );

        $Session->receipts = $data['receipts'];
        $Session->closed = $data['closed'];
        $Session->bytes = $data['bytes'];
        $Session->rateStart = $data['rateStart'];
        $Session->rateCount = $data['rateCount'];
        $Session->securityVersion = $data['securityVersion'] ?? 0;
        $Session->code = $data['code'] ?? '';
        $Session->codeStep = $data['codeStep'] ?? -1;
        $Session->usedCodeStep = $data['usedCodeStep'] ?? -1;
        $Session->failedCodes = $data['failedCodes'] ?? 0;
        $Session->blockedUntil = $data['blockedUntil'] ?? 0;
        $Session->grants = $data['grants'] ?? [];

        return $Session;
    }
}
