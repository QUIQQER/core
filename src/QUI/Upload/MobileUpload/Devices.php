<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use Doctrine\DBAL\Connection;
use QUI;

/**
 * Private, persistent user attribute. Only verified enrollment may add devices.
 *
 * @phpstan-type Device array{id: string, name: string, description: string, createdAt: int,
 *     lastUsedAt: int, registeredStep: int}
 */
class Devices
{
    public const ATTRIBUTE = 'quiqqer.upload.allowedDevices';

    public function __construct(
        private readonly ?Connection $Connection = null,
        private readonly ?string $table = null
    ) {
    }

    /** @return array<string, Device> */
    public function get(string $user): array
    {
        $extra = $this->read($user);

        return $extra[self::ATTRIBUTE] ?? [];
    }

    /** @return Device|null */
    public function find(string $user, string $token): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return null;
        }

        return $this->get($user)[hash('sha256', $token)] ?? null;
    }

    public function register(string $user, string $token, string $name, string $description, int $now): void
    {
        Store::assertId($token);
        $name = $this->name($name);

        $this->change($user, static function (array $devices) use ($token, $name, $description, $now): array {
            $key = hash('sha256', $token);

            if (isset($devices[$key])) {
                return $devices;
            }

            if (count($devices) >= 50) {
                throw new QUI\Exception('Device limit reached.', 413);
            }

            $devices[$key] = [
                'id' => bin2hex(random_bytes(16)),
                'name' => $name,
                'description' => mb_substr($description, 0, 250),
                'createdAt' => $now,
                'lastUsedAt' => $now,
                'registeredStep' => intdiv($now, 30)
            ];

            return $devices;
        });
    }

    public function touch(string $user, string $token, int $now): void
    {
        $this->change($user, static function (array $devices) use ($token, $now): array {
            $key = hash('sha256', $token);

            if (!isset($devices[$key])) {
                throw new QUI\Exception('Device access was revoked.', 403);
            }

            $devices[$key]['lastUsedAt'] = $now;

            return $devices;
        });
    }

    /** @return list<array{id: string, name: string, description: string, createdAt: int, lastUsedAt: int}> */
    public function listing(string $user): array
    {
        $result = [];

        foreach ($this->get($user) as $device) {
            unset($device['registeredStep']);
            $result[] = $device;
        }

        return $result;
    }

    public function edit(string $user, string $id, ?string $name): void
    {
        $name = $name === null ? null : $this->name($name);

        $this->change($user, static function (array $devices) use ($id, $name): array {
            foreach ($devices as $key => $device) {
                if ($device['id'] !== $id) {
                    continue;
                }

                if ($name === null) {
                    unset($devices[$key]);
                } else {
                    $devices[$key]['name'] = $name;
                }

                return $devices;
            }

            throw new QUI\Exception('Device not found.', 404);
        });
    }

    /**
     * Ordinary profile saves must preserve the current protected attribute, even
     * when the User object predates a registration or revocation.
     *
     * @param array<string, mixed> $extra
     * @param callable(array<string, mixed>): void $save
     */
    public function preserveOnSave(string $user, array $extra, callable $save): void
    {
        $this->locked($user, static function (array $stored) use ($extra, $save): void {
            unset($extra[self::ATTRIBUTE]);

            if (isset($stored[self::ATTRIBUTE])) {
                $extra[self::ATTRIBUTE] = $stored[self::ATTRIBUTE];
            }

            $save($extra);
        });
    }

    /** @param callable(array<string, Device>): array<string, Device> $change */
    private function change(string $user, callable $change): void
    {
        $this->locked($user, function (array $extra) use ($user, $change): void {
            $extra[self::ATTRIBUTE] = $change($extra[self::ATTRIBUTE] ?? []);
            $json = json_encode($extra, JSON_THROW_ON_ERROR);

            $this->connection()->update($this->tableName(), ['extra' => $json], ['uuid' => $user]);
        });
    }

    /** @param callable(array<string, mixed>): void $callback */
    private function locked(string $user, callable $callback): void
    {
        $Connection = $this->connection();

        $Connection->transactional(function () use ($Connection, $user, $callback): void {
            // A no-op update obtains a row write lock on all supported databases,
            // including SQLite, without overwriting another user's attributes.
            $Connection->update($this->tableName(), ['uuid' => $user], ['uuid' => $user]);
            $callback($this->read($user));
        });
    }

    /** @return array<string, mixed> */
    private function read(string $user): array
    {
        $row = $this->connection()->createQueryBuilder()
            ->select('extra')
            ->from($this->tableName())
            ->where('uuid = :uuid')
            ->setParameter('uuid', $user)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new QUI\Exception('User not found.', 404);
        }

        $extra = json_decode($row['extra'] ?: '{}', true, 32, JSON_THROW_ON_ERROR);

        if (!is_array($extra)) {
            throw new \RuntimeException('Invalid user attributes.');
        }

        return $extra;
    }

    private function connection(): Connection
    {
        return $this->Connection ?? QUI::getDataBaseConnection();
    }

    private function tableName(): string
    {
        return $this->connection()->quoteIdentifier($this->table ?? QUI\Users\Manager::table());
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw new QUI\Exception('A device name of up to 100 characters is required.', 400);
        }

        return $name;
    }
}
