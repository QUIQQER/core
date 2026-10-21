<?php

/**
 * This file contains the \QUI\MCP\Users\AbstractUserTool
 */

namespace QUI\MCP\Users;

use QUI;
use QUI\AI\MCP\Server;
use QUI\Groups\Group;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\MCP\AbstractTool;
use QUI\Users\Address;
use QUI\Users\User;

abstract class AbstractUserTool extends AbstractTool
{
    protected const USERS_MCP_PERMISSION = 'quiqqer.core.mcp.users.canUse';

    protected const UPDATE_ATTRIBUTES = [
        'username',
        'email',
        'firstname',
        'lastname',
        'usertitle',
        'company',
        'birthday',
        'lang',
        'avatar'
    ];

    protected const ADDRESS_ATTRIBUTES = [
        'title',
        'salutation',
        'firstname',
        'lastname',
        'company',
        'delivery',
        'street_no',
        'zip',
        'city',
        'state',
        'country',
        'suffix'
    ];

    protected static function checkUserPermission(string $permission): void
    {
        self::checkCorePermission();
        self::checkPermission(self::USERS_MCP_PERMISSION);
        self::checkPermission($permission);
    }

    protected static function getUser(int | string $userId): User
    {
        $User = QUI::getUsers()->get($userId);

        if (!$User instanceof User) {
            throw new QUI\Exception('The selected account is not a manageable QUIQQER user.', 400);
        }

        return $User;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseUser(UserInterface $User): array
    {
        return [
            'id' => $User->getId() ?: null,
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
            'active' => $User->isActive(),
            'registrationDate' => (int)$User->getAttribute('regdate'),
            'lastVisit' => (int)$User->getAttribute('lastvisit'),
            'groups' => array_map(
                static fn(Group $Group): array => [
                    'uuid' => $Group->getUUID(),
                    'name' => $Group->getName()
                ],
                $User->getGroups()
            )
        ];
    }

    /**
     * @return array{users: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    protected static function findUsers(?string $query, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = [
            'limit' => $limit,
            'start' => $offset,
            'field' => 'username',
            'order' => 'ASC'
        ];
        $Users = QUI::getUsers();

        if ($query !== null && trim($query) !== '') {
            $params['search'] = true;
            $params['searchSettings'] = [
                'userSearchString' => trim($query),
                'fields' => [
                    'uuid' => 1,
                    'email' => 1,
                    'username' => 1,
                    'firstname' => 1,
                    'lastname' => 1
                ]
            ];
        }

        $userRows = $Users->search($params);
        $result = [];

        if (is_array($userRows)) {
            foreach ($userRows as $userRow) {
                if (!isset($userRow['uuid'])) {
                    continue;
                }

                try {
                    $result[] = self::parseUser($Users->get((string)$userRow['uuid']));
                } catch (QUI\Exception) {
                }
            }
        }

        return [
            'users' => $result,
            'total' => $Users->count($params),
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @return array{attributes: array<string, mixed>, ignored: array<int, string>}
     */
    protected static function filterUserAttributes(array $attributes): array
    {
        $valid = [];
        $ignored = [];

        foreach ($attributes as $attribute => $value) {
            if (
                !is_string($attribute)
                || !in_array($attribute, self::UPDATE_ATTRIBUTES, true)
                || (!is_scalar($value) && $value !== null)
            ) {
                $ignored[] = is_string($attribute) ? $attribute : (string)$attribute;
                continue;
            }

            $valid[$attribute] = $value;
        }

        return [
            'attributes' => $valid,
            'ignored' => $ignored
        ];
    }

    protected static function saveUser(User $User): void
    {
        $User->save(Server::getRequestUser());
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseAddress(User $User, Address $Address): array
    {
        $attributes = [];

        foreach (self::ADDRESS_ATTRIBUTES as $attribute) {
            $attributes[$attribute] = $Address->getAttribute($attribute);
        }

        $default = false;

        try {
            $DefaultAddress = $User->getStandardAddress();
            $default = $DefaultAddress->getUUID() === $Address->getUUID();
        } catch (QUI\Exception) {
        }

        return [
            'id' => $Address->getId(),
            'uuid' => $Address->getUUID(),
            'userUuid' => $User->getUUID(),
            'default' => $default,
            'attributes' => $attributes,
            'mails' => array_values($Address->getMailList()),
            'phones' => array_values($Address->getPhoneList()),
            'customData' => $Address->getCustomData(),
            'text' => $Address->getText()
        ];
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @return array{attributes: array<string, float|bool|int|string|null>, ignored: array<int, string>}
     */
    protected static function filterAddressAttributes(array $attributes): array
    {
        $valid = [];
        $ignored = [];

        foreach ($attributes as $attribute => $value) {
            if (
                !is_string($attribute)
                || !in_array($attribute, self::ADDRESS_ATTRIBUTES, true)
                || (!is_scalar($value) && $value !== null)
            ) {
                $ignored[] = is_string($attribute) ? $attribute : (string)$attribute;
                continue;
            }

            $valid[$attribute] = $value;
        }

        return [
            'attributes' => $valid,
            'ignored' => $ignored
        ];
    }

    /**
     * @param array<string, float|bool|int|string|null> $attributes
     * @param array<array-key, mixed>|null $mails
     * @param array<array-key, mixed>|null $phones
     */
    protected static function updateAddressData(
        Address $Address,
        array $attributes,
        ?array $mails,
        ?array $phones
    ): void {
        foreach ($attributes as $attribute => $value) {
            if ($attribute === 'suffix') {
                $Address->setAddressSuffix((string)$value);
                continue;
            }

            $Address->setAttribute($attribute, $value ?? '');
        }

        if ($mails !== null) {
            $Address->clearMail();

            foreach (self::normalizeAddressMails($mails) as $mail) {
                $Address->addMail($mail);
            }
        }

        if ($phones !== null) {
            $Address->clearPhone();

            foreach (self::normalizeAddressPhones($phones) as $phone) {
                $Address->addPhone($phone);
            }
        }

        $Address->save(Server::getRequestUser());
    }

    /**
     * @param array<array-key, mixed> $mails
     * @return array<int, string>
     */
    protected static function normalizeAddressMails(array $mails): array
    {
        $result = [];

        foreach ($mails as $mail) {
            if (!is_string($mail) || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                throw new QUI\Exception('Every address mail entry must be a valid e-mail address.');
            }

            $result[$mail] = $mail;
        }

        return array_values($result);
    }

    /**
     * @param array<array-key, mixed> $phones
     * @return array<int, array{type: string, no: string}>
     */
    protected static function normalizeAddressPhones(array $phones): array
    {
        $result = [];

        foreach ($phones as $phone) {
            if (!is_array($phone)) {
                throw new QUI\Exception('Every phone entry must be an object.');
            }

            $type = $phone['type'] ?? null;
            $number = $phone['no'] ?? null;

            if (!is_string($type) || !in_array($type, ['tel', 'mobile', 'fax'], true)) {
                throw new QUI\Exception('Every phone entry requires type tel, mobile or fax.');
            }

            if (!is_string($number) || trim($number) === '') {
                throw new QUI\Exception('Every phone entry requires a non-empty number.');
            }

            $key = $type . ':' . trim($number);
            $result[$key] = [
                'type' => $type,
                'no' => trim($number)
            ];
        }

        return array_values($result);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getAddressAttributesSchema(): array
    {
        $properties = [];

        foreach (self::ADDRESS_ATTRIBUTES as $attribute) {
            $properties[$attribute] = ['type' => ['string', 'integer', 'boolean', 'null']];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getAddressMailsSchema(): array
    {
        return [
            'type' => ['array', 'null'],
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'format' => 'email']
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getAddressPhonesSchema(): array
    {
        return [
            'type' => ['array', 'null'],
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['type', 'no'],
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['tel', 'mobile', 'fax']],
                    'no' => ['type' => 'string', 'minLength' => 1]
                ]
            ]
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    protected static function parseWebAuthnCredential(array $credential): array
    {
        return [
            'id' => (int)($credential['id'] ?? 0),
            'name' => (string)($credential['name'] ?? ''),
            'aaguid' => isset($credential['aaguid']) ? (string)$credential['aaguid'] : null,
            'transports' => is_array($credential['transports'] ?? null)
                ? array_values($credential['transports'])
                : [],
            'backupEligible' => (bool)($credential['backupEligible'] ?? false),
            'backedUp' => (bool)($credential['backedUp'] ?? false),
            'created' => (int)($credential['created'] ?? 0),
            'lastUsed' => isset($credential['lastUsed']) ? (int)$credential['lastUsed'] : null
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getUserIdSchema(): array
    {
        return [
            'description' => 'User UUID or legacy numeric ID.',
            'oneOf' => [
                ['type' => 'string', 'minLength' => 1],
                ['type' => 'integer', 'minimum' => 1]
            ]
        ];
    }
}
