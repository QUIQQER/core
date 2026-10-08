<?php

declare(strict_types=1);

namespace QUI\Projects\Media;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\Upload\MobileUpload\Document;
use QUI\Upload\MobileUpload\Files;
use QUI\Upload\MobileUpload\LimitsProviderInterface;

final class MobileUploadProvider implements LimitsProviderInterface
{
    public const RECEIPT = 'quiqqer.mobileUpload.receipt';

    public function authorize(array $context, User $Issuer): string
    {
        $Folder = $this->folder($context, $Issuer);

        return $Folder->getProject()->getName() . ': /' . ltrim($Folder->getAttribute('file'), '/');
    }

    public function getAllowedTypes(array $context): array
    {
        return [];
    }

    public function getLimits(array $context, User $Issuer): array
    {
        return Files::limits($Issuer, $this->folder($context, $Issuer)->getProject());
    }

    public function receive(array $context, User $Issuer, Document $Document): void
    {
        Permission::withUser($Issuer, function () use ($context, $Issuer, $Document): void {
            $Folder = $this->folder($context, $Issuer);
            $limits = $this->getLimits($context, $Issuer);
            $file = Files::validate($Document->path, $limits['maxBytes'], []);
            $name = (new QUI\Upload\Manager())->validateUploadFilename($Document->name);
            $name = Utils::stripMediaName($name);
            $this->checkAllowed($name, $Issuer->getPermission('quiqqer.upload.allowedEndings'));
            $this->checkAllowed($file['mime'], $Issuer->getPermission('quiqqer.upload.allowedTypes'));
            $digest = hash('sha256', $file['checksum'] . "\0" . $Document->name);
            $Connection = QUI::getDataBaseConnection();
            $table = $Connection->quoteIdentifier($Folder->getMedia()->getTable());

            $Connection->transactional(function () use (
                $Connection,
                $table,
                $Folder,
                $Issuer,
                $Document,
                $digest,
                $name
            ): void {
                // Serialize name selection and imports into this destination.
                $Connection->update($table, ['id' => $Folder->getId()], ['id' => $Folder->getId()]);
                $rows = $Connection->createQueryBuilder()
                    ->select('extra')
                    ->from($table)
                    ->where('extra LIKE :receipt')
                    ->setParameter('receipt', '%' . $Document->id . '%')
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($rows as $json) {
                    $extra = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $receipt = $extra[self::RECEIPT] ?? null;

                    if (!is_array($receipt) || ($receipt['id'] ?? '') !== $Document->id) {
                        continue;
                    }

                    if (
                        ($receipt['digest'] ?? '') !== $digest
                        || ($receipt['folder'] ?? 0) !== $Folder->getId()
                        || ($receipt['issuer'] ?? '') !== $Issuer->getUUID()
                    ) {
                        throw new QUI\Exception('Upload identifier already used.', 409);
                    }

                    return;
                }

                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $candidate = $name;
                $counter = 1;

                while (file_exists($Folder->getFullPath() . '/' . $candidate)) {
                    $candidate = $base . '_' . $counter++ . ($extension === '' ? '' : '.' . $extension);
                }

                $directory = QUI::getTemp()->createFolder();
                $path = $directory . '/' . $candidate;

                try {
                    if (!copy($Document->path, $path)) {
                        throw new \RuntimeException('Cannot stage media upload.');
                    }

                    $File = $Folder->uploadFile($path, Folder::FILE_OVERWRITE_NONE, $Issuer);
                    $File->setAttribute(self::RECEIPT, [
                        'id' => $Document->id,
                        'digest' => $digest,
                        'folder' => $Folder->getId(),
                        'issuer' => $Issuer->getUUID()
                    ]);
                    $File->save($Issuer);
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }

                    rmdir($directory);
                }
            });
        });
    }

    /** @param array<string, string> $context */
    private function folder(array $context, User $Issuer): Folder
    {
        Permission::checkAdminUser($Issuer);
        Permission::checkPermission('quiqqer.projects.media.upload', $Issuer);

        if (
            empty($context['project'])
            || empty($context['folderId'])
            || !ctype_digit($context['folderId'])
        ) {
            throw new QUI\Exception('Invalid media upload destination.', 400);
        }

        $Project = QUI::getProject($context['project']);

        if (!(bool)$Project->getConfig('media_allowQrUpload')) {
            throw new QUI\Exception('Mobile uploads are disabled for this project.', 403);
        }

        $Folder = $Project->getMedia()->get((int)$context['folderId']);

        if (!$Folder instanceof Folder || $Folder->isDeleted()) {
            throw new QUI\Exception('Media upload folder is unavailable.', 404);
        }

        $Folder->checkPermission('quiqqer.projects.media.view', $Issuer);
        $Folder->checkPermission('quiqqer.projects.media.upload', $Issuer);
        $Folder->checkPermission('quiqqer.projects.media.edit', $Issuer);

        return $Folder;
    }

    private function checkAllowed(string $value, mixed $patterns): void
    {
        if (is_string($patterns)) {
            $patterns = explode(',', $patterns);
        }

        if (!is_array($patterns) || $patterns === [] || $patterns === ['']) {
            return;
        }

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch(trim($pattern), $value)) {
                return;
            }
        }

        throw new QUI\Exception('This file type is not permitted.', 415);
    }
}
