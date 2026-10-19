<?php

/**
 * This file contains the QUI\Projects\Site\Edit
 */

namespace QUI\Projects\Site;

use Exception;
use QUI;
use QUI\ExceptionStack;
use QUI\Groups\Group;
use QUI\Lock\Locker;
use QUI\Permissions\Permission;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Users\User;
use QUI\Utils\Security\Orthos;

use function array_merge;
use function date;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function str_replace;
use function str_contains;
use function strtotime;
use function time;
use function trim;

/**
 * Site Objekt für den Adminbereich
 * Stellt Methoden für das Bearbeiten von einer Seite zur Verfügung
 *
 * @author     www.pcsg.de (Henning Leutz)
 * @licence    For copyright and license information, please view the /README.md
 *
 * @errorcodes 7XX = Site Errors
 * <ul>
 * <li>700 - Error Site: Bad Request; Aufruf ist falsch</li>
 * <li>701 - Error Site Name: 2 signs or lower</li>
 * <li>702 - Error Site Name: Not supported signs in Name</li>
 * <li>703 - Error Site Name: Duplicate Entry in Parent; Child width the same Name exist</li>
 * <li>704 - Error Site Name: 200 signs or higher</li>
 * <li>705 - Error Site  : Site not found</li>
 * </ul>
 *
 * @todo       Sortierung als eigene Methoden
 * @todo       Rechte Prüfung
 * @todo       translation der quelltext doku
 *
 * @qui-event  onSiteActivate [ \QUI\Projects\Site\Edit ]
 * @qui-event  onSiteDeactivate [ \QUI\Projects\Site\Edit ]
 * @qui-event  onSiteSave [ \QUI\Projects\Site\Edit ]
 *
 * @event      onSiteCreateChild [ integer $newId, \QUI\Projects\Site\Edit ]
 * @event      onActivate [ \QUI\Projects\Site\Edit ]
 * @event      onDeactivate [ \QUI\Projects\Site\Edit ]
 * @event      onSave [ \QUI\Projects\Site\Edit ]
 */
class Edit extends Site
{
    /**
     *
     */
    const ESAVE = 3;

    /**
     *
     */
    const EACCES = 4;

    /**
     * Project conf <<------ ??? why here
     *
     * @var array<string, mixed>
     */
    public array $conf = [];

    /**
     * @throws QUI\Exception
     */
    public function __construct(Project $Project, int $id)
    {
        $this->id = $id;
        $this->Project = $Project;
        $this->Events = new QUI\Events\Event();

        if (empty($this->id)) {
            throw new QUI\Exception('Site Error; No ID given:' . $id, 700);
        }

        $this->TABLE = $Project->table();
        $this->RELTABLE = $Project->table() . '_relations';
        $this->RELLANGTABLE = QUI::getDBTableName($Project->getName() . '_multilingual');

        $this->checkPermission('quiqqer.projects.site.view');

        $this->refresh();

        QUI\Utils\System\File::mkdir(VAR_DIR . 'admin/');
        QUI\Utils\System\File::mkdir(VAR_DIR . 'lock/');

        $this->load();

        // onInit event
        $this->Events->fireEvent('init', [$this]);
        QUI::getEvents()->fireEvent('siteInit', [$this]);
    }

    /**
     * Deleted sites are read-only until they are restored.
     *
     * @throws QUI\Exception
     */
    protected function assertNotDeleted(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $deleted = $Connection->createQueryBuilder()
            ->select($Platform->quoteSingleIdentifier('deleted'))
            ->from($Platform->quoteSingleIdentifier($this->TABLE))
            ->where($Platform->quoteSingleIdentifier('id') . ' = :siteId')
            ->setParameter('siteId', $this->getId())
            ->executeQuery()
            ->fetchOne();

        if ((int)$deleted !== 1) {
            return;
        }

        throw new QUI\Exception(
            QUI::getLocale()->get('quiqqer/core', 'exception.site.deleted.cannot.edit'),
            706
        );
    }

    /**
     * Fetch the data from the database
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     */
    public function refresh(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $params = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Platform->quoteSingleIdentifier($this->TABLE))
            ->where($Platform->quoteSingleIdentifier("id") . " = :id")
            ->setParameter("id", $this->getId())
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        // Verknüpfung hohlen
        if ($this->getId() != 1) {
            $relresult = $Connection->createQueryBuilder()
                ->select("*")
                ->from($Platform->quoteSingleIdentifier($this->RELTABLE))
                ->where($Platform->quoteSingleIdentifier("child") . " = :child")
                ->setParameter("child", $this->getId())
                ->executeQuery()
                ->fetchAllAssociative();

            if (isset($relresult[0])) {
                foreach ($relresult as $entry) {
                    if (!isset($entry["oparent"])) {
                        continue;
                    }

                    $this->LINKED_PARENT = $entry["oparent"];
                }
            }
        }

        if ($params === false) {
            throw new QUI\Exception(
                QUI::getLocale()->get("quiqqer/core", "exception.site.not.found"),
                705,
                [
                    "siteId" => $this->getId(),
                    "project" => $this->getProject()->getName(),
                    "lang" => $this->getProject()->getLang()
                ]
            );
        }

