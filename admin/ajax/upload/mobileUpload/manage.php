<?php

declare(strict_types=1);

use QUI\Upload\MobileUpload\Manager;

QUI::getAjax()->registerFunction(
    'ajax_upload_mobileUpload_manage',
    static function (string $id, string $generation, string $action): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new QUI\Exception('Method not allowed.', 405);
        }

        if (!in_array($action, ['status', 'revoke'], true)) {
            throw new QUI\Exception('Invalid request.', 400);
        }

        $Manager = new Manager();

        return $Manager->manage(
            $id,
            $generation,
            QUI::getUserBySession(),
            $action === 'revoke'
        );
    },
    ['id', 'generation', 'action'],
    'Permission::checkAdminUser'
);
