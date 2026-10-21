<?php

/**
 * This file contains QUI\Users\Controls\Login
 */

namespace QUI\Users\Controls;

use QUI;
use QUI\Control;

use function count;
use function forward_static_call;
use function in_array;
use function is_null;
use function usort;

/**
 * Class Login
 * Main Login Control - Log in a user with all authentications
 */
class Login extends Control
{
    protected bool $isGlobalAuth = false;

    /**
     * Provide the login logo before a backend session can expire.
     *
     * @return array{logo: string, alt: string}
     */
    public static function getBranding(?QUI\Projects\Project $Project): array
    {
        $branding = [
            'logo' => URL_BIN_DIR . 'quiqqer_logo.svg',
            'alt' => 'QUIQQER'
        ];

        if (!$Project || !$Project->getConfig('logo')) {
            return $branding;
        }

        try {
            $Image = $Project->getMedia()->getLogoImage();

            if ($Image !== null) {
                return [
                    'logo' => $Image->getSizeCacheUrl(),
                    'alt' => $Project->getName()
                ];
            }
        } catch (QUI\Exception) {
            // Keep the login usable when the configured project logo is unavailable.
        }

        return $branding;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $authStep = 'primary';

        $Session = QUI::getSession();

        if ($Session->get('auth-primary') === 1) {
            $secondaryLoginType = QUI::isFrontend()
                ? (int)QUI::conf('auth_settings', 'secondary_frontend')
                : (int)QUI::conf('auth_settings', 'secondary_backend');

            if (
                $Session->get('uid')
                && !$Session->get('auth')
                && $secondaryLoginType !== 0
            ) {
                $authStep = 'secondary';
            } elseif (!$Session->get('uid') && !$Session->get('auth')) {
                $Session->remove('auth-primary');
                $Session->remove('auth-secondary');
                $Session->remove('username');
            }
        }

        $this->setAttributes([
            'data-qui' => 'controls/users/Login',
            'authStep' => $authStep,

            // predefined list of Authenticator classes; if empty = use all authenticators
            // that are configured
            'authenticators' => []
        ]);

        parent::__construct($options);

        $this->addCSSClass('quiqqer-login ');
        $this->setJavaScriptControl('controls/users/Login');
    }

    public function getBody(): string
    {
        if (QUI::getUsers()->isAuth(QUI::getUserBySession())) {
            $message = QUI::getLocale()->get(
                'quiqqer/core',
                'controls.users.auth.quiqqerLogin.already.logged.in',
                [
                    'username' => QUI::getUserBySession()->getName()
                ]
            );

            return "<div class='q-message q-message-information'>$message</div>";
        }

        $nextAuthenticators = $this->next();

        if (is_null($nextAuthenticators)) {
            return '';
        }

        $authenticators = [];
        $exclusiveAuthenticators = $this->getAttribute('authenticators');

        if (empty($exclusiveAuthenticators)) {
            $exclusiveAuthenticators = [];
        }

        foreach ($nextAuthenticators as $authenticator) {
            if (
                $this->getAttribute('authStep') === 'primary'
                && !empty($exclusiveAuthenticators)
                && !in_array($authenticator, $exclusiveAuthenticators, true)
            ) {
                continue;
            }

            $Control = forward_static_call([$authenticator, 'getLoginControl']);

            if (is_null($Control)) {
                continue;
            }

            $instance = new $authenticator();

            $authenticators[] = [
                'instance' => $instance,
                'class' => $authenticator,
                'control' => $Control
            ];
        }

        $Engine = QUI::getTemplateManager()->getEngine();

        $this->setAttribute('data-qui-auth-step', $this->getAttribute('authStep'));

        $Engine->assign([
            'self' => $this,
            'passwordReset' => !empty($_REQUEST['password_reset']),
            'globalAuth' => $this->isGlobalAuth,
            'authenticators' => $authenticators,
            'count' => count($authenticators) - 1,
            'authStep' => $this->getAttribute('authStep')
        ]);

        return $Engine->fetch(__DIR__ . '/Login.html');
    }

    /**
     * Return the next Authenticator, if one exists
     *
     * @return array<int, class-string<QUI\Users\AuthenticatorInterface>>|null
     */
    public function next(): array | null
    {
        $authenticators = [];

        if (QUI::getSession()->get('auth-primary') !== 1) {
            // primary authenticator
            if (QUI::isFrontend()) {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalFrontendAuthenticators();
            } else {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalBackendAuthenticators();
            }

            $this->setAttribute('authStep', 'primary');
        }

        if (QUI::isFrontend()) {
            $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_frontend');
        } else {
            $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_backend');
        }

        if (
            empty($authenticators)
            && QUI::getSession()->get('auth-secondary') !== 1
        ) {
            if ($secondaryLoginType === 0) {
                return [];
            }

            // secondary authenticators
            if (QUI::isFrontend()) {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalFrontendSecondaryAuthenticators();
            } else {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalBackendSecondaryAuthenticators();
            }

            try {
                // use only from user activated authenticators
                $uid = QUI::getSession()->get('uid');
                $user = QUI::getUsers()->get($uid);
                $userAuthenticators = $user->getAuthenticators();

                $authenticators = array_filter($authenticators, function ($authenticator) use ($userAuthenticators) {
                    foreach ($userAuthenticators as $userAuthenticator) {
                        if (get_class($userAuthenticator) === $authenticator) {
                            return true;
                        }
                    }
                    return false;
                });
            } catch (\Exception) {
            }

            $this->setAttribute('authStep', 'secondary');
        }

        if (empty($authenticators)) {
            return null;
        }

        // sort globals (QUIQQER Login has to be first!)
        usort($authenticators, static function ($a, $b): int {
            if ($a === QUI\Users\Auth\QUIQQER::class) {
                return -1;
            }

            if ($b === QUI\Users\Auth\QUIQQER::class) {
                return 1;
            }

            return 0;
        });

        return $authenticators;
    }

    /**
     * @param string $icon
     */
    public function renderIcon($icon): string
    {
        if (str_starts_with($icon, 'fa ') || str_starts_with($icon, 'fa-')) {
            return '<span class="' . $icon . '"></span>';
        }

        return '<img src="' . $icon . '">';
    }
}