        foreach ($params as $a_key => $a_val) {
            // Extra-Feld behandeln
            if ($a_key == "extra") {
                if (empty($a_val)) {
                    continue;
                }

                //  get extra attribute list

                $extra = json_decode($a_val, true);

                foreach ($extra as $key => $value) {
                    $this->setAttribute($key, $value);
                }

                continue;
            }

            // integer values
            switch ($a_key) {
                case "active":
                case "deleted":
                case "id":
                case "nav_hide":
                    $a_val = (int)$a_val;
                    break;
            }

            $this->setAttribute($a_key, $a_val);
        }
    }

    /**
     * Säubert eine URL macht sie schön
     *
     * @param string $url
     * @param Project $Project - Project clear extension
     *
     * @return string
     * @deprecated use QUI\Projects\Site\Utils::clearUrl
     */
    public static function clearUrl(string $url, Project $Project): string
    {
        return QUI\Projects\Site\Utils::clearUrl($url, $Project);
    }

    /**
     * Activate a site
     *
     * @param QUI\Interfaces\Users\User|null $User - [optional] User to save
     *
     * @throws QUI\Exception
     */
    public function activate(null | QUI\Interfaces\Users\User $User = null): void
    {
        try {
            $this->checkPermission('quiqqer.projects.site.edit', $User);
        } catch (QUI\Exception) {
            throw new QUI\Exception(
                QUI::getLocale()
                    ->get('quiqqer/core', 'exception.permissions.edit')
            );
        }

        $this->assertNotDeleted();

        if (!$User) {
            $User = QUI::getUserBySession();
        }

        $this->Events->fireEvent('checkActivate', [$this]);
        QUI::getEvents()->fireEvent('siteCheckActivate', [$this]);


        // check release date
        $this->checkReleaseDate();

        $releaseFrom = $this->getAttribute('release_from');

        if (!Orthos::checkMySqlDatetimeSyntax($releaseFrom)) {
            $releaseFrom = date('Y-m-d H:i:s');
        }

        // save
        QUI::getDataBaseConnection()->update($this->TABLE, [
            'active' => 1,
            'release_from' => $releaseFrom,
            'e_user' => $User->getUUID()
        ], [
            'id' => $this->getId()
        ]);

        $this->setAttribute('active', 1);

        $this->Events->fireEvent('activate', [$this]);
        QUI::getEvents()->fireEvent('siteActivate', [$this]);

        $this->deleteCache();
        $this->getProject()->clearCache();
    }

    /**
     * Check if the release from date is in the future or the release until is in the past
     * throws exception if the site can't activate
     *
     * @throws QUI\Exception
     */
    protected function checkReleaseDate(): void
    {
        // check release date
        $release_from = $this->getAttribute('release_from');
        $release_to = $this->getAttribute('release_to');

        if ($release_from && $release_to !== '0000-00-00 00:00:00') {
            $release_from = strtotime($release_from);

            if ($release_from > time()) {
                throw new QUI\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.site.release.from.inFuture'
                    ),
                    1119
                );
            }
        }

        if (!$release_to || $release_to === '0000-00-00 00:00:00') {
            return;
        }

        $release_to = strtotime($release_to);

        if (!$release_to) {
            return;
        }

        if ($release_to >= time()) {
            return;
        }

        throw new QUI\Exception(
            QUI::getLocale()->get(
                'quiqqer/core',
                'exception.release.to.inPast'
            ),
            1120
        );
    }

    /**
     * Zerstört die Seite
     * Die Seite wird komplett aus der DB gelöscht und auch alle Beziehungen
     * Funktioniert nur wenn die Seite gelöscht ist
     *
     * @throws QUI\Exception
     */
    public function destroy(): void
    {
        if ($this->getAttribute('deleted') != 1) {
            return;
        }

        $this->checkPermission('quiqqer.projects.site.del');

        QUI::getRewrite()->unregisterPath($this);

        /**
         * package destroy
         */
        $dataList = Utils::getDataListForSite($this);

        foreach ($dataList as $dataEntry) {
            $table = $dataEntry['table'];

            QUI::getDataBaseConnection()->delete($table, [
                'id' => $this->getId()
            ]);
        }


        // on destroy event
        try {
            $this->Events->fireEvent('destroy', [$this]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        try {
            QUI::getEvents()->fireEvent('siteDestroy', [$this]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }


        /**
         * Site destroy
         */

        // Daten löschen
        QUI::getDataBaseConnection()->delete($this->TABLE, [
            'id' => $this->getId()
        ]);

        // sich als Kind löschen
        QUI::getDataBaseConnection()->delete($this->RELTABLE, [
            'child' => $this->getId()
        ]);

        // sich als parent löschen
        QUI::getDataBaseConnection()->delete($this->RELTABLE, [
            'parent' => $this->getId()
        ]);

        // Rechte löschen
        $Manager = QUI::getPermissionManager();
        $Manager->removeSitePermissions($this);

        // Cache löschen
        $this->deleteCache();
    }

    /**
     * @param integer $pid - Parent - ID
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>
     *
     * @throws QUI\Database\Exception
     * @see Site::getChildrenIdsFromParentId()
     *
     */
    public function getChildrenIdsFromParentId(int $pid, array $params = []): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $siteAlias = "site";
        $relationAlias = "rel";

        $QueryBuilder
            ->from($Platform->quoteSingleIdentifier($this->RELTABLE), $relationAlias)
            ->innerJoin(
                $relationAlias,
                $Platform->quoteSingleIdentifier($this->TABLE),
                $siteAlias,
                $relationAlias . "." . $Platform->quoteSingleIdentifier("child")
                    . " = " . $siteAlias . "." . $Platform->quoteSingleIdentifier("id")
            )
            ->where($relationAlias . "." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere($siteAlias . "." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->setParameter("parent", $pid)
            ->setParameter("deleted", 0);

        if (isset($params["where"]) && is_array($params["where"])) {
            $whereIndex = 0;

            foreach ($params["where"] as $field => $value) {
                if (is_array($value)) {
                    continue;
                }

                $fieldParts = explode(".", (string)$field, 2);
                $fieldName = $fieldParts[1] ?? $fieldParts[0];
                $alias = isset($fieldParts[1]) && $fieldParts[0] === $this->RELTABLE ? $relationAlias : $siteAlias;
                $paramName = "where" . $whereIndex;

                $QueryBuilder
                    ->andWhere($alias . "." . $Platform->quoteSingleIdentifier($fieldName) . " = :" . $paramName)
                    ->setParameter($paramName, $value);

                $whereIndex++;
            }
        } elseif (isset($params["where"]) && is_string($params["where"])) {
            QUI\System\Log::addDebug("WIRD NICHT verwendet" . $params["where"]);
        }

        $order = $this->TABLE . ".order_field";

        if (isset($params["order"])) {
            if (str_contains($params["order"], ".")) {
                $order = $this->TABLE . "." . $params["order"];
            } else {
                $order = $params["order"];
            }
        }

        if ($order === "manuell") {
            $order = "order_field";
        }

        $orderParts = explode(" ", $order, 2);
        $orderField = $orderParts[0];
        $orderDirection = isset($orderParts[1]) && $orderParts[1] === "DESC" ? "DESC" : "ASC";
        $orderFieldParts = explode(".", $orderField, 2);
        $orderFieldName = $orderFieldParts[1] ?? $orderFieldParts[0];
        $orderAlias = isset($orderFieldParts[1]) && $orderFieldParts[0] === $this->RELTABLE ? $relationAlias : $siteAlias;

        if (isset($params["count"])) {
            $QueryBuilder->select("COUNT(" . $siteAlias . "." . $Platform->quoteSingleIdentifier("id") . ") AS " . $Platform->quoteSingleIdentifier("count"));
        } else {
            $QueryBuilder
                ->select($siteAlias . "." . $Platform->quoteSingleIdentifier("id"))
                ->orderBy($orderAlias . "." . $Platform->quoteSingleIdentifier($orderFieldName), $orderDirection);

            QUI\Utils\Doctrine::applyLimit($QueryBuilder, $params["limit"] ?? null);
        }

        $result = $QueryBuilder->executeQuery()->fetchAllAssociative();

        if (isset($params["count"])) {
            return $result;
        }

        $ids = [];

        foreach ($result as $entry) {
            if (isset($entry["id"])) {
                $ids[] = (int)$entry["id"];
            }
        }

        return $ids;
    }

    /**
     * Return the children
     *
     * @param array<string, mixed> $params Parameter für die Childrenausgabe
     *                        $params['where']
     *                        $params['limit']
     * @param boolean $load Rekursiv alle Kinder IDs bekommen
     *
     * @return array<int, Edit>|int
     *
     * @throws QUI\Exception
     */
    public function getChildren(array $params = [], bool $load = false): int | array
    {
        if (!isset($params['order'])) {
            // Falls kein order übergeben wird, wird das eingestellte Site order
            switch ($this->getAttribute('order_type')) {
                case 'name ASC':
                case 'name DESC':
                case 'title ASC':
                case 'title DESC':
                case 'c_date ASC':
                case 'c_date DESC':
                case 'd_date ASC':
                case 'd_date DESC':
                case 'release_from ASC':
                case 'release_from DESC':
                    $params['order'] = $this->getAttribute('order_type');
                    break;

                case 'manual':
                case 'manuell':
                default:
                    $params['order'] = 'order_field';
                    break;
            }
        }

        $Project = $this->getProject();

        // if active = '0&1', project -> getchildren returns all children
        if (!isset($params['active'])) {
            $params['active'] = '0&1';
        }

        $children = [];
        $result = $this->getChildrenIds($params);

        if (isset($params['count'])) {
            return (int)$result;
        }

        if (isset($result[0])) {
            foreach ($result as $id) {
                $child = new Edit($Project, (int)$id);
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Fügt eine Verknüpfung zu einer anderen Sprache ein
     *
     * @param string $lang - Sprache zu welcher verknüpft werden soll
     * @param string|int $id - ID zu welcher verknüpft werden soll
     *
     * @return int
     *
     * @throws QUI\Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Permissions\Exception
     */
    public function addLanguageLink(string $lang, string | int $id): int
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        $this->assertNotDeleted();

        $Project = $this->getProject();
        $p_lang = $Project->getLang();
        $id = (int)$id;

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $result = $Connection->createQueryBuilder()
            ->select("1")
            ->from($Platform->quoteSingleIdentifier($this->RELLANGTABLE))
            ->where($Platform->quoteSingleIdentifier($p_lang) . " = :siteId")
            ->setParameter("siteId", $this->getId())
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($result !== false) {
            return (int)$Connection->update(
                $this->RELLANGTABLE,
                [$lang => $id],
                [$p_lang => $this->getId()]
            );
        }

        return (int)$Connection->insert($this->RELLANGTABLE, [
            $p_lang => $this->getId(),
            $lang => $id
        ]);
    }

    /**
     * Entfernt eine Verknüpfung zu einer Sprache
     *
     * @param string $lang
     *
     * @return int
     *
     * @throws QUI\Permissions\Exception
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function removeLanguageLink(string $lang): int
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        $this->assertNotDeleted();

        $Project = $this->getProject();

        return (int)QUI::getDataBaseConnection()->update(
            $this->RELLANGTABLE,
            [$lang => 0],
            [$Project->getLang() => $this->getId()]
        );
    }

    /**
     * Move the site to another parent
     *
     * @param integer $pid - Parent ID
     * @param QUI\Interfaces\Users\User|null $User - Defaults to the session user
     *
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function move(int $pid, ?QUI\Interfaces\Users\User $User = null): void
    {
        if ($User === null) {
            $User = QUI::getUserBySession();
        }

        $this->checkPermission('quiqqer.projects.site.edit', $User);
        $this->assertNotDeleted();

        $Project = $this->getProject();
        $Parent = new self($Project, $pid);
        $Parent->checkPermission('quiqqer.projects.site.new', $User);
        $Parent->assertNotDeleted();
        $children = $this->getChildrenIds(['active' => '0&1']);

        if (!is_array($children)) {
            $children = [];
        }

        if (in_array($pid, $children) || $pid === $this->getId()) {
            return;
        }


        Locker::editing()->run(
            'site:' . $this->getLockKey(),
            (string)$User->getUUID(),
            null,
            function () use ($Parent, $pid, $User): void {
                QUI::getEvents()->fireEvent('siteMoveBefore', [$this, $this->getParentId()]);

                // Only manually sorted destinations need a new position.
                if (
                    $Parent->getAttribute('order_type') === ''
                    || $Parent->getAttribute('order_type') === 'manuell'
                    || $Parent->getAttribute('order_type') === 'manual'
                    || !$Parent->getAttribute('order_type')
                ) {
                    $children = $Parent->getChildren([
                        'active' => '0&1',
                        'order' => 'order_field DESC',
                        'limit' => 1
                    ]);
                    $LastChild = is_array($children) ? ($children[0] ?? null) : null;

                    if (!$LastChild) {
                        $this->setAttribute('order_field', 1);
                    } else {
                        $this->setAttribute(
                            'order_field',
                            (int)$LastChild->getAttribute('order_field') + 1
                        );
                    }
                }

                $this->save($User);

                $Connection = QUI::getDataBaseConnection();
                $Platform = $Connection->getDatabasePlatform();
                $Connection->createQueryBuilder()
                    ->update($Platform->quoteSingleIdentifier($this->RELTABLE))
                    ->set($Platform->quoteSingleIdentifier('parent'), ':parent')
                    ->where($Platform->quoteSingleIdentifier('child') . ' = :child')
                    ->andWhere($Platform->quoteSingleIdentifier('oparent') . ' IS NULL')
                    ->setParameter('parent', $Parent->getId())
                    ->setParameter('child', $this->getId())
                    ->executeStatement();

                $this->deleteCache();

                // Remove internal parent IDs after changing the relation.
                $this->parents_id = null;
                $this->parent_id = null;

                $this->Events->fireEvent('move', [$this, $pid]);
                QUI::getEvents()->fireEvent('siteMove', [$this, $pid]);
            }
        );
    }

    /**
     * Saves the site
     *
     * @param QUI\Interfaces\Users\User|null $SaveUser - [optional] User to save
     *
     * @return void
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function save(?QUI\Interfaces\Users\User $SaveUser = null): void
    {
        $this->runLockedSave($SaveUser, null);
    }

    /** Save an editor request only while its exact lease is still valid. */
    public function saveWithLock(string $token, ?QUI\Interfaces\Users\User $SaveUser = null): void
    {
        $this->runLockedSave($SaveUser, $token);
    }

    private function runLockedSave(?QUI\Interfaces\Users\User $SaveUser, ?string $token): void
    {
        $SaveUser ??= QUI::getUserBySession();
        Locker::editing()->run(
            'site:' . $this->getLockKey(),
            (string)$SaveUser->getUUID(),
            $token,
            fn() => $this->saveWithEditingLock($SaveUser)
        );
    }

    private function saveWithEditingLock(QUI\Interfaces\Users\User $SaveUser): void
    {
        try {
            $this->checkPermission('quiqqer.projects.site.edit', $SaveUser);
        } catch (QUI\Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permissions.edit'
                )
            );
        }

        $this->assertNotDeleted();

        $Project = $this->getProject();
        $name = $this->getAttribute('name');

        if ($Project->getConfig('convertRomanLetters')) {
            $name = QUI\Utils\Convert::convertRoman($name); // cleanup name
        }

        $name = trim($name);

        if ($this->getId() === 1) {
            $name = '';
            $this->setAttribute('name', '');
        }


        // checks if the name is conformed and allowed to use
        if ($this->getId() !== 1) {
            QUI\Projects\Site\Utils::checkName($name);
        }


        // check if a name in the same level exists
        // observed linked sites
        if ($this->getId() != 1) {
            $parent_ids = $this->getParentIds();

            foreach ($parent_ids as $pid) {
                $Parent = new QUI\Projects\Site\Edit($Project, $pid);

                try {
                    $childId = $Parent->getChildIdByName($name);

                    if ($childId !== $this->id) {
                        throw new QUI\Exception(
                            QUI::getLocale()->get(
                                'quiqqer/core',
                                'exception.site.same.name',
                                [
                                    'id' => $pid,
                                    'name' => $name
                                ]
                            ),
                            703
                        );
                    }
                } catch (QUI\Exception $Exception) {
                    if ($Exception->getCode() !== 705) {
                        throw $Exception;
                    }
                }
            }
        }

        // release dates
        $release_from = '';
        $release_to = '';

        if ($this->getAttribute('release_from') && $this->getAttribute('release_from') != '0000-00-00 00:00:00') {
            $rf = strtotime($this->getAttribute('release_from'));
            if ($rf) {
                $release_from = date('Y-m-d H:i:s', $rf);
            }
        } elseif ($this->getAttribute('active')) {
            // nur bei aktiven seiten das e_date setzen
            // wenn der cron läuft, darf eine inaktive seite nicht sofort aktiviert werden
            // daher werden nur aktive seite beachten
            $release_from = date(
                'Y-m-d H:i:s',
                (int)strtotime(date('Y-m-d H:i:s'))
            );
        }

        if ($this->getAttribute('release_to')) {
            $rt = strtotime($this->getAttribute('release_to'));

            if ($rt && $rt > 0) {
                $release_to = date('Y-m-d H:i:s', $rt);
            }
        }

        if (empty($release_from)) {
            $release_from = null;
        }

        if (empty($release_to)) {
            $release_to = null;
        }

        $this->setAttribute('release_from', $release_from);
        $this->setAttribute('release_to', $release_to);


        try {
            $this->checkReleaseDate();
        } catch (QUI\Exception) {
            // if release date trigger an error, deactivate the site
            $this->deactivate($SaveUser);
        }

        // on save before event
        try {
            $this->Events->fireEvent('saveBefore', [$this]);
            QUI::getEvents()->fireEvent('siteSaveBefore', [$this]);
        } catch (QUI\ExceptionStack $Exception) {
            $list = $Exception->getExceptionList();

            foreach ($list as $Exc) {
                QUI\System\Log::writeException($Exc);
            }
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        // save extra package attributes (site.xml)
        $oldType = $this->getAttribute('type');

        $extraAttributes = Utils::getExtraAttributeListForSite($this);
        $siteExtra = [];

        foreach ($extraAttributes as $data) {
            $attribute = $data['attribute'];
            $default = $data['default'];

            if ($this->existsAttribute($attribute)) {
                $siteExtra[$attribute] = $this->getAttribute($attribute);
                continue;
            }

            $siteExtra[$attribute] = $default;
        }

        // order type
        $order_type = 'manuell';

        switch ($this->getAttribute('order_type')) {
            case 'manual':
            case 'manuell':
            case 'name ASC':
            case 'name DESC':
            case 'title ASC':
            case 'title DESC':
            case 'c_date ASC':
            case 'c_date DESC':
            case 'd_date ASC':
            case 'd_date DESC':
            case 'release_from ASC':
            case 'release_from DESC':
                $order_type = $this->getAttribute('order_type');
                break;
        }

        // clear paths
        // wenn sich der seitentyp geändert hat, muss ein clear path gemacht werden
        // somit bleiben keine alten register paths bestehen
        if ($oldType != $this->getAttribute('type')) {
            QUI::getRewrite()->unregisterPath($this);
        }

        $order_field = $this->getAttribute('order_field');

        if (is_numeric($order_field)) {
            $order_field = (int)$order_field;
        }

        if (!$order_field) {
            $order_field = 0;
        }

        // save main data
        $editDate = date('Y-m-d H:i:s');

        try {
            QUI::getDataBaseConnection()->update(
                $this->TABLE,
                [
                    'name' => $name,
                    'title' => trim($this->getAttribute('title')),
                    'short' => $this->getAttribute('short'),
                    'content' => $this->getAttribute('content'),
                    'type' => $this->getAttribute('type'),
                    'layout' => $this->getAttribute('layout'),
                    'nav_hide' => $this->getAttribute('nav_hide') ? 1 : 0,
                    'e_date' => $editDate,
                    'e_user' => $SaveUser->getUUID(),
                    // ORDER
                    'order_type' => $order_type,
                    'order_field' => $order_field,
                    // images
                    'image_emotion' => $this->getAttribute('image_emotion'),
                    'image_site' => $this->getAttribute('image_site'),
                    // release
                    'release_from' => $release_from,
                    'release_to' => $release_to,
                    // Extra-Feld
                    'extra' => json_encode($siteExtra),
                    'auto_release' => $this->getAttribute('auto_release') ? 1 : 0
                ],
                [
                    'id' => $this->getId()
                ]
            );
        } catch (\Doctrine\DBAL\Exception\DriverException $Exception) {
            $message = $Exception->getMessage();
            $isUnsupportedContentCharacter = $Exception->getCode() === 1366
                && str_contains($message, 'Incorrect string value')
                && preg_match(
                    '/for column\s+(?:[^\s.]+\.)*[`\'"]?content[`\'"]?\s+at row\b/i',
                    $message
                ) === 1;

            if (!$isUnsupportedContentCharacter) {
                throw $Exception;
            }

            QUI\System\Log::writeException($Exception);

            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.content.unsupported.characters'
                ),
                0,
                [],
                $Exception
            );
        }

        // save package automatic site data (database.xml)
        $dataList = Utils::getDataListForSite($this);

        foreach ($dataList as $dataEntry) {
            $data = [];

            $table = $dataEntry['table'];
            $fieldList = $dataEntry['data'];
            $package = $dataEntry['package'];
            $suffix = $dataEntry['suffix'];

            $attributeSuffix = $package . '.' . $suffix . '.';
            $attributeSuffix = str_replace('/', '.', $attributeSuffix);

            foreach ($fieldList as $siteAttribute) {
                $data[$siteAttribute] = $this->getAttribute(
                    $attributeSuffix . $siteAttribute
                );
            }

            $Connection = QUI::getDataBaseConnection();
            $Platform = $Connection->getDatabasePlatform();
            $result = $Connection->createQueryBuilder()
                ->select("1")
                ->from($Platform->quoteSingleIdentifier($table))
                ->where($Platform->quoteSingleIdentifier("id") . " = :id")
                ->setParameter("id", $this->getId())
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($result === false) {
                $Connection->insert($table, [
                    "id" => $this->getId()
                ]);
            }

            $Connection->update($table, $data, [
                "id" => $this->getId()
            ]);
        }

        //$this->deleteTemp($User);
        if (!QUI::conf('globals', 'ignoreSiteCacheClearing')) {
            $Project->clearCache();
        }

        // Cache löschen
        $this->deleteCache();

        // Objektcache anlegen
        $this->refresh();
        $this->createCache();

        // Letztes Speichern
        $Project->setEditDate(time());

        // on save event
        try {
            $this->Events->fireEvent('save', [$this, $SaveUser]);
            QUI::getEvents()->fireEvent('siteSave', [$this, $SaveUser]);
        } catch (QUI\ExceptionStack $Exception) {
            $list = $Exception->getExceptionList();

            foreach ($list as $Exc) {
                /* @var $Exc Exception */
                QUI\System\Log::writeException($Exc);
            }
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }


        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/core',
                'message.site.save.success',
                [
                    'id' => $this->getId(),
                    'title' => $this->getAttribute('title'),
                    'name' => $this->getAttribute('name')
                ]
            )
        );
    }

    /**
     * is the page currently edited from another user than me?
     */
    public function isLockedFromOther(): bool | int | string
    {
        return $this->isLocked();
    }

    /** Return the UUID of the other editor, or false if unoccupied/owned by this user. */
    public function isLocked(): bool | string
    {
        $record = Locker::editing()->status('site:' . $this->getLockKey());

        if ($record === null) {
            return false;
        }

        $owner = (string)QUI::getUserBySession()->getUUID();

        if ($record['owner'] === $owner) {
            return false;
        }

        return $record['owner'];
    }

    /**
     * Return the key for the lock file
     *
     * @return string
     */
    protected function getLockKey(): string
    {
        return $this->getProject()->getName() . '_' .
            $this->getProject()->getLang() . '_' .
            $this->getId();
    }

    /**
     * Demarkiert die Seite, die Seite wird nicht mehr bearbeitet
     */
    protected function unlock(): void
    {
        $this->unlockWithRights();
    }

    /**
     * Prüft ob der Name erlaubt ist
     *
     * @param string $name
     *
     * @return boolean
     * @throws QUI\Exception
     * @deprecated use \QUI\Projects\Site\Utils::checkName
     */
    public static function checkName(string $name): bool
    {
        return QUI\Projects\Site\Utils::checkName($name);
    }

    //region cache

    /**
     * Deactivate a site
     *
     * @param ?QUI\Interfaces\Users\User $User - [optional] User to save
     * @throws QUI\Exception
     */
    public function deactivate(null | QUI\Interfaces\Users\User $User = null): void
    {
        try {
            $this->checkPermission('quiqqer.projects.site.edit', $User);
        } catch (QUI\Exception) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.permissions.edit'
                )
            );
        }

        $this->assertNotDeleted();

        if (!$User) {
            $User = QUI::getUserBySession();
        }

        QUI::getEvents()->fireEvent('siteCheckDeactivate', [$this]);

        // deactivate
        QUI::getDataBaseConnection()->update(
            $this->TABLE,
            [
                "active" => 0,
                "release_from" => null,
                "e_user" => $User->getUUID()
            ],
            ["id" => $this->getId()]
        );

        $this->setAttribute('active', 0);
        $this->getProject()->clearCache();

        //$this->deleteTemp();
        $this->deleteCache();


        $this->Events->fireEvent('deactivate', [$this]);
        QUI::getEvents()->fireEvent('siteDeactivate', [$this]);
    }

    //endregion

    /**
     * Create the site cache
     */
    public function createCache(): void
    {
        // create object cache
        parent::createCache();

        // create url rewritten cache
        $linkCache = $this->getLinkCachePath(
            $this->getProject()->getName(),
            $this->getProject()->getLang(),
            $this->getId()
        );

        try {
            QUI\Cache\Manager::set($linkCache, $this->getLocation());
        } catch (Exception $Exception) {
            QUI\System\Log::addDebug($Exception->getMessage());
        }
    }

    /**
     * Copies the page
     *
     * @param integer $pid - ID of the parent under which the copy is to be mounted
     * @param Project|null $Project $Project - (optional) Parent Project
     *
     * @return Edit
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     * @todo Rekursiv kopieren
     */
    public function copy(int $pid, null | Project $Project = null): Edit
    {
        // Edit Rechte prüfen
        $this->checkPermission('quiqqer.projects.site.edit');
        $this->assertNotDeleted();

        if (!$Project) {
            $Project = $this->getProject();
        }

        $Parent = new QUI\Projects\Site\Edit($Project, $pid);
        $attributes = $this->getAttributes();
        $name = $this->getAttribute('name');
        $title = $this->getAttribute('title');

        // Prüfen ob es eine Seite mit dem gleichen Namen im Parent schon gibt
        if ($Parent->existNameInChildren($name)) {
            $newName = QUI::getLocale()->get(
                'quiqqer/core',
                'projects.project.site.copy.text',
                ['name' => $name]
            );

            $newTitle = QUI::getLocale()->get(
                'quiqqer/core',
                'projects.project.site.copy.text',
                ['name' => $title]
            );

            // kind gefunden, wir brauchen ein neuen namen
            $i = 1;

            while ($Parent->existNameInChildren($newName)) {
                $newName = QUI::getLocale()->get(
                    'quiqqer/core',
                    'projects.project.site.copy.text.count',
                    [
                        'name' => $name,
                        'count' => $i
                    ]
                );

                $newTitle = QUI::getLocale()->get(
                    'quiqqer/core',
                    'projects.project.site.copy.text.count',
                    [
                        'name' => $title,
                        'count' => $i
                    ]
                );

                $i++;
            }

            $name = $newName;
            $title = $newTitle;
        }

        // kopiervorgang beginnen
        $PermManager = QUI::getPermissionManager();
        $permissions = $PermManager->getSitePermissions($this);

        $site_id = $Parent->createChild(
            [
                'name' => $name,
                'title' => $title
            ],
            $permissions
        );

        // Erstmal Seitentyp setzn
        $Site = new QUI\Projects\Site\Edit($Project, $site_id);
        $Site->setAttribute('type', $this->getAttribute('type'));
        $Site->setAttribute('title', $title);
        $Site->save();

        // Alle Attribute setzen
        $Site = new QUI\Projects\Site\Edit($Project, $site_id);

        foreach ($attributes as $key => $value) {
            if ($key == 'name') {
                continue;
            }

            if ($key == 'title') {
                continue;
            }

            if ($key == 'type') {
                continue;
            }

            if ($key == 'order_field') {
                continue;
            }

            $Site->setAttribute($key, $value);
        }

        $Site->save();

        return $Site;
    }

    /**
     * Checks if a site with the name in the children exists
     */
    public function existNameInChildren(string $name): bool
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $count = $Connection->createQueryBuilder()
            ->select("COUNT(site." . $Platform->quoteSingleIdentifier("id") . ")")
            ->from($Platform->quoteSingleIdentifier($this->RELTABLE), "rel")
            ->innerJoin(
                "rel",
                $Platform->quoteSingleIdentifier($this->TABLE),
                "site",
                "rel." . $Platform->quoteSingleIdentifier("child") . " = site." . $Platform->quoteSingleIdentifier("id")
            )
            ->where("rel." . $Platform->quoteSingleIdentifier("parent") . " = :parent")
            ->andWhere("site." . $Platform->quoteSingleIdentifier("name") . " = :name")
            ->andWhere("site." . $Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->setParameter("parent", $this->getId())
            ->setParameter("name", $name)
            ->setParameter("deleted", 0)
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * Erstellt ein neues Kind
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $childPermissions - [optional] permissions for the child
     * @param QUI\Interfaces\Users\User|null $User - [optional] the user which create the site, optional
     *
     * @return Int
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     * @throws ExceptionStack
     * @throws QUI\Permissions\Exception
     */
    public function createChild(
        array $params = [],
        array $childPermissions = [],
        null | QUI\Interfaces\Users\User $User = null
    ): int {
        $this->checkPermission('quiqqer.projects.site.new', $User);
        $this->assertNotDeleted();

        if (!$User) {
            $User = QUI::getUserBySession();
        }

        $new_name = 'Neue Seite';   // @todo multilingual
        $old = $new_name;
        $i = 1;

        if (empty($params['name'])) {
            while ($this->existNameInChildren($new_name)) {
                $new_name = $old . ' (' . $i . ')';
                $i++;
            }
        } else {
            $new_name = $params['name'];
        }

        if ($this->existNameInChildren($new_name)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.same.name',
                    [
                        'name' => $new_name
                    ]
                )
            );
        }

        // can we use this name?
        QUI\Projects\Site\Utils::checkName($new_name);


        $childCount = $this->hasChildren(true);

        $creationDate = date('Y-m-d H:i:s');

        $_params = [
            'name' => $new_name,
            'title' => $new_name,
            'c_date' => $creationDate,
            'e_date' => $creationDate,
            'e_user' => $User->getUUID(),
            'c_user' => $User->getUUID(),
            'c_user_ip' => QUI\Utils\System::getClientIP(),
            'order_field' => $childCount + 1
        ];

        if (isset($params['title'])) {
            $_params['title'] = $params['title'];
        }

        if (isset($params['short'])) {
            $_params['short'] = $params['short'];
        }

        if (isset($params['content'])) {
            $_params['content'] = $params['content'];
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $Connection->insert($this->TABLE, $_params);

        $newId = (int)$Connection->lastInsertId();

        // something is wrong
        if ($newId == 0) {
            $max = $Connection->createQueryBuilder()
                ->select("MAX(" . $Platform->quoteSingleIdentifier("id") . ")")
                ->from($Platform->quoteSingleIdentifier($this->TABLE))
                ->executeQuery()
                ->fetchOne();

            $newId = (int)$max + 1;

            $Connection->update(
                $this->TABLE,
                ["id" => $newId],
                ["id" => 0]
            );
        }

        $Connection->insert($this->RELTABLE, [
            "parent" => $this->getId(),
            "child" => $newId
        ]);

        // copy permissions to the child
        $PermManager = QUI::getPermissionManager();
        $permissions = $PermManager->getSitePermissions($this);
        $newPermissions = [];

        // parent permissions
        foreach ($permissions as $permission => $value) {
            if (empty($value)) {
                continue;
            }

            $newPermissions[$permission] = $value;
        }

        // optional new permission
        foreach ($childPermissions as $permission => $value) {
            if (empty($value)) {
                continue;
            }

            $newPermissions[$permission] = $value;
        }

        if (!empty($newPermissions)) {
            $Child = new Edit($this->getProject(), $newId);

            $PermManager->setSitePermissions(
                $Child,
                $newPermissions,
                QUI::getUsers()->getSystemUser()
            );
        }

        // Aufruf der createChild Methode im TempSite - für den Adminbereich
        try {
            $this->Events->fireEvent('createChild', [$newId, $this]);
            QUI::getEvents()->fireEvent('siteCreateChild', [$newId, $this]);
        } catch (\Exception $Exception) {
            QUI::getMessagesHandler()->addAttention(
                $Exception->getMessage()
            );
        }


        return $newId;
    }

    /**
     * Erstellt eine Verknüpfung
     *
     * @param integer $pid
     * @param QUI\Interfaces\Users\User|null $User - [optional] User creating the link
     *
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function linked(int $pid, null | QUI\Interfaces\Users\User $User = null): void
    {
        $this->checkPermission('quiqqer.projects.site.edit', $User);
        $this->assertNotDeleted();

        $Project = $this->getProject();
        $LinkedParent = new self($Project, $pid);
        $LinkedParent->checkPermission('quiqqer.projects.site.new', $User);
        $LinkedParent->assertNotDeleted();
        $Parent = $this->getParent();

        if ($Parent === false) {
            throw new QUI\Exception('The root site cannot be linked.', 703);
        }

        $table = QUI::getDBTableName(
            $Project->getName() . '_' .
            $Project->getLang() . '_sites_relations'
        );

        if ($this->getId() == $pid) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.linked.in.itself'),
                703
            );
        }

        if ($Parent->getId() == $pid) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.linked.already.exists'),
                703
            );
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $links = $Connection->createQueryBuilder()
            ->select("*")
            ->from($Platform->quoteSingleIdentifier($table))
            ->where($Platform->quoteSingleIdentifier("child") . " = :child")
            ->setParameter("child", $this->getId())
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($links as $entry) {
            if ($entry['parent'] == $pid) {
                throw new QUI\Exception(
                    QUI::getLocale()->get('quiqqer/core', 'exception.site.linked.already.exists'),
                    703
                );
            }
        }

        $Connection->insert($table, [
            "parent" => $LinkedParent->getId(),
            "child" => $this->getId(),
            "oparent" => $Parent->getId()
        ]);
    }

    /**
     * Delete all linked sites
     *
     * @param integer $pid - Parent ID
     * @param boolean|integer $all - (optional) Delete all linked sites and the original site
     * @param boolean $orig - (optional) Delete the original site, too
     *
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function deleteLinked(int $pid, bool | int $all = false, bool $orig = false): void
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        $this->assertNotDeleted();


        $Project = $this->getProject();
        $Parent = $this->getParent();
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();

        $table = QUI::getDBTableName(
            $Project->getName() . "_" .
            $Project->getLang() . "_sites_relations"
        );

        if (QUI\Utils\BoolHelper::JSBool($all)) {
            if ($Parent === false) {
                throw new QUI\Exception('The root site has no linked parent.', 703);
            }

            // Seite löschen
            $this->delete();

            // Alle Verknüpfungen
            $Connection->createQueryBuilder()
                ->delete($Platform->quoteSingleIdentifier($table))
                ->where($Platform->quoteSingleIdentifier("child") . " = :child")
                ->andWhere($Platform->quoteSingleIdentifier("parent") . " <> :parent")
                ->setParameter("child", $this->getId())
                ->setParameter("parent", $Parent->getId())
                ->executeStatement();

            return;
        }

        // Einzelne Verknüpfung löschen
        if ($pid && !$orig) {
            $Connection->delete($table, [
                "child" => $this->getId(),
                "parent" => $pid
            ]);

            return;
        }

        $Connection->delete($table, [
            "child" => $this->getId(),
            "parent" => $pid,
            "oparent" => (int)$orig
        ]);
    }

    /**
     * permissions
     */

    /**
     * Markiert die Seite -> die Seite wird gerade bearbeitet
     * Markiert nur wenn die Seite nicht markiert ist
     *
     * @return boolean - true if it worked, false if it is not worked
     *
     * @throws QUI\Exception
     */
    public function lock(): bool
    {
        return $this->acquireEditingLock(bin2hex(random_bytes(32)));
    }

    public function acquireEditingLock(string $token): bool
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        return Locker::editing()->acquire(
            'site:' . $this->getLockKey(),
            (string)QUI::getUserBySession()->getUUID(),
            $token
        );
    }

    public function refreshLock(string $token): bool
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        return Locker::editing()->refresh('site:' . $this->getLockKey(), (string)QUI::getUserBySession()->getUUID(), $token);
    }

    /** Release only this editor's lease; stale requests cannot release its successor. */
    public function releaseEditingLock(string $token): void
    {
        $this->checkPermission('quiqqer.projects.site.edit');
        Locker::editing()->release('site:' . $this->getLockKey(), (string)QUI::getUserBySession()->getUUID(), $token);
    }

    /** Forced removal is restricted to super/system users. */
    public function unlockWithRights(): void
    {
        $User = QUI::getUserBySession();
        $this->checkPermission('quiqqer.projects.site.edit', $User);

        if (!$User->isSU() && !QUI::getUsers()->isSystemUser($User)) {
            throw new QUI\Permissions\Exception(['quiqqer/core', 'exception.permissions.edit'], 403);
        }

        Locker::editing()->release('site:' . $this->getLockKey(), (string)$User->getUUID(), '', true);
    }

    /**
     * Add a user to the permission
     *
     * @param string $permission - name of the permission
     * @param User $User - User Object
     * @param ?QUI\Interfaces\Users\User $EditUser
     *
     * @throws QUI\Exception
     */
    public function addUserToPermission(
        QUI\Interfaces\Users\User $User,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::addUserToSitePermission($User, $this, $permission, $EditUser);
    }

    /**
     * @throws QUI\Exception
     */
    public function addGroupToPermission(
        Group $Group,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::addGroupToSitePermission($Group, $this, $permission, $EditUser);
    }

    /**
     * Utils
     */

    /**
     * Remove a user from the permission
     *
     * @param QUI\Interfaces\Users\User $User - User Object#
     * @param string $permission - name of the permission
     * @param ?QUI\Interfaces\Users\User $EditUser
     *
     * @throws QUI\Exception
     */
    public function removeUserFromSitePermission(
        QUI\Interfaces\Users\User $User,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::removeUserFromSitePermission($User, $this, $permission, $EditUser);
    }

    /**
     * @throws QUI\Exception
     */
    public function removeGroupFromSitePermission(
        Group $Group,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::removeGroupFromSitePermission($Group, $this, $permission, $EditUser);
    }
}
