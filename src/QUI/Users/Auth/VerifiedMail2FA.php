<?php

namespace QUI\Users\Auth;

use QUI;
use QUI\Interfaces\Users\User;
use QUI\Locale;
use QUI\Users\AbstractAuthenticator;
use QUI\Users\Attribute\AttributeVerificationStatus;
use QUI\Users\Attribute\Verifiable\MailAttribute;
use QUI\Users\Exception;
use QUI\Utils\Security\Orthos;
use Random\RandomException;

/**
 * Class Mail2FA
 * - only as second authentication
 */
class VerifiedMail2FA extends AbstractAuthenticator
{
    private const MAIL_THROTTLE_SECONDS = 60;
    private const SETUP_CODE_TTL = 300;
    private const SETUP_CODE_ATTEMPTS = 5;
    private const SETUP_CONTEXT = 'quiqqer.security.mail2fa.setup';

    public const USER_CODE_ATTRIBUTE = 'quiqqer.verified.2fa.mail.code';
    public const USER_CODE_VERIFYING_ATTRIBUTE = 'quiqqer.verifying.2fa.mail.code';

    protected ?User $User = null;
    protected mixed $user = null;
    protected bool $authenticated = false;

    /**
     * @param array<array-key, mixed>|int|string|User|null $user
     */
    public function __construct(null | array | int | string | User $user = null)
    {
        if (empty($user)) {
            return;
        }

        if ($user instanceof User) {
            $this->User = $user;
            return;
        }

        if (is_int($user)) {
            $user = (string)$user;
        }

        if (!is_string($user)) {
            return;
        }

        $this->user = Orthos::clear($user);
    }

    public static function getLoginControl(): QUI\Control
    {
        return new Controls\VerifiedMail2FA();
    }

    public function getSettingsControl(): ?QUI\Control
    {
        $user = null;

        try {
            $user = $this->getUser();
        } catch (QUI\Exception) {
        }

        return new Controls\Settings\VerifiedMail2FA([
            'user' => $user
        ]);
    }

    public static function isCLICompatible(): bool
    {
        return true;
    }

    public function getTitle(null | Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.mail2fa.title');
    }

    public function getDescription(null | Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.mail2fa.description');
    }

    public function getIcon(): string
    {
        return 'fa fa-envelope';
    }

    public function getFrontendTitle(null | Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.mail2fa.frontend.title');
    }

    public function getFrontendDescription(null | Locale $Locale = null): string
    {
        if (is_null($Locale)) {
            $Locale = QUI::getLocale();
        }

        return $Locale->get('quiqqer/core', 'quiqqer.mail2fa.frontend.description');
    }

