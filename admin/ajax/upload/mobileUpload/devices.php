<?php

declare(strict_types=1);

use QUI\Permissions\Permission;
use QUI\Upload\MobileUpload\Devices;

QUI::getAjax()->registerFunction(
    'ajax_upload_mobileUpload_devices',
    static function (string $uid, string $action, string $id, string $name): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new QUI\Exception('Method not allowed.', 405);
        }

        $Actor = QUI::getUserBySession();
        $User = QUI::getUsers()->get($uid);

        if ((string)$Actor->getUUID() !== (string)$User->getUUID()) {
            Permission::checkPermission('quiqqer.admin.users.edit', $Actor);
        }

        $Devices = new Devices();
        $user = (string)$User->getUUID();

        switch ($action) {
            case 'list':
                break;

            case 'rename':
                $Devices->edit($user, $id, $name);
                break;

            case 'revoke':
                $Devices->edit($user, $id, null);
                break;

            default:
                throw new QUI\Exception('Invalid device action.', 400);
        }

        return $Devices->listing($user);
    },
    ['uid', 'action', 'id', 'name'],
    'Permission::checkAdminUser'
);
