<?php

/**
 * This file contains the \QUI\Update class
 */

namespace QUI;

use Composer\Composer;
use Composer\Script\Event;
use DOMElement;
use QUI;
use QUI\System\Log;
use QUI\Utils\System\File as QUIFile;
use QUI\Utils\Text\XML;

use function array_merge;
use function basename;
use function count;
use function define;
use function defined;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function glob;
use function is_dir;
use function str_replace;
use function trim;
use function unlink;

if (!function_exists('glob_recursive')) {
    /**
     * polyfill for glob_recursive
     * Does not support flag GLOB_BRACE
     *
     * @param string $pattern
     *
     * @return array<int, string>
     */
    function glob_recursive($pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags) ?: [];

        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $files = array_merge(
                $files,
                glob_recursive($dir . '/' . basename($pattern), $flags)
            );
        }

        return $files;
    }
}

/**
 * Update from QUIQQER
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * @todo Backup vor dem Einspielen machen
 */
class Update
{
    /**
     * If a plugin / package would be installed via composer
     *
     * @throws Exception
     * @todo implement the installation
     */
    public static function onInstall(Event $Event): void
    {
        $IO = $Event->getIO();

        QUI::load();

        $IO->write('QUI\Update->onInstall');
        $IO->write(CMS_DIR);
    }

    /**
     * If a plugin / package is updated via composer
     *
     * @throws Exception|\Doctrine\DBAL\Exception
     */
    public static function onUpdate(Event $Event): void
    {
        // clear package cache
        QUI::getEvents()->fireEvent('updateBegin');

        $IO = $Event->getIO();
        $Composer = $Event->getComposer();

        if (!defined('ETC_DIR')) {
            define('ETC_DIR', $Composer->getConfig()->get('quiqqer-dir') . 'etc/');
        }

        // load quiqqer
        QUI::load();

        if (!defined('URL_LIB_DIR')) {
            define('URL_LIB_DIR', QUI::conf('globals', 'url_lib_dir'));
        }

        if (!defined('URL_BIN_DIR')) {
            define('URL_BIN_DIR', QUI::conf('globals', 'url_bin_dir'));
        }

        if (!defined('URL_SYS_DIR')) {
            define('URL_SYS_DIR', QUI::conf('globals', 'url_sys_dir'));
        }

        if (!defined('URL_OPT_DIR')) {
            define('URL_OPT_DIR', URL_DIR . str_replace(CMS_DIR, '', OPT_DIR));
        }

        if (!defined('URL_USR_DIR')) {
            define('URL_USR_DIR', URL_DIR . str_replace(CMS_DIR, '', USR_DIR));
        }

        if (!defined('URL_VAR_DIR')) {
            define('URL_VAR_DIR', URL_DIR . str_replace(CMS_DIR, '', VAR_DIR));
        }

        // clear package cache, so we get the newest package data
        QUI\Cache\Manager::clearPackagesCache();


        QUI::getLocale()->setCurrent('en');

        // session table
        QUI::getSession()->setup();

        // rights setup, so we have all important tables
        QUI\Permissions\Manager::setup();

        // WYSIWYG Setup
        QUI\Editor\Manager::setup();

        // Events setup
        QUI\Events\Manager::setup();
        QUI\Events\Manager::clear();

        QUI\Messages\Handler::setup();

        $packages_dir = $Composer->getConfig()->get('vendor-dir');

        if (defined('OPT_DIR')) {
            $packages_dir = OPT_DIR;
        }

        $packages = QUIFile::readDir($packages_dir);

        $IO->write('Start QUIQQER updating ...');

        // first we need all databases
        foreach ($packages as $package) {
            if ($package == 'composer') {
                continue;
            }

            $package_dir = $packages_dir . '/' . $package;
            $list = QUIFile::readDir($package_dir);

            foreach ($list as $sub) {
                if (!is_dir($package_dir . '/' . $sub)) {
                    continue;
                }

                // database setup
                self::importDatabase(
                    $package_dir . '/' . $sub . '/database.xml',
                    $IO
                );
            }
        }

        // Then we need translations
        self::importAllLocaleXMLs($Composer);


        // compile the translations
        // so the new translations are available
        $IO->write('Execute QUIQQER Translator');

        QUI\Translator::create();


        // then we can read the rest xml files
        foreach ($packages as $package) {
            if ($package == 'composer') {
                continue;
            }

            $package_dir = $packages_dir . '/' . $package;
            $list = QUIFile::readDir($package_dir);

            foreach ($list as $sub) {
                if (!is_dir($package_dir . '/' . $sub)) {
                    continue;
                }

                // register template engines, if exist in a package
                self::importTemplateEngines(
                    $package_dir . '/' . $sub . '/engines.xml',
                    $IO
                );

                // register wysiwyg editors
                self::importEditors(
                    $package_dir . '/' . $sub . '/wysiwyg.xml',
                    $IO
                );

                // register menu entries
                self::importMenu(
                    $package_dir . '/' . $sub . '/menu.xml',
                    $IO
                );

                // permissions
                self::importPermissions(
                    $package_dir . '/' . $sub . '/permissions.xml',
                    $sub,
                    $IO
                );

                // events
                self::importEvents(
                    $package_dir . '/' . $sub . '/events.xml',
                    $package . '/' . $sub
                );

                try {
                    $Package = QUI::getPackage($package_dir . '/' . $sub);
                    $Package->clearCache();
                } catch (QUI\Exception) {
                }
            }
        }

        // permissions
        self::importPermissions(
            CMS_DIR . '/admin/permissions.xml',
            'system',
            $IO
        );


        $IO->write('QUIQQER Update finish');

        // quiqqer setup
        $IO->write('Starting QUIQQER setup');

        if (QUI::getUserBySession()->getUUID()) {
            QUI::setup();
            QUI::getTemp()->moveToTemp(VAR_DIR . 'cache/');
            $IO->write('QUIQQER Setup finish');
        } else {
            QUI\Cache\Manager::clearCompleteQuiqqerCache();
            QUI\Cache\Manager::longTimeCacheClearCompleteQuiqqer();
            $IO->write('Maybe some Databases or Plugins need a setup. Please log in and execute the setup.');
        }

        QUI::getEvents()->fireEvent('updateEnd');
    }

