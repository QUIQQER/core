<?php

/**
 * This file contains the \QUI\Projects\Manager
 */

namespace QUI\Projects;

use DOMElement;
use DOMXPath;
use Exception;
use QUI;
use QUI\Cache\Manager as QUICacheManager;
use QUI\Config;
use QUI\Permissions\Permission;
use QUI\Utils\DOM;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Text\XML;

use function array_filter;
use function array_flip;
use function array_unique;
use function count;
use function date;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_numeric;
use function is_string;
use function json_decode;
use function key;
use function preg_replace;
use function preg_match;
use function str_replace;
use function strtolower;
use function strlen;
use function trim;
use function unlink;

/**
 * The Project Manager
 * The main object to get a project
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 *
 * @event onProjectConfigSave [ string project, Array config ]
 * @event onCreateProject [ string \QUI\Projects\Project ]
 *
 * @errorcodes 8xx Project Errors -> Look at Project.php
 */
class Manager
{
    /**
     * Built-in project configuration definitions.
     *
     * @var array<string, array{default: string|int, type: string}>
     */
    private const PROJECT_CONFIG_DEFINITIONS = [
        'default_lang' => ['default' => 'de', 'type' => 'string'],
        'langs' => ['default' => 'de', 'type' => 'string'],
        'admin_mail' => ['default' => '', 'type' => 'string'],
        'template' => ['default' => '', 'type' => 'string'],
        'layout' => ['default' => '', 'type' => 'string'],
        'image_text' => ['default' => '0', 'type' => 'boolean'],
        'standard' => ['default' => '1', 'type' => 'boolean'],
        'adminSitemapMax' => ['default' => 20, 'type' => 'integer'],
        'media_watermark' => ['default' => '', 'type' => 'string'],
        'media_watermark_position' => ['default' => '', 'type' => 'string'],
        'media_watermark_ratio' => ['default' => '', 'type' => 'string'],
        'media_image_library' => ['default' => '', 'type' => 'string'],
        'media_maxUploadSize' => ['default' => 4000, 'type' => 'integer'],
        'media_maxUploadFileSize' => ['default' => '', 'type' => 'string'],
        'media_allowQrUpload' => ['default' => 1, 'type' => 'boolean'],
        'media_maxImageCacheSize' => ['default' => 4000, 'type' => 'integer'],
        'media_createCacheOnSave' => ['default' => 1, 'type' => 'boolean'],
        'media_imageCacheSizeRounding' => ['default' => 1, 'type' => 'boolean'],
        'media_imageCacheExactSizeThreshold' => ['default' => 100, 'type' => 'integer'],
        'media_useImageScale' => ['default' => 2, 'type' => 'integer'],
        'placeholder' => ['default' => '', 'type' => 'string'],
        'logo' => ['default' => '', 'type' => 'string'],
        'emailLogo' => ['default' => '', 'type' => 'string'],
        'favicon' => ['default' => '', 'type' => 'string'],
        'convertRomanLetters' => ['default' => 0, 'type' => 'boolean'],
        'publisher' => ['default' => '', 'type' => 'string'],
        'publisher_type' => ['default' => '', 'type' => 'string'],
        'publisher_image' => ['default' => '', 'type' => 'string'],
        'publisher_url' => ['default' => '', 'type' => 'string']
    ];

    /**
     * Projects config
     */
    public static ?Config $Config = null;

    /**
     * loaded projects
     *
     * @var array<string, array<string, Project>>
     */
    public static array $projects = [];

    /**
     * standard project
     */
    public static ?Project $Standard = null;

    /**
     * Clearing / cleanup the manager
     */
    public static function cleanup(): void
    {
        self::$projects = [];
    }

