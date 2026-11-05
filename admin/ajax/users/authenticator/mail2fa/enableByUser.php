<?php

/**
 * Activate mail 2FA only after the session user's email has been verified.
 */

use QUI\Users\Auth\VerifiedMail2FA;

QUI::getAjax()->registerFunction(
    'ajax_users_authenticator_mail2fa_enableByUser',
    static function (): bool {
        return VerifiedMail2FA::enableByUser();
    }
);