    /**
     * Database setup
     * Reads the database.xml and create the tables
     *
     * @param string $xml_file - path to a database.xml
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     *
     * @throws QUI\Exception
     * @throws \Exception
     */
    public static function importDatabase(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        XML::importDataBaseFromXml($xml_file);
    }

    /**
     * Importation from all locale.xml files
     *
     * @param Composer|null $Composer - optional
     *
     * @throws QUI\Exception
     */
    public static function importAllLocaleXMLs(null | Composer $Composer = null): void
    {
        $packages_dir = false;

        if ($Composer) {
            $packages_dir = $Composer->getConfig()->get('vendor-dir');
        }

        if (defined('OPT_DIR')) {
            $packages_dir = OPT_DIR;
        }

        if (!$packages_dir) {
            throw new QUI\Exception(
                'Could not import menu.xml. Package-Dir not found'
            );
        }

        $packages = QUIFile::readDir($packages_dir);

        foreach ($packages as $package) {
            if ($package == 'composer') {
                continue;
            }

            $package_dir = $packages_dir . '/' . $package;
            $list = QUIFile::readDir($package_dir);

            foreach ($list as $sub) {
                if (!is_dir($package_dir . '/' . $sub)) {
                    continue;
                }

                // Include referenced catalogs and retain package ownership before publication.
                QUI\Translator::importFromPackage(
                    QUI::getPackage($package . '/' . $sub),
                    1,
                    true,
                    true
                );
            }
        }

        // projects
        $projects = QUI::getProjectManager()->getProjects();

        foreach ($projects as $project) {
            // locale setup
            self::importLocale(
                USR_DIR . $project . '/locale.xml'
            );
        }

        // system xmls
        $File = new QUIFile();
        $locale_dir = CMS_DIR . 'admin/locale/';
        $locales = $File->readDirRecursiv($locale_dir, true);

        foreach ($locales as $locale) {
            self::importLocale($locale_dir . $locale);
        }


        // javascript
        $list = QUI\Utils\System\File::find(BIN_DIR . 'QUI/', '*.xml');

        foreach ($list as $file) {
            self::importLocale(trim($file));
        }

        // lib
        $list = QUI\Utils\System\File::find(LIB_DIR . 'xml/locale/', '*.xml');

        foreach ($list as $file) {
            self::importLocale(trim($file));
        }

        // admin templates
        $list = QUI\Utils\System\File::find(SYS_DIR . 'template/', '*.xml');

        foreach ($list as $file) {
            self::importLocale(trim($file));
        }
    }

    /**
     * Locale setup - translations
     * Reads the locale.xml and import it
     *
     * @param string $xml_file - path to a locale.xml
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     *
     * @throws QUI\Exception
     */
    public static function importLocale(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        QUI\Translator::import($xml_file, true, true);
    }

    /**
     * Import / register the template engines in a xml file and register it
     *
     * @param string $xml_file - path to an engine.xml
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     *
     * @throws QUI\Exception
     */
    public static function importTemplateEngines(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        $engines = XML::getTemplateEnginesFromXml($xml_file);

        foreach ($engines as $Engine) {
            if (!($Engine instanceof DOMElement)) {
                continue;
            }

            if (!$Engine->getAttribute('class_name')) {
                continue;
            }

            if (empty($Engine->nodeValue)) {
                continue;
            }

            QUI::getTemplateManager()->registerEngine(
                trim($Engine->nodeValue),
                $Engine->getAttribute('class_name')
            );
        }
    }

    /**
     * Import / register the wysiwyg editors
     *
     * @param string $xml_file - path to an engine.xml
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     * @throws Exception
     */
    public static function importEditors(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        $editors = QUI\Editor\Utils::getWysiwygEditorDefinitionsFromXml($xml_file);

        foreach ($editors as $Editor) {
            if (empty($Editor['component']) || empty($Editor['name'])) {
                continue;
            }

            QUI\Editor\Manager::registerEditor(
                $Editor['name'],
                $Editor['component']
            );
        }
    }