    /**
     * Add an installed language to a project and execute the required setup.
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function addLanguage(string $project, string $language): Project
    {
        $Project = self::getProject($project);

        Permission::checkProjectPermission(
            'quiqqer.projects.setconfig',
            $Project
        );

        $language = strtolower(trim($language));

        if (!preg_match('/^[a-z]{2}$/', $language)) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.project.lang.not.two.signs'
            ]);
        }

        if (!in_array($language, QUI::availableLanguages(), true)) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.project.lang.not.available',
                ['lang' => $language]
            ]);
        }

        $languages = $Project->getLanguages();

        if (!in_array($language, $languages, true)) {
            $languages[] = $language;

            self::setConfigForProject(
                $Project->getName(),
                ['langs' => implode(',', $languages)]
            );
        }

        return self::getProject($Project->getName(), $language);
    }

    /**
     * set configuration for a project
     *
     * @param array<string, mixed> $params
     *
     * @throws QUI\Exception
     * @throws Exception
     */
    public static function setConfigForProject(string $project, array $params = []): void
    {
        $handedParams = $params;
        $Project = self::getProject($project);
        $projectName = $Project->getName();

        Permission::checkProjectPermission(
            'quiqqer.projects.setconfig',
            $Project
        );

        $Config = self::getConfig();
        $projects = $Config->toArray();

        // $config
        $availableConfig = self::getProjectConfigList($Project);
        $projectConfig = [];

        if (isset($projects[$projectName])) {
            $projectConfig = $projects[$projectName];
        }

        // merge current config and available config
        foreach (array_keys($availableConfig) as $key) {
            if (!isset($projectConfig[$key])) {
                continue;
            }

            $str = Orthos::removeHTML($projectConfig[$key]);
            $str = Orthos::clearPath($str);

            $availableConfig[$key] = $str;
        }

        // merge params config with available / current config
        if (!empty($params)) {
            foreach (array_keys($availableConfig) as $key) {
                if (!isset($params[$key])) {
                    continue;
                }

                $setValue = $params[$key];

                if (!is_string($setValue) && !is_bool($setValue) && !is_numeric($setValue)) {
                    continue;
                }

                if (is_string($setValue)) {
                    $setValue = Orthos::removeHTML($setValue);
                    $setValue = Orthos::clearPath($setValue);
                }

                $availableConfig[$key] = $setValue;
            }
        }

        // doppelte sprachen filtern
        $languages = explode(',', $availableConfig['langs']);
        $languages = array_unique($languages);

        $availableConfig['langs'] = implode(',', $languages);

        $Config->setSection($projectName, $availableConfig);
        $Config->save();


        QUI::getEvents()->fireEvent('projectConfigSave', [
            $projectName,
            $availableConfig,
            $params
        ]);

        // remove the project from the temp
        if (self::$projects[$projectName]) {
            unset(self::$projects[$projectName]);
        }

        // execute the project setup
        $Project = self::getProject($projectName);

        // if language config has changed,
        // we need to execute a complete project setup
        // quiqqer/core#768
        // quiqqer/core#767
        if (
            isset($handedParams['langs']) &&
            isset($projectConfig['langs']) &&
            $handedParams['langs'] !== $projectConfig['langs']
        ) {
            $Project->setup();
            QUI\Translator::create();
        } else {
            $Project->setup([
                'executePackagesSetup' => false
            ]);
        }

        /**
         * clear media cache
         * eq: if watermark settings changed
         *
         * @param array $config
         * @param array $oldConfig
         * @param Project $Project
         * @throws QUI\Exception
         */
        $clearMediaCache = static function (array $config, array $oldConfig, Project $Project): void {
            if (
                !isset($config['media_watermark'])
                && !isset($config['media_watermark_position'])
                && !isset($config['media_image_library'])
                && !isset($config['media_imageCacheSizeRounding'])
                && !isset($config['media_imageCacheExactSizeThreshold'])
            ) {
                return;
            }

            if (
                isset($oldConfig['media_imageCacheSizeRounding'])
                && $config['media_imageCacheSizeRounding'] != $oldConfig['media_imageCacheSizeRounding']
            ) {
                $Project->getMedia()->clearCache();
                return;
            }

            if (
                isset($oldConfig['media_imageCacheExactSizeThreshold'])
                && $config['media_imageCacheExactSizeThreshold'] != $oldConfig['media_imageCacheExactSizeThreshold']
            ) {
                $Project->getMedia()->clearCache();
                return;
            }

            if (
                isset($config['media_watermark'])
                && isset($oldConfig['media_watermark'])
                && $config['media_watermark'] != $oldConfig['media_watermark']
            ) {
                // clear cache
                $Project->getMedia()->clearCache();
                return;
            }

            if (
                isset($config['media_watermark_ratio'])
                && isset($oldConfig['media_watermark_ratio'])
                && $config['media_watermark_ratio'] != $oldConfig['media_watermark_ratio']
            ) {
                // clear cache
                $Project->getMedia()->clearCache();
                return;
            }

            if (
                isset($config['media_watermark_position'])
                && isset($oldConfig['media_watermark_position'])
                && $config['media_watermark_position'] != $oldConfig['media_watermark_position']
            ) {
                // clear cache
                $Project->getMedia()->clearCache();
                return;
            }

            if (!isset($config['media_image_library'])) {
                return;
            }

            if (!isset($oldConfig['media_image_library'])) {
                return;
            }

            if ($config['media_image_library'] == $oldConfig['media_image_library']) {
                return;
            }

            // clear cache
            $Project->getMedia()->clearCache();
        };

        $clearMediaCache($availableConfig, $projectConfig, $Project);

        // if this project should be the standard,
        // all other projects are not
        if (!isset($availableConfig['standard']) || $availableConfig['standard'] != 1) {
            return;
        }

        $projects = $Config->toArray();

        foreach (array_keys($projects) as $_project) {
            if ($_project != $projectName) {
                $Config->setValue($_project, 'standard', 0);
            }
        }

        $Config->save();
    }

