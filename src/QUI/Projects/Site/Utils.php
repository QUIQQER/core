<?php

/**
 * This file contains \QUI\Projects\Site\Utils
 */

namespace QUI\Projects\Site;

use DOMElement;
use DOMNode;
use DOMXPath;
use QUI;
use QUI\Exception;
use QUI\Interfaces\Projects\Site;
use QUI\Projects;
use QUI\Projects\Project;
use QUI\Utils\DOM;
use QUI\Utils\Security\Orthos;
use QUI\Utils\StringHelper as StringUtils;
use QUI\Utils\Text\XML;

use function array_merge;
use function count;
use function explode;
use function file_exists;
use function function_exists;
use function html_entity_decode;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function method_exists;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function realpath;
use function str_replace;
use function trim;

/**
 * Site Utils - Site Helper
 */
class Utils
{
    /**
     * Prüft ob der Name erlaubt ist
     *
     * @throws Exception
     */
    public static function checkName(string $name): bool
    {
        $length = mb_strlen($name, 'UTF-8');

        if ($length <= 2) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.url.2.signs'),
                701
            );
        }

        if ($length > 200) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.url.200.signs'),
                704
            );
        }

        $signs = '@[.,:;#`!§$%&/?<>\=\'\"\@\_\]\[\+\-]@u';


        // @phpstan-ignore-next-line
        if (QUI\Rewrite::URL_SPACE_CHARACTER === '-') {
            $signs = '@[.,:;#`!§$%&/?<>\=\'\"\@\_\]\[\+]@u';
        }

        // Reject forbidden characters and invalid UTF-8 (preg_match returns false).
        if (preg_match($signs, $name) !== 0) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.url.wrong.signs', [
                    'name' => $name,
                    'signs' => $signs
                ]),
                702
            );
        }

        return true;
    }

    /**
     * Clean a URL -> makes it beautiful
     * unwanted signs will be converted or filtered
     *
     * @param string $url
     * @param QUI\Projects\Project|null $Project - optional, Project clear extension
     *
     * @return string
     */
    public static function clearUrl(string $url, null | QUI\Projects\Project $Project = null): string
    {
        // space separator
        $url = str_replace(QUI\Rewrite::URL_SPACE_CHARACTER, ' ', $url);

        // clear
        $signs = [
            '-',
            '.',
            ',',
            ':',
            ';',
            '#',
            '`',
            '!',
            '§',
            '$',
            '%',
            '&',
            '?',
            '<',
            '>',
            '=',
            '\'',
            '"',
            '@',
            '_',
            ']',
            '[',
            '+',
            '/'
        ];

        $url = str_replace($signs, '', $url);
        //$url = preg_replace('[-.,:;#`!§$%&/?<>\=\'\"\@\_\]\[\+]', '', $url);

        // doppelte leerzeichen löschen
        $url = preg_replace('/([ ]){2,}/', "$1", $url);

        // URL Filter
        if ($Project !== null) {
            $name = $Project->getName();
            $filter = USR_DIR . 'lib/' . $name . '/url.filter.php';
            $func = 'url_filter_' . $name;

            $filter = Orthos::clearPath((string)realpath($filter));

            if (file_exists($filter)) {
                require_once $filter;

                if (function_exists($func)) {
                    $url = $func($url);
                }
            }
        }

        return str_replace(' ', QUI\Rewrite::URL_SPACE_CHARACTER, $url);
    }

    /**
     * Return data table array for the Site Object
     * a list of the extra database and extra attributes for saving the site
     * the extra attributes are all from database.xml files
     *
     * @return array<array-key, mixed>
     */
    public static function getDataListForSite(Projects\Site $Site): array
    {
        $siteType = $Site->getAttribute('type');
        $cache = $Site->getCachePath() . '/xml-database-tables/' . $siteType;

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (Exception) {
        }

        $dbXmlList = self::getDataBaseXMLListForSite($Site);
        $Project = $Site->getProject();
        $name = $Project->getName();
        $lang = $Project->getLang();

        $result = [];

        foreach ($dbXmlList as $dbXml) {
            $Dom = XML::getDomFromXml($dbXml['file']);
            $Path = new DOMXPath($Dom);
            $package = $dbXml['package'];

            $tableList = $Path->query("//database/projects/table");

            if ($tableList === false) {
                continue;
            }

            for ($i = 0, $len = $tableList->length; $i < $len; $i++) {
                $Table = $tableList->item($i);

                if (!($Table instanceof DOMElement)) {
                    continue;
                }

                if ($Table->getAttribute('no-auto-update')) {
                    continue;
                }

                if ($Table->getAttribute('no-project-lang')) {
                    continue;
                }


                // types check
                $types = $Table->getAttribute('site-types');

                if ($types) {
                    $types = explode(',', $types);
                }

                if (!empty($types)) {
                    foreach ($types as $allowedType) {
                        if (!StringUtils::match($allowedType, $siteType)) {
                            continue 2;
                        }
                    }
                }


                $suffix = $Table->getAttribute('name');
                $fields = $Table->getElementsByTagName('field');

                $table = QUI::getDBTableName($name . '_' . $lang . '_' . $suffix);
                $data = [];


                for ($f = 0, $fLen = $fields->length; $f < $fLen; $f++) {
                    $Field = $fields->item($f);

                    if ($Field === null) {
                        continue;
                    }

                    $attribute = trim($Field->nodeValue ?? '');

                    $data[] = $attribute;
                }

                if (empty($data)) {
                    continue;
                }

                $result[] = [
                    'table' => $table,
                    'data' => $data,
                    'package' => $package,
                    'suffix' => $suffix
                ];
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $result);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $result;
    }

    /**
     * Return database.xml list for the Site Object
     *
     * @return array<int, array<string, string>>
     */
    public static function getDataBaseXMLListForSite(Projects\Site $Site): array
    {
        $siteType = $Site->getAttribute('type');
        $cache = $Site->getCachePath() . '/xml-database-list/' . $siteType;

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (Exception) {
        }

        $dbXmlList = QUI::getPackageManager()->getPackageDatabaseXmlList();
        $result = [];

        foreach ($dbXmlList as $package) {
            $file = OPT_DIR . $package . '/database.xml';

            if (!file_exists($file)) {
                continue;
            }

            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);

            $tableList = $Path->query("//database/projects/table");

            if ($tableList === false) {
                continue;
            }

            for ($i = 0, $len = $tableList->length; $i < $len; $i++) {
                $Table = $tableList->item($i);

                if (!($Table instanceof DOMElement)) {
                    continue;
                }

                if ($Table->getAttribute('no-auto-update')) {
                    continue;
                }

                if ($Table->getAttribute('no-project-lang')) {
                    continue;
                }


                // types check
                $types = $Table->getAttribute('site-types');

                if ($types) {
                    $types = explode(',', $types);
                }

                if (!empty($types)) {
                    foreach ($types as $allowedType) {
                        if (!StringUtils::match($allowedType, $siteType)) {
                            continue 2;
                        }
                    }
                }

                // table is ok
                $result[] = [
                    'file' => $file,
                    'package' => $package
                ];
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $result);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $result;
    }

    /**
     * Return database.xml list for the Site Object
     *
     * @return array<array-key, mixed>
     */
    public static function getExtraAttributeListForSite(Projects\Site $Site): array
    {
        $siteType = $Site->getAttribute('type');
        $cache = $Site->getCachePath() . '/xml-database-attributes/' . $siteType;

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (Exception) {
        }


        // global extra attributes
        $siteXmlList = QUI::getPackageManager()->getPackageSiteXmlList();
        $result = [];


        foreach ($siteXmlList as $package) {
            $file = OPT_DIR . $package . '/site.xml';

            if (!file_exists($file)) {
                continue;
            }

            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);
            $attributes = $Path->query('//site/attributes/attribute');

            if ($attributes === false) {
                continue;
            }

            foreach ($attributes as $Attribute) {
                if (!($Attribute instanceof DOMElement)) {
                    continue;
                }

                $result[] = [
                    'attribute' => trim($Attribute->nodeValue ?? ''),
                    'default' => $Attribute->getAttribute('default')
                ];
            }
        }


        // extra type attributes
        $type = explode(':', $siteType);

        if (isset($type[1])) {
            // Query for site type attributes in the original package of the site type
            $exprPackage = '//site/types/type[@type="' . $type[1] . '"]/attributes/attribute';

            $originalPackageSiteXmlFile = OPT_DIR . $type[0] . '/site.xml';

            $Dom = XML::getDomFromXml($originalPackageSiteXmlFile);
            $Path = new DOMXPath($Dom);
            $attributes = $Path->query($exprPackage);

            if ($attributes !== false) {
                foreach ($attributes as $Attribute) {
                    if (!($Attribute instanceof DOMElement)) {
                        continue;
                    }

                    $result[] = [
                        'attribute' => trim($Attribute->nodeValue ?? ''),
                        'default' => $Attribute->getAttribute('default')
                    ];
                }
            }

            // Query for site type attributes in other packages than the original package of the site type
            $exprOtherPackage = '//site/types/type[@type="' . $type[0] . ':' . $type[1] . '"]/attributes/attribute';

            foreach ($siteXmlList as $package) {
                $siteXmlFile = OPT_DIR . $package . '/site.xml';

                if ($siteXmlFile === $originalPackageSiteXmlFile) {
                    continue;
                }

                if (!file_exists($siteXmlFile)) {
                    continue;
                }

                $Dom = XML::getDomFromXml($siteXmlFile);
                $Path = new DOMXPath($Dom);
                $attributes = $Path->query($exprOtherPackage);

                if ($attributes === false) {
                    continue;
                }

                foreach ($attributes as $Attribute) {
                    if (!($Attribute instanceof DOMElement)) {
                        continue;
                    }

                    $result[] = [
                        'attribute' => trim($Attribute->nodeValue ?? ''),
                        'default' => $Attribute->getAttribute('default')
                    ];
                }
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $result);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $result;
    }

    /**
     * Return the extra settings from site.xml`s
     */
    public static function getExtraSettingsForSite(QUI\Interfaces\Projects\Site $Site, string $current = ''): string
    {
        if (empty($current)) {
            $current = QUI::getLocale()->getCurrent();
        }

        if (!method_exists($Site, 'getCachePath')) {
            return '';
        }

        $siteType = $Site->getAttribute('type');
        $cache = $Site->getCachePath() . '/xml-database-settings/' . $current . '/' . $siteType;

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (Exception) {
        }


        // global extra
        $siteXmlList = QUI::getPackageManager()->getPackageSiteXmlList();
        $result = '';

        foreach ($siteXmlList as $package) {
            $file = OPT_DIR . $package . '/site.xml';

            if (!file_exists($file)) {
                continue;
            }

            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);
            $cats = $Path->query("//site/settings/category");

            if ($cats === false) {
                continue;
            }

            foreach ($cats as $Category) {
                if (!$Category instanceof DOMNode) {
                    continue;
                }

                $result .= DOM::parseCategoryToHTML($Category, $current);
            }
        }


        // site type extra xml
        $type = explode(':', $Site->getAttribute('type'));
        $dir = OPT_DIR . $type[0];
        $siteXML = $dir . '/site.xml';

        if (file_exists($siteXML)) {
            $Dom = XML::getDomFromXml($siteXML);
            $Path = new DOMXPath($Dom);

            // type extra
            $cats = $Path->query(
                "//site/types/type[@type='" . $type[1] . "']/settings/category"
            );

            if ($cats !== false) {
                foreach ($cats as $Category) {
                    if (!$Category instanceof DOMNode) {
                        continue;
                    }

                    $result .= DOM::parseCategoryToHTML($Category, $current);
                }
            }
        }

        if (!empty($type[1])) {
            // site type extra xml from OTHER packages
            foreach ($siteXmlList as $package) {
                $file = OPT_DIR . $package . '/site.xml';

                if ($file === $siteXML) {
                    continue;
                }

                if (!file_exists($file)) {
                    continue;
                }

                $Dom = XML::getDomFromXml($file);
                $Path = new DOMXPath($Dom);

                // type extra
                $cats = $Path->query(
                    "//site/types/type[@type='" . $type[0] . ':' . $type[1] . "']/settings/category"
                );

                if ($cats === false) {
                    continue;
                }

                foreach ($cats as $Category) {
                    if (!$Category instanceof DOMNode) {
                        continue;
                    }

                    $result .= DOM::parseCategoryToHTML($Category, $current);
                }
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $result);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $result;
    }

    /**
     * Return the admin site modules from site.xml`s
     *
     * @return bool|array<string, mixed>
     */
    public static function getAdminSiteModulesFromSite(Edit | Projects\Site $Site): bool | array
    {
        $siteType = $Site->getAttribute('type');
        $cache = $Site->getCachePath() . '/xml-admin-modules/' . $siteType;

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (Exception) {
        }

        // site type extra xml
        $type = explode(':', $Site->getAttribute('type'));
        $dir = OPT_DIR . $type[0];
        $siteXML = $dir . '/site.xml';

        $result = [];

        if (file_exists($siteXML)) {
            $Dom = XML::getDomFromXml($siteXML);
            $Path = new DOMXPath($Dom);

            // type extra
            $modules = $Path->query(
                "//site/types/type[@type='" . $type[1] . "']/admin/js"
            );

            if ($modules !== false) {
                foreach ($modules as $Module) {
                    if (!$Module instanceof DOMElement) {
                        continue;
                    }

                    foreach ($Module->attributes as $Attr) {
                        $result['js'][$Attr->nodeName][] = $Attr->nodeValue;
                    }
                }
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $result);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $result;
    }


    /**
     * is the object one of the site objects
     */
    public static function isSiteObject(QUI\Interfaces\Projects\Site $Site): bool
    {
        return $Site instanceof Projects\Site;
    }

    /**
     * Return the site object of the quiqqer site link
     * eq: getSiteByLink( index.php?project=test&lang=de&id=1 )
     *
     * @param string $link - index.php?project=test&lang=de&id=1
     *
     * @return Projects\Site
     *
     * @throws Exception
     */
    public static function getSiteByLink(string $link): Projects\Site
    {
        if (!self::isSiteLink($link)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.not.found'
                ),
                705,
                [
                    'method' => 'getSiteByLink',
                    'class' => 'QUI/Projects/Site/Utils',
                    'link' => $link
                ]
            );
        }

        $parseUrl = parse_url($link);

        if (empty($parseUrl['query'])) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.not.found'
                ),
                705,
                [
                    'method' => 'getSiteByLink',
                    'class' => 'QUI/Projects/Site/Utils',
                    'link' => $link
                ]
            );
        }

        parse_str($parseUrl['query'], $urlQueryParams);

        if (
            !isset(
                $urlQueryParams['project'],
                $urlQueryParams['lang'],
                $urlQueryParams['id']
            )
            || !is_string($urlQueryParams['project'])
            || !is_string($urlQueryParams['lang'])
            || !is_scalar($urlQueryParams['id'])
        ) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.not.found'
                ),
                705,
                [
                    'method' => 'getSiteByLink',
                    'class' => 'QUI/Projects/Site/Utils',
                    'link' => $link
                ]
            );
        }

        $Project = QUI::getProject(
            $urlQueryParams['project'],
            $urlQueryParams['lang']
        );

        return $Project->get((int)$urlQueryParams['id']);
    }

    /**
     * is the link a quiqqer site link?
     * eq: index.php?project=test&lang=de&id=1
     *
     * @param string $link - index.php?project=test&lang=de&id=1
     *
     * @return boolean
     */
    public static function isSiteLink(string $link): bool
    {
        if (!str_contains($link, 'index.php')) {
            return false;
        }

        if (!str_contains($link, 'project=')) {
            return false;
        }

        if (!str_contains($link, 'lang=')) {
            return false;
        }

        if (!str_contains($link, 'id=')) {
            return false;
        }

        return true;
    }

    /**
     * Return a site by an url (relative url)
     *
     * @param string $link
     *
     * @throws Exception
     */
    public static function getSiteByUrl(Project $Project, $link): Projects\Site
    {
        $link = str_replace('.html', '', $link);
        $link = trim($link);
        $link = trim($link, '/');

        $parts = explode('/', $link);

        $Site = $Project->firstChild();

        foreach ($parts as $part) {
            $id = $Site->getChildIdByName($part);
            $Site = $Project->get($id);
        }

        return $Site;
    }

    /**
     * Return sites from a site list
     * site list from controls/projects/project/site/Select
     *
     * @param Project $Project - Project of the sites
     * @param array<array-key, mixed>|string $list - list from controls/projects/project/site/Select
     * @param array<string, mixed> $params - order / sort params
     *
     * @return array<int, mixed>
     *
     * @throws QUI\Database\Exception
     */
    public static function getSitesByInputList(
        Project $Project,
        array | string $list,
        array $params = []
    ): array {
        $limit = 2;
        $order = 'release_from ASC';

        if (isset($params['limit'])) {
            $limit = $params['limit'];
        }

        if (isset($params['order'])) {
            $order = $params['order'];
        }

        if (is_string($list)) {
            $sitetypes = explode(';', $list);
        } else {
            $sitetypes = $list;
        }

        $ids = [];
        $types = [];
        $parents = [];
        $where = $params['where'] ?? [];
        $selectorWhere = [];

        foreach ($sitetypes as $sitetypeEntry) {
            if (is_numeric($sitetypeEntry)) {
                $ids[] = (int)$sitetypeEntry;
                continue;
            }

            if (
                str_starts_with($sitetypeEntry, 'p')
                && !str_contains($sitetypeEntry, '/')
                && !str_contains($sitetypeEntry, ':')
            ) {
                $parents[] = str_replace('p', '', $sitetypeEntry);
                continue;
            }

            $types[] = $sitetypeEntry;
        }

        // query params
        if (!empty($ids)) {
            $selectorWhere['id'] = [
                'type' => 'IN',
                'value' => $ids
            ];
        }

        if (!empty($types)) {
            $selectorWhere['type'] = [
                'type' => 'IN',
                'value' => $types
            ];
        }

        // parents are set
        if (count($parents)) {
            foreach ($parents as $parentId) {
                try {
                    $Parent = $Project->get((int)$parentId);

                    $children = $Parent->getChildrenIds([
                        'order' => $order
                    ]);

                    if (!is_array($children)) {
                        $children = [];
                    }

                    $ids = array_merge($ids, $children);
                } catch (Exception) {
                }
            }

            if (!count($ids)) {
                if (isset($params['count']) && $params['count']) {
                    return [['count' => 0]];
                }

                return [];
            }

            $selectorWhere['id'] = [
                'type' => 'IN',
                'value' => $ids
            ];


            if (isset($params['count']) && $params['count']) {
                return $Project->getSitesIds([
                    'count' => true,
                    'where' => array_merge($where, $selectorWhere)
                ]);
            }

            // by with parents, we use WHERE AND
            $sites = $Project->getSites([
                'where' => array_merge($where, $selectorWhere),
                'limit' => $limit,
                'order' => $order
            ]);

            if (!is_array($sites)) {
                $sites = [];
            }

            return $sites;
        }

        if (isset($params['count']) && $params['count']) {
            if (count($selectorWhere) <= 1) {
                return $Project->getSitesIds([
                    'count' => true,
                    'where' => array_merge($where, $selectorWhere)
                ]);
            }

            return $Project->getSitesIds([
                'count' => true,
                'where' => $where,
                'where_or' => $selectorWhere
            ]);
        }

        if (count($selectorWhere) <= 1) {
            $sites = $Project->getSites([
                'where' => array_merge($where, $selectorWhere),
                'limit' => $limit,
                'order' => $order
            ]);

            if (!is_array($sites)) {
                $sites = [];
            }

            return $sites;
        }

        // by no parents and mixed selectors, we use WHERE OR for the selectors only
        $sites = $Project->getSites([
            'where' => $where,
            'where_or' => $selectorWhere,
            'limit' => $limit,
            'order' => $order
        ]);

        if (!is_array($sites)) {
            $sites = [];
        }

        return $sites;
    }

    /**
     * Return the rewritten link
     * eq: rewriteSiteLink( index.php?project=test&lang=de&id=1 )
     *
     * @param string $link - Project of the sites
     *
     * @return string
     *
     * @throws Exception
     */
    public static function rewriteSiteLink(string $link): string
    {
        if (!self::isSiteLink($link)) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.not.found'
                ),
                705,
                [
                    'method' => 'rewriteSiteLink',
                    'class' => 'QUI/Projects/Site/Utils',
                    'link' => $link
                ]
            );
        }

        $parseUrl = parse_url($link);

        if (empty($parseUrl['query'])) {
            throw new Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.site.not.found'
                ),
                705,
                [
                    'method' => 'rewriteSiteLink',
                    'class' => 'QUI/Projects/Site/Utils',
                    'link' => $link
                ]
            );
        }

        // html_entity_decode because -> &nbsp; in index.php links
        parse_str(html_entity_decode($parseUrl['query']), $urlQueryParams);

        return QUI::getRewrite()->getOutput()->getSiteUrl($urlQueryParams);
    }
}
