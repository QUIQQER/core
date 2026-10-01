<?php

namespace QUI\InstallationWizard\QuiqqerSteps;

use QUI;
use QUI\Locale;

/**
 * Class Welcome
 */
class MailSMTP extends QUI\InstallationWizard\AbstractInstallationWizardStep
{
    /**
     * @param array<array-key, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setJavaScriptControl('controls/installation/MailSMTP');
    }

    public function getTitle(?Locale $Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.setup.MailSMTP.title');
    }

    public function getDescription(?Locale $Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.setup.MailSMTP.description');
    }

    public function create(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        $Engine->assign([
            'urlImageDir' => URL_OPT_DIR . 'quiqqer/core/bin/images/installation/',
            'SMTPServer' => QUI::conf('mail', 'SMTPServer'),
            'SMTPPort' => QUI::conf('mail', 'SMTPPort'),
            'SMTPSecure' => QUI::conf('mail', 'SMTPSecure'),
            'SMTPAuth' => QUI::conf('mail', 'SMTPAuth'),
            'SMTPUser' => QUI::conf('mail', 'SMTPUser'),
            'SMTPPass' => QUI::conf('mail', 'SMTPPass'),

            'SMTPSecureSSL_verify_peer' => QUI::conf('mail', 'SMTPSecureSSL_verify_peer'),
            'SMTPSecureSSL_verify_peer_name' => QUI::conf('mail', 'SMTPSecureSSL_verify_peer_name'),
            'SMTPSecureSSL_allow_self_signed' => QUI::conf('mail', 'SMTPSecureSSL_allow_self_signed'),
        ]);

        return $Engine->fetch(__DIR__ . '/MailSMTP.html');
    }
}
