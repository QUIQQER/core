<?php

/**
 * This file contains \QUI\Users\Manager
 */

namespace QUI\Users;

use Doctrine\DBAL\ParameterType;
use DOMElement;
use DOMXPath;
use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Interfaces\Users\User as QUIUserInterface;
use QUI\Security\Password;
use QUI\Utils\DOM;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Text\XML;
use Throwable;

use function class_implements;
use function count;
use function defined;
use function explode;
use function filter_var;
use function file_exists;
use function func_get_args;
use function func_num_args;
use function idn_to_ascii;
use function implode;
use function in_array;
use function is_numeric;
use function is_object;
use function md5;
use function microtime;
use function preg_replace;
use function print_r;
use function round;
use function serialize;
use function str_contains;
use function strtotime;
use function substr;
use function time;

use const FILTER_VALIDATE_EMAIL;
use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * QUIQQER user manager
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * @event onUserLogin [ \QUI\Users\User ]
 */
class Manager
{
    const AUTH_ERROR_AUTH_ERROR = 'AUTH_ERROR_AUTH_ERROR';

    const AUTH_ERROR_USER_NOT_FOUND = 'auth_error_user_not_found';

    const AUTH_ERROR_USER_NOT_ACTIVE = 'auth_error_user_not_active';

    const AUTH_ERROR_USER_DELETED = 'auth_error_user_deleted';

    const AUTH_ERROR_LOGIN_EXPIRED = 'auth_error_login_expired';

    const AUTH_ERROR_NO_ACTIVE_GROUP = 'auth_error_no_active_group';

    /**
     * internal prevention for multiple session user calling
     */
    protected bool $multipleCallPrevention = false;

    /**
     * @var array<int|string, QUIUserInterface> - list of users (cache)
     */
    private array $users = [];

    /**
     * @var array<int|string, int|string>
     */
    private array $usersUUIDs = [];

    private ?Nobody $Nobody = null;

    private ?SystemUser $SystemUser = null;

    private ?QUIUserInterface $Session = null;

    /**
     * Return the db table for the addresses
     */
    public static function tableAddress(): string
    {
        return QUI::getDBTableName('users_address');
    }

    /**
     * @param string $pass
     * @param string|null $salt -> deprecated
     *
     * @return string
     * @deprecated
     *
     * Generates a hash of a password
     *
     */
    public static function genHash(string $pass, null | string $salt = null): string
    {
        return Password::generateHash($pass);
    }

    /**
     * Get user profile template (profile window)
     */
    public static function getProfileTemplate(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine(true);
        $packages = QUI::getPackageManager()->getInstalled();
        $extend = '';

        foreach ($packages as $package) {
            $name = $package['name'];
            $userXml = OPT_DIR . $name . '/user.xml';

            if (!file_exists($userXml)) {
                continue;
            }

            $Document = XML::getDomFromXml($userXml);
            $Path = new DOMXPath($Document);

            $tabs = $Path->query("//user/profile/tab");

            if ($tabs === false) {
                continue;
            }

            foreach ($tabs as $Tab) {
                if (!$Tab instanceof DOMElement) {
                    continue;
                }

                $extend .= DOM::parseCategoryToHTML($Tab);
            }
        }

        $Engine->assign([
            'QUI' => new QUI(),
            'extend' => $extend
        ]);

        return $Engine->fetch(SYS_DIR . 'template/users/profile.html');
    }

    /**
     * Create the database tables for the users
     *
     * @throws \Exception
     */
    public function setup(): void
    {
        Install::user();
    }

    /**
     * Return the db table
     */
    public static function table(): string
    {
        return QUI::getDBTableName('users');
    }

    /**
     * Get the user by id or uuid
     *
     * @param int|string $id - Could be user-id or user uuid
     * @return QUI\Interfaces\Users\User
     *
     * @throws Exception
     * @throws QUI\Exception
     * @throws ExceptionStack
     */
    public function get(int | string $id): QUI\Interfaces\Users\User
    {
        if (is_numeric($id)) {
            $id = (int)$id;

            if (!$id) {
                return new Nobody();
            }

            if ($id == 5) {
                return new SystemUser();
            }
        }

        if (isset($this->usersUUIDs[$id])) {
            $id = $this->usersUUIDs[$id];
        }

        if (isset($this->users[$id])) {
            return $this->users[$id];
        }

        try {
            $User = new User($id);
        } catch (\Exception $exception) {
            try {
                $userGetResult = QUI::getEvents()->fireEvent('userGet', [$id]);

                if (!empty($userGetResult)) {
                    $UserInstance = null;

                    foreach ($userGetResult as $entry) {
                        if (!$entry) {
                            continue;
                        }

                        $interfaces = class_implements($entry);

                        if (!is_array($interfaces)) {
                            $interfaces = [];
                        }

                        if (!in_array(QUI\Interfaces\Users\User::class, $interfaces, true)) {
                            continue;
                        }

                        $UserInstance = $entry;
                    }

                    if ($UserInstance) {
                        $interfaces = class_implements($UserInstance);

                        if (!is_array($interfaces)) {
                            $interfaces = [];
                        }

                        if (in_array(QUI\Interfaces\Users\User::class, $interfaces, true)) {
                            $User = $UserInstance;
                        }
                    }
                }
            } catch (\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }

            if (empty($User)) {
                throw $exception;
            }
        }


        $uuid = $User->getUUID();

        if (QUI::isRuntimeCacheEnabled()) {
            $this->usersUUIDs[$User->getId()] = $User->getUUID();
            $this->users[$uuid] = $User;
        }

        return $User;
    }