    /**
     * Returns a project
     *
     * @param string $project - Project name
     * @param false|string $lang - Project lang, optional (if not set, the standard language used)
     * @param false|string $template - used template, optional (if not set, the standard templaed used)
     *
     * @return Project
     *
     * @throws QUI\Exception
     */
    public static function getProject(
        string $project,
        false | string $lang = false,
        false | string $template = false
    ): Project {
        if (isset(self::$projects[$project]['_standard']) && !$lang) {
            return self::$projects[$project]['_standard'];
        }

        if (isset(self::$projects[$project][$lang])) {
            /* @var $Project Project */
            $Project = self::$projects[$project][$lang];

            if (!$template) {
                return $Project;
            }

            if ($Project->getTemplate() === $template) {
                return $Project;
            }
        }

        // Wenn der RAM zu voll wird, Objekte mal leeren
        if (QUI\Utils\System::memUsageToHigh()) {
            self::$projects = [];
        }


        if ($lang === false) {
            $Project = new Project($project);

            if (QUI::isRuntimeCacheEnabled()) {
                self::$projects[$project]['_standard'] = $Project;
            }

            return $Project;
        }

        $Project = new Project(
            $project,
            $lang,
            $template
        );

        if (QUI::isRuntimeCacheEnabled()) {
            self::$projects[$project]['_standard'] = $Project;
        }

        return $Project;
    }

    /**
     * @throws QUI\Exception
     */
    public static function getConfig(): Config
    {
        return QUI::getConfig('etc/projects.ini');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getProjectConfigList(Project $Project): array
    {
        $cache = $Project->getCachePath() . '/configList';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $config = [];

        foreach (self::getProjectConfigDefinitions($Project) as $key => $definition) {
            $config[$key] = $definition['default'];
        }

        QUI\Cache\Manager::set($cache, $config);

        return $config;
    }

    /**
     * Return all available project configuration definitions, including
     * project settings contributed by installed packages and templates.
     *
     * @return array<string, array{default: mixed, type: string, source: string}>
     */
    public static function getProjectConfigDefinitions(Project $Project): array
    {
        $cache = $Project->getCachePath() . '/configDefinitions';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $definitions = [];

        foreach (self::PROJECT_CONFIG_DEFINITIONS as $key => $definition) {
            $definitions[$key] = [
                'default' => $definition['default'],
                'type' => $definition['type'],
                'source' => 'quiqqer/core'
            ];
        }

        // settings.xml
        $settingsXml = self::getRelatedSettingsXML($Project);

        foreach ($settingsXml as $file) {
            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);

            $settingsList = $Path->query('//project/settings');

            if ($settingsList === false) {
                continue;
            }

            for ($i = 0, $len = $settingsList->length; $i < $len; $i++) {
                $Settings = $settingsList->item($i);

                if (!($Settings instanceof DOMElement)) {
                    continue;
                }

                $sections = DOM::getConfigParamsFromDOM($Settings);
                $settingsName = $Settings->getAttribute('name');

                if (!empty($settingsName)) {
                    $settingsName = $settingsName . '.';
                }

                foreach ($sections as $section => $entry) {
                    foreach ($entry as $key => $param) {
                        $definitionKey = $settingsName . $section . '.' . $key;
                        $definitions[$definitionKey] = [
                            'default' => $param['default'] ?? '',
                            'type' => is_string($param['type'] ?? null) ? $param['type'] : 'string',
                            'source' => self::getProjectSettingsSource($file, $Project)
                        ];
                    }
                }
            }
        }

        QUI\Cache\Manager::set($cache, $definitions);

        return $definitions;
    }

    private static function getProjectSettingsSource(string $file, Project $Project): string
    {
        if ($file === USR_DIR . $Project->getName() . '/settings.xml') {
            return 'project/' . $Project->getName();
        }

        $packageDirectory = rtrim(OPT_DIR, '/\\') . '/';

        if (str_starts_with($file, $packageDirectory) && str_ends_with($file, '/settings.xml')) {
            return substr($file, strlen($packageDirectory), -strlen('/settings.xml'));
        }

        return 'unknown';
    }

