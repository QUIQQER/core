<?php

namespace QUI\Users\Auth\Controls\Settings;

use QUI;
use QUI\Control;
use QUI\Users\Auth\WebAuthn\CredentialRepository;

class WebAuthn extends Control
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->addCSSClass('quiqqer-webauthn-auth-settings');
        $this->addCSSClass('default-content');
        $this->addCSSFile(__DIR__ . '/WebAuthn.css');
        $this->setJavaScriptControl('controls/users/auth/settings/WebAuthn');
    }

    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $user = $this->getAttribute('user');
        $credentials = [];
        $activationMode = (bool)$this->getAttribute('activationMode');
        $activatedExistingCredentials = (bool)$this->getAttribute('activatedExistingCredentials');
        $canCreateCredential = false;
        $isEnabled = false;

        if ($user instanceof QUI\Interfaces\Users\User) {
            $credentials = (new CredentialRepository())->findByUserUuid((string)$user->getUUID());
            $isEnabled = $user->hasAuthenticator(QUI\Users\Auth\WebAuthn::class);
            $SessionUser = QUI::getUserBySession();

            if (
                $SessionUser->getUUID() === $user->getUUID()
                || QUI::getSession()->get('uid') === $user->getUUID()
            ) {
                $canCreateCredential = true;
            }
        }

        $Engine->assign([
            'activatedExistingCredentials' => $activatedExistingCredentials,
            'activationMode' => $activationMode,
            'canCreateCredential' => $canCreateCredential,
            'isEnabled' => $isEnabled,
            'userUuid' => $user instanceof QUI\Interfaces\Users\User ? (string)$user->getUUID() : '',
            'credentials' => $credentials
        ]);

        return $Engine->fetch(__DIR__ . '/WebAuthn.html');
    }
}
