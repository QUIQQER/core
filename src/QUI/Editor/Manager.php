<?php

/**
 * This file contains \QUI\Editor\Manager
 */

namespace QUI\Editor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use QUI;
use QUI\Config;
use QUI\Projects\Project;
use QUI\Utils\Security\Orthos;
use QUI\Utils\System\File;
use QUI\Utils\System\File as QUIFile;
use QUI\Utils\Text\XML;
use Tidy;

use function array_filter;
use function array_flip;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_numeric;
use function json_decode;
use function json_last_error;
use function ksort;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function md5;
use function method_exists;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rename;
use function sort;
use function strpos;
use function str_contains;
use function str_ends_with;
use function str_ireplace;
use function str_starts_with;
use function str_replace;
use function substr;
use function trim;
use function unlink;

use const OPT_DIR;
use const URL_DIR;
use const USR_DIR;

/**
 * Wysiwyg manager
 *
 * manages all wysiwyg editors and the settings for them
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Manager
{
    private const CACHE_EDITOR_DEFINITIONS = 'quiqqer/editor/definitions';

    /**
     * WYSIWYG editor config
     */
    public static ?Config $Config = null;

    /**
     * @var array<int, string>|null
     */
    protected static ?array $toolbars = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    protected static ?array $editorDefinitions = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $toolbarDefinitions = null;

    /**
     * Editor plugins
     *
     * @var array<int, object>
     */
    protected array $plugins = [];

    /**
     * Execute the editor setup
     *
     * @return void
     *
     * @throws QUI\Database\Exception
     * @throws QUI\Exception
     */
    public static function setup(): void
    {
        QUIFile::mkdir(self::getToolbarsPath());

        if (!file_exists(CMS_DIR . 'etc/wysiwyg/conf.ini.php')) {
            file_put_contents(CMS_DIR . 'etc/wysiwyg/conf.ini.php', '');
        }

        if (!file_exists(CMS_DIR . 'etc/wysiwyg/editors.ini.php')) {
            file_put_contents(CMS_DIR . 'etc/wysiwyg/editors.ini.php', '');
        }

        // If toolbar path is empty, use default toolbars
        $path = self::getToolbarsPath();

        if (!is_dir($path)) {
            File::mkdir($path);
        }

        // Remove old standard.xml toolbar for compatibility
        if (file_exists($path . "standard.xml")) {
            rename($path . "standard.xml", CMS_DIR . "var/backup/standard.xml");
        }

        self::ensureCustomToolbarDirectories();
    }

    /**
     * Return the path to the toolbars
     */
    public static function getToolbarsPath(): string
    {
        return self::getPath() . 'toolbars/';
    }

    /**
     * Path to the toolbar xml files
     */
    public static function getPath(): string
    {
        return CMS_DIR . 'etc/wysiwyg/';
    }

    /**
     * Register a js editor
     *
     * @param string $name - name of the editor
     * @param string $package - js module/package name
     *
     * @throws QUI\Exception
     */
    public static function registerEditor(string $name, string $package): void
    {
        $Conf = QUI::getConfig('etc/wysiwyg/editors.ini.php');
        $Conf->setValue($name, null, $package);
        $Conf->save();

        self::ensureCustomToolbarDirectory(
            self::getModuleFromEditorPackage($package, $name)
        );
    }

    /**
     * Return all settings of the manager
     *
     * @return array<string, mixed>
     *
     * @throws QUI\Exception
     */
    public static function getConfig(): array
    {
        $config = self::getConf()->toArray();
        $config['toolbars'] = self::getToolbars();
        $config['editors'] = QUI::getConfig('etc/wysiwyg/editors.ini.php')->toArray();

        return $config;
    }

    /**
     * Return the main editor manager (WYSIWYG) config object
     *
     * @throws QUI\Exception
     */
    public static function getConf(): Config
    {
        if (!self::$Config) {
            self::$Config = QUI::getConfig('etc/wysiwyg/conf.ini.php');
        }

        return self::$Config;
    }

    /**
     * Return all available toolbars
     *
     * @return array<int, string>
     */
    public static function getToolbars(): array
    {
        if (self::$toolbars !== null) {
            return self::$toolbars;
        }

        $standardEditor = Manager::getConf()->getValue('settings', 'standard');
        $toolbarDefinitions = self::getToolbarDefinitions();

        $availableToolbars = array_keys(array_filter(
            $toolbarDefinitions,
            static fn(array $toolbarDefinition) => $toolbarDefinition['editor'] === $standardEditor
        ));

        sort($availableToolbars);

        self::$toolbars = $availableToolbars;

        return $availableToolbars;
    }

    /**
     * @param string $search
     *
     * @return array<int, string>
     */
    public static function search($search): array
    {
        return array_filter(self::getToolbars(), static function ($toolbar) use ($search): bool {
            return str_contains($toolbar, $search);
        });
    }

    /**
     * Return all known toolbar definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getToolbarDefinitions(): array
    {
        if (self::$toolbarDefinitions !== null) {
            return self::$toolbarDefinitions;
        }

        $definitions = [];

        foreach (self::getEditorDefinitions() as $Editor) {
            foreach ($Editor['toolbars'] as $Toolbar) {
                $identifier = $Editor['module'] . ':' . $Toolbar['name'];

                $definitions[$identifier] = [
                    'identifier' => $identifier,
                    'module' => $Editor['module'],
                    'editor' => $Editor['name'],
                    'name' => $Toolbar['name'],
                    'src' => $Toolbar['src'],
                    'path' => $Toolbar['path'],
                    'customPath' => null,
                    'legacyPath' => null
                ];
            }
        }

        foreach (self::getCustomToolbarFiles() as $identifier => $path) {
            if (!isset($definitions[$identifier])) {
                [$module, $toolbar] = explode(':', $identifier, 2);

                $definitions[$identifier] = [
                    'identifier' => $identifier,
                    'module' => $module,
                    'editor' => null,
                    'name' => $toolbar,
                    'src' => null,
                    'path' => null,
                    'customPath' => $path,
                    'legacyPath' => null
                ];

                continue;
            }

            $definitions[$identifier]['customPath'] = $path;
        }

        foreach (self::getLegacyToolbarFiles() as $identifier => $path) {
            if (!isset($definitions[$identifier])) {
                [$module, $toolbar] = explode(':', $identifier, 2);

                $definitions[$identifier] = [
                    'identifier' => $identifier,
                    'module' => $module,
                    'editor' => null,
                    'name' => $toolbar,
                    'src' => null,
                    'path' => null,
                    'customPath' => null,
                    'legacyPath' => $path
                ];

                continue;
            }

            $definitions[$identifier]['legacyPath'] = $path;
        }

        ksort($definitions);

        self::$toolbarDefinitions = $definitions;

        return $definitions;
    }

    /**
     * Return known editor definitions from installed packages.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getEditorDefinitions(): array
    {
        if (self::$editorDefinitions !== null) {
            return self::$editorDefinitions;
        }

        try {
            $cachedDefinitions = QUI\Cache\Manager::get(self::CACHE_EDITOR_DEFINITIONS);

            if (is_array($cachedDefinitions)) {
                self::$editorDefinitions = $cachedDefinitions;

                return $cachedDefinitions;
            }
        } catch (QUI\Exception) {
        }

        $result = [];
        $packages = QUI::getPackageManager()->getInstalled();

        foreach ($packages as $package) {
            if (empty($package['name'])) {
                continue;
            }

            $xmlFile = OPT_DIR . $package['name'] . '/wysiwyg.xml';

            if (!file_exists($xmlFile)) {
                continue;
            }

            $definitions = Utils::getWysiwygEditorDefinitionsFromXml($xmlFile);

            foreach ($definitions as $Editor) {
                if (empty($Editor['component']) || empty($Editor['name'])) {
                    QUI\System\Log::addWarning("Editor Manager: 'wysiwyg.xml' of package '{$package['name']}' is invalid, skipping it.");
                    continue;
                }

                $module = self::getModuleFromEditorPackage($Editor['component'], $package['name']);
                $toolbars = [];

                foreach ($Editor['toolbars'] as $Toolbar) {
                    if (empty($Toolbar['name']) || empty($Toolbar['src'])) {
                        continue;
                    }

                    $toolbars[] = [
                        'name' => $Toolbar['name'],
                        'src' => $Toolbar['src'],
                        'path' => OPT_DIR . $module . '/' . ltrim($Toolbar['src'], '/')
                    ];
                }

                $result[] = [
                    'name' => $Editor['name'],
                    'component' => $Editor['component'],
                    'module' => $module,
                    'toolbars' => $toolbars
                ];
            }
        }

        self::$editorDefinitions = $result;

        if (!empty($result)) {
            QUI\Cache\Manager::set(self::CACHE_EDITOR_DEFINITIONS, $result);
        }

        return $result;
    }

    protected static function getModuleFromEditorPackage(string $package, string $fallback): string
    {
        if (!str_starts_with($package, 'package/')) {
            return $fallback;
        }

        $package = substr($package, 8);
        $pos = strpos($package, '/bin/');

        if ($pos === false) {
            return $fallback;
        }

        return substr($package, 0, $pos);
    }

    protected static function ensureCustomToolbarDirectories(): void
    {
        foreach (self::getEditorDefinitions() as $Editor) {
            self::ensureCustomToolbarDirectory($Editor['module']);
        }
    }

    protected static function ensureCustomToolbarDirectory(string $module): void
    {
        $module = trim($module, '/');

        if (empty($module)) {
            return;
        }

        QUIFile::mkdir(self::getToolbarsPath() . $module . '/');
    }

    /**
     * @return array<string, string>
     */
    protected static function getCustomToolbarFiles(): array
    {
        $result = [];
        $folder = self::getToolbarsPath();

        if (!is_dir($folder)) {
            return $result;
        }

        $Iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($Iterator as $File) {
            if (!$File->isFile()) {
                continue;
            }

            $path = $File->getPathname();
            $relative = str_replace($folder, '', $path);
            $relative = str_replace('\\', '/', $relative);

            if (!is_string($relative)) {
                continue;
            }

            if (!str_contains($relative, '/')) {
                continue;
            }

            $parts = explode('/', $relative);
            $toolbar = array_pop($parts);
            $module = implode('/', $parts);

            if (empty($toolbar)) {
                continue;
            }

            $result[$module . ':' . $toolbar] = $path;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    protected static function getLegacyToolbarFiles(): array
    {
        $result = [];
        $folder = self::getToolbarsPath();

        if (!is_dir($folder)) {
            return $result;
        }

        $files = File::readDir($folder);

        foreach ($files as $file) {
            if (is_dir($folder . $file)) {
                continue;
            }

            if (!str_ends_with($file, '.xml')) {
                continue;
            }

            $toolbar = str_replace('.xml', '', $file);
            $module = self::guessModuleNameForToolbar($toolbar);

            if (empty($module)) {
                continue;
            }

            $result[$module . ':' . $toolbar] = $folder . $file;
        }

        return $result;
    }

    protected static function guessModuleNameForToolbar(string $toolbar): string
    {
        $matches = [];

        foreach (self::getEditorDefinitions() as $Editor) {
            foreach ($Editor['toolbars'] as $Toolbar) {
                if ($Toolbar['name'] === $toolbar) {
                    $matches[] = $Editor['module'];
                }
            }
        }

        $matches = array_values(array_unique($matches));

        if (count($matches) === 1) {
            return $matches[0];
        }

        $modules = array_values(array_unique(array_map(static function ($Editor) {
            return $Editor['module'];
        }, self::getEditorDefinitions())));

        if (count($modules) === 1) {
            return $modules[0];
        }

        return '';
    }

    public static function normalizeToolbarIdentifier(string $toolbar): string
    {
        $toolbar = trim($toolbar);

        if (empty($toolbar)) {
            return '';
        }

        if (str_contains($toolbar, ':')) {
            return $toolbar;
        }

        $toolbar = str_replace('.xml', '', $toolbar);
        $module = self::guessModuleNameForToolbar($toolbar);

        if (empty($module)) {
            return $toolbar;
        }

        return $module . ':' . $toolbar;
    }

    /**
     * Return all available toolbars for a user
     *
     * @return array<int, string>
     */
    public static function getToolbarsFromUser(QUI\Interfaces\Users\User $User): array
    {
        $result = [];
        $groups = $User->getGroups();

        /* @var $Group QUI\Groups\Group */
        foreach ($groups as $Group) {
            if ($Group->getAttribute('assigned_toolbar')) {
                $toolbars = explode(',', $Group->getAttribute('assigned_toolbar'));

                foreach ($toolbars as $toolbar) {
                    $toolbar = self::normalizeToolbarIdentifier($toolbar);

                    if (self::existsToolbar($toolbar)) {
                        $result[] = $toolbar;
                    }
                }
            }
        }

        $userSpecific = $User->getAttribute('assigned_toolbar');

        if ($userSpecific) {
            $userSpecific = explode(',', $userSpecific);

            foreach ($userSpecific as $toolbar) {
                $toolbar = self::normalizeToolbarIdentifier($toolbar);

                if (self::existsToolbar($toolbar)) {
                    $result[] = $toolbar;
                }
            }
        }

        $result = array_unique($result);
        sort($result);

        return $result;
    }

    /**
     * Return all available toolbars for a group
     *
     * @return array<int, string>
     */
    public static function getToolbarsFromGroup(QUI\Groups\Group $Group): array
    {
        $result = [];

        if (
            $Group->getAttribute('toolbar') &&
            self::existsToolbar($Group->getAttribute('toolbar'))
        ) {
            $result[] = self::normalizeToolbarIdentifier($Group->getAttribute('toolbar'));
        }

        $groupSpecific = $Group->getAttribute('assigned_toolbar');

        if ($groupSpecific) {
            $groupSpecific = explode(',', $groupSpecific);

            foreach ($groupSpecific as $toolbar) {
                $toolbar = self::normalizeToolbarIdentifier($toolbar);

                if (self::existsToolbar($toolbar)) {
                    $result[] = $toolbar;
                }
            }
        }

        $result = array_unique($result);
        sort($result);

        return $result;
    }

    /**
     * @param mixed $toolbar
     */
    public static function existsToolbar($toolbar): bool
    {
        $toolbar = self::normalizeToolbarIdentifier((string)$toolbar);
        $toolbars = array_flip(self::getToolbars());

        return isset($toolbars[$toolbar]);
    }

    /**
     * Return the effective toolbar source file for an identifier.
     */
    public static function getToolbarSourceFile(string $toolbar): string|false
    {
        $toolbar = self::normalizeToolbarIdentifier($toolbar);

        if (empty($toolbar)) {
            return false;
        }

        $definitions = self::getToolbarDefinitions();

        if (!isset($definitions[$toolbar])) {
            return false;
        }

        $definition = $definitions[$toolbar];

        if (!empty($definition['customPath']) && file_exists($definition['customPath'])) {
            return $definition['customPath'];
        }

        if (!empty($definition['path']) && file_exists($definition['path'])) {
            return $definition['path'];
        }

        if (!empty($definition['legacyPath']) && file_exists($definition['legacyPath'])) {
            return $definition['legacyPath'];
        }

        return false;
    }

    /**
     * Return toolbar data for an identifier.
     *
     * @return array<array-key, mixed>
     */
    public static function getToolbarData(string $toolbar): array
    {
        $file = self::getToolbarSourceFile($toolbar);

        if ($file === false) {
            return [];
        }

        return self::parseToolbarFile($file);
    }

    /**
     * Return the Editor Settings for a specific Project
     *
     * @return array<string, mixed>
     *
     * @throws QUI\Exception
     */
    public static function getSettings(Project $Project): array
    {
        $project = $Project->getName();
        $cacheName = $Project->getCachePath() . '/wysiwyg-settings';

        try {
            $result = QUI\Cache\Manager::get($cacheName);
            QUI::getEvents()->fireEvent('editorGetSettings', [$Project, &$result]);
            return $result;
        } catch (QUI\Exception) {
        }

        // css files
        $css = [];
        $styles = [];
        $file = USR_DIR . $Project->getName() . '/settings.xml';

        $bodyId = false;
        $bodyClass = false;

        // project files
        if (file_exists($file)) {
            $files = XML::getWysiwygCSSFromXml($file);

            foreach ($files as $cssFile) {
                $css[] = URL_USR_DIR . $project . '/' . $cssFile;
            }

            // id and css class
            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);

            $WYSIWYG = $Path->query("//wysiwyg");

            if ($WYSIWYG !== false && $WYSIWYG->length) {
                $DomElement = $WYSIWYG->item(0);

                if ($DomElement instanceof DOMElement) {
                    $bodyId = $DomElement->getAttribute('id');
                    $bodyClass = $DomElement->getAttribute('class');
                }
            }

            // styles
            $styles = array_merge(
                QUI\Utils\DOM::getWysiwygStyles($Dom),
                $styles
            );
        }

        // template files
        $templates = [];

        if ($Project->getTemplate()) {
            try {
                $Package = QUI::getPackage($Project->getTemplate());
                $templates[] = OPT_DIR . $Package->getName() . '/settings.xml';

                $TemplateParent = $Package->getTemplateParent();

                if ($TemplateParent) {
                    $templates[] = OPT_DIR . $TemplateParent->getName() . '/settings.xml';
                }
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeDebugException($Exception);
            }
        }

        // project vhosts
        $VHosts = new QUI\System\VhostManager();
        $projectHosts = $VHosts->getHostsByProject($Project->getName());

        foreach ($projectHosts as $host) {
            $data = $VHosts->getVhost($host);

            if (!isset($data['template'])) {
                continue;
            }

            if (empty($data['template'])) {
                continue;
            }

            $file = OPT_DIR . $data['template'] . '/settings.xml';

            if (file_exists($file)) {
                $templates[] = $file;
            }
        }

        $templates = array_unique($templates);


        foreach ($templates as $file) {
            self::appendWysiwygSettingsFromXml($file, $css, $styles, $bodyId, $bodyClass);
        }

        // read wysiwyg styles && css files from packages files
        $packages = QUI::getPackageManager()->getInstalled();

        foreach ($packages as $package) {
            if (
                $package['type'] != 'quiqqer-plugin'
                && $package['type'] != 'quiqqer-module'
            ) {
                continue;
            }

            $settings = OPT_DIR . $package['name'] . '/settings.xml';

            if (!file_exists($settings)) {
                continue;
            }

            $Dom = XML::getDomFromXml($settings);

            // styles
            $styles = array_merge(
                QUI\Utils\DOM::getWysiwygStyles($Dom),
                $styles
            );

            // css files
            $cssFiles = XML::getWysiwygCSSFromXml($settings);

            foreach ($cssFiles as $cssFile) {
                // external file
                if (
                    str_starts_with($cssFile, '//')
                    || str_starts_with($cssFile, 'https://')
                    || str_starts_with($cssFile, 'http://')
                ) {
                    $css[] = $cssFile;
                    continue;
                }

                $css[] = QUI\Utils\DOM::parseVar($cssFile);
            }
        }

        // custom css file
        if (file_exists(USR_DIR . $project . '/bin/custom.css')) {
            $css[] = URL_USR_DIR . $project . '/bin/custom.css';
        }

        $result = [
            'cssFiles' => array_values(array_unique($css)),
            'bodyId' => $bodyId,
            'bodyClass' => $bodyClass,
            'styles' => $styles
        ];

        try {
            QUI\Cache\Manager::set($cacheName, $result);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        QUI::getEvents()->fireEvent('editorGetSettings', [$Project, &$result]);
        return $result;
    }

    /**
     * Append wysiwyg settings from a settings.xml file.
     *
     * @param array<array-key, mixed> $css
     * @param array<array-key, mixed> $styles
     */
    private static function appendWysiwygSettingsFromXml(
        string $file,
        array &$css,
        array &$styles,
        bool|string &$bodyId,
        bool|string &$bodyClass
    ): void {
        if (!file_exists($file)) {
            return;
        }

        $cssFiles = XML::getWysiwygCSSFromXml($file);

        foreach ($cssFiles as $cssFile) {
            // external file
            if (
                str_starts_with($cssFile, '//')
                || str_starts_with($cssFile, 'https://')
                || str_starts_with($cssFile, 'http://')
            ) {
                $css[] = $cssFile;
                continue;
            }

            $css[] = QUI\Utils\DOM::parseVar($cssFile);
        }

        $Dom = XML::getDomFromXml($file);

        // styles
        $styles = array_merge(
            QUI\Utils\DOM::getWysiwygStyles($Dom),
            $styles
        );

        // id and css class
        if ($bodyId || $bodyClass) {
            return;
        }

        $Path = new DOMXPath($Dom);
        $WYSIWYG = $Path->query("//wysiwyg");

        if ($WYSIWYG === false || !$WYSIWYG->length) {
            return;
        }

        $DomElement = $WYSIWYG->item(0);

        if ($DomElement instanceof DOMElement) {
            $bodyId = $DomElement->getAttribute('id');
            $bodyClass = $DomElement->getAttribute('class');
        }
    }

    /**
     * Delete a toolbar
     *
     * @param string $toolbar - Name of the tools (toolbar.xml)
     */
    public static function deleteToolbar(string $toolbar): void
    {
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.editors.toolbar.delete'
        );

        $folder = self::getToolbarsPath();
        $path = $folder . $toolbar;

        $path = Orthos::clearPath($path);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Add a new toolbar
     *
     * @param string $toolbar - Name of the tools (myNewToolbar)
     *
     * @throws QUI\Exception
     */
    public static function addToolbar(string $toolbar): void
    {
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.editors.toolbar.add'
        );

        $toolbar = self::normalizeToolbarFileName($toolbar);

        $folder = self::getToolbarsPath();
        $file = $folder . $toolbar . '.xml';

        if (file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.qui.editor.manager.toolbar.exist'
                )
            );
        }

        QUIFile::mkfile($file);
    }

    /**
     * Save the Toolbar
     *
     * @param string $toolbar - toolbar name
     * @param string $xml - toolbar xml
     *
     * @throws QUI\Exception
     */
    public static function saveToolbar(string $toolbar, string $xml): void
    {
        QUI\Permissions\Permission::checkPermission(
            'quiqqer.editors.toolbar.save'
        );

        if (empty($xml)) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.lib.qui.editor.manager.toolbar.empty'
            ]);
        }

        $toolbar = self::normalizeToolbarFileName($toolbar);

        $folder = self::getToolbarsPath();
        $file = $folder . $toolbar . '.xml';

        if (!file_exists($file)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.qui.editor.manager.toolbar.exist'
                )
            );
        }

        // check the xml
        libxml_use_internal_errors(true);

        $Doc = new DOMDocument('1.0', 'utf-8');
        $Doc->loadXML($xml);

        $errors = libxml_get_errors();

        if (!empty($errors)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.lib.qui.editor.manager.toolbar.xml.error',
                    ['error' => $errors[0]->message]
                )
            );
        }

        file_put_contents($file, $xml);

        QUI\Cache\Manager::clear('settings/editor/xml');
    }

    /**
     * Normalize a toolbar file name and reject paths.
     *
     * @throws QUI\Exception
     */
    private static function normalizeToolbarFileName(string $toolbar): string
    {
        $toolbar = str_replace('.xml', '', $toolbar);

        if (
            $toolbar === ''
            || preg_match('/[\x00-\x1F\x7F]/', $toolbar)
            || str_contains($toolbar, '/')
            || str_contains($toolbar, '\\')
        ) {
            throw new QUI\Exception('Invalid toolbar name.', 400);
        }

        return $toolbar;
    }

    /**
     * Return the toolbar buttons for a user
     * Used the right user toolbar
     *
     * @return array<array-key, mixed>
     */
    public static function getToolbarButtonsFromUser(): array
    {
        $Users = QUI::getUsers();
        $User = $Users->getUserBySession();

        if (!$Users->isAuth($User)) {
            return [];
        }

        // user
        $toolbar = self::normalizeToolbarIdentifier($User->getAttribute('toolbar'));

        if (!empty($toolbar)) {
            $data = self::getToolbarData($toolbar);

            if (!empty($data)) {
                return $data;
            }
        }

        // group
        $groups = $User->getGroups();

        /* @var $Group QUI\Groups\Group */
        foreach ($groups as $Group) {
            $toolbar = self::normalizeToolbarIdentifier($Group->getAttribute('toolbar'));

            if (!empty($toolbar)) {
                $data = self::getToolbarData($toolbar);

                if (!empty($data)) {
                    return $data;
                }
            }
        }

        try {
            $Config = self::getConf();
            $toolbar = $Config->get('toolbars', 'standard');
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());

            return [];
        }

        // standard
        if ($toolbar === false) {
            return [];
        }

        if (is_string($toolbar)) {
            $toolbar = self::normalizeToolbarIdentifier($toolbar);
            $data = self::getToolbarData($toolbar);

            if (!empty($data)) {
                return $data;
            }
        }

        return explode(',', $Config->get('toolbars', 'standard'));
    }

    /**
     * Reads a toolbar file and returns it as array.
     *
     * JSON is treated as the preferred editor-native format.
     * Legacy XML toolbars remain readable for compatibility.
     *
     * @return array<array-key, mixed>
     */
    public static function parseToolbarFile(string $file): array
    {
        if (str_ends_with($file, '.xml')) {
            return self::parseXmlFileToArray($file);
        }

        $cache = 'settings/editor/toolbar/' . md5($file);

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return [];
        }

        $result = json_decode(trim($content), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            return [];
        }

        QUI\Cache\Manager::set($cache, $result);

        return $result;
    }

    /**
     * Reads a toolbar xml and return and return it as array
     *
     * @param string $file - path to the file
     *
     * @return array<array-key, mixed>
     */
    public static function parseXmlFileToArray(string $file): array
    {
        $cache = 'settings/editor/xml/' . md5($file);

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $Dom = XML::getDomFromXml($file);
        $toolbar = $Dom->getElementsByTagName('toolbar');

        if (!$toolbar->length) {
            return [];
        }

        $Toolbar = $toolbar->item(0);

        if ($Toolbar === null) {
            return [];
        }

        $children = $Toolbar->childNodes;
        $result = [];

        for ($i = 0; $i < $children->length; $i++) {
            $Param = $children->item($i);

            if ($Param === null) {
                continue;
            }

            if ($Param->nodeName == '#text') {
                continue;
            }

            if ($Param->nodeName == 'line') {
                $result['lines'][] = self::parseXMLLineNode($Param);
            }

            if ($Param->nodeName == 'group') {
                $result['groups'][] = self::parseXMLGroupNode($Param);
            }
        }

        QUI\Cache\Manager::set($cache, $result);

        return $result;
    }

    /**
     * Parse an XML <line> node
     *
     * @return bool|array<array-key, mixed>
     */
    public static function parseXMLLineNode(DOMNode $Node): bool | array
    {
        if ($Node->nodeName !== 'line') {
            return false;
        }

        $children = $Node->childNodes;
        $result = [];

        for ($i = 0; $i < $children->length; $i++) {
            $Param = $children->item($i);

            if ($Param === null) {
                continue;
            }

            if ($Param->nodeName == '#text') {
                continue;
            }

            if ($Param->nodeName == 'group') {
                $result[] = self::parseXMLGroupNode($Param);
            }
        }

        return $result;
    }

    /**
     * Parse an XML <group> node
     *
     * @return bool|array<array-key, mixed>
     */
    public static function parseXMLGroupNode(DOMNode $Node): bool | array
    {
        if ($Node->nodeName !== 'group') {
            return false;
        }

        $children = $Node->childNodes;
        $result = [];

        for ($i = 0; $i < $children->length; $i++) {
            $Param = $children->item($i);

            if ($Param === null) {
                continue;
            }

            if ($Param->nodeName == 'separator') {
                $result[] = [
                    'type' => 'separator'
                ];

                continue;
            }

            if ($Param->nodeName == 'button') {
                $result[] = [
                    'type' => 'button',
                    'button' => trim($Param->nodeValue ?? '')
                ];
            }
        }

        return $result;
    }

    /**
     * Load the html for an editor and clean it up
     */
    public function load(string $html): string
    {
        $html = preg_replace_callback(
            '#(src)="([^"]*)"#',
            $this->cleanAdminSrc(...),
            $html
        ) ?? $html;

        foreach ($this->plugins as $p) {
            if (method_exists($p, 'onLoad')) {
                $html = $p->onLoad($html);
            }
        }

        return $html;
    }

    /**
     * Clean up methods
     */

    /**
     * Prepare html for saving
     * Clean it up
     */
    public function prepareHTMLForSave(string $html): string
    {
        $html = preg_replace_callback(
            '#(src)="([^"]*)"#',
            $this->cleanSrc(...),
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#(href)="([^"]*)"#',
            $this->cleanHref(...),
            $html
        ) ?? $html;

        foreach ($this->plugins as $p) {
            if (method_exists($p, 'onSave')) {
                $html = $p->onSave($html);
            }
        }

        $html = $this->cleanHTML($html);

        // remove line breaks in html
        return preg_replace_callback(
            '#(<)(.*?)(>)#',
            $this->deleteLineBreaksInHtml(...),
            $html
        ) ?? $html;
    }

    /**
     * Cleanup HTML
     */
    public function cleanHTML(string $html): string
    {
        $html = preg_replace('/<!--\[if gte mso.*?-->/s', '', $html) ?? $html;

        $search = [
            'font-family: Arial',
            'class="MsoNormal"'
        ];

        $html = str_ireplace($search, '', $html);

        if (class_exists('tidy')) {
            $Tidy = new Tidy();

            $config = [
                "char-encoding" => "utf8",
                'output-xhtml' => true,
                'indent-attributes' => false,
                'wrap' => 0,
                'word-2000' => 1,
                // html 5 Tags registrieren
                'new-blocklevel-tags' => 'header, footer, article, section, hgroup, nav, figure'
            ];

            $Tidy->parseString($html, $config, 'utf8');
            $Tidy->cleanRepair();
            $cleanedHtml = $Tidy->value;

            if ($cleanedHtml !== null) {
                $html = $cleanedHtml;
            }
        }

        return $html;
    }

    /**
     * Cleanup image src
     *
     * @param array<int, string> $html
     */
    public function cleanSrc(array $html): string
    {
        if (isset($html[2]) && str_contains($html[2], 'image.php')) {
            $html[2] = str_replace('&amp;', '&', $html[2]);
            $src_ = explode('image.php?', $html[2]);

            return ' ' . $html[1] . '="image.php?' . $src_[1] . '"';
        }

        return $html[0];
    }

    /**
     * Cleanup image href
     *
     * @param array<int, string> $html
     */
    public function cleanHref(array $html): string
    {
        if (isset($html[2]) && str_contains($html[2], 'index.php')) {
            $index = explode('index.php?', $html[2]);

            return $html[1] . '="index.php?' . $index[1] . '"';
        }


        if (isset($html[2]) && str_contains($html[2], 'image.php')) {
            $index = explode('image.php?', $html[2]);

            return ' ' . $html[1] . '="image.php?' . $index[1] . '"';
        }

        return $html[0];
    }

    /**
     * Cleanup image.php? paths from the admin
     *
     * @param array<int, string> $html
     */
    public function cleanAdminSrc(array $html): string
    {
        if (isset($html[2]) && str_contains($html[2], 'image.php')) {
            $src_ = explode('image.php?', $html[2]);

            return ' ' . $html[1] . '="' . URL_DIR . 'image.php?' . $src_[1] . '" ';
        }

        return $html[0];
    }

    /**
     * Delete line breaks in html content
     *
     * @param array<int, string> $params
     */
    protected function deleteLineBreaksInHtml(array $params): string
    {
        if (!isset($params[0])) {
            return $params[0];
        }

        return str_replace(
            ["\r\n", "\n", "\r"],
            "",
            $params[0]
        );
    }
}