    /**
     * Is the user authenticated
     * Checks only the session user
     */
    public function isAuth(QUIUserInterface | null $User): bool
    {
        if ($User === null) {
            return false;
        }

        if (!$User->getUUID()) {
            return false;
        }

        $_User = $this->getUserBySession();

        if ($User->getUUID() == $_User->getUUID()) {
            return true;
        }

        return false;
    }

    public function getUserBySession(): QUIUserInterface
    {
        if (defined('SYSTEM_INTERN')) {
            return $this->getSystemUser();
        }

        if ($this->Session !== null) {
            return $this->Session;
        }

        if ($this->multipleCallPrevention) {
            return $this->getNobody();
        }

        try {
            $result = QUI::getEvents()->fireEvent('userGetBySession');

            if (!empty($result)) {
                $UserInstance = null;

                foreach ($result as $entry) {
                    if (!$entry) {
                        continue;
                    }

                    $interfaces = class_implements($entry);

                    if (!is_array($interfaces)) {
                        $interfaces = [];
                    }

                    if (!in_array(QUI\Interfaces\Users\User::class, $interfaces, true)) {
                        continue;
                    }

                    $UserInstance = $entry;
                }

                if ($UserInstance) {
                    $interfaces = class_implements($UserInstance);

                    if (!is_array($interfaces)) {
                        $interfaces = [];
                    }

                    if (in_array(QUI\Interfaces\Users\User::class, $interfaces, true)) {
                        $this->Session = $UserInstance;
                        return $this->Session;
                    }
                }
            }
        } catch (\Exception $exception) {
            QUI\System\Log::addDebug($exception->getMessage());
        }

        $this->multipleCallPrevention = true;

        // max_life_time check
        try {
            $this->checkUserSession();
            $this->Session = $this->get(QUI::getSession()->get('uid'));
        } catch (QUI\Exception $Exception) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                QUI\System\Log::writeDebugException($Exception);
            }

