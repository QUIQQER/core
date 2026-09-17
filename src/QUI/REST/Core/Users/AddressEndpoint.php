<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\Users\Address;
use QUI\Users\User;

abstract class AddressEndpoint extends UserEndpoint
{
    protected const ADDRESS_FIELDS = [
        'title' => 'string', 'salutation' => 'string', 'firstName' => 'string', 'lastName' => 'string',
        'company' => 'string', 'delivery' => 'string', 'streetNo' => 'string', 'zip' => 'string',
        'city' => 'string', 'country' => 'string', 'suffix' => 'string', 'mails' => 'array', 'phones' => 'array'
    ];
    private const ATTRIBUTE_MAP = ['firstName' => 'firstname', 'lastName' => 'lastname', 'streetNo' => 'street_no'];

    protected static function address(User $User, int|string $id): Address
    {
        try {
            return $User->getAddress($id);
        } catch (QUI\Users\Exception) {
            throw new ApiException('not_found', 'The requested address does not belong to this user.', 404);
        }
    }

    /** @return array<string, mixed> */
    protected static function addressRepresentation(User $User, Address $Address): array
    {
        $result = [
            'id' => $Address->getId(),
            'uuid' => $Address->getUUID(),
            'userUuid' => $User->getUUID(),
            'default' => $User->getStandardAddress()->getUUID() === $Address->getUUID()
        ];

        foreach (self::ADDRESS_FIELDS as $field => $type) {
            if ($type === 'string') {
                $result[$field] = $Address->getAttribute(self::ATTRIBUTE_MAP[$field] ?? $field);
            }
        }

        $result['mails'] = array_values($Address->getMailList());
        $result['phones'] = array_values($Address->getPhoneList());
        return $result;
    }

    /** @return array<string, mixed> */
    protected static function addressInput(ServerRequestInterface $Request): array
    {
        $body = Input::body($Request, self::ADDRESS_FIELDS);

        if ($body === []) {
            throw new ApiException('invalid_input', 'At least one address field is required.');
        }

        if (isset($body['mails'])) {
            foreach ($body['mails'] as $mail) {
                if (!is_string($mail) || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ApiException('invalid_input', 'Every mail entry must be a valid email address.');
                }
            }
        }

        if (isset($body['phones'])) {
            foreach ($body['phones'] as $phone) {
                if (
                    !$phone instanceof \stdClass || count(get_object_vars($phone)) !== 2
                    || !isset($phone->type, $phone->no) || !in_array($phone->type, ['tel', 'mobile', 'fax'], true)
                    || !is_string($phone->no) || trim($phone->no) === ''
                ) {
                    throw new ApiException('invalid_input', 'Every phone requires type (tel, mobile or fax) and no.');
                }
            }
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    protected static function updateAddress(Address $Address, array $body, Actor $Actor): void
    {
        foreach ($body as $field => $value) {
            if ($field === 'mails') {
                $Address->clearMail();

                foreach ($value as $mail) {
                    $Address->addMail($mail);
                }
            } elseif ($field === 'phones') {
                $Address->clearPhone();

                foreach ($value as $phone) {
                    $Address->addPhone(['type' => $phone->type, 'no' => $phone->no]);
                }
            } elseif ($field === 'suffix') {
                $Address->setAddressSuffix($value);
            } else {
                $Address->setAttribute(self::ATTRIBUTE_MAP[$field] ?? $field, $value);
            }
        }

        $Address->save($Actor);
    }
}
