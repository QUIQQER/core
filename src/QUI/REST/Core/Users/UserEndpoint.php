<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Endpoint;
use QUI\REST\Core\Input;
use QUI\Users\User;

abstract class UserEndpoint extends Endpoint
{
    protected const PROFILE_FIELDS = [
        'username' => 'string',
        'email' => 'string',
        'firstName' => 'string',
        'lastName' => 'string',
        'title' => 'string',
        'company' => 'boolean',
        'birthday' => 'string',
        'language' => 'string',
        'avatar' => 'string'
    ];

    protected static function authorize(Actor $Actor, string $action): void
    {
        Permission::checkPermission('quiqqer.core.rest.users.canUse', $Actor);
        Permission::checkPermission('quiqqer.admin.users.' . $action, $Actor);
    }

    protected static function user(int|string $id): User
    {
        try {
            $User = QUI::getUsers()->get($id);
        } catch (QUI\Users\Exception) {
            throw new ApiException('not_found', 'The requested user does not exist.', 404);
        }

        if (!$User instanceof User) {
            throw new ApiException('not_found', 'The requested user is not a manageable account.', 404);
        }

        $User->refresh();
        return $User;
    }

    /** @return array<string, mixed> */
    protected static function representation(Actor $User): array
    {
        return [
            'id' => $User->getId(),
            'uuid' => $User->getUUID(),
            'username' => $User->getUsername(),
            'displayName' => $User->getName(),
            'email' => $User->getAttribute('email'),
            'firstName' => $User->getAttribute('firstname'),
            'lastName' => $User->getAttribute('lastname'),
            'title' => $User->getAttribute('usertitle'),
            'company' => (bool)$User->getAttribute('company'),
            'birthday' => $User->getAttribute('birthday'),
            'language' => $User->getAttribute('lang'),
            'avatar' => $User->getAttribute('avatar'),
            'active' => $User->isActive(),
            'registrationDate' => (int)$User->getAttribute('regdate'),
            'lastVisit' => (int)$User->getAttribute('lastvisit')
        ];
    }

    /** @return array<string, mixed> */
    protected static function profile(ServerRequestInterface $Request, bool $create = false): array
    {
        $values = Input::body($Request, self::PROFILE_FIELDS, $create ? ['username'] : []);

        if ($values === []) {
            throw new ApiException('invalid_input', 'At least one profile field is required.');
        }

        if (isset($values['username'])) {
            if (!is_string($values['username']) || trim($values['username']) === '' || mb_strlen($values['username']) > 50) {
                throw new ApiException('invalid_input', 'username must contain between 1 and 50 characters.');
            }

            $values['username'] = trim($values['username']);

            try {
                QUI\Users\Manager::checkUsernameSigns($values['username']);
            } catch (QUI\Users\Exception) {
                throw new ApiException('invalid_input', 'username contains unsupported characters.');
            }
        }

        if (isset($values['email']) && $values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('invalid_input', 'email must be a valid email address.');
        }

        $mapping = ['firstName' => 'firstname', 'lastName' => 'lastname', 'title' => 'usertitle', 'language' => 'lang'];

        foreach ($mapping as $external => $internal) {
            if (array_key_exists($external, $values)) {
                $values[$internal] = $values[$external];
                unset($values[$external]);
            }
        }

        return $values;
    }
}