    /**
     * Returns the current project
     */
    public static function get(): Project
    {
        $Rewrite = QUI::getRewrite();

        if ($Rewrite->getParam('project')) {
            try {
                return self::getProject(
                    $Rewrite->getParam('project'),
                    $Rewrite->getParam('lang'),
                    $Rewrite->getParam('template')
                );
            } catch (QUI\Exception) {
            }
        }

        $Standard = self::getStandard();

        // Falls andere Sprache gewünscht
        if (
            $Rewrite->getParam('lang')
            && $Rewrite->getParam('lang') != $Standard->getLang()
        ) {
            try {
                return self::getProject(
                    $Standard->getName(),
                    $Rewrite->getParam('lang')
                );
            } catch (QUI\Exception) {
            }
        }

        return $Standard;
    }

    /**
     * Standard Projekt bekommen
     *
     * @throws QUI\Exception
     */
    public static function getStandard(): Project
    {
        if (self::$Standard !== null) {
            return self::$Standard;
        }

        $config = self::getConfig()->toArray();

        if (!count($config)) {
            throw new QUI\Exception(
                'No project exist'
            );
        }

        foreach ($config as $project => $conf) {
            if (!isset($conf['standard'])) {
                continue;
            }

            if ($conf['standard'] != 1) {
                continue;
            }

            self::$Standard = self::getProject(
                $project,
                $conf['default_lang'],
                $conf['template']
            );
        }

        if (self::$Standard === null) {
            QUI\System\Log::addAlert(
                'No standard project are set. Please define a standard projekt'
            );

            $project = key($config);

            self::$Standard = QUI\Projects\Manager::getProject(
                $project,
                $config[key($config)]['default_lang']
            );
        }

        return self::$Standard;
    }

    /**
     * Return all settings.xml which are related to the project
     * eq: all settings.xml from templates
     *
     * @return array<int, string>
     */
    public static function getRelatedSettingsXML(Project $Project): array
    {
        $cache = $Project->getCachePath() . '/relatedSettingsXml';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $list = [];
        $packages = QUI::getPackageManager()->getInstalled();

        // read template config
        foreach ($packages as $package) {
            // if the package is a quiqqer template,
            //
            // commented out because of: quiqqer/core#1247
            //
            /*
            if ($package['type'] == 'quiqqer-template') {
                // note only related templates
                if (!isset($templates[$package['name']])) {
                    continue;
                }
            }
            */

            // consider inheritance
            $file = OPT_DIR . $package['name'] . '/settings.xml';

            if (!file_exists($file)) {
                continue;
            }

            $Dom = XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);

            $Settings = $Path->query('//quiqqer/project/settings');

            if ($Settings !== false && $Settings->length) {
                $list[] = $file;
            }
        }

        // direct - project settings
        $projectSettings = USR_DIR . $Project->getName() . '/settings.xml';

        if (file_exists($projectSettings)) {
            $Dom = XML::getDomFromXml($projectSettings);
            $Path = new DOMXPath($Dom);

            $Settings = $Path->query('//quiqqer/project/settings');

            if ($Settings !== false && $Settings->length) {
                $list[] = $projectSettings;
            }
        }

        try {
            QUI\Cache\Manager::set($cache, $list);
        } catch (Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }

        return $list;
    }

    /**
     * Return all templates which are related to the project
     * the vhost templates are included
     *
     * @return array<int, string>
     */
    public static function getRelatedTemplates(Project $Project): array
    {
        $result = [];
        $templates = [];
        $project = $Project->getName();

        if ($Project->getTemplate()) {
            $result[] = $Project->getTemplate();

            $templates[$Project->getTemplate()] = true;
        }

        // vhosts und templates schauen
        $vhosts = QUI::getRewrite()->getVHosts();

        foreach ($vhosts as $vhost) {
            if (!isset($vhost['project'])) {
                continue;
            }

            if ($vhost['project'] != $project) {
                continue;
            }

            if (!isset($vhost['template'])) {
                continue;
            }

            if (isset($templates[$vhost['template']])) {
                continue;
            }

            $templates[$vhost['template']] = true;

            $result[] = $vhost['template'];
        }

        // search & consider inheritance template
        foreach ($result as $template) {
            try {
                $Package = QUI::getPackage($template);
                $Parent = $Package->getTemplateParent();

                if ($Parent) {
                    $result[] = $Parent->getName();
                }
            } catch (QUI\Exception) {
                // nothing
            }
        }

        return array_unique($result);
    }

    /**
     * Decode project data
     * Decode a project json string to a Project or decode a project array to a Project
     *
     * @param array<string, mixed>|string $project - project data
     *
     * @return Project
     *
     * @throws QUI\Exception
     */
    public static function decode(array | string $project): Project
    {
        if (is_string($project)) {
            $project = json_decode($project, true);
        }

        if (!isset($project['name']) || !$project['name']) {
            throw new QUI\Exception(
                'Could not decode project data'
            );
        }

        $projectName = $project['name'];
        $projectLang = false;
        $projectTpl = false;

        if (isset($project['lang'])) {
            $projectLang = $project['lang'];
        }

        if (isset($project['template'])) {
            $projectTpl = $project['template'];
        }

        return self::getProject($projectName, $projectLang, $projectTpl);
    }

    /**
     * Return all projects as objects
     * - it returns every project and every language
     *
     * if a project has multiple languages, getProjectList will return multiple projects
     * eq: project exist in en,de,fr getProjectList will return Project(en, Project(de), Project(fr)
     *
     * @return Project[]
     */
    public static function getProjectList(): array
    {
        try {
            $config = self::getConfig()->toArray();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());

            return [];
        }

        $result = [];

        foreach ($config as $project => $conf) {
            $langs = explode(',', trim($conf['langs']));

            foreach ($langs as $lang) {
                if (isset(self::$projects[$project][$lang])) {
                    $result[] = self::$projects[$project][$lang];
                    continue;
                }

                try {
                    $result[] = self::getProject(
                        $project,
                        $lang,
                        $conf['template']
                    );
                } catch (QUI\Exception) {
                }
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $languages
     *
     * @throws QUI\Exception
     * @throws Exception
     * @todo noch einmal anschauen und übersichtlicher schreiben
     */
    public static function createProject(
        string $name,
        string $lang,
        array $languages = [],
        string $template = ''
    ): Project {
        Permission::checkPermission('quiqqer.projects.create');

        if (strlen($name) <= 2) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.longer.two.signs'
                ),
                801
            );
        }

        if (strlen($lang) != 2) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.lang.not.two.signs'
                ),
                801
            );
        }

        QUI\Utils\Project::validateProjectName($name);

        $projects = self::getProjects();

        if (isset($projects[$name])) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.not.allowed.signs'
                ),
                802
            );
        }

        $name = QUI\Utils\Security\Orthos::clear($name);

        $Connection = QUI::getDataBaseConnection();


        /**
         * Sites and sites relation
         */
        $table_site = QUI_DB_PRFX . $name . '_' . $lang . '_sites';
        $table_site_rel = QUI_DB_PRFX . $name . '_' . $lang . '_sites_relations';

        self::createProjectSiteTables($table_site, $table_site_rel);

        // first site
        $Connection->insert($table_site, [
            'id' => 1,
            'name' => 'Start',
            'title' => 'start',
            'short' => 'Shorttext',
            'content' => '<p>Welcome to my project</p>',
            'type' => 'standard',
            'active' => 1,
            'deleted' => 0,
            'c_date' => date('Y-m-d H:i:s'),
            'e_date' => date('Y-m-d H:i:s'),
            'c_user' => QUI::getUserBySession()->getUUID(),
            'e_user' => QUI::getUserBySession()->getUUID(),
            'nav_hide' => 0
        ]);


        /**
         * Media and media relation
         */
        $table_media = QUI_DB_PRFX . $name . '_media';
        $table_media_rel = QUI_DB_PRFX . $name . '_media_relations';

        self::createProjectMediaTables($table_media, $table_media_rel);

        // first folder
        $Connection->insert($table_media, [
            'id' => 1,
            'name' => 'Start',
            'title' => 'start',
            'short' => 'Shorttext',
            'type' => 'folder',
            'file' => '',
            'active' => 1,
            'deleted' => 0,
            'c_date' => date('Y-m-d H:i:s'),
            'e_date' => date('Y-m-d H:i:s'),
            'c_user' => QUI::getUserBySession()->getUUID(),
            'e_user' => QUI::getUserBySession()->getUUID(),
            'pathHash' => md5('')
        ]);


        /**
         * Create the file system folders
         */
        QUI\Utils\System\File::mkdir(CMS_DIR . 'media/sites/' . $name . '/');
        QUI\Utils\System\File::mkdir(USR_DIR . $name . '/');


        /**
         * Languages
         */
        if (!in_array($lang, $languages)) {
            $languages[] = $lang;
        }

        $languages = array_filter($languages, static function ($language): bool {
            return strlen($language) === 2;
        });

        $languages = array_unique($languages);


        /**
         * Write the config
         */
        if (!file_exists(CMS_DIR . 'etc/projects.ini.php')) {
            file_put_contents(CMS_DIR . 'etc/projects.ini.php', '');
        }

        $Config = self::getConfig();

        $Config->setSection($name, [
            'default_lang' => $lang,
            'langs' => implode(',', $languages),
            'admin_mail' => '',
            'template' => $template,
            'image_text' => '0',
            'keywords' => '',
            'description' => '',
            'robots' => 'index',
            'author' => '',
            'publisher' => '',
            'copyright' => '',
            'standard' => '0'
        ]);

        if (count($Config->toArray()) <= 1) {
            $Config->setValue($name, 'standard', 1);
        }

        $Config->save();

        // Clear projects cache
        QUI\Cache\Manager::clearProjectsCache();

        // Project setup
        $Project = self::getProject($name);
        $Project->refresh();
        $Project->setup();

        // Package / Plugin Setup
        QUI\Setup::executeEachPackageSetup();

        // project create event
        QUI::getEvents()->fireEvent('createProject', [$Project]);

        return $Project;
    }


    private static function createProjectSiteTables(string $siteTableName, string $relationTableName): void
    {
        $Table = new \Doctrine\DBAL\Schema\Table($siteTableName);
        self::addUtf8Options($Table);
        $Table->addColumn("id", "bigint", ["autoincrement" => true]);
        $Table->addColumn("name", "string", ["length" => 200]);
        $Table->addColumn("title", "text", ["notnull" => false]);
        $Table->addColumn("short", "text", ["notnull" => false]);
        $Table->addColumn("content", "text", ["notnull" => false]);
        $Table->addColumn("type", "string", ["length" => 255, "notnull" => false]);
        $Table->addColumn("active", "smallint", ["default" => 0]);
        $Table->addColumn("deleted", "smallint", ["default" => 0]);
        $Table->addColumn("c_date", "datetime", ["notnull" => false]);
        $Table->addColumn("e_date", "datetime", ["notnull" => false]);
        $Table->addColumn("c_user", "string", ["length" => 50, "notnull" => false]);
        $Table->addColumn("e_user", "string", ["length" => 50, "notnull" => false]);
        $Table->addColumn("nav_hide", "smallint", ["default" => 0]);
        $Table->addColumn("order_type", "string", ["length" => 100, "notnull" => false]);
        $Table->addColumn("order_field", "bigint", ["notnull" => false]);
        $Table->addColumn("extra", "text", ["notnull" => false]);
        $Table->setPrimaryKey(["id"]);

        QUI::getSchemaManager()->createTable($Table);

        $RelationTable = new \Doctrine\DBAL\Schema\Table($relationTableName);
        self::addUtf8Options($RelationTable);
        $RelationTable->addColumn("parent", "bigint");
        $RelationTable->addColumn("child", "bigint");

        QUI::getSchemaManager()->createTable($RelationTable);
    }

    private static function createProjectMediaTables(string $mediaTableName, string $relationTableName): void
    {
        $Table = new \Doctrine\DBAL\Schema\Table($mediaTableName);
        self::addUtf8Options($Table);
        $Table->addColumn("id", "bigint", ["autoincrement" => true]);
        $Table->addColumn("name", "string", ["length" => 200]);
        $Table->addColumn("title", "text", ["notnull" => false]);
        $Table->addColumn("short", "text", ["notnull" => false]);
        $Table->addColumn("type", "string", ["length" => 32, "notnull" => false]);
        $Table->addColumn("active", "smallint", ["default" => 0]);
        $Table->addColumn("deleted", "smallint", ["default" => 0]);
        $Table->addColumn("c_date", "datetime", ["notnull" => false]);
        $Table->addColumn("e_date", "datetime", ["notnull" => false]);
        $Table->addColumn("c_user", "string", ["length" => 50, "notnull" => false]);
        $Table->addColumn("e_user", "string", ["length" => 50, "notnull" => false]);
        $Table->addColumn("file", "text", ["notnull" => false]);
        $Table->addColumn("alt", "text", ["notnull" => false]);
        $Table->addColumn("mime_type", "text", ["notnull" => false]);
        $Table->addColumn("image_height", "integer", ["notnull" => false]);
        $Table->addColumn("image_width", "integer", ["notnull" => false]);
        $Table->addColumn("pathHash", "string", ["length" => 32]);
        $Table->setPrimaryKey(["id"]);

        QUI::getSchemaManager()->createTable($Table);

        $RelationTable = new \Doctrine\DBAL\Schema\Table($relationTableName);
        self::addUtf8Options($RelationTable);
        $RelationTable->addColumn("parent", "bigint");
        $RelationTable->addColumn("child", "bigint");

        QUI::getSchemaManager()->createTable($RelationTable);
    }

    private static function dropProjectTable(string $tableName): void
    {
        $SchemaManager = QUI::getSchemaManager();

        if ($SchemaManager->tablesExist([$tableName])) {
            $SchemaManager->dropTable($tableName);
        }
    }

    private static function addUtf8Options(\Doctrine\DBAL\Schema\Table $Table): void
    {
        $Table->addOption("charset", "utf8mb4");
        $Table->addOption("collation", "utf8mb4_general_ci");
    }


    /**
     * Return all projects as array list or object list
     * Return the projects with its default language
     *
     * if you want a complete project list with every project language, please use getProjectList()
     *
     * @param bool $asObject - default = false, true = projects as objects
     * @return ($asObject is true ? array<int, Project> : array<int, int|string>)
     */
    public static function getProjects(bool $asObject = false): array
    {
        try {
            $config = self::getConfig()->toArray();
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
            return [];
        }

        $list = [];

        foreach ($config as $project => $conf) {
            if (!isset($conf['default_lang'])) {
                $conf['default_lang'] = 'en';
            }

            try {
                $Project = self::getProject(
                    $project,
                    $conf['default_lang'],
                    $conf['template']
                );

                if (isset($conf['standard']) && $conf['standard'] == 1) {
                    self::$Standard = $Project;
                }

                if ($asObject) {
                    $list[] = $Project;
                } else {
                    $list[] = $project;
                }
            } catch (QUI\Exception) {
            }
        }

        return $list;
    }

    /**
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public static function deleteProject(Project $Project): void
    {
        Permission::checkProjectPermission(
            'quiqqer.projects.destroy',
            $Project
        );

        // If only one project exists it should not be deleted (no existing projects cause errors)
        if (self::count() < 2) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.project.delete.last'
                )
            );
        }

        $project = $Project->getName();
        $languages = $Project->getLanguages();

        // delete site tables for all languages
        foreach ($languages as $lang) {
            $table_site = QUI::getDBTableName($project . '_' . $lang . '_sites');
            $table_site_rel = QUI::getDBTableName(
                $project . '_' . $lang
                . '_sites_relations'
            );

            $table_multi = QUI::getDBTableName($project . '_multilingual');
            $table_media = QUI::getDBTableName($project . '_media');
            $table_media_rel = QUI::getDBTableName($project . '_media_relations');

            self::dropProjectTable($table_site);
            self::dropProjectTable($table_site_rel);
            self::dropProjectTable($table_multi);
            self::dropProjectTable($table_media);
            self::dropProjectTable($table_media_rel);
        }

        // delete database tables from plugins
        $packages = QUI::getPackageManager()->getInstalled();

        foreach ($packages as $package) {
            // search database tables
            $databaseXml = OPT_DIR . $package['name'] . '/database.xml';

            if (!file_exists($databaseXml)) {
                continue;
            }

            $dbFields = XML::getDataBaseFromXml($databaseXml);

            if (!isset($dbFields['projects'])) {
                continue;
            }

            // for each language
            foreach ($dbFields['projects'] as $table) {
                foreach ($languages as $lang) {
                    $tbl = QUI::getDBTableName(
                        $project . '_' . $lang . '_' . $table['suffix']
                    );

                    self::dropProjectTable($tbl);
                }
            }
        }

        // delete projects permissions
        QUI::getDataBaseConnection()->delete(
            QUI::getDBTableName(QUI\Permissions\Manager::TABLE) . '2projects',
            [
                'project' => $project
            ]
        );

        QUI::getDataBaseConnection()->delete(
            QUI::getDBTableName(QUI\Permissions\Manager::TABLE) . '2sites',
            [
                'project' => $project
            ]
        );

        // delete media
        QUI::getTemp()->moveToTemp(CMS_DIR . 'media/sites/' . $project);
        QUI::getTemp()->moveToTemp(CMS_DIR . 'media/cache/' . $project);


        // config schreiben
        $Config = self::getConfig();
        $Config->del($project);
        $Config->save();
        QUI\Cache\Manager::clear('QUI::config');

        QUI::getEvents()->fireEvent('deleteProject', [$project]);
    }

    /**
     * Return the projects count
     */
    public static function count(): int
    {
        try {
            $Config = self::getConfig();
            $config = $Config->toArray();
        } catch (\Exception) {
            return 0;
        }

        return count($config);
    }

    /**
     * Renames the given project
     *
     * @param string $oldName - The projects current name
     * @param string $newName - The new name for the project
     *
     * @throws QUI\Exception
     */
    public static function rename(string $oldName, string $newName): void
    {
        QUI\Utils\Project::validateProjectName($newName);

        $Project = self::getProject($oldName);
        // ----------------------------- //
        //              Config           //
        // ----------------------------- //

        // File: etc/projects.ini.php
        $filename = ETC_DIR . 'projects.ini.php';
        $content = file_get_contents($filename);

        $content = str_replace($oldName, $newName, (string)$content);
        file_put_contents($filename, $content);


        // File: etc/vhosts.ini.php
        $filename = ETC_DIR . 'vhosts.ini.php';
        $content = file_get_contents($filename);

        $content = str_replace($oldName, $newName, (string)$content);
        file_put_contents($filename, $content);


        // ----------------------------- //
        //            Database           //
        // ----------------------------- //

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $tables = QUI::getSchemaManager()->listTableNames();

        foreach ($tables as $oldTableName) {
            if (!str_contains($oldTableName . '_', QUI_DB_PRFX . $oldName)) {
                continue;
            }

            $newTableName = preg_replace(
                "~^" . QUI_DB_PRFX . $oldName . "_~m",
                QUI_DB_PRFX . $newName . "_",
                $oldTableName
            ) ?? $oldTableName;

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

        $sourceDir = CMS_DIR . 'media/sites/' . $oldName;
        $targetDir = CMS_DIR . 'media/sites/' . $newName;

        if (is_dir($sourceDir)) {
            QUI\Utils\System\File::move($sourceDir, $targetDir);
        }

        // ----------------------------- //
        //              USR           //
        // ----------------------------- //
        $sourceDir = USR_DIR . $oldName;
        $targetDir = USR_DIR . $newName;

        if (is_dir($sourceDir)) {
            QUI\Utils\System\File::move($sourceDir, $targetDir);
        }

        // -----------------------------//
        //              Cache           //
        // -----------------------------//
        QUI\Cache\Manager::clearCompleteQuiqqerCache();


        // ----------------------------- //
        //              Locale           //
        // ----------------------------- //
        if (file_exists(VAR_DIR . 'locale/localefiles')) {
            unlink(VAR_DIR . 'locale/localefiles');
        }


        // Remove old translation
        $translationGroup = 'project/' . $oldName;
        $translationVar = 'title';

        $translation = QUI\Translator::get($translationGroup, $translationVar);

        if (isset($translation[0])) {
            QUI\Translator::delete($translationGroup, $translationVar);
        }


        $translationGroup = 'project/' . $newName;
        $translationVar = 'title';

        $translation = QUI\Translator::get($translationGroup, $translationVar);

        if (!isset($translation[0])) {
            try {
                QUI\Translator::add($translationGroup, $translationVar);
            } catch (Exception $Exception) {
                QUI\System\Log::addError(
                    'Rename project: Could not add language variable ' . $translationGroup . '/' . $translationVar . ': ' . $Exception->getMessage(
                    )
                );
            }
        }

        QUI\Translator::create();

        // ----------------------------- //
        //              Finish           //
        // ----------------------------- //

        QUI::getEvents()->fireEvent('projectRenamed', [
            $Project,
            $oldName,
            $newName
        ]);

        unset(self::$projects[$oldName]);
    }

    /**
     * Search a project
     *
     * @param array<string, mixed> $params - Search params
     *                      'search' => 'search string',
     *                      'limit'  => 5,
     *                      'page'   => 1
     *
     * @return array<int, array<string, string>>
     *
     * @throws QUI\Exception
     */
    public static function search(array $params): array
    {
        if (!isset($params['search'])) {
            return [];
        }

        $result = [];
        $list = self::getConfig()->toArray();
        $search = $params['search'];

        foreach ($list as $project => $entry) {
            if (!empty($search) && !str_contains($project, $search)) {
                continue;
            }

            $languages = explode(',', $entry['langs']);

            foreach ($languages as $lang) {
                $result[] = [
                    'project' => $project,
                    'lang' => $lang
                ];
            }
        }

        return $result;
    }

    /**
     * Check if a project with given name exists.
     */
    public static function existsProject(string $projectName): bool
    {
        if (isset(self::$projects[$projectName])) {
            return true;
        }

        $cacheName = 'quiqqer/projects/__exists/' . $projectName;

        try {
            return QUICacheManager::get($cacheName);
        } catch (Exception) {
            // re-build cache
        }

        try {
            $config = Manager::getConfig()->toArray();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        $projectExists = isset($config[$projectName]);

        QUICacheManager::set($cacheName, $projectExists);

        return $projectExists;
    }
}
