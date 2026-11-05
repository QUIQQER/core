<?php

namespace QUI\Users\Auth;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\Users\Attribute\Verifiable\MailAttribute;

final class Settings
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function checkRequiredSecondFactors(User $User, array $settings): void
    {
        $Session = QUI::getSession();

        foreach (['frontend', 'backend'] as $area) {
            if ((int)($settings['secondary_' . $area] ?? 0) !== 1) {
                continue;
            }

            if (
                $Session->get('auth') !== 1
                || $Session->get('auth-primary') !== 1
                || $Session->get('auth-secondary') !== 1
                || (string)$Session->get('uid') !== (string)$User->getUUID()
            ) {
                self::denyActivation();
            }

            $selected = $settings['secondary'][$area] ?? [];
            $confirmed = false;

            foreach ($User->getAuthenticators() as $Authenticator) {
                if (
                    !is_array($selected)
                    || !in_array($Authenticator::class, $selected, true)
                    || !$Authenticator->isSecondaryAuthentication()
                    || $Session->get('auth-' . $Authenticator::class) !== 1
                ) {
                    continue;
                }

                if (
                    $Authenticator instanceof VerifiedMail2FA
                    && (
                        !method_exists($User, 'isAttributeVerified')
                        || !$User->isAttributeVerified($User->getAttribute('email'), MailAttribute::class)
                    )
                ) {
                    continue;
                }

                if (
                    $Authenticator instanceof WebAuthn
                    && (new WebAuthn\CredentialRepository())->findByUserUuid((string)$User->getUUID()) === []
                ) {
                    continue;
                }

                $confirmed = true;
                break;
            }

            if (!$confirmed) {
                self::denyActivation();
            }
        }
    }

    private static function denyActivation(): never
    {
        throw new Exception(['quiqqer/core', 'exception.auth.require_own_second_factor'], 409);
    }
}
