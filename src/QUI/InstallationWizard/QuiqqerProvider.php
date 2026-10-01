<?php

namespace QUI\InstallationWizard;

use QUI;

use function dirname;
use function file_get_contents;
use function mb_strtolower;

/**
 * Class QuiqqerProvider
 */
class QuiqqerProvider extends AbstractInstallationWizard
{
    /**
     * @param \QUI\Locale|null $Locale
     */
    public function getTitle($Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'set.up.title');
    }

    /**
     * @param \QUI\Locale|null $Locale
     */
    public function getDescription($Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'set.up.description');
    }

    public function getPriority(): int
    {
        return 2;
    }

    /**
     * @return QUI\InstallationWizard\AbstractInstallationWizardStep[]
     */
    public function getSteps(): array
    {
        return [
            new QuiqqerSteps\Welcome(),
            //new QuiqqerSteps\Licence(),
            new QuiqqerSteps\Country(),
            new QuiqqerSteps\Groups(),
            new QuiqqerSteps\Mail(),
            new QuiqqerSteps\MailSMTP(),
            new QuiqqerSteps\Cron(),
            new QuiqqerSteps\Workspace(),
            new QuiqqerSteps\Finish(),
        ];
    }

    /**
     * @throws QUI\Exception
     */
    public function execute(array $data = []): void
    {
        $Config = QUI::getConfig('etc/conf.ini.php');

        // check if all data are available what we needed
        if (isset($data['quiqqer-country'])) {
            $Config->set('globals', 'standardLanguage', mb_strtolower($data['quiqqer-country']));
        }

        if (!empty($data['mail.admin_mail'])) {
            $Config->set('mail', 'admin_mail', $data['mail.admin_mail']);
        }

        // smtp stuff
        if (isset($data['use-smtp']) && (int)$data['use-smtp']) {
            $Config->set('mail', 'SMTP', '1');
            $Config->set('mail', 'SMTPServer', $data['smtp-server']);

            $Config->set('mail', 'SMTPPort', $data['smtp-port']);
            $Config->set('mail', 'SMTPAuth', (int)!empty($data['smtp-auth']));
            $Config->set('mail', 'SMTPUser', $data['smtp-user']);
            $Config->set('mail', 'SMTPPass', $data['smtp-password']);
            $Config->set('mail', 'SMTPSecure', $data['smtp-secure']);
            $Config->set('mail', 'SMTPSecureSSL_verify_peer', (int)!empty($data['smtp-secure-verify_peer']));
            $Config->set('mail', 'SMTPSecureSSL_verify_peer_name', (int)!empty($data['smtp-secure-verify_peer_name']));
            $Config->set('mail', 'SMTPSecureSSL_allow_self_signed', (int)!empty($data['mail.settings.allow_self_signed']));
        }

        if (!empty($data['add-quiqqer-groups'])) {
            $this->setupForGroupsAndToolbars();
        }

        // workspace
        if (isset($data['workspace-columns'])) {
            switch ($data['workspace-columns']) {
                case '2-columns':
                    QUI\Workspace\Manager::setStandardWorkspace(
                        QUI::getUserBySession(),
                        1
                    );
                    break;

                case '3-columns':
                    QUI\Workspace\Manager::setStandardWorkspace(
                        QUI::getUserBySession(),
                        2
                    );
                    break;
            }
        }

        $Config->save();
    }

    /**
     * @return void
     */
    protected function setupForGroupsAndToolbars()
    {
        try {
            $Root = QUI::getGroups()->get(QUI::conf('globals', 'root'));
            $Config = QUI::getConfig('etc/installationWizard.ini.php');
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
            return;
        }

        // Redakteur / Editor
        $table = QUI\Permissions\Manager::table();
        $table2groups = $table . '2groups';

        try {
            if (!$Config->getValue('installationWizard', 'editorId')) {
                $Editor = $Root->createChild('Editor');
                $Editor->activate();
                $Config->setValue('installationWizard', 'editorId', $Editor->getUUID());

                $permissions = file_get_contents(__DIR__ . '/permissions.editor.json');

                QUI::getDataBaseConnection()->update(
                    $table2groups,
                    ['permissions' => $permissions],
                    ['group_id' => $Editor->getUUID()]
                );
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        // sys admin
        try {
            if (!$Config->getValue('installationWizard', 'sysAdminId')) {
                $sysAdmin = $Root->createChild('System administrator');
                $sysAdmin->activate();
                $Config->setValue('installationWizard', 'sysAdminId', $sysAdmin->getUUID());

                $permissions = file_get_contents(__DIR__ . '/permissions.sysadmin.json');

                QUI::getDataBaseConnection()->update(
                    $table2groups,
                    ['permissions' => $permissions],
                    ['group_id' => $sysAdmin->getUUID()]
                );
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }


        try {
            $Config->save();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
            return;
        }
    }
}