            //QUI::getSession()->destroy();
            //QUI::getSession()->start();
            $this->Session = $this->getNobody();
        }

        return $this->Session;
    }

    public function resetSessionUser(): void
    {
        $this->Session = null;
        $this->multipleCallPrevention = false;
    }

    /**
     * Execute a request as its authenticated user without changing the login session.
     *
     * @template T
     * @param callable(): T $Callback
     * @return T
     */
    public function withSessionUser(QUIUserInterface $User, callable $Callback): mixed
    {
        $PreviousUser = $this->Session;
        $previousPrevention = $this->multipleCallPrevention;
        $this->Session = $User;

        try {
            return $Callback();
        } finally {
            $this->Session = $PreviousUser;
            $this->multipleCallPrevention = $previousPrevention;
        }
    }

    public function getSystemUser(): SystemUser
    {
        if ($this->SystemUser === null) {
            $this->SystemUser = new SystemUser();
        }

        return $this->SystemUser;
    }

    public function getNobody(): Nobody
    {
        if ($this->Nobody === null) {
            $this->Nobody = new Nobody();
        }

        return $this->Nobody;
    }

    /**
     * Checks, if the session is ok
     *
     * @throws QUI\Users\Exception
     * @throws QUI\Exception
     */
    public function checkUserSession(): void
    {
        // max_life_time check
        $Session = QUI::getSession();

        $clearSessionData = static function () use ($Session): void {
            // only if auth is not running
            if ($Session->get('inAuthentication')) {
                return;
            }

            $SymfonySession = $Session->getSymfonySession();

            if ($SymfonySession === false) {
                return;
            }

            $sessionData = $SymfonySession->all();

            foreach (array_keys($sessionData) as $key) {
                if (str_starts_with($key, 'auth-')) {
                    $Session->remove($key);
                }
            }
        };

        if (!$Session->check()) {
            $clearSessionData();

            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permission.session.expired'
                ),
                401
            );
        }


        $isAuthenticated = false;

        // NEW CHECK
        if ($Session->get('uid')) {
            if (QUI::isFrontend()) {
                $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_frontend');
            } else {
                $secondaryLoginType = (int)QUI::conf('auth_settings', 'secondary_backend');
            }

            if (
                $secondaryLoginType === 1
                && $Session->get('auth-primary')
                && $Session->get('auth-secondary')
            ) {
                // 2fa is active and a must
                $isAuthenticated = true;
            } elseif ($secondaryLoginType === 2) {
                // 2FA possible
                // check if user has 2FA activated
                $userId = $Session->get('uid');

                try {
                    $user = QUI::getUsers()->get($userId);
                    $has2FA = QUI\Users\Utils::has2FAAuthenticator($user);

                    if (
                        $has2FA === false
                        && $Session->get('auth-primary')
                    ) {
                        $isAuthenticated = true;
                    }

                    if (
                        $has2FA
                        && $Session->get('auth-primary')
                        && $Session->get('auth-secondary')
                    ) {
                        $isAuthenticated = true;
                    }
                } catch (QUI\Exception $Exception) {
                    $clearSessionData();
                    throw $Exception;
                }
            } elseif (
                $secondaryLoginType === 0
                && $Session->get('auth-primary')
            ) {
                // no 2FA
                $isAuthenticated = true;
            }
        }

        // OLD CHECK !$Session->get('uid') || !$Session->get('auth')
        if ($isAuthenticated === false) {
            $clearSessionData();

            if ($Session->get('expired.from.other')) {
                throw new QUI\Users\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.session.expired.from.other'
                    ),
                    401
                );
            }

            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permission.session.expired'
                ),
                401
            );
        }

        try {
            $User = $this->get($Session->get('uid'));
        } catch (QUI\Exception $Exception) {
            $clearSessionData();
            throw $Exception;
        }

        if (!$User->isActive()) {
            $clearSessionData();

            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.user.inactive'
                ),
                401
            );
        }

        // Mehrfachanmeldungen? Dann keine Prüfung
        if (
            QUI::conf('session', 'multible')
            || $Session->get('inAuthentication')
        ) {
            return;
        }

        $sessionSecHash = $Session->get('secHash');
        $secHash = $this->getSecHash();
        $userSecHash = $User->getAttribute('secHash');

        if ($sessionSecHash == $secHash && $userSecHash == $secHash) {
            return;
        }

        $message = $User->getLocale()->get(
            'quiqqer/core',
            'exception.session.expired.from.other'
        );

        $Session->set('uid', 0);
        $SymfonySession = $Session->getSymfonySession();

        if ($SymfonySession !== false) {
            $SymfonySession->clear();
        }

        $Session->refresh();
        $Session->set('expired.from.other', 1);

        throw new QUI\Users\Exception($message, 401);
    }

    /**
     * Generate a user-dependent security hash
     * There are different data use such as IP, User-Agent and the System-Salt
     *
     * @todo   noch eine eindeutige möglichkeit der Identifizierung des Browser finden
     */
    public function getSecHash(): string
    {
        $secHashData = [];
        $useragent = '';

        // chromeframe nicht mitaufnehmen -> bug
        if (
            isset($_SERVER['HTTP_USER_AGENT'])
            && !str_contains($_SERVER['HTTP_USER_AGENT'], 'chromeframe')
        ) {
            $useragent = $_SERVER['HTTP_USER_AGENT'];
        }

        $secHashData[] = $useragent;
        $secHashData[] = QUI\Utils\System::getClientIP();
        $secHashData[] = QUI::conf('globals', 'salt');

        return md5(serialize($secHashData));
    }

    /**
     * Is the Object a User?
     * It checks the user interface, for authentication please use ->isAuth()
     */
    public function isUser(mixed $User): bool
    {
        if (!is_object($User)) {
            return false;
        }

        if ($User instanceof User) {
            return true;
        }

        if ($User instanceof QUI\Interfaces\Users\User) {
            return true;
        }

        return false;
    }

    public function isSystemUser(mixed $User): bool
    {
        if (!is_object($User)) {
            return false;
        }

        if ($User::class === SystemUser::class) {
            return true;
        }

        return false;
    }

    /**
     * Create a new User
     *
     * @throws QUI\Users\Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function createChild(
        bool | string $username = false,
        null | QUIUserInterface $ParentUser = null
    ): QUIUserInterface {
        // check, is the user allowed to create new users
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.admin.users.create',
            $ParentUser
        );

        if ($username) {
            if ($this->usernameExists((string)$username)) {
                throw new QUI\Users\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.lib.user.exist'
                    )
                );
            }

            $newName = $username;
        } else {
            $newUserLocale = QUI::getLocale()->get('quiqqer/core', 'user.create.new.username');
            $newName = $newUserLocale;

            while ($this->usernameExists($newName)) {
                $milliseconds = round(microtime(true) * 1000);
                $newName = $newUserLocale . ' (' . $milliseconds . ')';
            }
        }

        self::checkUsernameSigns((string)$username);

        try {
            $uuid = QUI\Utils\Uuid::get();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            throw new QUI\Users\Exception('Could not create User. Please try again later.');
        }

        try {
            $Connection = QUI::getDataBaseConnection();
            $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(self::table()), [
                'uuid' => $uuid,
                'username' => $newName,
                'regdate' => time(),
                'lang' => QUI::getLocale()->getCurrent()
            ]);

            $newId = $Connection->lastInsertId();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw new Exception(
                $Exception->getMessage(),
                (int)$Exception->getCode()
            );
        }
        $User = $this->get($newId);

        $Everyone = new QUI\Groups\Everyone();

        $User->setAttribute('toolbar', $Everyone->getAttribute('toolbar'));

        if (!$User->getAttribute('toolbar')) {
            $available = QUI\Editor\Manager::getToolbars();

            if (!empty($available)) {
                $User->setAttribute('toolbar', $available[0]);
            }
        }

        $User->addToGroup($Everyone->getUUID());
        $User->save($ParentUser);

        QUI::getEvents()->fireEvent('userCreate', [$User]);

        // workspace
        $this->setDefaultWorkspacesForUsers($User);

        return $User;
    }

    public function usernameExists(string $username): bool
    {
        if (empty($username)) {
            return false;
        }

        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('username')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq('username', ':username'))
                ->setParameter('username', $username)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return false;
        }

        return !empty($result);
    }

    /**
     * Checks name for illegal characters
     *
     * @throws QUI\Users\Exception
     */
    public static function checkUsernameSigns(string $username): bool
    {
        if (str_contains($username, '@')) {
            if (self::checkEmailUsername($username)) {
                return true;
            }

            throw new QUI\Users\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.lib.user.illegal.signs')
            );
        }

        if ($username !== self::clearUsername($username)) {
            throw new QUI\Users\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.lib.user.illegal.signs')
            );
        }

        return true;
    }

    private static function checkEmailUsername(string $username): bool
    {
        $parts = explode('@', $username);

        if (count($parts) !== 2) {
            return false;
        }

        [$localPart, $domain] = $parts;

        if ($localPart === '' || $domain === '') {
            return false;
        }

        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($asciiDomain === false) {
            return false;
        }

        return filter_var($localPart . '@' . $asciiDomain, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Delete illegal characters from the name
     */
    public static function clearUsername(string $username): string
    {
        return preg_replace('/[^a-zA-Z0-9-_äöüß@\.\+]/', '', $username) ?? $username;
    }

    /**
     * Set the default workspace for a user
     * The user must have administration permissions
     *
     * @throws QUI\Exception
     */
    public function setDefaultWorkspacesForUsers(QUIUserInterface $User): void
    {
        if (!QUI\Permissions\Permission::isAdmin($User)) {
            return;
        }

        if (!$User->canUseBackend()) {
            return;
        }

        $twoColumn = QUI\Workspace\Manager::getTwoColumnDefault();
        $threeColumn = QUI\Workspace\Manager::getThreeColumnDefault();

        $newWorkspaceId = QUI\Workspace\Manager::addWorkspace(
            $User,
            QUI::getLocale()->get('quiqqer/core', 'workspaces.2.columns'),
            $twoColumn,
            500,
            700
        );

        QUI\Workspace\Manager::addWorkspace(
            $User,
            QUI::getLocale()->get('quiqqer/core', 'workspaces.3.columns'),
            $threeColumn,
            500,
            700
        );

        QUI\Workspace\Manager::setStandardWorkspace($User, $newWorkspaceId);
    }

    /**
     * Create user with specific attributes
     *
     * @param array<string, mixed> $attributes
     *
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws QUI\ExceptionStack
     * @throws QUI\Permissions\Exception
     */
    public function createChildWithAttributes(
        array $attributes = [],
        ?QUIUserInterface $PermissionUser = null
    ): QUIUserInterface {
        // check, is the user allowed to create new users
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.admin.users.create',
            $PermissionUser
        );

        $insertData = [
            'regdate' => time(),
            'lang' => QUI::getLocale()->getCurrent()
        ];

        // Specific attributes that identify users need to checked first
        if (!empty($attributes['username'])) {
            $username = $attributes['username'];

            if ($this->usernameExists($username)) {
                throw new QUI\Users\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.lib.user.exist'
                    )
                );
            }

            self::checkUsernameSigns($username);

            $insertData['username'] = $username;
            unset($attributes['username']);
        } else {
            $newUserLocale = QUI::getLocale()->get('quiqqer/core', 'user.create.new.username');
            $newName = $newUserLocale;

            while ($this->usernameExists($newName)) {
                $milliseconds = round(microtime(true) * 1000);
                $newName = $newUserLocale . ' (' . $milliseconds . ')';
            }

            $insertData['username'] = $newName;
        }

        if (!empty($attributes['uuid'])) {
            $uuid = $attributes['uuid'];

            try {
                $this->get($uuid);

                throw new QUI\Users\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.lib.user.exist'
                    )
                );
            } catch (\Exception) {
                // uuid does not exist - this is good
            }

            $insertData['uuid'] = $uuid;
            unset($attributes['uuid']);
        } else {
            try {
                $insertData['uuid'] = QUI\Utils\Uuid::get();
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
                throw new QUI\Users\Exception('Could not create User. Please try again later.');
            }
        }

        if (!empty($attributes['id'])) {
            $id = (int)$attributes['id'];

            try {
                $this->get($id);

                throw new QUI\Users\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.lib.user.exist'
                    )
                );
            } catch (\Exception) {
                // id does not exist - this is good
            }

            $insertData['id'] = $id;
            unset($attributes['id']);
        }

        try {
            $Connection = QUI::getDataBaseConnection();
            $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier(self::table()), $insertData);

            $newId = $Connection->lastInsertId();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw new Exception(
                $Exception->getMessage(),
                (int)$Exception->getCode()
            );
        }
        $User = $this->get($newId);

        $Everyone = new QUI\Groups\Everyone();

        $User->setAttribute('toolbar', $Everyone->getAttribute('toolbar'));

        if (!$User->getAttribute('toolbar')) {
            $available = QUI\Editor\Manager::getToolbars();

            if (!empty($available)) {
                $User->setAttribute('toolbar', $available[0]);
            }
        }

        $User->addToGroup($Everyone->getUUID());
        $User->save($PermissionUser);

        QUI::getEvents()->fireEvent('userCreate', [$User]);

        // workspace
        $this->setDefaultWorkspacesForUsers($User);

        if (!empty($attributes)) {
            $User->setAttributes($attributes);
            $User->save($PermissionUser);
        }

        return $User;
    }

    /**
     * Returns the number of users in the system
     */
    public function countAllUsers(): int
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('COUNT(id)')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return 0;
        }

        return (int)$result;
    }

    /**
     * @param boolean $objects - as objects=true, as array=false
     *
     * @return ($objects is true
     *     ? array<int, QUIUserInterface>
     *     : array<int, array<string, mixed>>)
     */
    public function getAllUsers(bool $objects = false): array
    {
        if (!$objects) {
            try {
                $QueryBuilder = QUI::getQueryBuilder();

                return $QueryBuilder
                    ->select('*')
                    ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                    ->orderBy('username')
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (\Doctrine\DBAL\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());

                return [];
            }
        }

        $result = [];
        $ids = $this->getAllUserIds();

        foreach ($ids as $id) {
            try {
                $result[] = $this->get($id['uuid']);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllUserIds(): array
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();

            return $QueryBuilder
                ->select('id', 'uuid')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->orderBy('username')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return [];
        }
    }

    /**
     * Logged in a user
     *
     * @param array<string, mixed>|integer|string $authData - Authentication data, passwords, keys, hashes etc
     *
     * @return QUIUserInterface|null
     * @throws QUI\Database\Exception
     * @throws QUI\Users\Exception
     * @throws \Exception
     */
    public function login(array | int | string $authData = []): QUIUserInterface | null
    {
        if (QUI::getSession()->get('auth') && QUI::getSession()->get('uid')) {
            $userId = QUI::getSession()->get('uid');
            $this->Session = $this->get($userId);

            return $this->Session;
        }

        $Events = QUI::getEvents();
        $numArgs = func_num_args();
        $userId = false;

        // old login -> v 1.0; fallback
        if ($numArgs == 2) {
            $arguments = func_get_args();
            $authData = [
                'username' => $arguments[0],
                'password' => $arguments[1]
            ];
        }

        if (!is_array($authData)) {
            $authData = [];
        }

        // try to get userId by authData
        if (!empty($authData['username'])) {
            try {
                $User = self::getUserByName($authData['username']);
                $userId = $User->getUUID();
            } catch (\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        $Events->fireEvent('userLoginStart', [$userId]);

        // global authenticators
        if (QUI::getSession()->get('auth-primary') !== 1) {
            if (QUI::isBackend()) {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalBackendAuthenticators();
            } else {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalFrontendAuthenticators();
            }

            foreach ($authenticators as $authenticator) {
                $this->authenticate($authenticator, $authData);
            }

            if (!empty($authenticators)) {
                QUI::getSession()->set('auth-primary', 1);
            }
        }

        $secondaryAuth = false;

        if (QUI::isBackend() && QUI::conf('auth_settings', 'secondary_backend')) {
            $secondaryAuth = true;
        }

        if (QUI::isFrontend() && QUI::conf('auth_settings', 'secondary_frontend')) {
            $secondaryAuth = true;
        }

        if ($secondaryAuth && QUI::getSession()->get('auth-secondary') !== 1) {
            if (QUI::isBackend()) {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalBackendSecondaryAuthenticators();
            } else {
                $authenticators = QUI\Users\Auth\Handler::getInstance()->getGlobalFrontendSecondaryAuthenticators();
            }

            /* @var $Authenticator QUI\Users\AbstractAuthenticator */
            try {
                foreach ($authenticators as $authenticator) {
                    $this->authenticate($authenticator, $authData);
                }
            } catch (\Exception $exception) {
                throw new QUI\Users\Auth\Exception2FA(
                    $exception->getMessage()
                );
            }

            QUI::getSession()->set('auth-secondary', 1);
        }

        $userId = QUI::getSession()->get('uid');
        $User = $this->get($userId);

        if (QUI::getUsers()->isNobodyUser($User)) {
            $Exception = new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
                404
            );

            $Exception->setAttribute('reason', self::AUTH_ERROR_USER_NOT_FOUND);

            $Events->fireEvent('userLoginError', [$userId, $Exception]);

            throw $Exception;
        }

        // check user data
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $userData = $QueryBuilder
                ->select('id', 'uuid', 'expire', 'secHash', 'active')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq('uuid', ':uuid'))
                ->setParameter('uuid', $userId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new Exception(
                ['quiqqer/core', 'exception.login.fail'],
                500
            );
        }

        if (!$userData) {
            $Exception = new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user.not.found'],
                404
            );

            $Exception->setAttribute('reason', self::AUTH_ERROR_USER_NOT_FOUND);

            $Events->fireEvent('userLoginError', [$userId, $Exception]);

            throw $Exception;
        }

        if ($userData['active'] == 0) {
            $Exception = new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user_not_active'],
                401
            );

            $Exception->setAttribute('userId', $userId);
            $Exception->setAttribute('reason', self::AUTH_ERROR_USER_NOT_ACTIVE);

            $Events->fireEvent('userLoginError', [$userId, $Exception]);

            throw $Exception;
        }

        if ($userData['active'] == -1) {
            $Exception = new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail.user_deleted'],
                401
            );

            $Exception->setAttribute('userId', $userId);
            $Exception->setAttribute('reason', self::AUTH_ERROR_USER_DELETED);

            $Events->fireEvent('userLoginError', [$userId, $Exception]);

            throw $Exception;
        }

        if (
            $userData['expire']
            && $userData['expire'] != '0000-00-00 00:00:00'
            && strtotime($userData['expire']) < time()
        ) {
            $Exception = new QUI\Users\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.login.expire', [
                    'expire' => $userData['expire']
                ]),
                401
            );

            $Exception->setAttribute('reason', self::AUTH_ERROR_LOGIN_EXPIRED);

            $Events->fireEvent('userLoginError', [$userId, $Exception]);

            throw $Exception;
        }

        /* @var $User User */
        // user authenticators
        //$authenticator = $User->getAuthenticators();
        //foreach ($authenticator as $Authenticator) {
        //$this->authenticate($Authenticator, $authData);
        //}

        // has user permission for a login
        QUI\Permissions\Permission::checkPermission('quiqqer.login', $User);

        // session
        $Session = QUI::getSession();

        if (!$Session->regenerate()) {
            throw new QUI\Users\Exception(
                ['quiqqer/core', 'exception.login.fail'],
                500
            );
        }

        $Session->remove('inAuthentication');
        $Session->set('auth', 1);
        $Session->set('uid', $userId);
        $Session->set('secHash', $this->getSecHash());

        $userAgent = '';

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
        }

        try {
            QUI::getDataBaseConnection()->update(
                QUI\Utils\Doctrine::quoteIdentifier(self::table()),
                [
                    'lastvisit' => time(),
                    'user_agent' => $userAgent,
                    'secHash' => $this->getSecHash()
                ],
                ['uuid' => $userId]
            );
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new Exception(
                ['quiqqer/core', 'exception.login.fail'],
                500
            );
        }

        $User->refresh();
        $this->users[$userId] = $User;
        $this->Session = $User;

        QUI::getEvents()->fireEvent('userLogin', [$User]);

        return $User;
    }

    /**
     * @throws Exception
     * @throws ExceptionStack
     * @throws QUI\Exception
     */
    public function getUserByName(string $username): QUIUserInterface | User
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('id', 'uuid')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq('username', ':username'))
                ->setParameter('username', $username)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\Database\Exception(
                ['quiqqer/core', 'exception.login.fail'],
                500
            );
        }

        if (!$result) {
            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.user.not.found'
                ),
                404
            );
        }

        return $this->get($result['uuid']);
    }

    /**
     * Authenticate the user at one authenticator
     *
     * @param array<string, mixed> $params
     * @param bool|null $authenticationExecuted - true if the authenticator was executed in this call
     *
     * @throws QUI\Users\UserAuthException
     * @throws QUI\Exception
     * @throws QUI\ExceptionStack
     */
    public function authenticate(
        AuthenticatorInterface | AbstractAuthenticator | string $authenticator,
        array $params = [],
        ?bool &$authenticationExecuted = null
    ): bool {
        $authenticationExecuted = false;
        $username = '';
        $Session = QUI::getSession();

        // Wenn im Session ein Benutzername schon gesetzt wurde, von einem anderen Authenticator
        // dann muss IMMER dieser Benutzer zur Authentifizierung verwendet werden
        if (QUI::getSession()->get('username')) {
            $username = QUI::getSession()->get('username');
        } elseif (isset($params['username'])) {
            $username = $params['username'];
        }

        // try to get user id
        $userId = false;

        if (!empty($username)) {
            try {
                $User = self::getUserByName($username);
                $userId = $User->getUUID();
            } catch (QUI\Users\Exception $Exception) {
                if ($Exception->getCode() !== 404) {
                    throw $Exception;
                }
            }
        }

        if ($authenticator instanceof AuthenticatorInterface) {
            $Authenticator = $authenticator;
        } else {
            $Authenticator = QUI\Users\Auth\Handler::getInstance()->getAuthenticator(
                $authenticator,
                $username
            );
        }

        if ($userId === false) {
            try {
                $userId = $Authenticator->getUser()->getUUID();
            } catch (\Exception $Exception) {
                if ($Exception instanceof QUI\Database\Exception) {
                    throw $Exception;
                }

                // The authenticator reports an invalid identity during authentication.
            }
        }

        QUI::getEvents()->fireEvent('userAuthenticatorLoginStart', [$userId, $authenticator]);

        if (
            $Session->get('auth-' . $Authenticator::class)
            && $Session->get('username')
            && $Session->get('uid')
        ) {
            return true;
        }

        try {
            $Authenticator->auth($params);
            $authenticationExecuted = true;
        } catch (QUI\Users\Exception $Exception) {
            $Exception->setAttribute('reason', self::AUTH_ERROR_AUTH_ERROR);

            QUI\System\Log::write(
                'Login failed: ' . $username,
                QUI\System\Log::LEVEL_WARNING,
                [
                    'exception' => $Exception->getMessage()
                ],
                'auth'
            );

            QUI::getEvents()->fireEvent('userLoginError', [$userId, $Exception, $authenticator]);

            $UserAuthException = new QUI\Users\UserAuthException(
                ['quiqqer/core', 'exception.login.fail'],
                401
            );
            $UserAuthException->setAttribute('reason', self::AUTH_ERROR_AUTH_ERROR);

            throw $UserAuthException;
        } catch (Throwable $Exception) {
            QUI\System\Log::write(
                'Login failed: ' . $username,
                QUI\System\Log::LEVEL_WARNING,
                [
                    'exception' => $Exception->getMessage()
                ],
                'auth'
            );

            throw new QUI\Users\UserAuthException(
                ['quiqqer/core', 'exception.login.fail'],
                401
            );
        }

        // auth successful, set to session
        if (!$Session->get('username')) {
            $Session->set(
                'username',
                $Authenticator->getUser()->getUsername()
            );
        }

        if (!$Session->get('uid')) {
            $Session->set(
                'uid',
                $Authenticator->getUser()->getUUID()
            );
        }

        $Session->set(
            'auth-' . $Authenticator::class,
            1
        );

        return true;
    }

    public function isNobodyUser(mixed $User): bool
    {
        if (!is_object($User)) {
            return false;
        }

        if ($User::class === Nobody::class) {
            return true;
        }

        return false;
    }

    /**
     * Get specific users
     *
     * @param array<string, mixed> $params -> SQL Array
     *
     * @return QUIUserInterface[]
     */
    public function getUsers(array $params = []): array
    {
        $result = $this->getUserIds($params);

        if (!isset($result[0])) {
            return [];
        }

        $Users = [];

        foreach ($result as $entry) {
            try {
                $Users[] = $this->get($entry['uuid']);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage());
            }
        }

        return $Users;
    }

    /**
     * Get specific users ids
     *
     * @param array<string, mixed> $params -> SQL Array
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserIds(array $params = []): array
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $QueryBuilder
                ->select('id', 'uuid')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()));

            QUI\Utils\Doctrine::parseDbArrayToQueryBuilder($QueryBuilder, $params);

            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return [];
    }

    /**
     * Session initialize?
     */
    public function existsSession(): bool
    {
        return $this->Session !== null;
    }

    /**
     * this method is used for a cleanup of the ram.
     * individual user instances can be removed from the internal ram cache.
     */
    public function unsetUserInstance(QUIUserInterface $User): void
    {
        $uuid = $User->getUUID();
        $id = $User->getId();

        if (isset($this->users[$id])) {
            unset($this->users[$id]);
        }

        if (isset($this->usersUUIDs[$uuid])) {
            unset($this->usersUUIDs[$uuid]);
        }
    }

    /**
     * @throws Exception
     * @throws ExceptionStack
     * @throws QUI\Exception
     */
    public function getUserByMail(string $email): QUIUserInterface
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('id', 'uuid')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq('email', ':email'))
                ->setParameter('email', $email)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\Database\Exception(
                ['quiqqer/core', 'exception.login.fail'],
                500
            );
        }

        if (!$result) {
            throw new QUI\Users\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.user.not.found'
                ),
                404
            );
        }

        return $this->get($result['uuid']);
    }

    /**
     * @deprecated use usernameExists()
     */
    public function existsUsername(string $username): bool
    {
        return $this->usernameExists($username);
    }

    /**
     * @deprecated use existsUsername
     */
    public function checkUsername(string $username): bool
    {
        return $this->usernameExists($username);
    }

    /**
     * @deprecated use emailExists
     */
    public function existEmail(string $email): bool
    {
        return $this->emailExists($email);
    }

    /**
     * Checks the e-mail if this is already on the system
     */
    public function emailExists(string $email): bool
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('email')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->where($QueryBuilder->expr()->eq('email', ':email'))
                ->setParameter('email', $email)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());

            return false;
        }

        return !empty($result);
    }

    /**
     * @throws Exception
     * @throws ExceptionStack
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function deleteUser(int | string $id): bool
    {
        return $this->get($id)->delete();
    }

    public function onDeleteUser(QUIUserInterface $User): void
    {
        $id = $User->getId();
        $uuid = $User->getUUID();

        if (isset($this->users[$uuid])) {
            unset($this->users[$uuid]);
        }

        if (isset($this->usersUUIDs[$id])) {
            unset($this->usersUUIDs[$id]);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>|int
     *
     * @throws Exception
     */
    public function search(array $params): array | int
    {
        return $this->execSearch($params);
    }

    /**
     * User search
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>|int
     *
     * @throws QUI\Database\Exception
     * @todo where params
     */
    protected function execSearch(array $params): array | int
    {
        $params = Orthos::clearArray($params);

        $allowOrderFields = [
            'id' => true,
            'uuid' => true,
            'email' => true,
            'username' => true,
            'usergroup' => true,
            'firstname' => true,
            'lastname' => true,
            'birthday' => true,
            'active' => true,
            'regdate' => true,
            'lastedit' => true,
            'su' => true
        ];

        $max = 10;
        $start = 0;

        /**
         * SELECT
         */
        $query = 'SELECT * FROM ' . QUI\Utils\Doctrine::quoteIdentifier(self::table());
        $binds = [];

        if (isset($params['count'])) {
            $query = 'SELECT COUNT( id ) AS count FROM ' . QUI\Utils\Doctrine::quoteIdentifier(self::table());
        }

        /**
         * WHERE
         */

        /**
         * WHERE Search
         */
        if (!empty($params['search'])) {
            if (empty($params['searchSettings'])) {
                $params['searchSettings'] = [];
            }

            if (!isset($params['searchSettings']['filter'])) {
                $params['searchSettings']['filter'] = [];
            }

            if (!isset($params['searchSettings']['fields'])) {
                $params['searchSettings']['fields'] = $allowOrderFields;
            }

            if (!isset($params['searchSettings']['userSearchString'])) {
                $params['searchSettings']['userSearchString'] = '';
            }

            $search = $params['searchSettings']['userSearchString'];
            $filter = $params['searchSettings']['filter'];
            $fields = $params['searchSettings']['fields'];

            // cleanup fields
            foreach ($fields as $field => $val) {
                $fields[$field] = (int)$val;

                if (!$fields[$field]) {
                    unset($fields[$field]);
                }
            }

            if (empty($fields)) {
                $fields = $allowOrderFields;
            }

            if (isset($fields['id']) && !isset($fields['uuid'])) {
                $fields['uuid'] = true;
            }

            $filter_status = false;
            $filter_group = false;
            $filter_groups_exclude = false;
            $filter_regdate_first = false;
            $filter_regdate_last = false;

            // set the filters
            if (isset($filter['filter_status'])) {
                switch ($filter['filter_status']) {
                    case '1':
                    case '0':
                    case '-1':
                        $filter_status = true;
                        break;

                    default:
                    case 'all':
                        $filter_status = false;
                        break;
                }
            }

            if (!empty($filter['filter_group'])) {
                $filter_group = true;
            }

            if (!empty($filter['filter_groups_exclude'])) {
                $filter_groups_exclude = true;
            }

            if (!empty($filter['filter_regdate_first'])) {
                $filter_regdate_first = true;
            }

            if (!empty($filter['filter_regdate_last'])) {
                $filter_regdate_last = true;
            }


            // create the search
            if (empty($search)) {
                $query .= ' WHERE 1=1 ';
            } else {
                $query .= ' WHERE (';
                $binds[':search'] = '%' . $search . '%';

                foreach ($fields as $field => $value) {
                    if (!isset($allowOrderFields[$field])) {
                        continue;
                    }

                    if (empty($value)) {
                        continue;
                    }

                    $query .= ' ' . $field . ' LIKE :search OR ';
                }

                if (str_ends_with($query, 'OR ')) {
                    $query = substr($query, 0, -3);
                }

                $query .= ') ';
            }


            // empty where, no search possible
            if (str_contains($query, 'WHERE ()')) {
                return [];
            }


            if ($filter_status) {
                $query .= ' AND active = :active';
                $binds[':active'] = (int)$filter['filter_status'];
            }


            if ($filter_group) {
                $groups = explode(',', $filter['filter_group']);
                $subQuery = [];

                foreach ($groups as $g => $groupId) {
                    if ($groupId != 0) {
                        $subQuery[] = 'usergroup LIKE :g' . $g . ' ';

                        $binds[':g' . $g] = '%,' . $groupId . ',%';
                    }
                }

                $query .= ' AND (' . implode(' OR ', $subQuery) . ')';
            }

            if ($filter_groups_exclude) {
                $i = 0;

                foreach ($filter['filter_groups_exclude'] as $groupId) {
                    if ($groupId != 0) {
                        $query .= ' AND usergroup NOT LIKE :group' . $i . ' ';
                        $binds[':group' . $i] = '%,' . $groupId . ',%';
                    }

                    $i++;
                }
            }

            if ($filter_regdate_first) {
                $query .= ' AND regdate >= :firstreg ';
                $binds[':firstreg'] = QUI\Utils\Convert::convertMySqlDatetime(
                    $filter['filter_regdate_first'] . ' 00:00:00'
                );
            }


            if ($filter_regdate_last) {
                $query .= " AND regdate <= :lastreg ";
                $binds[':lastreg'] = QUI\Utils\Convert::convertMySqlDatetime(
                    $filter['filter_regdate_last'] . ' 00:00:00'
                );
            }
        }

        /**
         * ORDER
         */
        if (
            isset($params['order'])
            && isset($params['field'])
            && $params['field']
            && isset($allowOrderFields[$params['field']])
        ) {
            $query .= ' ORDER BY ' . $params['field'] . ' ' . $params['order'];
        } elseif (!isset($params['count'])) {
            $query .= ' ORDER BY regdate DESC';
        }

        /**
         * LIMIT
         */
        if (isset($params['limit']) || isset($params['start'])) {
            if (isset($params['limit'])) {
                $max = (int)$params['limit'];
            }

            if (isset($params['start'])) {
                $start = (int)$params['start'];
            }

            $query = QUI::getDataBaseConnection()
                ->getDatabasePlatform()
                ->modifyLimitQuery($query, $max, $start);
        }

        try {
            $Connection = QUI::getDataBaseConnection();
            $Statement = $Connection->prepare($query);

            foreach ($binds as $key => $value) {
                if ($key == ':active' || $key == ':su') {
                    $Statement->bindValue($key, $value, ParameterType::INTEGER);
                } else {
                    $Statement->bindValue($key, $value);
                }
            }

            $result = $Statement->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            $message = $Exception->getMessage();
            $message .= print_r($query, true);

            throw new QUI\Database\Exception(
                $message,
                (int)$Exception->getCode()
            );
        }


        if (isset($params['count'])) {
            return (int)$result[0]['count'];
        }

        return $result;
    }

    /**
     * User count
     *
     * @param array<string, mixed> $params - Search parameter
     * @return integer
     *
     * @throws QUI\DataBase\Exception
     */
    public function count(array $params = []): int
    {
        $params['count'] = true;

        if (isset($params['limit'])) {
            unset($params['limit']);
        }

        if (isset($params['start'])) {
            unset($params['start']);
        }

        return (int)$this->execSearch($params);
    }

    /**
     * Create a new ID for a not created user
     *
     * @throws Exception
     * @deprecated
     */
    protected function newId(): int
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder();
            $result = $QueryBuilder
                ->select('MAX(id) AS id')
                ->from(QUI\Utils\Doctrine::quoteIdentifier(self::table()))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw new Exception(
                $Exception->getMessage(),
                (int)$Exception->getCode()
            );
        }

        $newId = 100;

        if (isset($result['id'])) {
            $newId = $result['id'] + 1;
        }

        if ($newId < 100) {
            $newId = 100;
        }

        return $newId;
    }
}
