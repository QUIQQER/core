<?php

namespace QUI\System\Update;

use InvalidArgumentException;

class RunState
{
    private const WEB_SESSION_TTL = 3600;

    public const STATUS_CREATED = 'created';
    public const STATUS_RUNNING = 'running';
    public const STATUS_RESTART_REQUIRED = 'restart_required';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PHASE_CREATED = 'created';
    public const PHASE_PREPARED = 'prepared';
    public const PHASE_COMPOSER_UPDATE = 'composer_update';
    public const PHASE_RESTART_REQUIRED = 'restart_required';
    public const PHASE_SYSTEM_UPDATE = 'system_update';
    public const PHASE_CLEANUP = 'cleanup';
    public const PHASE_FINISHED = 'finished';
    public const PHASE_FAILED = 'failed';
    public const PHASE_CANCELLED = 'cancelled';

    private const ALLOWED_PHASE_TRANSITIONS = [
        self::PHASE_CREATED => [
            self::PHASE_PREPARED,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_PREPARED => [
            self::PHASE_COMPOSER_UPDATE,
            self::PHASE_SYSTEM_UPDATE,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_COMPOSER_UPDATE => [
            self::PHASE_RESTART_REQUIRED,
            self::PHASE_SYSTEM_UPDATE,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_RESTART_REQUIRED => [
            self::PHASE_SYSTEM_UPDATE,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_SYSTEM_UPDATE => [
            self::PHASE_CLEANUP,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_CLEANUP => [
            self::PHASE_FINISHED,
            self::PHASE_FAILED,
            self::PHASE_CANCELLED
        ],
        self::PHASE_FINISHED => [],
        self::PHASE_FAILED => [],
        self::PHASE_CANCELLED => []
    ];

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $process
     */
    public function __construct(
        private readonly string $id,
        private readonly string $tokenHash,
        private string $phase,
        private string $status,
        private readonly int $createdAt,
        private readonly int $expiresAt,
        private array $metadata = [],
        private ?int $startedAt = null,
        private ?int $finishedAt = null,
        private ?string $errorMessage = null,
        private ?array $process = null,
        private ?string $webTokenHash = null,
        private ?string $webSessionHash = null,
        private ?int $webSessionExpiresAt = null
    ) {
        self::assertValidIdentifier($id);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function create(string $id, string $tokenHash, int $createdAt, int $ttl, array $metadata = []): self
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('The update run ttl must be greater than zero.');
        }

        return new self(
            $id,
            $tokenHash,
            self::PHASE_CREATED,
            self::STATUS_CREATED,
            $createdAt,
            $createdAt + $ttl,
            $metadata
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['id'] ?? ''),
            (string)($data['tokenHash'] ?? ''),
            (string)($data['phase'] ?? ''),
            (string)($data['status'] ?? ''),
            (int)($data['createdAt'] ?? 0),
            (int)($data['expiresAt'] ?? 0),
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            isset($data['startedAt']) ? (int)$data['startedAt'] : null,
            isset($data['finishedAt']) ? (int)$data['finishedAt'] : null,
            isset($data['errorMessage']) ? (string)$data['errorMessage'] : null,
            self::sanitizeProcess(is_array($data['process'] ?? null) ? $data['process'] : null),
            isset($data['webTokenHash']) ? (string)$data['webTokenHash'] : null,
            isset($data['webSessionHash']) ? (string)$data['webSessionHash'] : null,
            isset($data['webSessionExpiresAt']) ? (int)$data['webSessionExpiresAt'] : null
        );
    }

    public static function assertValidIdentifier(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $id)) {
            throw new InvalidArgumentException('Invalid update run identifier.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tokenHash' => $this->tokenHash,
            'phase' => $this->phase,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'metadata' => $this->getSafeMetadata(),
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'errorMessage' => $this->errorMessage,
            'process' => self::sanitizeProcess($this->process),
            'webTokenHash' => $this->webTokenHash,
            'webSessionHash' => $this->webSessionHash,
            'webSessionExpiresAt' => $this->webSessionExpiresAt
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'metadata' => $this->getPublicMetadata(),
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'errorMessage' => $this->errorMessage,
            'process' => $this->getPublicProcess()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPublicMetadata(): array
    {
        return $this->getSafeMetadata();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPublicProcess(): ?array
    {
        if ($this->process === null) {
            return null;
        }

        return self::sanitizeProcess($this->process);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSafeMetadata(): array
    {
        $metadata = $this->metadata;

        unset($metadata['cliCommand']);

        if (is_string($metadata['webUrl'] ?? null)) {
            $metadata['webUrl'] = self::removeTokenFromUrl($metadata['webUrl']);
        }

        return $metadata;
    }

    private static function removeTokenFromUrl(string $url): string
    {
        $url = preg_replace_callback(
            '/([?&])token=[^&#]*(&?)/i',
            static fn (array $matches): string => $matches[2] !== '' ? $matches[1] : '',
            $url
        ) ?? $url;

        return rtrim($url, '?&');
    }

    /**
     * @param array<string, mixed>|null $process
     * @return array<string, mixed>|null
     */
    private static function sanitizeProcess(?array $process): ?array
    {
        if ($process === null) {
            return null;
        }

        unset($process['command']);

        return $process;
    }

    public function assertToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $validCliToken = $token !== '' && hash_equals($this->tokenHash, $tokenHash);
        $validWebSession = $token !== ''
            && $this->webSessionHash !== null
            && hash_equals($this->webSessionHash, $tokenHash);

        if (!$validCliToken && !$validWebSession) {
            throw new InvalidArgumentException('Invalid update run token.');
        }
    }

    public function assertAuthorized(string $token, int $now): void
    {
        $this->assertToken($token);
        $this->assertNotExpired($now);
    }

    public function prepareWebAccess(string $token): void
    {
        if ($token === '') {
            throw new InvalidArgumentException('Invalid update run web token.');
        }

        $this->webTokenHash = hash('sha256', $token);
        $this->webSessionHash = null;
        $this->webSessionExpiresAt = null;
    }

    public function exchangeWebToken(string $token, string $sessionToken, int $now): void
    {
        $this->assertNotExpired($now);

        if (
            $token === ''
            || $sessionToken === ''
            || $this->webTokenHash === null
            || !hash_equals($this->webTokenHash, hash('sha256', $token))
        ) {
            throw new InvalidArgumentException('Invalid update run web token.');
        }

        $this->webTokenHash = null;
        $this->webSessionHash = hash('sha256', $sessionToken);
        $this->webSessionExpiresAt = min($this->expiresAt, $now + self::WEB_SESSION_TTL);
    }

    public function assertWebSession(string $sessionToken, int $now): void
    {
        $this->assertNotExpired($now);

        if (
            $sessionToken === ''
            || $this->webSessionHash === null
            || $this->webSessionExpiresAt === null
            || $now > $this->webSessionExpiresAt
            || !hash_equals($this->webSessionHash, hash('sha256', $sessionToken))
        ) {
            throw new InvalidArgumentException('Invalid update run web session.');
        }
    }

    public function assertNotExpired(int $now): void
    {
        if ($this->isExpired($now)) {
            throw new InvalidArgumentException('The update run has expired.');
        }
    }

    public function isExpired(int $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function transitionTo(string $phase): void
    {
        $allowed = self::ALLOWED_PHASE_TRANSITIONS[$this->phase] ?? [];

        if (!in_array($phase, $allowed, true)) {
            throw new InvalidArgumentException('Invalid update run phase transition.');
        }

        $this->phase = $phase;
    }

    public function markRunning(int $now): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt ??= $now;
    }

    public function markRestartRequired(string $phase = self::PHASE_RESTART_REQUIRED): void
    {
        $this->transitionTo($phase);
        $this->status = self::STATUS_RESTART_REQUIRED;
    }

    public function markFinished(int $now): void
    {
        $this->phase = self::PHASE_FINISHED;
        $this->status = self::STATUS_FINISHED;
        $this->finishedAt = $now;
    }

    public function markFailed(string $message, int $now): void
    {
        $this->phase = self::PHASE_FAILED;
        $this->status = self::STATUS_FAILED;
        $this->finishedAt = $now;
        $this->errorMessage = $message;
    }

    public function markCancelled(string $message, int $now): void
    {
        $this->phase = self::PHASE_CANCELLED;
        $this->status = self::STATUS_CANCELLED;
        $this->finishedAt = $now;
        $this->errorMessage = $message;
    }

    public function setProcess(int $pid, string $command, int $startedAt, ?string $method = null): void
    {
        // Runner commands contain bearer credentials and must not be persisted.
        $this->process = [
            'pid' => $pid,
            'startedAt' => $startedAt
        ];

        if ($method !== null) {
            $this->process['method'] = $method;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getWebSessionExpiresAt(): ?int
    {
        return $this->webSessionExpiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadataValue(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProcess(): ?array
    {
        return $this->process;
    }
}