    /**
     * Import / register the menu items
     * it creates a cache file for the package
     *
     * @param string $xml_file - path to an engine.xml
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     */
    public static function importMenu(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        $items = XML::getMenuItemsXml($xml_file);

        if (!count($items)) {
            return;
        }

        $file = str_replace(
            [CMS_DIR, '/'],
            ['', '_'],
            $xml_file
        );

        $dir = VAR_DIR . 'cache/menu/';
        $cachfile = $dir . $file;

        QUIFile::mkdir($dir);

        if (file_exists($cachfile)) {
            unlink($cachfile);
        }

        file_put_contents($cachfile, file_get_contents($xml_file));
    }

    /**
     * Permissions import
     * Reads the permissions.xml and import it
     *
     * @param string $xml_file - path to a locale.xml
     * @param string $src - Source for the permissions
     * @param \Composer\IO\IOInterface|null $IO - Composer InputOutput
     */
    public static function importPermissions(string $xml_file, string $src = '', $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        XML::importPermissionsFromXml($xml_file, $src);
    }

    /**
     * Import / register quiqqer events
     *
     * @param string $xml_file - path to an engine.xml
     * @param string $packageName - optional, Name of the package
     * @throws Exception
     */
    public static function importEvents(string $xml_file, string $packageName = ''): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        $events = XML::getEventsFromXml($xml_file);
        $Events = QUI::getEvents();

        foreach ($events as $Event) {
            /* @var $Event DOMElement */
            if (!$Event->getAttribute('on')) {
                continue;
            }

            if (!$Event->getAttribute('fire')) {
                continue;
            }

            $priority = 10;

            if ($Event->getAttribute('priority')) {
                $priority = (int)$Event->getAttribute('priority');
            }

            $Events->addEvent(
                $Event->getAttribute('on'),
                $Event->getAttribute('fire'),
                $priority,
                $packageName
            );
        }
    }

    /**
     * Import / register quiqqer site events
     *
     * @param string $xml_file - path to an engine.xml
     * @param \Composer\IO\IOInterface|null $IO - (optional)  Composer InputOutput
     * @throws Exception
     */
    public static function importSiteEvents(string $xml_file, $IO = null): void
    {
        if (!file_exists($xml_file)) {
            return;
        }

        Log::addDebug('Read: ' . $xml_file);

        $events = XML::getSiteEventsFromXml($xml_file);
        $Events = QUI::getEvents();

        foreach ($events as $event) {
            $Events->addSiteEvent($event['on'], $event['fire'], $event['type']);
        }
    }

    /**
     * Importation from all menu.xml files
     * Read all packages and import the menu.xml files to the quiqqer system
     *
     * @param Composer|null $Composer - optional
     *
     * @throws QUI\Exception
     * @deprecated
     */
    public static function importAllMenuXMLs(null | Composer $Composer = null): void
    {
        $packages_dir = false;

        if ($Composer) {
            $packages_dir = $Composer->getConfig()->get('vendor-dir');
        }

        if (defined('OPT_DIR')) {
            $packages_dir = OPT_DIR;
        }

        if (!$packages_dir) {
            throw new QUI\Exception(
                'Could not import menu.xml. Package-Dir not found'
            );
        }

        $packages = QUIFile::readDir(OPT_DIR);

        // then we can read the rest xml files
        foreach ($packages as $package) {
            if ($package == 'composer') {
                continue;
            }

            $Package = QUI::getPackage($package);

            if (!$Package->hasPermission()) {
                continue;
            }

            // @todo in Paket Klasse integrieren
            $package_dir = OPT_DIR . '/' . $package;
            $list = QUIFile::readDir($package_dir);

            foreach ($list as $sub) {
                if (!is_dir($package_dir . '/' . $sub)) {
                    continue;
                }

                // register menu entries
                self::importMenu(
                    $package_dir . '/' . $sub . '/menu.xml'
                );
            }
        }
    }

    /**
     * Importation from all permissions.xml files
     * Read all packages and import the permissions.xml files to the quiqqer system
     */
    public static function importAllPermissionsXMLs(): void
    {
        $packages = QUIFile::readDir(OPT_DIR);

        // clear system permissions
        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->delete(QUI\Permissions\Manager::table())
            ->where($QueryBuilder->expr()->neq('src', ':src'))
            ->setParameter('src', 'user')
            ->executeStatement();

        QUI::$Rights = null; // so we have no permission cache


        self::importPermissions(
            CMS_DIR . '/admin/permissions.xml',
            'system'
        );

        foreach ($packages as $package) {
            if ($package == 'composer') {
                continue;
            }

            $package_dir = OPT_DIR . '/' . $package;
            $list = QUIFile::readDir($package_dir);

            foreach ($list as $sub) {
                if (!is_dir($package_dir . '/' . $sub)) {
                    continue;
                }

                // register permissions entries
                self::importPermissions(
                    $package_dir . '/' . $sub . '/permissions.xml',
                    $sub
                );
            }
        }
    }

    /**
     * Returns the current update log file
     */
    public static function getLogFile(): string
    {
        return VAR_DIR . 'log/error' . date('-Y-m-d') . '.log';
    }
}
