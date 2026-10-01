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

    public function __construct(
        private readonly Store $Store = new Store(),
        private readonly Providers $Providers = new Providers()
    ) {
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
            time() + self::LIFETIME
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
     * @return array{
     *     expiresAt: int,
     *     label: string,
     *     maxBytes: int,
     *     maxFiles: int,
     *     allowedTypes: list<string>
     * }
     */
    public function info(string $id, string $token): array
    {
        return $this->access($id, $token, static function (
            Session $Session,
            ProviderInterface $Provider,
            User $Issuer
        ): array {
            return [
                'expiresAt' => $Session->expiresAt,
                'label' => $Provider->authorize($Session->context, $Issuer),
                ...Files::limits($Issuer),
                'allowedTypes' => $Provider->getAllowedTypes($Session->context)
            ];
        });
    }

    /**
     * @return array{
     *     active: bool,
     *     count: int,
     *     expiresAt: int
     * }
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

            if ($revoke || $Session->expiresAt <= time()) {
                $Session->closed = true;
            }

            $state = [
                'active' => !$Session->closed,
                'count' => count($Session->receipts),
                'expiresAt' => $Session->expiresAt
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
    public function upload(string $id, string $token, string $uploadId, string $path, string $name): array
    {
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
            $name
        ): array {
            $limits = Files::limits($Issuer);
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

    public function close(string $id, string $token): void
    {
        $this->access($id, $token, static function (Session $Session): null {
            $Session->closed = true;

            return null;
        });
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
                if ($Session->closed || $Session->expiresAt <= time()) {
                    $Session->closed = true;

                    throw new QUI\Exception('Upload access has expired.', 410);
                }

                if ($Session->rateStart + 60 <= time()) {
                    $Session->rateStart = time();
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
