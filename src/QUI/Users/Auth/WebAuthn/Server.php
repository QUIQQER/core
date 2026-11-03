<?php

namespace QUI\Users\Auth\WebAuthn;

use QUI;
use QUI\Users\Auth\WebAuthn as WebAuthnAuthenticator;
use ReportUri\Passkeys\WebAuthn;

use function array_merge;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function get_class;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function str_contains;
use function strlen;
use function str_repeat;
use function strtolower;
use function strtr;
use function time;
use function trim;

use const PHP_URL_HOST;

class Server
{
    public const ENROLLMENT_FLOW_AUTHENTICATED = 'authenticated';
    public const ENROLLMENT_FLOW_BOOTSTRAP = 'bootstrap';
    public const ENROLLMENT_PURPOSE = 'webauthn-enrollment';
    public const SESSION_ENROLLMENT = 'quiqqer.webauthn.enrollment';

    private const CHALLENGE_TTL = 300;
    private const ENROLLMENT_TTL = 300;
    private const REGISTRATION_EXISTING_USER = 'existing-user-enrollment';
    private const REGISTRATION_NEW_USER = 'new-user-registration';
    private const SESSION_CREATE = 'quiqqer.webauthn.create';
    private const SESSION_GET = 'quiqqer.webauthn.get';

    private CredentialRepository $credentials;
    private ?WebAuthn $webAuthn;

    public function __construct(?CredentialRepository $credentials = null, ?WebAuthn $webAuthn = null)
    {
        $this->credentials = $credentials ?? new CredentialRepository();
        $this->webAuthn = $webAuthn;
    }

    public function authorizeAuthenticatedEnrollment(QUI\Interfaces\Users\User $User): bool
    {
        if (
            !$this->isFullyAuthenticatedUser($User)
            || !$this->isWebAuthnConfigured()
            || !$this->isWebAuthnAllowedForUser($User)
        ) {
            $this->clearEnrollmentAuthorization();
            return false;
        }

        $this->createEnrollmentAuthorization($User, self::ENROLLMENT_FLOW_AUTHENTICATED);
        return true;
    }

    public function authorizeRequiredMfaBootstrap(QUI\Interfaces\Users\User $User): bool
    {
        if (!$this->isRequiredMfaBootstrap($User)) {
            $this->clearEnrollmentAuthorization();
            return false;
        }

        $this->createEnrollmentAuthorization($User, self::ENROLLMENT_FLOW_BOOTSTRAP);
        return true;
    }

    public function getAuthorizedEnrollmentUser(): QUI\Interfaces\Users\User
    {
        $Session = QUI::getSession();

        if (
            $this->getSecondaryLoginType() === 1
            && $Session->get('auth') === 1
            && $Session->get('auth-secondary') !== 1
        ) {
            $this->denyExpiredEnrollment();
        }

        $authorization = $this->getEnrollmentAuthorization();

        try {
            $User = QUI::getUsers()->get($authorization['userUuid']);
        } catch (QUI\Exception) {
            $this->denyEnrollment();
        }

        if ((string)$User->getUUID() !== $authorization['userUuid']) {
            $this->denyEnrollment();
        }

        if (
            !$this->isWebAuthnConfigured()
            || !$this->isWebAuthnAllowedForUser($User)
        ) {
            $this->denyEnrollment();
        }

        if ($authorization['flow'] === self::ENROLLMENT_FLOW_AUTHENTICATED) {
            if (!$this->isFullyAuthenticatedUser($User)) {
                $this->denyEnrollment();
            }
        } elseif (!$this->isRequiredMfaBootstrap($User)) {
            $this->denyEnrollment();
        }

        return $User;
    }

