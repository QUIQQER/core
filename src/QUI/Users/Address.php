<?php

/**
 * This file contains \QUI\Users\Address
 */

namespace QUI\Users;

use QUI;
use QUI\Interfaces\Users\User as QUIUserInterface;
use QUI\Utils\Security\Orthos as Orthos;

use function array_key_exists;
use function count;
use function date;
use function file_exists;
use function implode;
use function in_array;
use function is_array;
use function is_null;
use function is_numeric;
use function json_decode;
use function json_encode;
use function method_exists;
use function preg_replace;
use function reset;
use function trim;

/**
 * User Address
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * available options
 * - template -> set an own address template
 */
class Address extends QUI\QDOM
{
    protected null | int | string $id = null;

    protected ?string $uuid = null;

    /**
     * @var array<string, float|bool|int|string>
     */
    protected array $customData = [];

    protected QUIUserInterface $User;

    /**
     * @throws Exception|QUI\ExceptionStack
     */
    public function __construct(QUIUserInterface $User, int | string $id)
    {
        $this->User = $User;

        try {
            $where = [
                'userUuid' => $User->getUUID()
            ];

            if (is_numeric($id)) {
                $where['id'] = (int)$id;
            } else {
                $where['uuid'] = $id;
            }

            $QueryBuilder = QUI::getQueryBuilder();
            $QueryBuilder
                ->select('*')
                ->from(Manager::tableAddress())
                ->where($QueryBuilder->expr()->eq('userUuid', ':userUuid'))
                ->setParameter('userUuid', $where['userUuid'])
                ->setMaxResults(1);

            if (isset($where['id'])) {
                $QueryBuilder
                    ->andWhere($QueryBuilder->expr()->eq('id', ':id'))
                    ->setParameter('id', $where['id']);
            } else {
                $QueryBuilder
                    ->andWhere($QueryBuilder->expr()->eq('uuid', ':uuid'))
                    ->setParameter('uuid', $where['uuid']);
            }

            $result = $QueryBuilder->executeQuery()->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            QUI\System\Log::addWarning($Exception->getMessage());

            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.not.found',
                    [
                        'addressId' => $id,
                        'userId' => $User->getUUID()
                    ]
                ),
                404
            );
        }

        if (is_numeric($id)) {
            $this->id = $id;
        } else {
            $this->uuid = $id;
        }

        if (!$result) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.not.found',
                    [
                        'addressId' => $id,
                        'userId' => $User->getUUID()
                    ]
                )
            );
        }

        $data = $result;
        $this->uuid = $data['uuid'];
        $this->id = (int)$data['id'];

        unset($data['id']);
        unset($data['uid']);

        QUI::getEvents()->fireEvent('userAddressInitData', [$this, &$data]);

        if (!empty($data['custom_data'])) {
            $this->setCustomData(json_decode($data['custom_data'], true));
        }

        $this->setAttributes($data);
    }

    /**
     * @deprecated
     */
    public function getId(): int
    {
        if ($this->id === null) {
            return -1;
        }

        return (int)$this->id;
    }

    public function getUUID(): ?string
    {
        return $this->uuid;
    }

    /**
     * Add a phone number
     *
     *
     * @example addPhone([
     *     'no'   => '555 29 29',
     *     'type' => 'tel'
     * ]);
     *
     * @param array<string, mixed> $phone
     */
    public function addPhone(array $phone): void
    {
        if (!isset($phone['no'])) {
            return;
        }

        if (!isset($phone['type'])) {
            return;
        }

        if (
            $phone['type'] != 'tel'
            && $phone['type'] != 'fax'
            && $phone['type'] != 'mobile'
        ) {
            return;
        }

        $list = $this->getPhoneList();

        foreach ($list as $entry) {
            if ($entry['type'] != $phone['type']) {
                continue;
            }

            if ($entry['no'] != $phone['no']) {
                continue;
            }

            return;
        }

        $list[] = $phone;

        $this->setAttribute('phone', json_encode($list));
    }

    //region attributes

    /**
     * Return the complete phone list
     *
     * @return array<array-key, mixed>
     */
    public function getPhoneList(): array
    {
        if (is_array($this->getAttribute('phone'))) {
            return $this->getAttribute('phone');
        }

        $result = json_decode($this->getAttribute('phone'), true);

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    public function getAttribute(string $name): mixed
    {
        if ($name === 'suffix') {
            return $this->getAddressSuffix();
        }

        return parent::getAttribute($name);
    }

    //endregion

    /**
     * Return the address suffix to the address
     *
     * @return mixed|null
     */
    public function getAddressSuffix(): mixed
    {
        if (!empty($this->attributes['suffix'])) {
            return $this->attributes['suffix'];
        }

        return $this->getCustomDataEntry('address-suffix');
    }

    public function getCustomDataEntry(string $key): mixed
    {
        if (array_key_exists($key, $this->customData)) {
            return $this->customData[$key];
        }

        return null;
    }

    /**
     * Edit an existing entry
     *
     * @param integer $index
     * @param array<string, mixed>|string $phone - [no => '+0049 929292', 'type' => 'fax'] or '+0049 929292'
     */
    public function editPhone(int $index, array | string $phone): void
    {
        if (!is_array($phone)) {
            $phone = [
                'no' => Orthos::clear($phone),
                'type' => 'tel'
            ];
        }

        if (!isset($phone['no'])) {
            return;
        }

        if (!isset($phone['type'])) {
            return;
        }

        $list = $this->getPhoneList();

        $list[$index] = [
            'no' => Orthos::clear($phone['no']),
            'type' => Orthos::clear($phone['type'])
        ];

        $this->setAttribute('phone', json_encode($list));
    }

    /**
     * @param string $number
     */
    public function editMobile($number): void
    {
        $list = $this->getPhoneList();
        $edited = false;

        foreach ($list as $key => $entry) {
            if ($entry['type'] !== 'mobile') {
                continue;
            }

            $list[$key]['no'] = $number;
            $edited = true;
        }

        if ($edited === false) {
            $list[] = [
                'type' => 'mobile',
                'no' => $number
            ];
        }

        $this->setAttribute('phone', json_encode($list));
    }

    /**
     * @param string $number
     */
    public function editFax($number): void
    {
        $list = $this->getPhoneList();
        $edited = false;

        foreach ($list as $key => $entry) {
            if ($entry['type'] !== 'fax') {
                continue;
            }

            $list[$key]['no'] = $number;
            $edited = true;
        }

        if ($edited === false) {
            $list[] = [
                'type' => 'fax',
                'no' => $number
            ];
        }

        $this->setAttribute('phone', json_encode($list));
    }

    /**
     * Delete the complete phone list
     */
    public function clearPhone(): void
    {
        $this->setAttribute('phone', []);
    }

    /**
     * Return the first telephone number
     */
    public function getPhone(): string
    {
        $list = $this->getPhoneList();

        if (empty($list)) {
            return '';
        }

        foreach ($list as $entry) {
            if ($entry['type'] !== 'tel') {
                continue;
            }

            return $entry['no'];
        }

        return '';
    }

    /**
     * Return the first telephone number
     */
    public function getMobile(): string
    {
        $list = $this->getPhoneList();

        if (empty($list)) {
            return '';
        }

        foreach ($list as $entry) {
            if ($entry['type'] !== 'mobile') {
                continue;
            }

            return $entry['no'];
        }

        return '';
    }

    /**
     * Return the first fax number
     */
    public function getFax(): string
    {
        $list = $this->getPhoneList();

        if (empty($list)) {
            return '';
        }

        foreach ($list as $entry) {
            if ($entry['type'] !== 'fax') {
                continue;
            }

            return $entry['no'];
        }

        return '';
    }

    /**
     * Add an Email address
     *
     * @param string $mail - new mail address
     *
     * @throws Exception
     */
    public function addMail(string $mail): void
    {
        if (!Orthos::checkMailSyntax($mail)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.mail.wrong.syntax'
                )
            );
        }

        $list = $this->getMailList();

        if (in_array($mail, $list)) {
            return;
        }

        $list[] = $mail;

        $this->setAttribute('mail', json_encode($list));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getMailList(): array
    {
        if (is_array($this->getAttribute('mail'))) {
            return $this->getAttribute('mail');
        }

        $result = json_decode($this->getAttribute('mail'), true);

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    /**
     * Clear mail addresses
     */
    public function clearMail(): void
    {
        $this->setAttribute('mail', json_encode([]));
    }

    /**
     * Edit an Email Entry
     *
     * @param integer $index - index of the mail
     * @param string $mail - E-Mail (eq: my@mail.com)
     *
     * @throws Exception
     */
    public function editMail(int $index, string $mail): void
    {
        if (!Orthos::checkMailSyntax($mail)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.mail.wrong.syntax'
                )
            );
        }

        $list = $this->getMailList();
        $list[$index] = $mail;

        $this->setAttribute('mail', json_encode($list));
    }

    /**
     * @throws Exception
     */
    public function getCountry(): QUI\Countries\Country
    {
        $country = $this->getAttribute('country');

        if (!is_string($country) || trim($country) === '') {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.user.address.no.country'
                )
            );
        }

        try {
            return QUI\Countries\Manager::get(
                $country
            );
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        throw new Exception(
            QUI::getLocale()->get(
                'quiqqer/core',
                'exception.lib.user.address.no.country'
            )
        );
    }

    /**
     * @throws QUI\Permissions\Exception|QUI\ExceptionStack
     */
    public function save(?QUIUserInterface $PermissionUser = null): void
    {
        if (is_null($PermissionUser)) {
            $PermissionUser = QUI::getUserBySession();
        }


        $User = $this->getUser();

        if (method_exists($User, 'checkEditPermission')) {
            $User->checkEditPermission($PermissionUser);
        }

        try {
            QUI::getEvents()->fireEvent('userAddressSaveBegin', [$this, $this->getUser()]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        $mail = json_encode($this->getMailList());
        $phone = json_encode($this->getPhoneList());

        $cleanupAttributes = static function ($str) {
            $str = Orthos::removeHTML($str);
            $str = Orthos::clearFormRequest($str);
            return Orthos::clearPath($str);
        };

        $dbData = [
            'title' => $cleanupAttributes($this->getAttribute('title')),
            'salutation' => $cleanupAttributes($this->getAttribute('salutation')),
            'firstname' => $cleanupAttributes($this->getAttribute('firstname')),
            'lastname' => $cleanupAttributes($this->getAttribute('lastname')),
            'company' => $cleanupAttributes($this->getAttribute('company')),
            'delivery' => $cleanupAttributes($this->getAttribute('delivery')),
            'street_no' => $cleanupAttributes($this->getAttribute('street_no')),
            'zip' => $cleanupAttributes($this->getAttribute('zip')),
            'city' => $cleanupAttributes($this->getAttribute('city')),
            'state' => $cleanupAttributes($this->getAttribute('state')),
            'country' => $cleanupAttributes($this->getAttribute('country')),
            'mail' => $mail,
            'phone' => $phone,
            'custom_data' => json_encode($this->getCustomData()),
            'e_date' => date('Y-m-d H:i:s')
        ];

        QUI::getEvents()->fireEvent('userAddressSaveBefore', [$this, $this->getUser(), &$dbData]);

        try {
            QUI::getDataBaseConnection()->update(
                Manager::tableAddress(),
                $dbData,
                ['uuid' => $this->getUUID()]
            );
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
            QUI\System\Log::writeDebugException($Exception);
        }

        $StandardAddress = $User->getStandardAddress();

        if (
            $StandardAddress !== null
            && $StandardAddress->getUUID() === $this->getUUID()
        ) {
            $mailList = $this->getMailList();

            if (count($mailList)) {
                $email = reset($mailList);
                $User->setAttribute('email', $cleanupAttributes($email));
            }

            $User->setAttribute('firstname', $cleanupAttributes($this->getAttribute('firstname')));
            $User->setAttribute('lastname', $cleanupAttributes($this->getAttribute('lastname')));
        }

        try {
            QUI::getEvents()->fireEvent('userAddressSave', [$this, $this->getUser()]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    public function getUser(): QUIUserInterface
    {
        return $this->User;
    }

    /**
     * @return array<string, float|bool|int|string>
     */
    public function getCustomData(): array
    {
        return $this->customData;
    }

    /**
     * @param array<string, float|bool|int|string>|null $entries
     */
    public function setCustomData(null | array $entries): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $k => $v) {
            $this->setCustomDataEntry($k, $v);
        }
    }

    /**
     * Delete the address
     *
     * @throws QUI\Exception
     */
    public function delete(): void
    {
        try {
            QUI::getDataBaseConnection()->delete(
                Manager::tableAddress(),
                [
                    'uid' => $this->User->getId(),
                    'id' => $this->getId()
                ]
            );
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw new QUI\Exception(
                $Exception->getMessage(),
                (int)$Exception->getCode()
            );
        }
    }

    /**
     * Alias for getDisplay
     *
     * @param array<string, mixed> $options
     */
    public function render(array $options = []): string
    {
        return $this->getDisplay($options);
    }

    /**
     * Return the address as HTML display
     *
     * @param array<string, mixed> $options - options ['mail' => true, 'tel' => true]
     * @return string - HTML <address>
     */
    public function getDisplay(array $options = []): string
    {
        $Engine = QUI::getTemplateManager()->getEngine(true);

        // defaults
        if (!isset($options['tel'])) {
            $options['tel'] = true;
        }

        if (!isset($options['mail'])) {
            $options['mail'] = true;
        }


        $Engine->assign([
            'User' => $this->User,
            'Address' => $this,
            'Countries' => new QUI\Countries\Manager(),
            'options' => $options
        ]);

        $template = $this->getAttribute('template');

        if (file_exists($template)) {
            return $Engine->fetch($template);
        }

        return $Engine->fetch(SYS_DIR . 'template/users/address/display.html');
    }

    public function getText(): string
    {
        $salutation = $this->getAttribute('salutation');
        $firstName = $this->getAttribute('firstname');
        $lastName = $this->getAttribute('lastname');

        $street_no = $this->getAttribute('street_no');
        $zip = $this->getAttribute('zip');
        $city = $this->getAttribute('city');
        $state = $this->getAttribute('state');
        $country = $this->getAttribute('country');

        // build parts
        $part = [0 => [], 1 => [], 2 => []];

        if (!empty($salutation)) {
            $part[0][] = $salutation;
        }

        if (!empty($firstName)) {
            $part[0][] = $firstName;
        }

        if (!empty($lastName)) {
            $part[0][] = $lastName;
        }

        if (!empty($street_no)) {
            $part[1][] = $street_no;
        }

        if (!empty($zip)) {
            $part[2][] = $zip;
        }

        if (!empty($city)) {
            $part[2][] = $city;
        }

        if (!empty($state)) {
            $part[2][] = $state;
        }

        if (!empty($country)) {
            $part[2][] = $country;
        }

        // build parts
        $part[0] = trim(implode(' ', $part[0]));
        $part[1] = trim(implode(' ', $part[1]));
        $part[2] = trim(implode(' ', $part[2]));

        if (empty($part[2])) {
            unset($part[2]);
        }

        if (empty($part[1])) {
            unset($part[1]);
        }

        if (empty($part[0])) {
            unset($part[0]);
        }

        $address = implode('; ', $part);
        $company = $this->getAttribute('company');

        if (!empty($company)) {
            return $company . '; ' . $address;
        }

        return $address;
    }

    /**
     * Return the main name of the address
     */
    public function getName(): string
    {
        $User = $this->User;

        $salutation = $this->getAttribute('salutation');
        $firstName = $this->getAttribute('firstname');
        $lastName = $this->getAttribute('lastname');

        if (empty($firstName)) {
            $firstName = $User->getAttribute('firstname');
        }

        if (!$firstName) {
            $firstName = '';
        }

        if (empty($lastName)) {
            $lastName = $User->getAttribute('lastname');
        }

        if (!$lastName) {
            $lastName = '';
        }

        if (!$salutation) {
            $salutation = '';
        }

        $result = "$salutation $firstName $lastName";
        $result = preg_replace('/[  ]{2,}/', ' ', $result) ?? $result;

        return trim($result);
    }

    public function toJSON(): string
    {
        $attributes = $this->getAttributes();
        $attributes['id'] = $this->getId();
        $attributes['uuid'] = $this->getUUID();

        return (string)json_encode($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $attributes = parent::getAttributes();
        $attributes['suffix'] = $this->getAddressSuffix();
        $attributes['uuid'] = $this->getUUID();

        return $attributes;
    }

    /**
     * Check if this address equals another address
     *
     * @param Address $Address
     * @param bool $compareCustomData (optional) - Consider custom data on comparison [default: false]
     * @return bool
     */
    public function equals(Address $Address, bool $compareCustomData = false): bool
    {
        if (
            $this->getUUID()
            && $Address->getUUID()
            && $this->getUUID() === $Address->getUUID()
        ) {
            return true;
        }

        $dataThis = $this->getAttributes();
        $dataOther = $Address->getAttributes();

        // always ignore internal custom_data attribute
        if (array_key_exists('custom_data', $dataThis)) {
            unset($dataThis['custom_data']);
        }

        if (array_key_exists('custom_data', $dataOther)) {
            unset($dataOther['custom_data']);
        }

        // consider actual custom data
        if (!$compareCustomData) {
            if (array_key_exists('customData', $dataThis)) {
                unset($dataThis['customData']);
            }

            if (array_key_exists('customData', $dataOther)) {
                unset($dataOther['customData']);
            }
        }

        // ignore id
        unset($dataThis['id']);
        unset($dataOther['id']);

        // ignore empty fields
        foreach ($dataThis as $k => $v) {
            if (empty($v)) {
                unset($dataThis[$k]);
            }
        }

        foreach ($dataOther as $k => $v) {
            if (empty($v)) {
                unset($dataOther[$k]);
            }
        }

        return $dataThis == $dataOther;
    }

    //region address suffix - Address suffix

    /**
     * Add an address suffix to the address
     */
    public function setAddressSuffix(string $suffix): void
    {
        $this->setCustomDataEntry('address-suffix', $suffix);
    }

    /**
     * Set custom data entry
     */
    public function setCustomDataEntry(string $key, float | bool | int | string $value): void
    {
        $this->customData[$key] = $value;
        $this->setAttribute('customData', $this->customData);
    }

    //endregion
}
