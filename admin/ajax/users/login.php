<?php

use QUI\Interfaces\Users\User;
use QUI\Security\RequestOrigin;
use QUI\System\Log;
use QUI\Users\Auth\SessionFailureCounter;
use QUI\Users\Auth\WebAuthn\Server as WebAuthnServer;

QUI::getAjax()->registerFunction(
    'ajax_users_login',
    static function ($authenticator, $params, $authStep, null | string | array $authenticators = null) {
        $failureMessage = 'exception.login.fail';
        $failureCode = 403;

        try {
            RequestOrigin::assertNotCrossOrigin();
            $failureCode = 401;

            // One budget across frontend/backend, authenticators and browser sessions.
            $source = QUI::getRequest()->getClientIp();
            $limit = filter_var(QUI::conf('auth_settings', 'loginIpLimit'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000000]
            ]);

            if (
                $source === null
                || !QUI\Security\Throttle::acquireForIp(
                    $source,
                    'quiqqer/core',
                    'users.login',
                    $limit === false ? 60 : $limit,
                    900
                )
            ) {
                $failureMessage = 'exception.login.fail.ip_throttled';
                $failureCode = 429;

                throw new QUI\Users\UserAuthException(
                    ['quiqqer/core', 'exception.login.fail.ip_throttled'],
                    429
                );
            }

            QUI::getEvents()->fireEvent('userLoginAjaxStart');
            QUI::getSession()->set('inAuthentication', 1);

            $currentAuthStep = $authStep === SessionFailureCounter::STEP_SECONDARY
                ? SessionFailureCounter::STEP_SECONDARY
                : SessionFailureCounter::STEP_PRIMARY;
            $isSecondaryAuthentication = $currentAuthStep === SessionFailureCounter::STEP_SECONDARY;

            if (
                $isSecondaryAuthentication
                && (
                    QUI::getSession()->get('auth-primary') !== 1
                    || QUI::getSession()->get('auth-' . $authenticator) === 1
                )
            ) {
                QUI::getSession()->remove('inAuthentication');
                return false;
            }

            if (is_string($authenticators)) {
                $authenticators = json_decode($authenticators, true);
            }

            if (!is_array($authenticators)) {
                $authenticators = [];
            }

            $AuthHandler = QUI\Users\Auth\Handler::getInstance();
            $AuthenticationTarget = $authenticator;

            if (QUI::isFrontend()) {
                $allowedAuthenticators = $isSecondaryAuthentication
                    ? $AuthHandler->getGlobalFrontendSecondaryAuthenticators()
                    : $AuthHandler->getGlobalFrontendAuthenticators();
            } else {
                $allowedAuthenticators = $isSecondaryAuthentication
                    ? $AuthHandler->getGlobalBackendSecondaryAuthenticators()
                    : $AuthHandler->getGlobalBackendAuthenticators();
            }

            if (!$isSecondaryAuthentication && !empty($authenticators)) {
                $allowedAuthenticators = array_values(array_intersect(
                    $allowedAuthenticators,
                    $authenticators
                ));
            }

            if (!in_array($authenticator, $allowedAuthenticators, true)) {
                if ($isSecondaryAuthentication) {
                    QUI::getSession()->remove('inAuthentication');
                    return false;
                }

                throw new QUI\Users\Auth\Exception(
                    ['quiqqer/core', 'exception.authenticator.not.found'],
                    404
                );
            }

            if ($isSecondaryAuthentication) {
                $uid = QUI::getSession()->get('uid');

                if ((!is_int($uid) && !is_string($uid)) || (string)$uid === '') {
                    QUI::getSession()->remove('inAuthentication');
                    return false;
                }

                try {
                    $User = QUI::getUsers()->get($uid);

                    if (!$User->hasAuthenticator($authenticator)) {
                        QUI::getSession()->remove('inAuthentication');
                        return false;
                    }

                    $SecondaryAuthenticator = $AuthHandler->getAuthenticator($authenticator, $User);
                } catch (QUI\Exception) {
                    QUI::getSession()->remove('inAuthentication');
                    return false;
                }

                if (!$SecondaryAuthenticator->isSecondaryAuthentication()) {
                    QUI::getSession()->remove('inAuthentication');
                    return false;
                }

                $AuthenticationTarget = $SecondaryAuthenticator;
            }

            $User = QUI::getUserBySession();

            if ($User->getUUID()) {
                QUI::getSession()->remove('inAuthentication');
            }

            if (is_string($params)) {
                $authParams = json_decode($params, true);
            } else {
                $authParams = $params;
            }

            if (!is_array($authParams)) {
                $authParams = [];
            }

            $FailureCounter = new SessionFailureCounter(QUI::getSession());
            $authenticationExecuted = false;

            try {
                QUI::getUsers()->authenticate(
                    $AuthenticationTarget,
                    $authParams,
                    $authenticationExecuted
                );
            } catch (QUI\Users\UserAuthException | QUI\Users\Auth\Exception | QUI\Users\Exception $Exception) {
                if ($Exception->getCode() === 429) {
                    $failureMessage = 'exception.login.fail.login_locked';
                    $failureCode = 429;
                } elseif (
                    $Exception instanceof QUI\Users\UserAuthException
                    && $Exception->getAttribute('reason') === QUI\Users\Manager::AUTH_ERROR_AUTH_ERROR
                ) {
                    $FailureCounter->recordFailure($currentAuthStep);
                }

                throw $Exception;
            }

            $FailureCounter->reset($currentAuthStep);

            if ($currentAuthStep === SessionFailureCounter::STEP_PRIMARY) {
                QUI::getSession()->set('auth-primary', 1);
                QUI::getSession()->set('auth-secondary', 0);

                $PrimaryAuthenticator = new $authenticator();

                if ($PrimaryAuthenticator->satisfiesSecondaryAuthentication()) {
                    QUI::getSession()->set('auth-secondary', 1);
                }
            }

            if ($currentAuthStep === SessionFailureCounter::STEP_SECONDARY) {
                QUI::getSession()->set('auth-secondary', 1);
            }

            if (QUI::isFrontend()) {
                $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_frontend');
            } else {
                $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_backend');
            }

            // $secondaryLoginType = 0 no 2fa
            // $secondaryLoginType = 1 2fa is required
            // $secondaryLoginType = 2 2fa is optional
            if (
                $authenticationExecuted
                && $currentAuthStep === SessionFailureCounter::STEP_PRIMARY
                && $secondaryLoginType === 1
            ) {
                try {
                    $EnrollmentUser = QUI::getUsers()->get(QUI::getSession()->get('uid'));
                    (new WebAuthnServer())->authorizeRequiredMfaBootstrap($EnrollmentUser);
                } catch (\Throwable $Exception) {
                    QUI::getSession()->remove(WebAuthnServer::SESSION_ENROLLMENT);
                    Log::writeException($Exception);
                }
            }

            if ($secondaryLoginType === 2 && QUI::getSession()->get('auth-primary')) {
                QUI::getSession()->set('auth', 1);
            }

            $Login = new QUI\Users\Controls\Login([
                'authenticators' => $authenticators
            ]);
            $next = $Login->next();
            $loggedIn = false;
            if (
                empty($next) && $secondaryLoginType !== 1
                ||
                QUI::getSession()->get('auth-primary') === 1 && QUI::getSession()->get('auth-secondary') === 1
            ) {
                try {
                    $LoggedInUser = QUI::getUsers()->login();

                    if ($LoggedInUser instanceof User && $authenticationExecuted) {
                        try {
                            (new WebAuthnServer())->authorizeAuthenticatedEnrollment($LoggedInUser);
                        } catch (\Throwable $Exception) {
                            QUI::getSession()->remove(WebAuthnServer::SESSION_ENROLLMENT);
                            Log::writeException($Exception);
                        }
                    }

                    $loggedIn = true;
                } catch (\Throwable $Exception) {
                    // User cannot log in (e.g. User is not active)
                    QUI::getSession()->destroy();
                    throw $Exception;
                }
            }

            // result
            $SessionUser = QUI::getUserBySession();

            $control = $Login->create();
            $control .= QUI\Control\Manager::getCSS();

            return [
                'authenticator' => $next,
                'secondaryLoginType' => $secondaryLoginType,
                'control' => $control,
                'loggedIn' => $loggedIn,
                'authStep' => $Login->getAttribute('authStep'),
                'user' => [
                    'id' => $SessionUser->getUUID(),
                    'name' => $SessionUser->getName(),
                    'lang' => $SessionUser->getLang()
                ]
            ];
        } catch (\Throwable $Exception) {
            // Database wrappers have already logged the underlying database failure.
            if (!$Exception instanceof QUI\Exception) {
                Log::writeException($Exception);
            }

            throw new QUI\Users\UserAuthException(
                ['quiqqer/core', $failureMessage],
                $failureCode
            );
        }
    },
    ['authenticator', 'params', 'authStep', 'authenticators']
);