    /**
     * @throws Exception
     */
    public function getUser(): User
    {
        if (!is_null($this->User)) {
            return $this->User;
        }

        if (empty($this->user)) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
                404
            );
        }

        try {
            $this->User = QUI::getUsers()->get($this->user);
            return $this->User;
        } catch (QUI\Exception) {
        }

        try {
            $this->User = QUI::getUsers()->getUserByName($this->user);
            return $this->User;
        } catch (QUI\Exception) {
        }

        try {
            $this->User = QUI::getUsers()->getUserByMail($this->user);
            return $this->User;
        } catch (QUI\Exception) {
        }

        throw new QUI\Users\Exception(
            ['quiqqer/core', 'exception.login.fail.user.not.found'],
            404
        );
    }

    /**
     * @param string|int|array<string, mixed> $authParams
     */
    public function auth(string | int | array $authParams): bool
    {
        $uid = QUI::getSession()->get('uid');
        $hasChar = false;

        for ($i = 1; $i <= 6; $i++) {
            if (isset($authParams["char-$i"]) && $authParams["char-$i"] !== '') {
                $hasChar = true;
                break;
            }
        }

        if (empty($uid) || !$hasChar) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
                401
            );
        }

        try {
            $User = QUI::getUsers()->get($uid);
        } catch (QUI\Exception) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
            );
        }

        if (!($User instanceof QUI\Users\User)) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
            );
        }

        $email = $User->getAttribute('email');
        $isVerified = $User->isAttributeVerified($email, MailAttribute::class);

        if (!$isVerified) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.user.mail.not.verified'],
            );
        }

        $code = $authParams['char-1'] . $authParams['char-2'] . $authParams['char-3'] .
            $authParams['char-4'] . $authParams['char-5'] . $authParams['char-6'];

        $userCode = QUI::getSession()->get(self::USER_CODE_ATTRIBUTE);

        if ($code === $userCode) {
            // reset code if all is ok
            QUI::getSession()->set(self::USER_CODE_ATTRIBUTE, '');

            return true;
        }

        throw new QUI\Users\Exception(
            ['quiqqer/core', 'exception.login.fail'],
            401
        );
    }

    /**
     * Sends a mail to the user with a 6-figure code
     *
     * @return void
     * @throws QUI\Permissions\Exception
     * @throws RandomException
     */
    public static function sendAuthMailToSessionUser(): void
    {
        // get user
        $uid = QUI::getSession()->get('uid');

        if (empty($uid)) {
            throw new QUI\Permissions\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
            );
        }

        try {
            $User = QUI::getUsers()->get($uid);
        } catch (QUI\Exception) {
            throw new QUI\Permissions\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
            );
        }

        if (!($User instanceof QUI\Users\User)) {
            throw new QUI\Permissions\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
            );
        }

        $email = $User->getAttribute('email');
        $isVerified = $User->isAttributeVerified($email, MailAttribute::class);

        if (!$isVerified) {
            throw new QUI\Permissions\Exception(
                ['quiqqer/core', 'exception.user.mail.not.verified'],
            );
        }

        // send mail
        $digitCode = '';
        for ($i = 0; $i < 6; $i++) {
            $digitCode .= random_int(0, 9);
        }

        $ThrottleDecision = QUI\Security\Throttle::acquireForUser(
            $User,
            'quiqqer/core',
            'users.verified-mail-auth',
            self::MAIL_THROTTLE_SECONDS
        );

        if (!$ThrottleDecision->isAllowed()) {
            return;
        }

        try {
            QUI::getSession()->set(self::USER_CODE_ATTRIBUTE, $digitCode);

            QUI::getMailManager()->send(
                $email,
                QUI::getLocale()->get('quiqqer/core', 'quiqqer.verified.mail2fa.mail.subject', [
                    'host' => $_SERVER['HTTP_HOST']
                ]),
                QUI::getLocale()->get('quiqqer/core', 'quiqqer.verified.mail2fa.mail.content', [
                    'code' => $digitCode,
                    'host' => $_SERVER['HTTP_HOST']
                ])
            );
        } catch (\Exception $exception) {
            $ThrottleDecision->release();
            QUI\System\Log::addError($exception->getMessage(), [
                'source' => self::class . '::sendAuthMailToSessionUser'
            ]);
        }
    }

    //region enable

    /**
     * @throws QUI\Permissions\Exception
     * @throws RandomException
     */
    public static function sendEnableMailToSessionUser(): void
    {
        $User = self::getAuthorizedUser();
        $email = $User->getAttribute('email');

        if (empty($email)) {
            throw new QUI\Permissions\Exception(
                ['quiqqer/core', 'exception.login.fail.user.need.email'],
            );
        }

        // send mail
        $digitCode = '';

        for ($i = 0; $i < 6; $i++) {
            $digitCode .= random_int(0, 9);
        }

        $ThrottleDecision = QUI\Security\Throttle::acquireForUser(
            $User,
            'quiqqer/core',
            'users.verified-mail-enable',
            self::MAIL_THROTTLE_SECONDS
        );

        if (!$ThrottleDecision->isAllowed()) {
            return;
        }

        try {
            QUI::getSession()->set(self::USER_CODE_VERIFYING_ATTRIBUTE, $digitCode);
            QUI::getSession()->set(self::SETUP_CONTEXT, [
                'userUuid' => (string)$User->getUUID(),
                'email' => $email,
                'expires' => time() + self::SETUP_CODE_TTL,
                'attempts' => 0
            ]);

            QUI::getMailManager()->send(
                $email,
                QUI::getLocale()->get('quiqqer/core', 'quiqqer.enable.mail2fa.mail.subject', [
                    'host' => $_SERVER['HTTP_HOST']
                ]),
                QUI::getLocale()->get('quiqqer/core', 'quiqqer.enable.mail2fa.mail.content', [
                    'code' => $digitCode,
                    'host' => $_SERVER['HTTP_HOST']
                ])
            );
        } catch (\Exception $exception) {
            $ThrottleDecision->release();
            self::clearSetupCode();
            QUI\System\Log::addError($exception->getMessage(), [
                'source' => self::class . '::sendEnableMailToSessionUser'
            ]);

            throw new Exception(['quiqqer/core', 'exception.login.fail'], 500);
        }
    }

    /**
     * @param mixed $code
     *
     * @throws Exception
     */
    public static function verifyMailByUser($code): bool
    {
        $User = self::getAuthorizedUser();
        $Session = QUI::getSession();
        $context = $Session->get(self::SETUP_CONTEXT);
        $userCode = $Session->get(self::USER_CODE_VERIFYING_ATTRIBUTE);

        if (
            !is_array($context)
            || ($context['userUuid'] ?? null) !== (string)$User->getUUID()
            || ($context['email'] ?? null) !== $User->getAttribute('email')
            || !is_int($context['expires'] ?? null)
            || $context['expires'] <= time()
            || !is_int($context['attempts'] ?? null)
            || $context['attempts'] >= self::SETUP_CODE_ATTEMPTS
            || !is_string($userCode)
            || !preg_match('/^[0-9]{6}$/D', $userCode)
        ) {
            self::clearSetupCode();
            return false;
        }

        $context['attempts']++;
        $Session->set(self::SETUP_CONTEXT, $context);

        if (!is_string($code) || !hash_equals($userCode, $code)) {
            return false;
        }

        self::clearSetupCode();

        try {
            // verify mail
            $User->setStatusToVerifiableAttribute(
                $User->getAttribute('email'),
                QUI\Users\Attribute\Verifiable\MailAttribute::class,
                AttributeVerificationStatus::VERIFIED
            );

            $User->save(QUI::getUsers()->getSystemUser());
        } catch (QUI\Exception $e) {
            throw new QUI\Users\Exception($e->getMessage());
        }

        return true;
    }

    public static function enableByUser(): bool
    {
        $User = self::getAuthorizedUser();

        if (!$User->isAttributeVerified($User->getAttribute('email'), MailAttribute::class)) {
            throw new Exception(['quiqqer/core', 'exception.user.mail.not.verified'], 403);
        }

        $ParentUser = QUI::getUsers()->isNobodyUser(QUI::getUserBySession())
            ? QUI::getUsers()->getSystemUser()
            : null;
        $User->enableAuthenticator(self::class, $ParentUser);

        return true;
    }

    private static function clearSetupCode(): void
    {
        QUI::getSession()->remove(self::USER_CODE_VERIFYING_ATTRIBUTE);
        QUI::getSession()->remove(self::SETUP_CONTEXT);
    }

    private static function getAuthorizedUser(): QUI\Users\User
    {
        QUI\Security\RequestOrigin::assertNotCrossOrigin();
        $Session = QUI::getSession();
        $uid = $Session->get('uid');

        if (
            $Session->get('auth-primary') !== 1
            || !is_string($uid)
            || $uid === ''
        ) {
            throw new Exception(['quiqqer/core', 'exception.2fa.mail.enable.not.authenticated'], 401);
        }

        $secondaryLoginType = QUI::isFrontend()
            ? (int)QUI::conf('auth_settings', 'secondary_frontend')
            : (int)QUI::conf('auth_settings', 'secondary_backend');

        if (
            $secondaryLoginType === 1
            && $Session->get('auth') === 1
            && $Session->get('auth-secondary') !== 1
        ) {
            throw new QUI\Users\Exception(['quiqqer/core', 'exception.permission.session.expired'], 401);
        }

        $User = QUI::getUsers()->get($uid);
        $Handler = Handler::getInstance();
        $configured = QUI::isFrontend()
            ? $Handler->getGlobalFrontendSecondaryAuthenticators()
            : $Handler->getGlobalBackendSecondaryAuthenticators();

        if (
            !$User instanceof QUI\Users\User
            || (string)$User->getUUID() !== $uid
            || !in_array(self::class, $configured, true)
            || !Helper::hasUserPermissionToUseAuthenticator($User, self::class)
        ) {
            throw new QUI\Permissions\Exception(['quiqqer/core', 'exception.no.permission'], 403);
        }

        if ((new WebAuthn\Server())->isFullyAuthenticatedUser($User)) {
            return $User;
        }

        if ($secondaryLoginType !== 1 || $Session->get('auth') || $Session->get('auth-secondary')) {
            throw new QUI\Permissions\Exception(['quiqqer/core', 'exception.no.permission'], 403);
        }

        foreach ($User->getAuthenticators() as $Authenticator) {
            if (
                $Authenticator instanceof WebAuthn
                && (new WebAuthn\CredentialRepository())->findByUserUuid($uid) === []
            ) {
                continue;
            }

            if (
                in_array($Authenticator::class, $configured, true)
                && $Authenticator->isSecondaryAuthentication()
            ) {
                throw new QUI\Permissions\Exception(['quiqqer/core', 'exception.no.permission'], 403);
            }
        }

        if (
            in_array(WebAuthn::class, $configured, true)
            && (new WebAuthn\CredentialRepository())->findByUserUuid($uid) !== []
        ) {
            throw new QUI\Permissions\Exception(['quiqqer/core', 'exception.no.permission'], 403);
        }

        return $User;
    }

    //endregion

    public function isPrimaryAuthentication(): bool
    {
        return false;
    }
}