    public function clearEnrollmentAuthorization(): void
    {
        QUI::getSession()->remove(self::SESSION_ENROLLMENT);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRegistrationOptions(
        QUI\Interfaces\Users\User $User,
        ?string $credentialName = null,
        ?string $userHandle = null
    ): array {
        $AuthorizedUser = $this->getAuthorizedEnrollmentUser();

        if ((string)$AuthorizedUser->getUUID() !== (string)$User->getUUID()) {
            $this->denyEnrollment();
        }

        $authorization = $this->getEnrollmentAuthorization($User);
        $webAuthn = $this->createWebAuthn();
        $existing = [];
        $credentials = $this->credentials->findByUserUuid((string)$User->getUUID());

        if (empty($userHandle) && !empty($credentials[0]['userHandle'])) {
            $userHandle = $credentials[0]['userHandle'];
        }

        $userHandle ??= $this->credentials->createUserHandle();

        foreach ($credentials as $credential) {
            $existing[] = $credential['credentialId'];
        }

        $options = $webAuthn->getCreateArgs(
            $this->credentials->userHandleToBinary($userHandle),
            $this->getWebAuthnAccountName($User->getUsername()),
            $this->getWebAuthnDisplayName($User->getUsername(), $User->getName()),
            120,
            'required',
            'required',
            null,
            $existing
        );

        QUI::getSession()->set(self::SESSION_CREATE, [
            'registrationType' => self::REGISTRATION_EXISTING_USER,
            'challenge' => $webAuthn->getChallenge(),
            'userUuid' => (string)$User->getUUID(),
            'userHandle' => $userHandle,
            'credentialName' => $credentialName ?: '',
            'enrollmentId' => $authorization['id'],
            'enrollmentPurpose' => $authorization['purpose'],
            'enrollmentFlow' => $authorization['flow'],
            'sessionBinding' => $authorization['sessionBinding'],
            'created' => time()
        ]);

        return [
            'publicKey' => $options->publicKey
        ];
    }

    private function getWebAuthnAccountName(string $username): string
    {
        if (str_contains($username, '@')) {
            return $username;
        }

        return $username . '@' . $this->getRpId();
    }

    private function getWebAuthnDisplayName(string $username, string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName !== '' && $displayName !== $username) {
            return $displayName;
        }

        return $this->getWebAuthnAccountName($username);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRegistrationOptionsForNewUser(
        string $username,
        string $displayName,
        ?string $credentialName = null
    ): array {
        $userHandle = $this->credentials->createUserHandle();
        $webAuthn = $this->createWebAuthn();

        $options = $webAuthn->getCreateArgs(
            $this->credentials->userHandleToBinary($userHandle),
            $this->getWebAuthnAccountName($username),
            $this->getWebAuthnDisplayName($username, $displayName),
            120,
            'required',
            'required'
        );

        QUI::getSession()->set(self::SESSION_CREATE, [
            'registrationType' => self::REGISTRATION_NEW_USER,
            'challenge' => $webAuthn->getChallenge(),
            'userUuid' => null,
            'userHandle' => $userHandle,
            'username' => $username,
            'credentialName' => $credentialName ?: '',
            'sessionBinding' => $this->getSessionBinding(),
            'created' => time()
        ]);

        return [
            'publicKey' => $options->publicKey,
            'userHandle' => $userHandle
        ];
    }

    /**
     * @param array<string, mixed> $attestation
     * @return array<string, mixed>
     */
    public function finishRegistrationForUser(
        QUI\Interfaces\Users\User $User,
        array $attestation,
        ?string $credentialName = null
    ): array {
        $state = QUI::getSession()->get(self::SESSION_CREATE);

        if (empty($state) || !is_array($state)) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_missing'],
                400
            );
        }

        $this->assertStateIsFresh(self::SESSION_CREATE, $state);
        $this->assertRegistrationSessionBinding($state);

        $registrationType = $state['registrationType'] ?? '';
        $authorization = null;

        if ($registrationType === self::REGISTRATION_EXISTING_USER) {
            $AuthorizedUser = $this->getAuthorizedEnrollmentUser();

            if ((string)$AuthorizedUser->getUUID() !== (string)$User->getUUID()) {
                $this->denyEnrollment();
            }

            $authorization = $this->getEnrollmentAuthorization($User);
            $this->assertRegistrationEnrollment($state, $authorization);
        } elseif ($registrationType !== self::REGISTRATION_NEW_USER) {
            $this->denyEnrollment();
        }

