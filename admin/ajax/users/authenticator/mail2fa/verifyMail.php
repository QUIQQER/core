<?php

use QUI\Users\Auth\VerifiedMail2FA;

QUI::getAjax()->registerFunction(
    'ajax_users_authenticator_mail2fa_verifyMail',
    static function ($code): bool {
        return VerifiedMail2FA::verifyMailByUser($code);
    },
    ['code']
);
