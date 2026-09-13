<?php

namespace QUI\REST\Core\Project\Media;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\REST\Core\ApiException;

final class UploadSessions
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function create(User $User, array $data): array
    {
        $root = self::ownerDirectory($User);
        $Lock = fopen($root . '/create.lock', 'c');

        if ($Lock === false) {
            throw new \RuntimeException('Could not open upload creation lock.');
        }

        try {
            if (!flock($Lock, LOCK_EX | LOCK_NB)) {
                throw new ApiException('conflict', 'Another upload session is being created.', 409);
            }

            $active = 0;

            foreach (glob($root . '/*/session.json') ?: [] as $path) {
                $state = json_decode((string)file_get_contents($path), true);

                if (!is_array($state) || (int)($state['expiresAt'] ?? 0) < time()) {
                    self::remove(dirname($path));
                } elseif (($state['status'] ?? '') !== 'complete') {
                    $active++;
                }
            }

            if ($active >= 5) {
                throw new ApiException('too_many_uploads', 'At most five pending uploads are allowed per user.', 429);
            }

            $data += [
                'id' => bin2hex(random_bytes(16)), 'status' => 'pending', 'size' => 0, 'expiresAt' => time() + 3600
            ];
            $directory = $root . '/' . $data['id'];

            if (!mkdir($directory, 0700)) {
                throw new \RuntimeException('Could not create upload session.');
            }

            self::save($directory, $data);
            return $data;
        } finally {
            flock($Lock, LOCK_UN);
            fclose($Lock);
        }
    }

    /** @template T
     * @param callable(array<string, mixed>&, string): T $operation
     * @return T
     */
    public static function withSession(User $User, string $project, string $id, callable $operation): mixed
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new ApiException('not_found', 'The upload session does not exist.', 404);
        }

        $directory = self::ownerDirectory($User) . '/' . $id;

        if (!is_file($directory . '/session.json')) {
            throw new ApiException('not_found', 'The upload session does not exist.', 404);
        }

        $Lock = fopen($directory . '/lock', 'c');

        if ($Lock === false) {
            throw new \RuntimeException('Could not open upload lock.');
        }

        try {
            if (!flock($Lock, LOCK_EX | LOCK_NB)) {
                throw new ApiException('conflict', 'Another request is using this upload session.', 409);
            }

            $data = json_decode((string)file_get_contents($directory . '/session.json'), true, 64, JSON_THROW_ON_ERROR);

            if (!is_array($data) || ($data['project'] ?? '') !== $project) {
                throw new ApiException('not_found', 'The upload session does not exist.', 404);
            }

            if ((int)$data['expiresAt'] < time()) {
                throw new ApiException('upload_expired', 'The upload session has expired.', 410);
            }

            $result = $operation($data, $directory);
            self::save($directory, $data);
            return $result;
        } finally {
            flock($Lock, LOCK_UN);
            fclose($Lock);
        }
    }

    /** @param array<string, mixed> $data */
    public static function save(string $directory, array $data): void
    {
        $temporary = $directory . '/session.new';
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        if (
            file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || !rename($temporary, $directory . '/session.json')
        ) {
            throw new \RuntimeException('Could not save upload session.');
        }
    }

    private static function ownerDirectory(User $User): string
    {
        $root = QUI::getTemp()->createFolder('rest-upload-sessions');
        chmod($root, 0700);
        $directory = $root . hash('sha256', (string)$User->getUUID());

        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create upload owner directory.');
        }

        return $directory;
    }

    private static function remove(string $directory): void
    {
        $Lock = fopen($directory . '/lock', 'c');

        if ($Lock === false) {
            return;
        }

        try {
            if (!flock($Lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            foreach (['payload', 'session.new', 'session.json', 'lock'] as $name) {
                if (is_file($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }

            rmdir($directory);
        } finally {
            fclose($Lock);
        }
    }
}
