<?php

/**
 * Return the settings control from an authenticator
 *
 * @param integer|string $uid
 * @param string $authenticator
 * @return string
 * @throws \QUI\Users\Exception
 */

QUI::getAjax()->registerFunction(
    'ajax_users_authenticator_settings',
    static function ($uid, $authenticator): string {
        $User = QUI::getUsers()->get($uid);
        $ownMailSetup = $authenticator === QUI\Users\Auth\VerifiedMail2FA::class
            && $User->getUUID() === QUI::getUserBySession()->getUUID();

        if ($ownMailSetup) {
            QUI\Users\Auth\Helper::checkUserPermissionToUseAuthenticator($User, $authenticator);
            $Authenticator = new QUI\Users\Auth\VerifiedMail2FA($User);
        } elseif (QUI::isFrontend()) {
            $AuthHandler = QUI\Users\Auth\Handler::getInstance();
            $available = $AuthHandler->getAvailableAuthenticators();
            $available = array_flip($available);

            if (!isset($available[$authenticator])) {
                return '';
            }

            $Authenticator = $AuthHandler->getAuthenticator($authenticator, $User);
        } else {
            $Authenticator = $User->getAuthenticator($authenticator);
        }

        $Settings = $Authenticator->getSettingsControl();

        if ($Settings) {
            if ($ownMailSetup) {
                $Settings->setAttribute('activationMode', true);
            }

            $Output = new QUI\Output();
            $css = QUI\Control\Manager::getCSS();

            return $Output->parse($css . $Settings->create());
        }

        return '';
    },
    ['uid', 'authenticator'],
    'Permission::checkAdminUser'
);
