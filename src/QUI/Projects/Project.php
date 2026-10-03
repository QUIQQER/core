<?php

/**
 * This file contains the \QUI\Projects\Project
 */

namespace QUI\Projects;

use DOMElement;
use Exception;
use PDO;
use QUI;
use QUI\Groups\Group;
use QUI\Permissions\Permission;
use QUI\Projects\Site\PermissionDenied;
use QUI\Users\User;
use QUI\Utils\Text\XML;

use function array_merge;
use function array_reverse;
use function array_unique;
use function count;
use function date;
use function defined;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function is_writable;
use function json_encode;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const USR_DIR;

/**
 * A project
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * @errorcodes
 * <ul>
 * <li>
 * <li>801 - Project Create Error: name must longer than two signs</li>
 * <li>802 - Project Create Error: not allowed signs</li>
 * <li>803 - Project Error: Project has no languages</li>
 * <li>804 - Project Error: Project not found</li>
 * <li>805 - Project Error: Project has no default language</li>
 * <li>806 - Project Error: Project language not found</li>
 * </ul>
 */
class Project implements \Stringable
{
    /**
     * caching files
     *
     * @var array<string, string>
     */
    protected array $cache_files = [];

    protected ?Media $Media = null;

    /**
     * The project site table
     */
    private string $TABLE;

    /**
     * The project site relation table
     */
    private string $RELTABLE;

    /**
     * The project site relation language table
     */
    private string $RELLANGTABLE;

    /**
     * configuration
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * default language
     */
    private string $default_lang;

    /**
     * All languages of the project
     *
     * @var array<int, string>
     */
    private array $langs;

    /**
     * loaded sites
     *
     * @var array<int, mixed>
     */
    private array $children = [];

    /**
     * first child
     */
    private Site|QUI\Projects\Site\Edit|null $firstchild = null;

    /**
     * @var array{
     *     host: string,
     *     httpshost: string,
     *     path: string,
     *     project: string,
     *     lang: string
     * }|false|null
     */
    private array|false|null $vhostRoute = null;

    /**
     * Constructor
     *
     * @param string $name - Name of the Project
     * @param false|string $lang - (optional) Language of the Project - optional
     * @param false|string $template - (optional) Template of the Project
     *
     * @throws QUI\Exception
     */
    public function __construct(
        private string $name,
        private false|string $lang = false,
        private false|string $template = false
    ) {
        try {
            $this->refresh();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            $this->name = '';
            $this->lang = '';
            $this->template = '';

            throw $Exception;
        }
    }

