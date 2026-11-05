<?php

QUI::getAjax()->registerFunction(
    'ajax_users_authenticator_secondarySettings',
    static function ($authenticator): string {
        $AuthHandler = QUI\Users\Auth\Handler::getInstance();
        $available = $AuthHandler->getAvailableAuthenticators();
        $available = array_flip($available);

        if (!isset($available[$authenticator])) {
            return '';
        }

        // check if 1fa is done
        $Session = QUI::getSession();
        $User = QUI::getUserBySession();
        $AuthenticatorUser = null;
        $activatedExistingCredentials = false;

        if (!QUI::getUsers()->isNobodyUser($User)) {
            $AuthenticatorUser = $User;
            $instance = $AuthHandler->getAuthenticator($authenticator, $User);
        } elseif ($Session->get('auth-primary')) {
            $uid = $Session->get('uid');

            if (!is_int($uid) && !is_string($uid)) {
                return '';
            }

            $instance = $AuthHandler->getAuthenticator($authenticator, $uid);

            try {
                $AuthenticatorUser = QUI::getUsers()->get($uid);
            } catch (QUI\Exception) {
            }
        } else {
            $instance = $AuthHandler->getAuthenticator($authenticator);
        }

        if (!$instance->isSecondaryAuthentication()) {
            return '';
        }

        if ($authenticator === QUI\Users\Auth\WebAuthn::class && $User->isSU()) {
            (new QUI\Users\Auth\WebAuthn\Server())->authorizeAuthenticatedEnrollment($User);
        }

        if (
            $authenticator === QUI\Users\Auth\WebAuthn::class
            && $AuthenticatorUser instanceof QUI\Interfaces\Users\User
        ) {
            $credentials = (new QUI\Users\Auth\WebAuthn\CredentialRepository())
                ->findByUserUuid((string)$AuthenticatorUser->getUUID());

            if (!empty($credentials)) {
                if (!$AuthenticatorUser->hasAuthenticator(QUI\Users\Auth\WebAuthn::class)) {
                    $AuthenticatorUser->enableAuthenticator(QUI\Users\Auth\WebAuthn::class);
                    $activatedExistingCredentials = true;
                }
            }
        }

        $settings = $instance->getSettingsControl();
        $Output = new QUI\Output();
        $control = '';
        $css = QUI\Control\Manager::getCSS();

        if ($settings) {
            $settings->setAttribute('activationMode', true);
            $settings->setAttribute('activatedExistingCredentials', $activatedExistingCredentials);
            $control = $settings->create();
        }

        return $Output->parse($css . $control);
    },
    ['authenticator']
);
