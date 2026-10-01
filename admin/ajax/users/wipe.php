<?php

/**
 * Clear user data while retaining the user IDs and UUIDs.
 */

QUI::getAjax()->registerFunction(
    'ajax_users_wipe',
    static function (string $uid): bool {
        $ids = json_decode($uid, true, 512, JSON_THROW_ON_ERROR);
        $ids = is_array($ids) ? $ids : [$ids];
        foreach ($ids as $id) {
            if ((!is_string($id) && !is_int($id)) || $id === '') {
                throw new QUI\Exception('Invalid user ID', 400);
            }
        }

        $Users = QUI::getUsers();
        $SessionUser = QUI::getUserBySession();
        $targets = [];

        // Check every selected account before changing the first one.
        foreach (array_unique($ids) as $id) {
            $User = $Users->get($id);
            $User->checkDeletePermission($SessionUser);
            $User->checkEditPermission($SessionUser);
            $targets[] = $User;
        }

        foreach ($targets as $User) {
            $User->disable($SessionUser);
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get('quiqqer/core', 'message.user.wiped.successful')
        );

        return true;
    },
    ['uid'],
    [
        'Permission::checkAdminUser',
        'quiqqer.admin.users.edit',
        'quiqqer.admin.users.delete'
    ]
);