    /**
     * Refresh the config
     *
     * @throws QUI\Exception
     */
    public function refresh(): void
    {
        $this->vhostRoute = null;
        $config = Manager::getConfig()->toArray();

        $name = $this->name;
        $lang = (string)$this->lang;
        $template = (string)$this->template;

        if (!isset($config[$name])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.not.found',
                    ['name' => $name]
                ),
                804
            );
        }

        $this->config = $config[$name];
        $this->name = $name;

        if (!isset($this->config['langs'])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.has.no.langs'
                ),
                803
            );
        }

        $this->langs = explode(',', $this->config['langs']);

        // Default Lang
        if (!isset($this->config['default_lang'])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.lang.no.default'
                ),
                805
            );
        }

        $this->default_lang = $this->config['default_lang'];

        // Sprache
        if ($lang) {
            if (!in_array($lang, $this->langs)) {
                throw new QUI\Exception(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.project.lang.not.found',
                        [
                            'lang' => $lang
                        ]
                    ),
                    806
                );
            }

            $this->lang = $lang;
        } else {
            $this->lang = $this->config['default_lang'];
        }

        // Template
        if (empty($template)) {
            $this->template = $config[$name]['template'];
        } else {
            $this->template = $template;
        }

        // defaults
        if (!isset($this->config['adminSitemapMax']) || !$this->config['adminSitemapMax']) {
            $this->config['adminSitemapMax'] = 20;
        }

        // vhosts
        $vhosts = QUI::vhosts();

        foreach ($vhosts as $host => $vhost) {
            if ((int)$host) {
                // falls 404 oder 301 oder sonst irgendein apache code eingetragen ist,
                //dann nicht weiter
                continue;
            }

            if (!isset($vhost['project'])) {
                continue;
            }

            if (!isset($vhost['lang'])) {
                continue;
            }

            if ($vhost['lang'] != $this->lang) {
                continue;
            }

            if ($vhost['project'] != $this->name) {
                continue;
            }

            $this->config['vhost'] = $host;
        }

        // tabellen setzen
        $this->TABLE = QUI_DB_PRFX . $this->name . '_' . $this->lang . '_sites';
        $this->RELTABLE = $this->TABLE . '_relations';
        $this->RELLANGTABLE = QUI_DB_PRFX . $this->name . '_multilingual';


        // cache files
        // @todo move to the cache
        $this->cache_files = [
            'types' => 'projects.' . $this->getName() . '.types',
            'gtypes' => 'projects.' . $this->getName() . '.globaltypes'
        ];
    }

    /**
     * Project Array Notation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'lang' => $this->getLang()
        ];
    }

    /**
     * Name of the project
     *
     * @param string $att -
     *                    name = Name des Projectes
     *                    lang = Aktuelle Sprache
     *                    db_table = Standard Datebanktabelle, please use this->table()
     *
     * @return string|int|bool|array<array-key, mixed>
     */
    public function getAttribute(string $att): string|int|bool|array
    {
        return match ($att) {
            "name" => $this->getName(),
            "lang" => $this->getLang(),
            "e_date" => $this->getLastEditDate(),
            "config" => $this->config,
            "default_lang" => $this->default_lang,
            "langs" => $this->langs,
            "template" => $this->template,
            "db_table" => $this->name . '_' . $this->lang . '_sites',
            "media_table" => $this->name . '_de_media',
            default => false,
        };
    }

    /**
     * Return the project name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Return the project lang
     */
    public function getLang(): string
    {
        return (string)$this->lang;
    }

    public function getDefaultLang(): string
    {
        return $this->default_lang;
    }

    public function getTemplate(): false | string
    {
        return $this->template;
    }

    /**
     * Return the last edit date in the project
     */
    public function getLastEditDate(): int
    {
        try {
            return (int)QUI\Cache\Manager::get($this->getEDateCacheName());
        } catch (QUI\Exception) {
        }

        return 0;
    }

    /**
     * Return a site
     *
     * @param integer $id - ID of the Site
     *
     * @return Site|Site\Edit
     * @throws QUI\Exception
     */
    public function get(int $id): Site\Edit|Site
    {
        if (
            (defined('ADMIN') && ADMIN == 1)
            || (defined('QUIQQER_CONSOLE') && QUIQQER_CONSOLE == 1)
        ) {
            return new Site\Edit($this, $id);
        }

        if (isset($this->children[$id])) {
            return $this->children[$id];
        }

        try {
            $Site = new Site($this, $id);
        } catch (QUI\Exception $Exception) {
            if ($Exception->getCode() !== 403) {
                throw $Exception;
            }

            $Site = new PermissionDenied($this, $id);
        }

        $this->children[$id] = $Site;
        return $Site;
    }

    protected function getEDateCacheName(): string
    {
        return $this->getCachePath() . '/edate/';
    }

    /**
     * Return the project cache path
     */
    public function getCachePath(): string
    {
        return self::getProjectCachePath($this->getName());
    }

    /**
     * Return the cache path for a project (without language)
     */
    public static function getProjectCachePath(string $projectName): string
    {
        return 'quiqqer/projects/' . $projectName;
    }

    /**
     * Gibt die gesuchte Einstellung vom Projekt zurück
     *
     * @param false|string $name - name of the config, default = false, returns complete configs
     *
     * @return mixed
     */
    public function getConfig(false|string $name = false): mixed
    {
        if (!$name) {
            return $this->config;
        }

        if (isset($this->config[$name])) {
            return $this->config[$name];
        }

        // default Werte
        return match ($name) {
            "sheets" => 5,
            "archive" => 10,
            default => false,
        };
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        unset($this->config);
    }

    public function __toString(): string
    {
        return 'Object ' . $this::class . '(' . $this->name . ',' . $this->lang . ')';
    }

    public function toJSON(): string
    {
        return (string)json_encode($this->toArray());
    }

    /**
     * Return all languages in the project
     *
     * @return array<int, string>
     */
    public function getLanguages(): array
    {
        $languages = $this->getAttribute('langs');

        if (is_string($languages)) {
            $languages = explode(',', $languages);
        }

        if (!is_array($languages)) {
            $languages = [];
        }

        return $languages;
    }

    /**
     * Return the project title
     * Locale->get('project/NAME', 'title') or getName()
     */
    public function getTitle(): string
    {
        $group = 'project/' . $this->getName();

        if (QUI::getLocale()->exists($group, 'title')) {
            $title = QUI::getLocale()->get($group, 'title');

            if ($title !== '') {
                return $title;
            }
        }

        return $this->getName();
    }

    /**
     * Return the project title locale entry, preferring entries without a package.
     * Fall back to a package-bound entry only when it is unambiguous.
     *
     * @return array<string, mixed>
     * @throws QUI\Database\Exception
     */
    public function getTitleLocaleData(): array
    {
        $entries = QUI\Translator::get(
            'project/' . $this->getName(),
            'title'
        );

        foreach ($entries as $entry) {
            if (empty($entry['package'])) {
                return $entry;
            }
        }

        return count($entries) === 1 ? $entries[0] : [];
    }

    /**
     * Save customized project titles to the user-edit locale fields.
     *
     * Original locale values must remain untouched because package setup may
     * import them again during an update.
     *
     * @param array<string, mixed> $translations
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public function setTitleLocaleData(array $translations): void
    {
        Permission::checkProjectPermission(
            'quiqqer.projects.setconfig',
            $this
        );

        $group = 'project/' . $this->getName();
        $entry = $this->getTitleLocaleData();

        if (!isset($entry['id'])) {
            QUI\Translator::add($group, 'title');
            $entry = $this->getTitleLocaleData();
        }

        if (!isset($entry['id'])) {
            throw new QUI\Exception('Could not create the project title locale entry.');
        }

        $availableLanguages = QUI::availableLanguages();
        $localeData = [];

        foreach ($translations as $language => $value) {
            $language = strtolower(trim($language));

            if (!in_array($language, $availableLanguages, true)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $localeData[$language . '_edit'] = $value;
        }

        if ($localeData === []) {
            return;
        }

        $localeData['groups'] = $group;
        $localeData['var'] = 'title';
        $localeData['package'] = $entry['package'] ?? '';

        if (!empty($entry['package'])) {
            $localeData['datatype'] = $entry['datatype'];
            $localeData['html'] = $entry['html'];
            $localeData['priority'] = $entry['priority'];
        }

        QUI\Translator::editById((int)$entry['id'], $localeData);
        QUI\Translator::publish($group);
    }

    /**
     * Durchsucht das Projekt nach Seiten
     *
     * @param string $search - Suchwort
     * @param boolean|array<int, string> $select - (optional) in welchen Feldern gesucht werden soll
     *                                array('name', 'title', 'short', 'content')
     *
     * @return array<int, mixed>
     */
    public function search(string $search, bool|array $select = false): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder()
            ->select($Platform->quoteSingleIdentifier("id"))
            ->from($Platform->quoteSingleIdentifier($this->table()))
            ->where($Platform->quoteSingleIdentifier("deleted") . " = :deleted")
            ->setParameter("deleted", 0)
            ->setParameter("search", "%" . $search . "%")
            ->setMaxResults(50);

        $allowed = ["id", "name", "title", "short", "content"];
        $searchFields = ["name"];

        if (is_array($select)) {
            $searchFields = [];

            foreach ($select as $field) {
                if (in_array($field, $allowed)) {
                    $searchFields[] = $field;
                }
            }

            if (empty($searchFields)) {
                $searchFields = ["name"];
            }
        }

        $searchParts = [];

        foreach ($searchFields as $field) {
            $searchParts[] = $Platform->quoteSingleIdentifier($field) . " LIKE :search";
        }

        $QueryBuilder->andWhere("(" . implode(" OR ", $searchParts) . ")");

        $dbResult = $QueryBuilder->executeQuery()->fetchAllAssociative();
        $result = [];

        foreach ($dbResult as $entry) {
            try {
                $result[] = $this->get($entry['id']);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
            }
        }

        return $result;
    }

    public function table(): string
    {
        return QUI::getDBTableName($this->name . '_' . $this->lang . '_sites');
    }

    public function hasVHost(): bool
    {
        return $this->getVHostRoute() !== null;
    }

    //region cache

    /**
     * Gibt den allgemein gültigen Host vom Projekt zurück
     */
    public function getHost(): string
    {
        $route = $this->getVHostRoute();

        if ($route !== null) {
            return $route['host'];
        }

        if (isset($this->config['host'])) {
            return $this->config['host'];
        }

        $host = QUI::conf('globals', 'host');

        if (!empty($host)) {
            return $host;
        }

        return $_SERVER['HTTP_HOST'];
    }

    /**
     * Get the Trash from the Project
     */
    public function getTrash(): Trash
    {
        return new Trash($this);
    }

    /**
     * Gibt alle Attribute vom Projekt zurück
     *
     * @return array<string, mixed>
     */
    public function getAllAttributes(): array
    {
        return [
            'config' => $this->config,
            'lang' => $this->lang,
            'langs' => $this->langs,
            'name' => $this->name,
            'sheets' => $this->getConfig('sheets'),
            'archive' => $this->getConfig('archive')
        ];
    }

    /**
     * Erste Seite des Projektes
     *
     * @$pluginload boolean
     *
     * @throws QUI\Exception
     */
    public function firstChild(): Site\Edit|Site
    {
        if ($this->firstchild === null) {
            $this->firstchild = $this->get(1);
        }

        return $this->firstchild;
    }

    /**
     * Clears the project cache path
     *
     * @param boolean $link - Clears the site link cache
     * @param boolean $site - Clears the site cache
     *
     * @todo muss überarbeitet werden
     */
    public function clearCache(bool $link = true, bool $site = true): void
    {
        $cachePath = $this->getCacheLanguagePath();

        if ($link === true) {
            QUI\Cache\Manager::clear($cachePath . '/urlRewritten');
        }

        if ($site === true) {
            QUI\Cache\Manager::clear($cachePath . '/site');
        }

        foreach ($this->cache_files as $cache) {
            QUI\Cache\Manager::clear($cache);
        }
    }

    //endregion

    /**
     * Return the project cache path with the language path
     */
    public function getCacheLanguagePath(): string
    {
        return self::getProjectLanguageCachePath($this->getName(), $this->getLang());
    }

    /**
     * Return the cache path with the language path for a project
     */
    public static function getProjectLanguageCachePath(string $projectName, string $projectLang): string
    {
        return self::getProjectCachePath($projectName) . '/' . $projectLang;
    }

    /**
     * Return all available layouts
     *
     * @return array<array-key, mixed>
     */
    public function getLayouts(): array
    {
        $VHosts = new QUI\System\VhostManager();
        $vhostList = $VHosts->getHostsByProject($this->getName());
        $template = OPT_DIR . $this->getTemplate();

        $siteXMLs = [
            $template . '/site.xml'
        ];

        // inheritance
        try {
            $Package = QUI::getPackage((string)$this->getTemplate());
            $Parent = $Package->getTemplateParent();
            $siteXml = false;

            if ($Parent) {
                $siteXml = $Parent->getXMLFilePath('site.xml');
            }

            if ($siteXml) {
                $siteXMLs[] = $siteXml;
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        foreach ($vhostList as $vhost) {
            $hostData = $VHosts->getVhost($vhost);

            if (!empty($hostData['template'])) {
                $siteXMLs[] = OPT_DIR . $hostData['template'] . '/site.xml';
            }
        }

        $result = [];
        $_resTemp = [];
        $siteXMLs = array_unique($siteXMLs);

        foreach ($siteXMLs as $siteXML) {
            $layouts = XML::getLayoutsFromXml($siteXML);

            foreach ($layouts as $Layout) {
                /* @var $Layout DOMElement */
                if (isset($_resTemp[$Layout->getAttribute('type')])) {
                    continue;
                }

                $data = [
                    'type' => $Layout->getAttribute('type'),
                    'title' => '',
                    'description' => '',
                    'image' => ''
                ];

                $_resTemp[$Layout->getAttribute('type')] = true;

                $title = $Layout->getElementsByTagName('title');
                $desc = $Layout->getElementsByTagName('description');

                if ($title->length) {
                    $data['title'] = QUI\Utils\DOM::getTextFromNode($title->item(0));
                }

                if ($desc->length) {
                    $data['description'] = QUI\Utils\DOM::getTextFromNode($desc->item(0));
                }

                if ($Layout->getAttribute('image')) {
                    $path = dirname($siteXML);
                    $path = str_replace(OPT_DIR, '', $path);

                    $file = OPT_DIR . $path . '/' . $Layout->getAttribute('image');

                    if (file_exists($file)) {
                        $data['image'] = URL_OPT_DIR . $path . '/' . $Layout->getAttribute('image');
                    }
                }

                $result[] = $data;
            }
        }


        return $result;
    }

    /**
     * VHost zurück geben
     *
     * @param boolean $with_protocol - Mit oder ohne http -> standard = ohne
     * @param boolean $ssl - with or without ssl
     *
     * @return string
     */
    public function getVHost(bool $with_protocol = false, bool $ssl = false): string
    {
        if (QUI::conf("webserver", "forceHttps")) {
            $ssl = true;
        }

        $route = $this->getVHostRoute();

        if ($route !== null) {
            $host = $route['host'];

            if ($ssl && $route['httpshost'] !== '') {
                $host = $route['httpshost'];
            }

            if (!$with_protocol) {
                return $host;
            }

            return 'https://' . $host;
        }

        try {
            $StandardProject = QUI::getProjectManager()->getStandard();
        } catch (Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
            return HOST;
        }

        if ($StandardProject->getName() === $this->getName()) {
            return HOST;
        }

        return HOST . '/' . QUI\Rewrite::URL_PROJECT_CHARACTER . $this->getName() . '/';
    }

    /**
     * Return the canonical VHost route for this project language.
     *
     * @return array{
     *     host: string,
     *     httpshost: string,
     *     path: string,
     *     project: string,
     *     lang: string
     * }|null
     */
    public function getVHostRoute(): ?array
    {
        if (is_array($this->vhostRoute)) {
            return $this->vhostRoute;
        }

        if ($this->vhostRoute === false) {
            return null;
        }

        try {
            $VHosts = new QUI\System\VhostManager();
            $route = $VHosts->getProjectLanguageRoute(
                $this->getName(),
                $this->getLang()
            );
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            $route = null;
        }

        if ($route === null) {
            $this->vhostRoute = false;
            return null;
        }

        $this->vhostRoute = $route;
        return $this->vhostRoute;
    }

    /**
     * Return the language path without surrounding slashes.
     * Unassigned languages use a prefix for multilingual projects or when VHosts
     * are configured, preserving the legacy fallback for unassigned projects.
     */
    public function getVHostPath(): string
    {
        $route = $this->getVHostRoute();

        if ($route !== null) {
            return $route['path'];
        }

        if (!empty(QUI::vhosts()) || count($this->getLanguages()) > 1) {
            return $this->getLang();
        }

        return '';
    }

    /**
     * Return the absolute base URL for this project language, including the
     * installation directory and an optional language path.
     */
    public function getVHostBaseUrl(bool $ssl = true): string
    {
        $host = rtrim((string)$this->getVHost(true, $ssl), '/');
        $path = trim(URL_DIR, '/');
        $languagePath = $this->getVHostPath();

        if ($languagePath !== '') {
            $path = trim($path . '/' . $languagePath, '/');
        }

        if ($path === '') {
            return $host . '/';
        }

        return $host . '/' . $path . '/';
    }

    /**
     * Return the children ids from a site
     *
     * @param integer $parentid - The parent site ID
     * @param array<string, mixed> $params - extra db statements, like order, where, count, limit
     *
     * @return array<int, int>|int
     * @throws QUI\Database\Exception
     */
    public function getChildrenIdsFrom(int $parentid, array $params = []): array|int
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
            ->setParameter("parent", $parentid)
            ->setParameter("deleted", 0);

        if (!(isset($params["active"]) && $params["active"] === "0&1")) {
            $QueryBuilder
                ->andWhere($siteAlias . "." . $Platform->quoteSingleIdentifier("active") . " = :active")
                ->setParameter("active", 1);
        }

        if (isset($params["where"]) && is_array($params["where"])) {
            self::applySiteConditions(
                $QueryBuilder,
                $params["where"],
                "andWhere",
                $siteAlias,
                [
                    $this->TABLE => $siteAlias,
                    $this->RELTABLE => $relationAlias
                ]
            );
        } elseif (isset($params["where"]) && is_string($params["where"])) {
            QUI\System\Log::addDebug(
                "Project->getChildrenIdsFrom WIRD NICHT verwendet" . $params["where"]
            );
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

        if (isset($params['count'])) {
            return (int)$result[0]['count'];
        }

        $ids = [];

        foreach ($result as $entry) {
            if (isset($entry['id'])) {
                $ids[] = (int)$entry['id'];
            }
        }

        return $ids;
    }

    /**
     * Returns the parent id from a site
     *
     * @throws QUI\Database\Exception
     * @deprecated
     */
    public function getParentId(int $id): int
    {
        return $this->getParentIdFrom($id);
    }

    /**
     * Returns the parent id from a site
     *
     * @param integer $id - Child id
     *
     * @return integer ID of the Parent
     * @throws QUI\Database\Exception
     */
    public function getParentIdFrom(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $parent = $QueryBuilder
            ->select($Platform->quoteSingleIdentifier("parent"))
            ->from($Platform->quoteSingleIdentifier($this->RELTABLE))
            ->where($Platform->quoteSingleIdentifier("child") . " = :child")
            ->setParameter("child", $id)
            ->orderBy($Platform->quoteSingleIdentifier("oparent"), "ASC")
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($parent) {
            return (int)$parent;
        }

        return 0;
    }

    /**
     * Gibt alle Parent IDs zurück
     *
     * @param integer $id - child id
     * @param boolean $reverse - revers the result
     *
     * @return array<int, int>
     *
     * @throws QUI\Database\Exception
     */
    public function getParentIds(int $id, bool $reverse = false): array
    {
        if ($id <= 1) {
            return [];
        }

        $ids = [];
        $pid = $this->getParentIdFrom($id);

        while ($pid > 1) {
            $ids[] = $pid;
            $pid = $this->getParentIdFrom($pid);
        }

        if ($reverse) {
            $ids = array_reverse($ids);
        }

        return $ids;
    }

    /**
     * Alle Seiten bekommen
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, QUI\Interfaces\Projects\Site>|int
     *
     * @throws QUI\Database\Exception
     */
    public function getSites(array $params = []): array|int
    {
        // Falls kein Query dann alle Seiten hohlen
        // @notice - Kann performancefressend sein
        $s = $this->getSitesIds($params);

        if (empty($s)) {
            return [];
        }

        if (isset($params['count'])) {
            if (isset($s[0]['count'])) {
                return $s[0]['count'];
            }

            return 0;
        }

        $sites = [];

        foreach ($s as $site_id) {
            try {
                $sites[] = $this->get((int)$site_id['id']);
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        return $sites;
    }

    /**
     * Ids von bestimmten Seiten bekommen
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws QUI\Database\Exception
     * @todo Muss mal echt überarbeitet werden, bad code
     */
    public function getSitesIds(array $params = []): array
    {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $table = $Platform->quoteSingleIdentifier($this->table());

        $QueryBuilder->from($table);

        if (empty($params)) {
            return $QueryBuilder
                ->select($Platform->quoteSingleIdentifier("id"))
                ->executeQuery()
                ->fetchAllAssociative();
        }

        $order = "order_field";

        if (isset($params["order"])) {
            switch ($params["order"]) {
                case "name ASC":
                case "name DESC":
                case "title ASC":
                case "title DESC":
                case "c_date ASC":
                case "c_date DESC":
                case "e_date ASC":
                case "e_date DESC":
                case "deleted_at ASC":
                case "deleted_at DESC":
                case "release_from ASC":
                case "release_from DESC":
                    $order = $params["order"];
                    break;

                case "manuell":
                default:
                    $order = "order_field";
                    break;
            }
        }

        $where = $params["where"] ?? [];

        if (is_array($where) && !isset($where["active"])) {
            $where["active"] = 1;
        } elseif (isset($where["active"]) && $where["active"] == -1) {
            unset($where["active"]);
        } elseif (is_string($where)) {
            $where .= " AND active = 1";
        }

        if (is_array($where) && !isset($where["deleted"])) {
            $where["deleted"] = 0;
        } elseif (isset($where["deleted"]) && $where["deleted"] == -1) {
            unset($where["deleted"]);
        } elseif (is_string($where)) {
            $where .= " AND deleted = 0";
        }

        if (isset($params["count"])) {
            $QueryBuilder->select("COUNT(" . $Platform->quoteSingleIdentifier("id") . ") AS " . $Platform->quoteSingleIdentifier("count"));
        } else {
            $QueryBuilder->select($Platform->quoteSingleIdentifier("id"));
        }

        if (is_string($where) && $where !== "") {
            $QueryBuilder->andWhere($where);
        }

        if (is_array($where)) {
            self::applySiteConditions($QueryBuilder, $where, "andWhere");
        }

        if (isset($params["where_or"]) && is_array($params["where_or"])) {
            self::applySiteConditions($QueryBuilder, $params["where_or"], "orWhere");
        }

        QUI\Utils\Doctrine::applyLimit($QueryBuilder, $params["limit"] ?? null);

        if (!isset($params["count"])) {
            $orderParts = explode(" ", $order, 2);
            $orderDirection = isset($orderParts[1]) && $orderParts[1] === "DESC" ? "DESC" : "ASC";
            $QueryBuilder->orderBy($Platform->quoteSingleIdentifier($orderParts[0]), $orderDirection);
        }

        if (isset($params["debug"])) {
            QUI\System\Log::writeRecursive($QueryBuilder->getSQL());
        }

        return $QueryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $conditions
     * @param array<string, string> $tableAliases
     */
    private static function applySiteConditions(
        \Doctrine\DBAL\Query\QueryBuilder $QueryBuilder,
        array $conditions,
        string $method,
        ?string $defaultAlias = null,
        array $tableAliases = []
    ): void {
        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $index = 0;

        foreach ($conditions as $field => $data) {
            $parameter = "condition" . $method . $index;
            $fieldName = (string)$field;
            $alias = $defaultAlias;
            $fieldParts = explode(".", (string)$field, 2);

            if (isset($fieldParts[1]) && ($defaultAlias !== null || isset($tableAliases[$fieldParts[0]]))) {
                $fieldName = $fieldParts[1];

                if (isset($tableAliases[$fieldParts[0]])) {
                    $alias = $tableAliases[$fieldParts[0]];
                }
            }

            $column = $Platform->quoteSingleIdentifier($fieldName);

            if ($alias !== null) {
                $column = $alias . "." . $column;
            }

            if (is_array($data)) {
                $type = strtoupper((string)($data["type"] ?? ""));
                $value = $data["value"] ?? null;

                if ($type === "" && self::isListArray($data)) {
                    $type = "IN";
                    $value = $data;
                }

                if (($type === "IN" || $type === "NOT IN") && is_array($value)) {
                    $placeholders = [];

                    foreach ($value as $valueIndex => $entry) {
                        $entryParameter = $parameter . "_" . $valueIndex;
                        $placeholders[] = ":" . $entryParameter;
                        $QueryBuilder->setParameter($entryParameter, $entry);
                    }

                    if (!empty($placeholders)) {
                        $operator = $type === "NOT IN" ? " NOT IN " : " IN ";
                        $QueryBuilder->{$method}($column . $operator . "(" . implode(",", $placeholders) . ")");
                    }

                    $index++;
                    continue;
                }

                if (is_array($value)) {
                    QUI\System\Log::addError("Unsupported project site query condition with array value skipped.", [
                        "caller" => __METHOD__,
                        "field" => (string)$field,
                        "type" => $type,
                        "condition" => $data,
                        "method" => $method
                    ]);

                    $index++;
                    continue;
                }

                if ($type === "NOT" || $type === "!=" || $type === "<>") {
                    $QueryBuilder->{$method}($column . " <> :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value);
                    $index++;
                    continue;
                }

                if (in_array($type, [">", ">=", "<", "<="], true)) {
                    $QueryBuilder->{$method}($column . " " . $type . " :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value);
                    $index++;
                    continue;
                }

                if ($type === "LIKE") {
                    $QueryBuilder->{$method}($column . " LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value);
                    $index++;
                    continue;
                }

                if ($type === "%LIKE%") {
                    $QueryBuilder->{$method}($column . " LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, "%" . $value . "%");
                    $index++;
                    continue;
                }

                if ($type === "LIKE%") {
                    $QueryBuilder->{$method}($column . " LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value . "%");
                    $index++;
                    continue;
                }

                if ($type === "%LIKE") {
                    $QueryBuilder->{$method}($column . " LIKE :" . $parameter);
                    $QueryBuilder->setParameter($parameter, "%" . $value);
                    $index++;
                    continue;
                }

                if (array_key_exists("value", $data)) {
                    $QueryBuilder->{$method}($column . " = :" . $parameter);
                    $QueryBuilder->setParameter($parameter, $value);
                    $index++;
                    continue;
                }

                QUI\System\Log::addError("Unsupported project site query condition skipped.", [
                    "caller" => __METHOD__,
                    "field" => (string)$field,
                    "condition" => $data,
                    "method" => $method
                ]);

                $index++;
                continue;
            }

            $QueryBuilder->{$method}($column . " = :" . $parameter);
            $QueryBuilder->setParameter($parameter, $data);
            $index++;
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function isListArray(array $data): bool
    {
        $index = 0;

        foreach (array_keys($data) as $key) {
            if ($key !== $index) {
                return false;
            }

            $index++;
        }

        return true;
    }


    private static function ensureMultilingualTable(string $tableName): void
    {
        $SchemaManager = QUI::getSchemaManager();

        if ($SchemaManager->tablesExist([$tableName])) {
            return;
        }

        $Table = new \Doctrine\DBAL\Schema\Table($tableName);
        self::addUtf8Options($Table);
        $Table->addColumn("id", "bigint", ["autoincrement" => true]);
        $Table->setPrimaryKey(["id"]);

        $SchemaManager->createTable($Table);
    }

    private static function ensureMultilingualColumn(string $tableName, string $lang): void
    {
        $SchemaManager = QUI::getSchemaManager();
        $Table = $SchemaManager->introspectTable($tableName);

        if ($Table->hasColumn($lang)) {
            return;
        }

        $Column = new \Doctrine\DBAL\Schema\Column($lang, \Doctrine\DBAL\Types\Type::getType("bigint"), [
            "notnull" => false
        ]);

        $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: [$Column]));
    }

    private static function ensureSitesTable(string $tableName): bool
    {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            $Table = new \Doctrine\DBAL\Schema\Table($tableName);
            self::addUtf8Options($Table);
            self::addSitesColumns($Table);
            $Table->setPrimaryKey(["id"]);
            self::addSitesIndexes($Table);
            $SchemaManager->createTable($Table);
            return true;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $addedColumns = [];
        $columns = self::getSitesColumnDefinitions();

        foreach ($columns as $name => $definition) {
            if ($Table->hasColumn($name)) {
                continue;
            }

            $addedColumns[] = new \Doctrine\DBAL\Schema\Column(
                $name,
                \Doctrine\DBAL\Types\Type::getType($definition["type"]),
                $definition["options"]
            );
        }

        if (!empty($addedColumns)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: $addedColumns));
            $Table = $SchemaManager->introspectTable($tableName);
        }

        $TargetTable = clone $Table;
        self::addMissingSingleColumnIndexes(
            $TargetTable,
            ["name", "active", "deleted", "deleted_at", "order_field", "type", "c_date", "e_date"]
        );
        $Diff = $SchemaManager->createComparator()->compareTables($Table, $TargetTable);

        if (!$Diff->isEmpty()) {
            $SchemaManager->alterTable($Diff);
        }

        return false;
    }

    private static function ensureSitesRelationTable(string $tableName): void
    {
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            $Table = new \Doctrine\DBAL\Schema\Table($tableName);
            self::addUtf8Options($Table);
            $Table->addColumn("parent", "bigint", ["notnull" => false]);
            $Table->addColumn("child", "bigint", ["notnull" => false]);
            $Table->addColumn("oparent", "bigint", ["notnull" => false]);
            self::addMissingSingleColumnIndexes($Table, ["parent", "child"]);
            $SchemaManager->createTable($Table);
            return;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $addedColumns = [];

        foreach (["parent", "child", "oparent"] as $columnName) {
            if (!$Table->hasColumn($columnName)) {
                $addedColumns[] = new \Doctrine\DBAL\Schema\Column(
                    $columnName,
                    \Doctrine\DBAL\Types\Type::getType("bigint"),
                    ["notnull" => false]
                );
            }
        }

        if (!empty($addedColumns)) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff($Table, addedColumns: $addedColumns));
            $Table = $SchemaManager->introspectTable($tableName);
        }

        $TargetTable = clone $Table;
        self::addMissingSingleColumnIndexes($TargetTable, ["parent", "child"]);
        $Diff = $SchemaManager->createComparator()->compareTables($Table, $TargetTable);

        if (!$Diff->isEmpty()) {
            $SchemaManager->alterTable($Diff);
        }
    }

    private static function addSitesColumns(\Doctrine\DBAL\Schema\Table $Table): void
    {
        foreach (self::getSitesColumnDefinitions() as $name => $definition) {
            $Table->addColumn($name, $definition["type"], $definition["options"]);
        }
    }

    private static function addSitesIndexes(\Doctrine\DBAL\Schema\Table $Table): void
    {
        self::addMissingSingleColumnIndexes(
            $Table,
            ["name", "active", "deleted", "deleted_at", "order_field", "type", "c_date", "e_date"]
        );
    }

    /**
     * @param list<string> $columnNames
     */
    private static function addMissingSingleColumnIndexes(
        \Doctrine\DBAL\Schema\Table $Table,
        array $columnNames
    ): void {
        foreach ($columnNames as $columnName) {
            if (self::hasSingleColumnIndex($Table, $columnName)) {
                continue;
            }

            $Table->addIndex([$columnName]);
        }
    }

    private static function hasSingleColumnIndex(
        \Doctrine\DBAL\Schema\Table $Table,
        string $columnName
    ): bool {
        $columnName = strtolower(str_replace(['`', '"', '[', ']'], '', $columnName));

        foreach ($Table->getIndexes() as $Index) {
            $indexedColumns = $Index->getIndexedColumns();

            if (count($indexedColumns) !== 1) {
                continue;
            }

            $indexedColumnName = strtolower(
                $indexedColumns[0]->getColumnName()->getIdentifier()->getValue()
            );

            if ($indexedColumnName === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function getSitesColumnDefinitions(): array
    {
        return [
            "id" => ["type" => "bigint", "options" => ["autoincrement" => true]],
            "name" => ["type" => "string", "options" => ["length" => 255]],
            "title" => ["type" => "text", "options" => ["notnull" => false]],
            "short" => ["type" => "text", "options" => ["notnull" => false]],
            "content" => ["type" => "text", "options" => ["notnull" => false]],
            "type" => ["type" => "string", "options" => ["length" => 255, "notnull" => false]],
            "layout" => ["type" => "string", "options" => ["length" => 255, "notnull" => false]],
            "active" => ["type" => "smallint", "options" => ["default" => 0]],
            "deleted" => ["type" => "smallint", "options" => ["default" => 0]],
            "deleted_at" => ["type" => "datetime", "options" => ["notnull" => false]],
            "c_date" => ["type" => "datetime", "options" => ["notnull" => false]],
            "e_date" => ["type" => "datetime", "options" => ["notnull" => false]],
            "c_user" => ["type" => "string", "options" => ["length" => 50, "notnull" => false]],
            "e_user" => ["type" => "string", "options" => ["length" => 50, "notnull" => false]],
            "nav_hide" => ["type" => "smallint", "options" => ["default" => 0]],
            "order_type" => ["type" => "string", "options" => ["length" => 255, "notnull" => false]],
            "order_field" => ["type" => "bigint", "options" => ["notnull" => false]],
            "extra" => ["type" => "text", "options" => ["notnull" => false]],
            "c_user_ip" => ["type" => "string", "options" => ["length" => 40, "notnull" => false]],
            "image_emotion" => ["type" => "text", "options" => ["notnull" => false]],
            "image_site" => ["type" => "text", "options" => ["notnull" => false]],
            "release_from" => ["type" => "datetime", "options" => ["notnull" => false]],
            "release_to" => ["type" => "datetime", "options" => ["notnull" => false]],
            "auto_release" => ["type" => "smallint", "options" => ["default" => 0]]
        ];
    }

    private static function addUtf8Options(\Doctrine\DBAL\Schema\Table $Table): void
    {
        $Table->addOption("charset", "utf8mb4");
        $Table->addOption("collation", "utf8mb4_general_ci");
    }

    private static function clearInvalidReleaseDates(\Doctrine\DBAL\Connection $Connection, string $table): void
    {
        $Platform = $Connection->getDatabasePlatform();
        $quotedTable = $Platform->quoteSingleIdentifier($table);

        try {
            foreach (["release_from", "release_to"] as $field) {
                $quotedField = $Platform->quoteSingleIdentifier($field);
                $Connection->executeStatement(
                    "UPDATE " . $quotedTable . " SET " . $quotedField . " = NULL WHERE " . $quotedField . " = :invalidDate",
                    ["invalidDate" => "0000-00-00 00:00:00"]
                );
            }
        } catch (\Doctrine\DBAL\Exception) {
        }
    }


    /**
     * Execute the project setup
     *
     * @param array<string, mixed> $setupOptions - options for the package setup
     *                              -> [executePackagesSetup => true]
     *
     * @throws Exception
     * @throws QUI\Exception
     * @throws QUI\ExceptionStack
     * @throws QUI\DataBase\Exception
     */
    public function setup(array $setupOptions = []): void
    {
        if (!isset($setupOptions['executePackagesSetup'])) {
            $setupOptions['executePackagesSetup'] = true;
        }


        QUI::getEvents()->fireEvent('projectSetupBegin', [$this]);

        $Connection = QUI::getDataBaseConnection();
        $User = QUI::getUserBySession();

        // multi lingual table
        $multiLingualTable = QUI_DB_PRFX . $this->name . '_multilingual';

        self::ensureMultilingualTable($multiLingualTable);


        foreach ($this->langs as $lang) {
            $table = QUI_DB_PRFX . $this->name . '_' . $lang . '_sites';

            $sitesTableCreated = self::ensureSitesTable($table);
            self::clearInvalidReleaseDates($Connection, $table);

            // create first site -> id 1 if not exist
            $firstChildExists = (bool)$Connection->createQueryBuilder()
                ->select('1')
                ->from($Connection->getDatabasePlatform()->quoteSingleIdentifier($table))
                ->where($Connection->getDatabasePlatform()->quoteSingleIdentifier('id') . ' = :id')
                ->setParameter('id', 1)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if (!$firstChildExists) {
                $creationDate = date('Y-m-d H:i:s');

                $firstSiteData = [
                    'active' => 1,
                    'deleted' => 0,
                    'name' => 'start',
                    'title' => 'Start',
                    'type' => 'standard',
                    'c_date' => $creationDate,
                    'e_date' => $creationDate,
                    'c_user' => $User->getUUID(),
                    'c_user_ip' => QUI\Utils\System::getClientIP()
                ];

                // Let auto-increment assign ID 1 and advance a fresh PostgreSQL sequence.
                if (!$sitesTableCreated) {
                    $firstSiteData['id'] = 1;
                }

                $Connection->insert($table, $firstSiteData);
            }

            // Beziehungen
            $table = QUI_DB_PRFX . $this->name . '_' . $lang . '_sites_relations';

            self::ensureSitesRelationTable($table);

            // multilingual field
            self::ensureMultilingualColumn($multiLingualTable, $lang);

            // Translation Setup
            QUI\Translator::addLang($lang);
        }

        // Media Setup
        $this->getMedia()->setup();

        // read xml files
        $dir = USR_DIR . $this->name . '/';

        // @todo only for project
        QUI\Update::importDatabase($dir . 'database.xml');
        QUI\Update::importTemplateEngines($dir . 'engines.xml');
        QUI\Update::importEditors($dir . 'wysiwyg.xml');
        QUI\Update::importMenu($dir . 'menu.xml');
        QUI\Update::importPermissions(
            $dir . 'permissions.xml',
            'project/' . $this->name
        );

        QUI\Update::importEvents($dir . 'events.xml');
        QUI\Update::importMenu($dir . 'menu.xml');

        // translations project names etc.
        $translationGroup = 'project/' . $this->getName();
        $translationVar = 'title';

        $translation = QUI\Translator::get($translationGroup, $translationVar);

        if (!isset($translation[0])) {
            QUI\Translator::add($translationGroup, $translationVar);
        }

        // set default settings and current settings
        QUI\Cache\Manager::clear(
            $this->getCachePath()
        );

        $defaults = QUI\Projects\Manager::getProjectConfigList($this);
        $Config = Manager::getConfig();
        $projects = $Config->toArray();
        $config = [];

        if (isset($projects[$this->getName()])) {
            $config = $projects[$this->getName()];
        }

        foreach ($defaults as $key => $value) {
            if (!isset($config[$key])) {
                $value = QUI\Utils\Security\Orthos::removeHTML($value);
                $value = QUI\Utils\Security\Orthos::clearPath($value);

                $Config->setValue($this->getName(), $key, $value);
            }
        }

        $Config->save();

        $this->refresh();

        if (!empty($setupOptions['executePackagesSetup'])) {
            QUI\Setup::executeEachPackageSetup();
        }


        QUI::getEvents()->fireEvent('projectSetupEnd', [$this]);
    }

    /**
     * Return the media object from the project
     */
    public function getMedia(): Media
    {
        if ($this->Media === null) {
            $this->Media = new QUI\Projects\Media($this);
        }

        return $this->Media;
    }

    /**
     * Set the last edit date in the project
     */
    public function setEditDate(int $date): void
    {
        try {
            QUI\Cache\Manager::set(
                $this->getEDateCacheName(),
                $date
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }
    }

    /**
     * Set custom CSS for the project -> set it to the custom.css file
     *
     * @param string $css - CSS Data
     *
     * @throws QUI\Exception
     */
    public function setCustomCSS(string $css): void
    {
        Permission::checkProjectPermission(
            'quiqqer.projects.editCustomCSS',
            $this
        );

        $file = USR_DIR . $this->getName() . '/bin/custom.css';

        QUI\Utils\System\File::mkfile($file);

        if (!is_writable($file)) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.custom.css.is.not.writeable',
                ['file' => $file]
            ]);
        }

        file_put_contents($file, $css);
    }

    /**
     * Return the custom css for the project
     */
    public function getCustomCSS(): string
    {
        if (file_exists(USR_DIR . $this->getName() . '/bin/custom.css')) {
            return (string)file_get_contents(USR_DIR . $this->getName() . '/bin/custom.css');
        }

        return '';
    }

    /**
     * Set custom CSS for the project -> set it to the custom.css file
     *
     * @param string $javascript - CSS Data
     *
     * @throws QUI\Exception
     */
    public function setCustomJavaScript(string $javascript): void
    {
        Permission::checkProjectPermission(
            'quiqqer.projects.editCustomJS',
            $this
        );

        $file = USR_DIR . $this->getName() . '/bin/custom.js';

        QUI\Utils\System\File::mkfile($file);

        if (!is_writable($file)) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.custom.javascript.is.not.writeable',
                ['file' => $file]
            ]);
        }

        file_put_contents($file, $javascript);
    }

    /**
     * Return the custom js for the project
     */
    public function getCustomJavaScript(): string
    {
        if (file_exists(USR_DIR . $this->getName() . '/bin/custom.js')) {
            return (string)file_get_contents(USR_DIR . $this->getName() . '/bin/custom.js');
        }

        return '';
    }

    /**
     * permissions
     */

    /**
     * Add a user to the project permission
     *
     * @param string $permission - name of the permission
     * @param User $User - User Object
     *
     * @throws QUI\Exception
     */
    public function addUserToPermission(User $User, string $permission): void
    {
        Permission::addUserToProjectPermission($User, $this, $permission);
    }

    /**
     * Add a group to the project permission
     *
     * @param string $permission - name of the permission
     * @param Group $Group - Group Object
     *
     * @throws QUI\Exception
     */
    public function addGroupToPermission(Group $Group, string $permission): void
    {
        Permission::addGroupToProjectPermission($Group, $this, $permission);
    }

    /**
     * Remove the user from the project permission
     *
     * @param string $permission - name of the permission
     * @param User $User - User Object
     *
     * @throws QUI\Exception
     */
    public function removeUserFromPermission(User $User, string $permission): void
    {
        Permission::removeUserFromProjectPermission($User, $this, $permission);
    }

    /**
     * Renames the project
     *
     * @throws QUI\Exception
     */
    public function rename(string $newName): void
    {
        QUI\Utils\Project::validateProjectName($newName);

        // ----------------------------- //
        //              Config           //
        // ----------------------------- //

        // File: etc/projects.ini.php
        $filename = ETC_DIR . "projects.ini.php";
        $content = file_get_contents($filename);

        $content = str_replace('[' . $this->name . ']', '[' . $newName . ']', (string)$content);
        file_put_contents($filename, $content);


        // File: etc/vhosts.ini.php
        $filename = ETC_DIR . "vhosts.ini.php";
        $content = file_get_contents($filename);

        $content = str_replace($this->name, $newName, (string)$content);
        file_put_contents($filename, $content);


        // ----------------------------- //
        //            Database           //
        // ----------------------------- //

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $tables = QUI::getSchemaManager()->listTableNames();

        foreach ($tables as $oldTableName) {
            if (!str_contains($oldTableName . "_", $this->name)) {
                continue;
            }

            $newTableName = str_replace($this->name . "_", $newName . "_", $oldTableName);

            $sql = $Platform->getRenameTableSQL(
                $Platform->quoteSingleIdentifier($oldTableName),
                $Platform->quoteSingleIdentifier($newTableName)
            );

            try {
                $Connection->executeStatement($sql);
            } catch (Exception $Exception) {
                QUI\System\Log::writeRecursive(
                    "Could not rename Table '" . $oldTableName . "': " . $Exception->getMessage()
                );
            }
        }


        // ----------------------------- //
        //              Media           //
        // ----------------------------- //

        $sourceDir = CMS_DIR . "media/sites/" . $this->name;
        $targetDir = CMS_DIR . "media/sites/" . $newName;

        if (is_dir($sourceDir)) {
            QUI\Utils\System\File::move($sourceDir, $targetDir);
        }

        // ----------------------------- //
        //              USR           //
        // ----------------------------- //
        $sourceDir = USR_DIR . $this->name;
        $targetDir = USR_DIR . $newName;

        if (is_dir($sourceDir)) {
            QUI\Utils\System\File::move($sourceDir, $targetDir);
        }

        // ----------------------------- //
        //              Cache           //
        // ----------------------------- //
        QUI\Cache\Manager::clearCompleteQuiqqerCache();

        // ----------------------------- //
        //              Finish           //
        // ----------------------------- //

        QUI::getEvents()->fireEvent("projectRenamed", [
            $this,
            $this->name,
            $newName
        ]);


        $this->TABLE = str_replace($this->name . "_", $newName . "_", $this->TABLE);
        $this->RELTABLE = str_replace($this->name . "_", $newName . "_", $this->RELTABLE);
        $this->RELLANGTABLE = str_replace($this->name . "_", $newName . "_", $this->RELLANGTABLE);

        $this->name = $newName;
    }

    /**
     * Explicitly set the project template for the runtime.
     */
    public function setTemplate(string $template): void
    {
        $this->template = $template;
    }
}