        if (!empty($state['userUuid']) && (string)$state['userUuid'] !== (string)$User->getUUID()) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_invalid_user'],
                400
            );
        }

        if (!empty($state['username']) && $state['username'] !== $User->getUsername()) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_invalid_user'],
                400
            );
        }

        $credential = $this->processCreate($state, $attestation);

        if (is_array($authorization)) {
            $AuthorizedUser = $this->getAuthorizedEnrollmentUser();

            if ((string)$AuthorizedUser->getUUID() !== (string)$User->getUUID()) {
                $this->denyEnrollment();
            }

            $authorization = $this->getEnrollmentAuthorization($User);
            $this->assertRegistrationEnrollment($state, $authorization);
            $this->consumeEnrollmentAuthorization($authorization);
        }

        $this->credentials->create(
            (string)$User->getUUID(),
            $state['userHandle'],
            $credential->credentialId,
            $credential->credentialPublicKey,
            $credential->signatureCounter,
            $credential->AAGUID,
            $attestation['transports'] ?? [],
            trim((string)($credentialName ?: ($state['credentialName'] ?? ''))),
            (bool)($credential->isBackupEligible ?? false),
            (bool)($credential->isBackedUp ?? false)
        );

        QUI::getSession()->remove(self::SESSION_CREATE);

        return [
            'credentialId' => $this->base64UrlEncode($credential->credentialId)
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthenticationOptions(?QUI\Interfaces\Users\User $User = null): array
    {
        $webAuthn = $this->createWebAuthn();
        $credentialIds = [];
        $userUuid = null;

        if ($User) {
            $userUuid = (string)$User->getUUID();

            foreach ($this->credentials->findByUserUuid($userUuid) as $credential) {
                $credentialIds[] = $credential['credentialId'];
            }
        }

        $options = $webAuthn->getGetArgs(
            $credentialIds,
            120,
            true,
            true,
            true,
            true,
            true,
            'required'
        );

        QUI::getSession()->set(self::SESSION_GET, [
            'challenge' => $webAuthn->getChallenge(),
            'userUuid' => $userUuid,
            'created' => time()
        ]);

        return [
            'publicKey' => $options->publicKey
        ];
    }

    /**
     * @param array<string, mixed> $assertion
     * @return array<string, mixed>
     */
    public function finishAuthentication(array $assertion): array
    {
        $state = QUI::getSession()->get(self::SESSION_GET);

        if (empty($state) || !is_array($state)) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_missing'],
                400
            );
        }

        $this->assertStateIsFresh(self::SESSION_GET, $state);

        $credentialId = $this->base64UrlDecode($assertion['id'] ?? '');
        $credential = $this->credentials->findByCredentialId($credentialId);

        if (!$credential) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.credential_not_found'],
                404
            );
        }

        if (!empty($state['userUuid']) && $state['userUuid'] !== $credential['userUuid']) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_invalid_user'],
                400
            );
        }

        $userHandle = $this->base64UrlDecode($assertion['response']['userHandle'] ?? '');

        if ($userHandle !== '' && $userHandle !== $this->credentials->userHandleToBinary($credential['userHandle'])) {
            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_invalid_user'],
                400
            );
        }

        $webAuthn = $this->createWebAuthn();
        $webAuthn->processGet(
            $this->base64UrlDecode($assertion['response']['clientDataJSON'] ?? ''),
            $this->base64UrlDecode($assertion['response']['authenticatorData'] ?? ''),
            $this->base64UrlDecode($assertion['response']['signature'] ?? ''),
            $credential['publicKey'],
            $state['challenge'],
            $credential['signCount'],
            true
        );

        $this->credentials->updateUsage($credential['id'], $webAuthn->getSignatureCounter());
        QUI::getSession()->remove(self::SESSION_GET);

        return $credential;
    }

    public function getCredentialRepository(): CredentialRepository
    {
        return $this->credentials;
    }

    public function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;

        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($value, true) ?: '';
    }

    public function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $attestation
     */
    private function processCreate(array $state, array $attestation): \stdClass
    {
        $webAuthn = $this->createWebAuthn();

        return $webAuthn->processCreate(
            $this->base64UrlDecode($attestation['response']['clientDataJSON'] ?? ''),
            $this->base64UrlDecode($attestation['response']['attestationObject'] ?? ''),
            $state['challenge'],
            true,
            true
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertStateIsFresh(string $sessionKey, array $state): void
    {
        if (empty($state['created']) || time() - (int)$state['created'] > self::CHALLENGE_TTL) {
            QUI::getSession()->remove($sessionKey);

            throw new QUI\Users\Auth\Exception(
                ['quiqqer/core', 'exception.webauthn.challenge_missing'],
                400
            );
        }
    }

    /**
     * @return array{id: string, purpose: string, flow: string, userUuid: string, sessionBinding: string,
     *     created: int, expires: int, used: bool}
     */
    private function getEnrollmentAuthorization(?QUI\Interfaces\Users\User $expectedUser = null): array
    {
        $authorization = QUI::getSession()->get(self::SESSION_ENROLLMENT);
        $sessionUserId = QUI::getSession()->get('uid');

        if (
            !is_array($authorization)
            || (is_int($authorization['expires'] ?? null) && $authorization['expires'] <= time())
        ) {
            $this->denyExpiredEnrollment();
        }

        if (
            !is_string($authorization['id'] ?? null)
            || $authorization['id'] === ''
            || ($authorization['purpose'] ?? null) !== self::ENROLLMENT_PURPOSE
            || !in_array($authorization['flow'] ?? null, [
                self::ENROLLMENT_FLOW_AUTHENTICATED,
                self::ENROLLMENT_FLOW_BOOTSTRAP
            ], true)
            || !is_string($authorization['userUuid'] ?? null)
            || $authorization['userUuid'] === ''
            || (!is_int($sessionUserId) && !is_string($sessionUserId))
            || (string)$sessionUserId !== $authorization['userUuid']
            || !is_string($authorization['sessionBinding'] ?? null)
            || !hash_equals($this->getSessionBinding(), $authorization['sessionBinding'])
            || !is_int($authorization['created'] ?? null)
            || !is_int($authorization['expires'] ?? null)
            || $authorization['created'] > time()
            || $authorization['expires'] <= time()
            || ($authorization['used'] ?? true) !== false
            || (
                $expectedUser !== null
                && (string)$expectedUser->getUUID() !== $authorization['userUuid']
            )
        ) {
            $this->denyEnrollment();
        }

        return $authorization;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertRegistrationSessionBinding(array $state): void
    {
        if (
            !is_string($state['sessionBinding'] ?? null)
            || !hash_equals($this->getSessionBinding(), $state['sessionBinding'])
        ) {
            $this->denyEnrollment();
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array{id: string, purpose: string, flow: string, userUuid: string, sessionBinding: string,
     *     created: int, expires: int, used: bool} $authorization
     */
    private function assertRegistrationEnrollment(array $state, array $authorization): void
    {
        if (
            ($state['enrollmentPurpose'] ?? null) !== self::ENROLLMENT_PURPOSE
            || ($state['enrollmentId'] ?? null) !== $authorization['id']
            || ($state['enrollmentFlow'] ?? null) !== $authorization['flow']
            || ($state['userUuid'] ?? null) !== $authorization['userUuid']
            || ($state['sessionBinding'] ?? null) !== $authorization['sessionBinding']
        ) {
            $this->denyEnrollment();
        }
    }

    private function createEnrollmentAuthorization(
        QUI\Interfaces\Users\User $User,
        string $flow
    ): void {
        $created = time();

        QUI::getSession()->set(self::SESSION_ENROLLMENT, [
            'id' => bin2hex(random_bytes(32)),
            'purpose' => self::ENROLLMENT_PURPOSE,
            'flow' => $flow,
            'userUuid' => (string)$User->getUUID(),
            'sessionBinding' => $this->getSessionBinding(),
            'created' => $created,
            'expires' => $created + self::ENROLLMENT_TTL,
            'used' => false
        ]);
    }

    /**
     * @param array{id: string, purpose: string, flow: string, userUuid: string, sessionBinding: string,
     *     created: int, expires: int, used: bool} $authorization
     */
    private function consumeEnrollmentAuthorization(array $authorization): void
    {
        $currentAuthorization = $this->getEnrollmentAuthorization();

        if ($currentAuthorization['id'] !== $authorization['id']) {
            $this->denyEnrollment();
        }

        $currentAuthorization['used'] = true;
        QUI::getSession()->set(self::SESSION_ENROLLMENT, $currentAuthorization);
        QUI::getSession()->remove(self::SESSION_ENROLLMENT);
    }

    public function isFullyAuthenticatedUser(QUI\Interfaces\Users\User $User): bool
    {
        $Session = QUI::getSession();

        if (
            $Session->get('auth') !== 1
            || $Session->get('auth-primary') !== 1
            || (string)$Session->get('uid') !== (string)$User->getUUID()
        ) {
            return false;
        }

        $SessionUser = QUI::getUserBySession();

        if (
            QUI::getUsers()->isNobodyUser($SessionUser)
            || (string)$SessionUser->getUUID() !== (string)$User->getUUID()
        ) {
            return false;
        }

        $secondaryLoginType = $this->getSecondaryLoginType();

        if ($secondaryLoginType === 1) {
            return $Session->get('auth-secondary') === 1;
        }

        if ($secondaryLoginType === 2 && $this->hasUsableSecondaryAuthenticator($User)) {
            return $Session->get('auth-secondary') === 1;
        }

        return true;
    }

    private function isRequiredMfaBootstrap(QUI\Interfaces\Users\User $User): bool
    {
        $Session = QUI::getSession();

        if (
            $this->getSecondaryLoginType() !== 1
            || $Session->get('auth-primary') !== 1
            || $Session->get('auth-secondary') === 1
            || $Session->get('auth')
            || (string)$Session->get('uid') !== (string)$User->getUUID()
            || !in_array(WebAuthnAuthenticator::class, $this->getConfiguredSecondaryAuthenticators(), true)
            || !$this->isWebAuthnAllowedForUser($User)
            || $this->hasUsableSecondaryAuthenticator($User)
            || !empty($this->credentials->findByUserUuid((string)$User->getUUID()))
        ) {
            return false;
        }

        return true;
    }

    private function hasUsableSecondaryAuthenticator(QUI\Interfaces\Users\User $User): bool
    {
        $configuredAuthenticators = $this->getConfiguredSecondaryAuthenticators();

        foreach ($User->getAuthenticators() as $Authenticator) {
            $authenticatorClass = get_class($Authenticator);

            if (
                !in_array($authenticatorClass, $configuredAuthenticators, true)
                || !$Authenticator->isSecondaryAuthentication()
            ) {
                continue;
            }

            if (
                $authenticatorClass === WebAuthnAuthenticator::class
                && empty($this->credentials->findByUserUuid((string)$User->getUUID()))
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<class-string<QUI\Users\AuthenticatorInterface>>
     */
    private function getConfiguredSecondaryAuthenticators(): array
    {
        $Handler = QUI\Users\Auth\Handler::getInstance();

        return QUI::isFrontend()
            ? $Handler->getGlobalFrontendSecondaryAuthenticators()
            : $Handler->getGlobalBackendSecondaryAuthenticators();
    }

    private function isWebAuthnConfigured(): bool
    {
        $Handler = QUI\Users\Auth\Handler::getInstance();

        if (QUI::isFrontend()) {
            $configuredAuthenticators = array_merge(
                $Handler->getGlobalFrontendAuthenticators(),
                $Handler->getGlobalFrontendSecondaryAuthenticators()
            );
        } else {
            $configuredAuthenticators = array_merge(
                $Handler->getGlobalBackendAuthenticators(),
                $Handler->getGlobalBackendSecondaryAuthenticators()
            );
        }

        return in_array(WebAuthnAuthenticator::class, $configuredAuthenticators, true);
    }

    private function isWebAuthnAllowedForUser(QUI\Interfaces\Users\User $User): bool
    {
        return QUI\Users\Auth\Helper::hasUserPermissionToUseAuthenticator(
            $User,
            WebAuthnAuthenticator::class
        );
    }

    private function getSecondaryLoginType(): int
    {
        return QUI::isFrontend()
            ? (int)QUI::conf('auth_settings', 'secondary_frontend')
            : (int)QUI::conf('auth_settings', 'secondary_backend');
    }

    private function getSessionBinding(): string
    {
        return hash('sha256', QUI::getSession()->getId());
    }

    private function denyEnrollment(): never
    {
        QUI::getSession()->remove(self::SESSION_ENROLLMENT);
        QUI::getSession()->remove(self::SESSION_CREATE);

        throw new QUI\Permissions\Exception(
            ['quiqqer/core', 'exception.no.permission'],
            403
        );
    }

    private function denyExpiredEnrollment(): never
    {
        $this->clearEnrollmentAuthorization();
        QUI::getSession()->remove(self::SESSION_CREATE);

        throw new QUI\Permissions\Exception(
            ['quiqqer/core', 'exception.webauthn.enrollment_expired'],
            401
        );
    }

    private function createWebAuthn(): WebAuthn
    {
        return $this->webAuthn ?? new WebAuthn(
            QUI::conf('globals', 'title') ?: 'QUIQQER',
            $this->getRpId(),
            true
        );
    }

    private function getRpId(): string
    {
        $Request = QUI::getRequest();
        $host = $Request->getHost();

        if ($host === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }

        $host = parse_url('https://' . $host, PHP_URL_HOST) ?: $host;
        $host = strtolower(trim($host, '.'));

        return preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    }
}
