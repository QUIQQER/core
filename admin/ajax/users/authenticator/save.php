<?php

/**
 * set the global authenticators
 *
 * @param integer|string $uid
 * @param string $authenticator
 *
 * @throws \QUI\Users\Exception
 */

QUI::getAjax()->registerFunction(
    'ajax_users_authenticator_save',
    static function ($authenticators): void {
        $User = QUI::getUserBySession();
        $authenticators = json_decode($authenticators, true);

        if (!$User->isSU()) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.config.save.not.allowed'
            ]);
        }

        if (!is_array($authenticators)) {
            throw new QUI\Users\Auth\Exception(['quiqqer/core', 'exception.config.save.not.allowed'], 400);
        }

        QUI\Users\Auth\Settings::checkRequiredSecondFactors($User, $authenticators);

        $Config = QUI::getConfig('etc/conf.ini.php');

        // cleanup
        $Config->del('auth');
        $Config->del('auth_frontend');
        $Config->del('auth_backend');
        $Config->del('auth_frontend_secondary');
        $Config->del('auth_backend_secondary');

        $authHandler = QUI\Users\Auth\Handler::getInstance();

        // setter
        if (!empty($authenticators['primary'])) {
            foreach (['backend', 'frontend'] as $type) {
                foreach ($authenticators['primary'][$type] as $authenticator) {
                    try {
                        $authHandler->getAuthenticator($authenticator, $User);
                        $Config->setValue('auth_' . $type, $authenticator, 1);
                    } catch (QUI\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }

        $Config->setValue('auth_settings', 'secondary_frontend', 0);
        $Config->setValue('auth_settings', 'secondary_backend', 0);

        if (isset($authenticators['secondary_frontend'])) {
            $Config->setValue('auth_settings', 'secondary_frontend', (int)$authenticators['secondary_frontend']);
        }

        if (isset($authenticators['secondary_backend'])) {
            $Config->setValue('auth_settings', 'secondary_backend', (int)$authenticators['secondary_backend']);
        }

        if (!empty($authenticators['secondary'])) {
            foreach (['backend', 'frontend'] as $type) {
                foreach ($authenticators['secondary'][$type] as $authenticator) {
                    try {
                        $authHandler->getAuthenticator($authenticator, $User);
                        $Config->setValue('auth_' . $type . '_secondary', $authenticator, 1);
                    } catch (QUI\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }

        $Config->save();
    },
    ['authenticators'],
    'Permission::checkAdminUser'
);
