<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use Throwable;

final class Manager
{
    public const LIFETIME = 1800;
    private readonly \Closure $clock;

    /** @param null|callable(): int $clock */
    public function __construct(
        private readonly Store $Store = new Store(),
        private readonly Providers $Providers = new Providers(),
        private readonly Devices $Devices = new Devices(),
        ?callable $clock = null
    ) {
        $this->clock = \Closure::fromCallable($clock ?? time(...));
    }

    /**
     * Called from an authenticated package endpoint with a server-selected provider and context.
     *
     * @param array<string, string> $context
     * @return array{
     *     id: string,
     *     generation: string,
     *     token: string,
     *     expiresAt: int,
     *     label: string
     * }
     */
    public function create(string $provider, array $context, User $Issuer): array
    {
        Permission::checkAdminUser($Issuer);

        if (!$Issuer->isActive()) {
            throw new QUI\Exception('Upload access is unavailable.', 403);
        }

        $Provider = $this->Providers->get($provider);

        $label = Permission::withUser(
            $Issuer,
            fn (): string => $Provider->authorize($context, $Issuer)
        );

        ksort($context);

        $id = hash('sha256', $Provider::class . json_encode($context, JSON_THROW_ON_ERROR));
        $token = bin2hex(random_bytes(32));

        $Session = new Session(
            $id,
            bin2hex(random_bytes(32)),
            hash('sha256', $token),
            $Provider::class,
            $context,
            (string)$Issuer->getUUID(),
            ($this->clock)() + self::LIFETIME
        );

        $this->Store->locked(
            $id,
            static fn (?Session $Previous): array => [$Session, null],
            true
        );

        return [
            'id' => $id,
            'generation' => $Session->generation,
            'token' => $token,
            'expiresAt' => $Session->expiresAt,
            'label' => $label
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function info(string $id, string $token, string $deviceToken): array
    {
        return $this->access($id, $token, function (
            Session $Session,
            ProviderInterface $Provider,
            User $Issuer
        ) use ($deviceToken): array {
            $now = ($this->clock)();
            $device = $this->Devices->find($Session->issuer, $deviceToken);
            $expiresAt = $device === null ? 0 : ($Session->grants[$device['id']] ?? 0);

            if ($expiresAt <= $now) {
                return [
                    'stage' => $device === null ? 'register' : 'unlock',
                    'serverTime' => $now,
                    'retryAt' => max(
                        $Session->blockedUntil,
                        ($Session->usedCodeStep + 1) * 30,
                        $device === null ? 0 : ($device['registeredStep'] + 1) * 30
                    ),
                    'expiresAt' => $Session->expiresAt
                ];
            }

            return [
                'stage' => 'upload',
                'serverTime' => $now,
                'expiresAt' => $expiresAt,
                'label' => $Provider->authorize($Session->context, $Issuer),
                ...$this->limits($Session, $Provider, $Issuer),
                'allowedTypes' => $Provider->getAllowedTypes($Session->context)
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function manage(string $id, string $generation, User $Issuer, bool $revoke = false): array
    {
        Permission::checkAdminUser($Issuer);

        return $this->Store->locked($id, function (?Session $Session) use ($generation, $Issuer, $revoke): array {
            if ($Session === null || $Session->issuer !== (string)$Issuer->getUUID()) {
                throw new QUI\Exception('Upload access is unavailable.', 403);
            }

            if (!hash_equals($Session->generation, $generation)) {
                $state = [
                    'active' => false,
                    'count' => 0,
                    'expiresAt' => 0
                ];

                return [$Session, $state];
            }

            $now = ($this->clock)();

            if ($revoke || $Session->expiresAt <= $now || $Session->securityVersion !== 2 || !$Issuer->isActive()) {
                $Session->closed = true;
            }

            if (!$Session->closed) {
                Permission::withUser($Issuer, function () use ($Session, $Issuer): void {
                    $this->Providers->get($Session->provider)->authorize($Session->context, $Issuer);
                });
                $this->rotateCode($Session, $now);
            }

            $state = [
                'active' => !$Session->closed,
                'count' => count($Session->receipts),
                'expiresAt' => $Session->expiresAt,
                'serverTime' => $now,
                'code' => $Session->closed ? '' : $Session->code,
                'codeExpiresAt' => ($Session->codeStep + 1) * 30,
                'codeUsed' => $Session->usedCodeStep === $Session->codeStep
            ];

            return [$Session, $state];
        });
    }

    /**
     * Validate one uploaded file and hand it directly to the application provider.
     * The HTTP endpoint additionally verifies is_uploaded_file().
     *
     * @return array{name: string}
     */
    public function upload(
        string $id,
        string $token,
        string $uploadId,
        string $path,
        string $name,
        string $deviceToken
    ): array {
        Store::assertId($uploadId);

        $UploadManager = new QUI\Upload\Manager();
        $name = $UploadManager->validateUploadFilename($name);

        $receive = function (
            Session $Session,
            ProviderInterface $Provider,
            User $Issuer
        ) use (
            $uploadId,
            $path,
            $name,
            $deviceToken
        ): array {
            $this->assertUploadAccess($Session, $deviceToken);
            $limits = $this->limits($Session, $Provider, $Issuer);
            $allowedTypes = $Provider->getAllowedTypes($Session->context);
            $file = Files::validate($path, $limits['maxBytes'], $allowedTypes);
            $digest = hash('sha256', $file['checksum'] . "\0" . $name);

            if (isset($Session->receipts[$uploadId])) {
                $receipt = $Session->receipts[$uploadId];

                if (!hash_equals($receipt['digest'], $digest)) {
                    throw new QUI\Exception('Upload identifier already used.', 409);
                }

                return ['name' => $receipt['name']];
            }

            if (
                $limits['maxFiles'] > 0
                && count($Session->receipts) >= $limits['maxFiles']
            ) {
                throw new QUI\Exception('Upload limit reached.', 413);
            }

            $Document = new Document(
                hash('sha256', $Session->generation . $uploadId),
                $path,
                $name
            );

            $Provider->receive($Session->context, $Issuer, $Document);

            $Session->receipts[$uploadId] = [
                'digest' => $digest,
                'name' => $name
            ];

            $Session->bytes += $file['size'];

            return ['name' => $name];
        };

        return $this->access($id, $token, $receive);
    }

    public function close(string $id, string $token, string $deviceToken): void
    {
        $this->access($id, $token, function (Session $Session) use ($deviceToken): null {
            $this->assertUploadAccess($Session, $deviceToken);
            $Session->closed = true;

            return null;
        });
    }

    /** @return array<string, mixed> */
    public function verify(
        string $id,
        string $token,
        string $deviceToken,
        string $code,
        string $action,
        string $name = '',
        string $description = ''
    ): array {
        Store::assertId($deviceToken);

        $this->access($id, $token, function (Session $Session) use (
            $deviceToken,
            $code,
            $action,
            $name,
            $description
        ): void {
            $now = ($this->clock)();

            if ($Session->blockedUntil > $now) {
                throw new QUI\Exception('Too many code attempts.', 429);
            }

            $device = $this->Devices->find($Session->issuer, $deviceToken);

            if ($action !== ($device === null ? 'register' : 'unlock')) {
                throw new QUI\Exception('Upload verification state changed.', 409);
            }

            $this->rotateCode($Session, $now);

            if (!preg_match('/\A[0-9]{6}\z/D', $code) || !hash_equals($Session->code, $code)) {
                $Session->failedCodes++;

                if ($Session->failedCodes >= 10) {
                    $Session->closed = true;
                }

                if ($Session->failedCodes % 5 === 0) {
                    $Session->blockedUntil = $now + 300;
                }

                throw new QUI\Exception('Invalid verification code.', 422);
            }

            if (
                $Session->usedCodeStep >= $Session->codeStep
                || ($device !== null && $device['registeredStep'] >= $Session->codeStep)
            ) {
                throw new QUI\Exception('Wait for a fresh verification code.', 409);
            }

            if ($device === null) {
                $this->Devices->register($Session->issuer, $deviceToken, $name, $description, $now);
            } else {
                $this->Devices->touch($Session->issuer, $deviceToken, $now);

                if (($Session->grants[$device['id']] ?? 0) <= $now) {
                    $Session->grants[$device['id']] = $now + self::LIFETIME;
                    $Session->expiresAt = max($Session->expiresAt, $now + self::LIFETIME);
                }
            }

            $Session->usedCodeStep = $Session->codeStep;
        });

        return $this->info($id, $token, $deviceToken);
    }

    /** @return array{maxBytes: int, maxFiles: int} */
    private function limits(Session $Session, ProviderInterface $Provider, User $Issuer): array
    {
        return $Provider instanceof LimitsProviderInterface
            ? $Provider->getLimits($Session->context, $Issuer)
            : Files::limits($Issuer);
    }

    private function rotateCode(Session $Session, int $now): void
    {
        $step = intdiv($now, 30);

        if ($step === $Session->codeStep) {
            return;
        }

        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while ($code === $Session->code);

        $Session->code = $code;
        $Session->codeStep = $step;
    }

    private function assertUploadAccess(Session $Session, string $deviceToken): void
    {
        $device = $this->Devices->find($Session->issuer, $deviceToken);

        if ($device === null || ($Session->grants[$device['id']] ?? 0) <= ($this->clock)()) {
            throw new QUI\Exception('Upload verification is required.', 403);
        }
    }

    /**
     * @template T
     * @param callable(Session, ProviderInterface, User): T $callback
     * @return T
     */
    private function access(string $id, string $token, callable $callback): mixed
    {
        Store::assertId($token);

        $result = $this->Store->locked($id, function (?Session $Session) use ($token, $callback): array {
            if ($Session === null || !hash_equals($Session->tokenHash, hash('sha256', $token))) {
                throw new QUI\Exception('Invalid upload access.', 410);
            }

            try {
                $now = ($this->clock)();

                if ($Session->closed || $Session->expiresAt <= $now || $Session->securityVersion !== 2) {
                    $Session->closed = true;

                    throw new QUI\Exception('Upload access has expired.', 410);
                }

                if ($Session->rateStart + 60 <= $now) {
                    $Session->rateStart = $now;
                    $Session->rateCount = 0;
                }

                if (++$Session->rateCount > 120) {
                    throw new QUI\Exception('Too many upload requests.', 429);
                }

                $Issuer = QUI::getUsers()->get($Session->issuer);

                if (!$Issuer->isActive()) {
                    throw new QUI\Exception('Upload access is unavailable.', 403);
                }

                $Provider = $this->Providers->get($Session->provider);

                $result = Permission::withUser($Issuer, function () use ($Session, $Provider, $Issuer, $callback) {
                    $Provider->authorize($Session->context, $Issuer);

                    return $callback($Session, $Provider, $Issuer);
                });
            } catch (Throwable $Exception) {
                // Persist rate limits and expiry changes even when the request is rejected.
                $result = $Exception;
            }

            return [$Session, $result];
        });

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
