<?php

/**
 * This file contains QUI\Template
 */

namespace QUI;

use QUI;
use QUI\Interfaces\Template\EngineInterface;
use QUI\Projects\Project;

use function class_exists;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function htmlspecialchars;
use function html_entity_decode;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function ltrim;
use function realpath;
use function rtrim;
use function str_starts_with;
use function str_replace;
use function strlen;
use function substr;
use function trim;

use const ETC_DIR;
use const PHP_EOL;

/**
 * Template Engine Manager
 *
 * @event onTemplateGetHeader [ $this ]
 */
class Template extends QUI\QDOM
{
    /**
     * Registered template engines
     *
     * @var array<string, string>
     */
    protected array $engines = [];

    /**
     * Header extensions
     *
     * @var array<int, string>
     */
    protected array $header = [];

    /**
     * Footer extensions
     *
     * @var array<int, string>
     */
    protected array $footer = [];

    /**
     * assigned vars
     *
     * @var array<string, mixed>
     */
    protected array $assigned = [];

    /**
     * modules that loaded after the onload event
     *
     * @var array<int, string>
     */
    protected array $onLoadModules = [];

    protected ?Package\Package $TemplatePackage = null;

    protected ?Package\Package $TemplateParent = null;

    protected ?Projects\Project $Project = null;

    /**
     * Page-scoped structured data.
     */
    protected ?Utils\JsonLd $jsonLd = null;

    /**
     * Project template list
     *
     * @var array<string, array<int, string>|false>
     */
    protected array $templates = [];

    public function __construct()
    {
        $this->setAttribute('mootools', true);
        $this->setAttribute('requirejs', true);
        $this->setAttribute('html5', true);

        // defaults
        $this->setAttributes([
            'mootools' => true,
            'requirejs' => true,
            'html5' => true,
            'content-header' => true,
            'content-body' => true,
            'template-header' => true,
            'template-footer' => true,
            'noConflict' => false // @todo in Version 2.0 -> true becomes the default
        ]);
    }

    /**
     * Register a template engine
     *
     * @param string $name
     * @param string $class - must a class that implements QUI\Interfaces\Template\EngineInterface
     * @throws QUI\Exception
     */
    public static function registerEngine(string $name, string $class): void
    {
        $Conf = self::getConfig();
        $Conf->setValue($name, null, $class);
        $Conf->save();
    }

    /**
     * @throws QUI\Exception
     */
    public static function getConfig(): Config
    {
        if (!file_exists(CMS_DIR . 'etc/templates.ini.php')) {
            file_put_contents(CMS_DIR . 'etc/templates.ini.php', '');
        }

        return QUI::getConfig('etc/templates.ini.php');
    }

    /**
     * Return the current header extensions
     *
     * @return array<int, string>
     */
    public function getExtendHeader(): array
    {
        return $this->header;
    }

    /**
     * Return the structured data for the current page.
     *
     * Site type scripts can add or override properties before the template
     * header is rendered. Use set('type', ...) to replace the WebPage default.
     */
    public function getJsonLd(): Utils\JsonLd
    {
        if ($this->jsonLd === null) {
            $this->jsonLd = new Utils\JsonLd();
        }

        return $this->jsonLd;
    }

    public function extendHeaderWithCSSFile(string $cssPath, int $priority = 3): void
    {
        $this->extendHeader(
            '<link href="' . $cssPath . '" rel="stylesheet" type="text/css" />',
            $priority
        );
    }

    /**
     * Extend the head <head>...</head>
     */
    public function extendHeader(string $str, int $priority = 3): void
    {
        if (!isset($this->header[$priority])) {
            $this->header[$priority] = '';
        }

        $_str = $this->header[$priority];
        $_str .= $str;

        $this->header[$priority] = $_str;
    }

    public function extendHeaderWithJavaScriptFile(
        string $jsPath,
        bool $async = true,
        int $priority = 3
    ): void {
        if ($async) {
            $this->extendHeader(
                '<script src="' . $jsPath . '" async></script>',
                $priority
            );

            return;
        }

        $this->extendHeader(
            '<script src="' . $jsPath . '"></script>',
            $priority
        );
    }

    /**
     * Add the JavaScript File to the bottom of the HTML
     */
    public function extendFooterWithJavaScriptFile(
        string $jsPath,
        bool $async = true,
        int $priority = 3
    ): void {
        if ($async) {
            $this->extendFooter(
                '<script src="' . $jsPath . '" async></script>',
                $priority
            );

            return;
        }

        $this->extendFooter(
            '<script src="' . $jsPath . '"></script>',
            $priority
        );
    }

    /**
     * Add Code to the bottom of the HTML
     */
    public function extendFooter(
        string $str,
        int $priority = 3
    ): void {
        if (!isset($this->footer[$priority])) {
            $this->footer[$priority] = '';
        }

        $_str = $this->footer[$priority];
        $_str .= $str;

        $this->footer[$priority] = $_str;
    }

    /**
     * Return the current footer extensions
     *
     * @return array<int, string>
     */
    public function getExtendFooter(): array
    {
        return $this->footer;
    }

    /**
     * Add a JavaScript module that loaded at the onload event
     */
    public function addOnloadJavaScriptModule(string $module): void
    {
        $this->onLoadModules[] = $module;
    }

    /**
     * Returns the url for a file
     * - also considers template inheritance - template parent
     *
     * @param string $path
     */
    public function getTemplateUrl($path): string
    {
        if ($this->TemplatePackage === null) {
            return $path;
        }

        $template = $this->TemplatePackage->getName();
        $absolute = OPT_DIR . $template . '/' . $path;

        if (file_exists($absolute)) {
            return URL_OPT_DIR . $template . '/' . $path;
        }

        if ($this->TemplateParent) {
            $template = $this->TemplateParent->getName();
            $absolute = OPT_DIR . $template . '/' . $path;

            if (file_exists($absolute)) {
                return URL_OPT_DIR . $template . '/' . $path;
            }
        }

        return $path;
    }

    /**
     * Get an absolute path to the current template package
     */
    public function getTemplatePath(): string
    {
        if ($this->TemplatePackage === null) {
            return '';
        }

        $template = $this->TemplatePackage->getName();

        return OPT_DIR . $template . '/';
    }

    public function getTemplatePackage(): ?Package\Package
    {
        return $this->TemplatePackage;
    }

    /**
     * Return a template output
     *
     * @param string $template - Path to a template
     * @param array<string, mixed> $params (optional) - Engine params
     * @return string
     *
     * @throws QUI\Exception
     */
    public function fetchTemplate(string $template, array $params = []): string
    {
        $Engine = $this->getEngine();
        $Engine->assign($params);

        return $Engine->fetch($template);
    }

    /**
     * Get the standard template engine
     * if $admin=true, admin template plugins were loaded
     *
     * @param bool $admin - (optional) is the template for the admin or frontend? <- param deprecated
     * @return EngineInterface
     */
    public function getEngine(bool $admin = false): Interfaces\Template\EngineInterface
    {
        if (empty($this->engines)) {
            $this->load();
        }

        $engine = QUI::conf('template', 'engine');

        if (!isset($this->engines[$engine]) || !class_exists($this->engines[$engine])) {
            $engine = $this->checkSmarty4Engine($engine);
        }

        $Engine = new $this->engines[$engine]($admin);

        if (!$Engine instanceof EngineInterface) {
            $message = 'The Template Engine implements not from QUI\Interfaces\Template\EngineInterface';
            QUI\System\Log::addError($message);

            throw new QUI\Exception($message);
        }

        $Engine->assign('__TEMPLATE__', $this);

        try {
            QUI::getTemplateManager()->assignGlobalParam('Project', QUI::getRewrite()->getProject());
        } catch (\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
        }

        if (!empty($this->assigned)) {
            $Engine->assign($this->assigned);
        }

        return $Engine;
    }

    /**
     * Check if the given template engine is Smarty4 and perform necessary actions
     *
     * @param mixed $engine - The template engine to check
     * @return string - Returns the name of the template engine if successful
     */
    protected function checkSmarty4Engine(mixed $engine): string
    {
        // smarty 4 workaround
        if ($engine === 'smarty3' && class_exists('QUI\Smarty\Smarty4')) {
            try {
                $Config = QUI::getConfig('etc/conf.ini.php');
                $Config->setValue('template', 'engine', 'smarty4');
                $Config->save();

                QUI::getConfig('etc/conf.ini.php')->reload();

                $templateIni = ETC_DIR . 'templates.ini.php';
                $iniContent = file_get_contents($templateIni);

                if (!str_contains($templateIni, 'QUI\\Smarty\\Smarty4')) {
                    file_put_contents(
                        $templateIni,
                        trim((string)$iniContent) . PHP_EOL . 'smarty4="QUI\Smarty\Smarty4"'
                    );
                }

                static::getConfig()->reload();
                $this->load();

                return 'smarty4';
            } catch (\Exception $exception) {
                QUI\System\Log::addError($exception->getMessage());
            }
        }

        QUI\System\Log::addError('Template Engine not found!');
        return '';
    }

    /**
     * Load the registered engines
     */
    public function load(): void
    {
        $this->engines = self::getConfig()->toArray();
    }

    /**
     * Register a param for the Template engine
     * This registered param would be assigned to the Template Engine at the getEngine() method
     */
    public function assignGlobalParam(string $param, mixed $value): void
    {
        $this->assigned[$param] = $value;
    }

    private function getRegisteredTemplatePackage(mixed $name): ?Package\Package
    {
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        foreach (QUI::getPackageManager()->searchInstalledPackages(['type' => 'quiqqer-template']) as $data) {
            if (($data['name'] ?? null) !== trim($name)) {
                continue;
            }
            try {
                $Package = QUI::getPackage(trim($name));
                $root = realpath($Package->getDir());
                return $root !== false && is_dir($root) ? $Package : null;
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeDebugException($Exception);
                return null;
            }
        }
        return null;
    }

    private function getContainedFile(mixed $root, mixed $file): ?string
    {
        $root = realpath((string)$root);
        $file = realpath((string)$file);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $prefix) ? $file : null;
    }

    protected function getUserDirectory(): string
    {
        return USR_DIR;
    }

    /** @return array{0: Package\Package, 1: string}|null */
    private function getRegisteredSiteType(mixed $value): ?array
    {
        if (!is_string($value) || !str_contains($value, ':')) {
            return null;
        }
        try {
            foreach (QUI::getPackageManager()->getAvailableSiteTypes() as $types) {
                foreach ($types as $entry) {
                    if (($entry['type'] ?? null) === $value) {
                        [$package, $type] = explode(':', $value, 2);
                        $Package = QUI::getPackage($package);
                        $root = realpath($Package->getDir());
                        return $root !== false && is_dir($root) ? [$Package, $type] : null;
                    }
                }
            }
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }
        return null;
    }

    /**
     * Prepares the contents of a template
     *
     * @throws QUI\Exception
     */
    public function fetchSite(Interfaces\Projects\Site $Site): string
    {
        $Project = $Site->getProject();
        $Engine = $this->getEngine();

        $this->Project = $Project;

        $Users = QUI::getUsers();
        $Rewrite = QUI::getRewrite();
        $Locale = QUI::getLocale();
        $Template = $this;
        $userDirectory = $this->getUserDirectory();

        $projectTemplate = $Project->getTemplate();
        $hasTemplateParent = false;

        if ($Site->getAttribute('quiqqer.site.template')) {
            $projectTemplate = $Site->getAttribute('quiqqer.site.template');
        }

        $this->TemplatePackage = $this->getRegisteredTemplatePackage($projectTemplate);
        if ($this->TemplatePackage !== null) {
            $hasTemplateParent = $this->TemplatePackage->hasTemplateParent();

            if ($hasTemplateParent) {
                $parent = $this->TemplatePackage->getTemplateParent();
                $this->TemplateParent = $this->getRegisteredTemplatePackage($parent?->getName());
            }
        } else {
            $projectTemplate = '';
        }

        $User = $Users->getUserBySession();

        $this->setAttribute('Project', $Project);
        $this->setAttribute('Site', $Site);
        $this->setAttribute('Engine', $Engine);
        $this->jsonLd = $this->createDefaultJsonLd($Site);

        $Engine->assign([
            'URL_DIR' => URL_DIR,
            'URL_BIN_DIR' => URL_BIN_DIR,
            'URL_LIB_DIR' => URL_LIB_DIR,
            'URL_VAR_DIR' => URL_VAR_DIR,
            'URL_OPT_DIR' => URL_OPT_DIR,
            'URL_USR_DIR' => URL_USR_DIR,
            'User' => $User,
            'Locale' => $Locale,
            'L' => $Locale,
            'Template' => $Template,
            'Site' => $Site,
            'Project' => $Project,
            'Rewrite' => $Rewrite,
            'lastUpdate' => QUI::getPackageManager()->getLastUpdateDate(),
            'Canonical' => new QUI\Projects\Site\Canonical($Site),
            'Hreflang' => new QUI\Projects\Site\Hreflang($Site)
        ]);

        /**
         * find the index.html
         */

        $default_tpl = LIB_DIR . 'templates/index.html';
        $projectLibRoot = $userDirectory . $Project->getName() . '/lib';
        $project_tpl = $this->getContainedFile($projectLibRoot, $projectLibRoot . '/index.html');
        $project_index = $this->getContainedFile($projectLibRoot, $projectLibRoot . '/index.php');

        $tpl = $default_tpl;

        // standard template
        if (!$projectTemplate) {
            QUI\System\Log::addInfo(
                'Project has no standard template. Please set a standard template to the project'
            );

            $vhosts = QUI::getRewrite()->getVHosts();
            $projectName = $Project->getName();

            foreach ($vhosts as $vhost) {
                if (
                    isset($vhost['project'])
                    && $vhost['project'] == $projectName
                    && !empty($vhost['template'])
                ) {
                    $projectTemplate = $vhost['template'];
                    $this->TemplatePackage = $this->getRegisteredTemplatePackage($projectTemplate);
                    if ($this->TemplatePackage !== null) {
                        $hasTemplateParent = $this->TemplatePackage->hasTemplateParent();

                        if ($hasTemplateParent) {
                            $parent = $this->TemplatePackage->getTemplateParent();
                            $this->TemplateParent = $this->getRegisteredTemplatePackage($parent?->getName());
                        }
                    } else {
                        $projectTemplate = '';
                    }

                    break;
                }
            }
        }

        $templateRoot = $this->TemplatePackage?->getDir();
        $template_tpl = $templateRoot ? $this->getContainedFile($templateRoot, $templateRoot . '/index.html') : null;
        $template_index = $templateRoot ? $this->getContainedFile($templateRoot, $templateRoot . '/index.php') : null;

        if (
            $template_tpl === null
            && $hasTemplateParent
            && $this->TemplateParent !== null
        ) {
            $template_tpl = $this->getContainedFile($this->TemplateParent->getDir(), $this->TemplateParent->getDir() . '/index.html');
        }

        if (
            $template_index === null
            && $hasTemplateParent
            && $this->TemplateParent !== null
        ) {
            $template_index = $this->getContainedFile($this->TemplateParent->getDir(), $this->TemplateParent->getDir() . '/index.php');
        }

        if ($template_tpl !== null) {
            $tpl = $template_tpl;

            $Engine->assign([
                'URL_TPL_DIR' => URL_OPT_DIR . $projectTemplate . '/',
                'TPL_DIR' => OPT_DIR . $projectTemplate . '/',
            ]);
        }

        if ($project_tpl !== null) {
            $tpl = $project_tpl;

            $Engine->assign([
                'URL_TPL_DIR' => URL_USR_DIR . $Project->getName() . '/',
                'TPL_DIR' => $userDirectory . $Project->getName() . '/',
            ]);
        }

        // @todo suffix template prüfen
        /*
        $suffix = $Rewrite->getSuffix();

        if ( file_exists(USR_DIR .'lib/'. $Project->getTemplate() .'/index' . $suffix) ) {
            $tpl = USR_DIR .'lib/'. $Project->getTemplate() .'/index' . $suffix;
        }
        */

        // scripts file (index.php)
        if ($project_index !== null) {
            include $project_index;
        } elseif ($template_index && file_exists($template_index)) {
            include $template_index;
        }


        // load template scripts
        $siteScript = false;
        $projectScript = false;

        $siteTypeValue = (string)$Site->getAttribute('type');
        $siteType = explode(':', $siteTypeValue, 2);

        if (isset($siteType[1])) {
            $registered = $this->getRegisteredSiteType($siteTypeValue);
            if ($registered !== null) {
                [$SitePackage, $type] = $registered;
                $package = $SitePackage->getName();

            // site template
                $siteScript = $this->getContainedFile($SitePackage->getDir(), $SitePackage->getDir() . '/' . $type . '.php');

            // project template
                $projectRoot = $userDirectory . 'lib/' . $projectTemplate;
                $projectScript = $projectTemplate
                ? $this->getContainedFile($projectRoot, $projectRoot . '/' . $type . '.php')
                : null;

            // template
                $tplRoot = $this->TemplatePackage?->getDir();
                $tplScript = $tplRoot ? $this->getContainedFile($tplRoot, $tplRoot . '/' . $package . '/' . $type . '.php') : null;

                if ($tplScript !== null) {
                    $siteScript = $tplScript;
                }

            // site template
                $siteUsrRoot = $projectLibRoot;
                $siteUsrScript = $this->getContainedFile($siteUsrRoot, $siteUsrRoot . '/' . $package . '/' . $type . '.php');

                if ($siteUsrScript !== null) {
                    $siteScript = $siteUsrScript;
                }
            }
        }

        if ($siteType[0] == 'standard' && $templateRoot) {
            // site template
            $siteScript = $this->getContainedFile($templateRoot, $templateRoot . '/standard.php');
        }

        // includes
        if ($siteScript) {
            include $siteScript;
        }

        if ($projectScript) {
            include $projectScript;
        }

        QUI::getEvents()->fireEvent('templateSiteFetch', [$this, $Site]);

        $result = $Engine->fetch($tpl);

        // footer extend
        $footer = $this->footer;
        $footerExtend = implode('', $footer);

        return str_replace('</body>', $footerExtend . '</body>', $result);
    }

    /**
     * Return the template title
     * eq: <title></title>
     *
     * @throws QUI\Exception
     */
    public function getTitle(): string
    {
        $Site = $this->getAttribute('Site');
        $Project = $this->getAttribute('Project');

        $siteMetaTitle = $Site->getAttribute('quiqqer.meta.site.title');

        if ($siteMetaTitle) {
            QUI::getEvents()->fireEvent('templateGetSiteTitle', [$this, $Site]);

            return $Site->getAttribute('meta.seotitle');
        }

        // prefix / suffix
        if ($Project) {
            $projectName = $Project->getName();
            $localeGroup = 'project/' . $projectName;
            $localePrefix = 'template.prefix';
            $localeSuffix = 'template.suffix';

            if (QUI::getLocale()->exists($localeGroup, $localePrefix)) {
                $prefix = QUI::getLocale()->get($localeGroup, $localePrefix);

                if (!empty($prefix)) {
                    $this->setAttribute('site_title_prefix', htmlspecialchars($prefix) . ' ');
                }
            }

            if (QUI::getLocale()->exists($localeGroup, $localeSuffix)) {
                $suffix = QUI::getLocale()->get($localeGroup, $localeSuffix);

                if (!empty($suffix)) {
                    $this->setAttribute('site_title_suffix', ' ' . htmlspecialchars($suffix));
                }
            }
        }

        QUI::getEvents()->fireEvent('templateGetSiteTitle', [$this, $Site]);

        $title = $this->getAttribute('site_title_prefix');
        $title .= $Site->getAttribute('meta.seotitle');
        $title .= $this->getAttribute('site_title_suffix');
        $title .= $this->getAttribute('site_title');

        return htmlspecialchars($title);
    }

    /**
     * Return the HTML header
     * With all important meta-entries and quiqqer libraries
     *
     * @throws QUI\Exception
     */
    public function getHeader(): string
    {
        /* @var $Project QUI\Projects\Project */
        $Project = $this->getAttribute('Project');
        $Site = $this->getAttribute('Site');
        $Engine = $this->getAttribute('Engine');
        $sessionUser = QUI::getUserBySession();

        $siteType = $Site->getAttribute('type');
        $siteType = explode(':', $siteType);

        $files = [];

        if (isset($siteType[1])) {
            $package = $siteType[0];
            $type = $siteType[1];

            // type CSS
            $siteStyle = OPT_DIR . $package . '/bin/' . $type . '.css';
            $siteScript = OPT_DIR . $package . '/bin/' . $type . '.js';

            if (file_exists($siteStyle)) {
                $Engine->assign(
                    'siteStyle',
                    URL_OPT_DIR . $package . '/bin/' . $type . '.css'
                );
            }

            if (file_exists($siteScript)) {
                $Engine->assign(
                    'siteScript',
                    URL_OPT_DIR . $package . '/bin/' . $type . '.js'
                );
            }

            $realSitePath = OPT_DIR . $package . '/' . $type . '.css';

            if (file_exists($realSitePath)) {
                $this->extendHeader(
                    '<style>' . file_get_contents($realSitePath) . '</style>'
                );
            }
        }

        QUI::getEvents()->fireEvent('templateGetHeader', [$this]);

        // locale files
        try {
            $files = QUI\Translator::getJSTranslationFiles(
                $Project->getLang()
            );
        } catch (QUI\Exception) {
        }

        $locales = [];

        foreach (array_keys($files) as $package) {
            $locales[] = $package . '/' . $Project->getLang();
        }

        $localePublishVersion = md5((string)QUI::getPackageManager()->getLastUpdateDate());

        try {
            $localePublishVersion = QUI\Translator::getLocalePublishVersion();
        } catch (QUI\Exception) {
        }


        $headers = $this->header;
        $headerExtend = implode('', $headers);

        $headerExtend .= $this->getJsonLd()->getJsonLdSchema();

        // custom CSS
        $customCSS = $Project->getName() . '/bin/custom.css';
        $customJS = $Project->getName() . '/bin/custom.js';

        if (file_exists(USR_DIR . $customCSS)) {
            $headerExtend .= '<link rel="stylesheet" href="' . URL_USR_DIR . $customCSS . '" />';
        }

        if (file_exists(USR_DIR . $customJS)) {
            $headerExtend .= '<script src="' . URL_USR_DIR . $customJS . '"></script>';
        }

        // prefix / suffix
        $projectName = $Project->getName();
        $localeGroup = 'project/' . $projectName;
        $localePrefix = 'template.prefix';
        $localeSuffix = 'template.suffix';

        if (QUI::getLocale()->exists($localeGroup, $localePrefix)) {
            $prefix = QUI::getLocale()->get($localeGroup, $localePrefix);

            if (!empty($prefix)) {
                $this->setAttribute(
                    'site_title_prefix',
                    htmlspecialchars($prefix) . ' '
                );
            }
        }

        if (QUI::getLocale()->exists($localeGroup, $localeSuffix)) {
            $suffix = QUI::getLocale()->get($localeGroup, $localeSuffix);

            if (!empty($suffix)) {
                $this->setAttribute(
                    'site_title_suffix',
                    ' ' . htmlspecialchars($suffix)
                );
            }
        }

        // template logo
        if ($Project->getConfig('logo')) {
            $Engine->assign(
                'projectLogo',
                $Project->getMedia()->getLogoImage()->getSizeCacheUrl()
            );
        }

        // assign
        $Engine->assign([
            'Favicon' => new QUI\Projects\Favicon(),
            'Project' => $Project,
            'Site' => $Site,
            'Engine' => $Engine,
            'localeFiles' => $locales,
            'loadModuleFiles' => $this->onLoadModules,
            'headerExtend' => $headerExtend,
            'ControlManager' => new QUI\Control\Manager(),
            'Canonical' => $Engine->getCanonical(),
            'Hreflang' => new QUI\Projects\Site\Hreflang($Site),
            'lastUpdate' => QUI::getPackageManager()->getLastUpdateDate(),
            'localePublishVersion' => $localePublishVersion,
            'loaderSettings' => QUI\Projects\LoaderSettings::fromProject($Project),
            'languages' => implode(',', $Project->getLanguages()),
            'systemCountry' => QUI::conf('globals', 'country'),
            'sessionUserIsUser' => (int)QUI::getUsers()->isUser($sessionUser),
            'sessionUserIsNobody' => (int)QUI::getUsers()->isNobodyUser($sessionUser),
            'sessionUserIsAuth' => (int)QUI::getUsers()->isAuth($sessionUser)
        ]);

        if ($this->getAttribute('noConflict')) {
            return $Engine->fetch(LIB_DIR . 'templates/headerNoConflict.html');
        }

        return $Engine->fetch(LIB_DIR . 'templates/header.html');
    }

    /**
     * Build the general structured data shared by all frontend site types.
     */
    protected function createDefaultJsonLd(Interfaces\Projects\Site $Site): Utils\JsonLd
    {
        $Project = $Site->getProject();
        $JsonLd = new Utils\JsonLd();
        $websiteUrl = $this->getWebsiteUrl($Project);
        $pageUrl = $this->getAbsoluteSiteUrl($Site);
        $projectTitle = $this->normalizeJsonLdText($Project->getTitle());
        $siteTitle = $this->normalizeJsonLdText((string)$Site->getAttribute('title'));

        if ($projectTitle === '') {
            $projectTitle = $this->normalizeJsonLdText($Project->getName());
        }

        $JsonLd->set('type', 'WebPage');

        $organizationId = $websiteUrl . '#organization';
        $websiteId = $websiteUrl . '#website';

        $Publisher = new Controls\Utils\MetaList\Publisher();
        $Publisher->importFromProject($Project);
        $organization = $Publisher->toArray();
        $organization['@id'] = $organizationId;

        if (empty($organization['name'])) {
            $organization['name'] = $projectTitle;
        }

        if (empty($organization['url'])) {
            $organization['url'] = $websiteUrl;
        }

        $website = [
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => $websiteUrl,
            'name' => $projectTitle,
            'inLanguage' => $Project->getLang(),
            'publisher' => [
                '@id' => $organizationId
            ]
        ];

        $JsonLd->add('@id', $pageUrl . '#webpage');
        $JsonLd->add('url', $pageUrl);
        $JsonLd->add('name', $siteTitle);
        $JsonLd->add('inLanguage', $Project->getLang());
        $JsonLd->add('isPartOf', [
            '@id' => $websiteId
        ]);
        $JsonLd->add('publisher', [
            '@id' => $organizationId
        ]);

        $datePublished = Utils\StructuredData::getValidDate($Site->getAttribute('release_from'))
            ?? Utils\StructuredData::getValidDate($Site->getAttribute('c_date'));
        $dateModified = Utils\StructuredData::getModificationDate(
            $Site->getAttribute('c_date'),
            $Site->getAttribute('e_date')
        );

        if ($datePublished !== null) {
            $JsonLd->add('datePublished', $datePublished);
        }

        if ($dateModified !== null) {
            $JsonLd->add('dateModified', $dateModified);
        }

        $description = $this->normalizeJsonLdText((string)$Site->getAttribute('meta.description'));

        if ($description === '') {
            $description = $this->normalizeJsonLdText((string)$Site->getAttribute('short'));
        }

        if ($description !== '') {
            $JsonLd->add('description', $description);
        }

        try {
            $isHomePage = $Site->getId() === $Project->firstChild()->getId();
        } catch (QUI\Exception) {
            $isHomePage = false;
        }

        if ($isHomePage && $Project->getVHostPath() === '') {
            $JsonLd->setJsonLdNode('organization', $organization);
            $JsonLd->setJsonLdNode('website', $website);
        }

        try {
            $breadcrumbSites = $Site->getParents();
        } catch (QUI\Exception) {
            $breadcrumbSites = [];
        }

        $breadcrumbSites[] = $Site;
        $itemListElements = [];

        foreach ($breadcrumbSites as $BreadcrumbSite) {
            $name = $this->normalizeJsonLdText((string)$BreadcrumbSite->getAttribute('title'));

            if ($name === '') {
                $name = $this->normalizeJsonLdText((string)$BreadcrumbSite->getAttribute('name'));
            }

            if ($name === '') {
                continue;
            }

            try {
                $itemUrl = $this->getAbsoluteSiteUrl($BreadcrumbSite);
            } catch (QUI\Exception) {
                continue;
            }

            $itemListElements[] = [
                '@type' => 'ListItem',
                'position' => count($itemListElements) + 1,
                'name' => $name,
                'item' => $itemUrl
            ];
        }

        if (count($itemListElements) > 1) {
            $breadcrumbId = $pageUrl . '#breadcrumb';

            $JsonLd->set('breadcrumb', [
                '@id' => $breadcrumbId
            ]);
            $JsonLd->setJsonLdNode('breadcrumb', [
                '@type' => 'BreadcrumbList',
                '@id' => $breadcrumbId,
                'itemListElement' => $itemListElements
            ]);
        }

        return $JsonLd;
    }

    /**
     * Return the absolute rewritten URL of a site.
     */
    protected function getAbsoluteSiteUrl(Interfaces\Projects\Site $Site): string
    {
        $url = $this->normalizeJsonLdText($Site->getUrlRewritten());

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $host = rtrim($Site->getProject()->getVHost(true, true), '/');

        return $host . URL_DIR . ltrim($url, '/');
    }

    /**
     * Return the domain-level website URL without a project language path.
     */
    protected function getWebsiteUrl(Project $Project): string
    {
        $websiteUrl = rtrim($Project->getVHostBaseUrl(), '/') . '/';
        $languagePath = trim($Project->getVHostPath(), '/');

        if ($languagePath === '') {
            return $websiteUrl;
        }

        $languageSuffix = '/' . $languagePath . '/';

        if (!str_ends_with($websiteUrl, $languageSuffix)) {
            return $websiteUrl;
        }

        return substr($websiteUrl, 0, -strlen($languagePath . '/'));
    }

    /**
     * Normalize textual content for JSON-LD output.
     */
    protected function normalizeJsonLdText(string $text): string
    {
        $text = trim($text);

        do {
            $previous = $text;
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($text !== $previous);

        return $text;
    }

    /**
     * Return the layout of the template
     * If a template is set to the project
     *
     * @param array<string, mixed> $params - body params
     *
     * @return string
     */
    public function getLayout(array $params = []): string
    {
        $this->setAttributes($params);

        $layout = $this->getLayoutType();

        if (!$layout) {
            return $this->getBody($params);
        }

        if ($this->TemplatePackage === null) {
            return $this->getBody($params);
        }

        $templateName = $this->TemplatePackage->getName();
        $templatePath = OPT_DIR . $templateName;

        $templates = [$templatePath];

        if ($this->TemplateParent) {
            $templateParentName = $this->TemplateParent->getName();
            $templateParentPath = OPT_DIR . $templateParentName;

            $templates[] = $templateParentPath;
        }

        foreach ($templates as $template) {
            $layoutFile = $template . '/' . $layout . '.html';

            if (file_exists($layoutFile)) {
                return $this->getAttribute('Engine')->fetch($layoutFile);
            }
        }

        return '';
    }

    /**
     * Return the layout type
     *
     * @return string|false
     */
    public function getLayoutType(): bool|string
    {
        $Project = $this->getAttribute('Project');
        $Site = $this->getAttribute('Site');

        QUI\Utils\Site::setRecursiveAttribute($Site, 'layout');

        $layout = $Site->getAttribute('layout');
        $templates = $this->getProjectTemplates($Project);

        if (!$layout) {
            return false;
        }

        if (!is_array($templates)) {
            $templates = [];
        }

        foreach ($templates as $template) {
            $siteXML = $template . '/site.xml';

            if (!file_exists($siteXML)) {
                continue;
            }

            $Layout = QUI\Utils\Text\XML::getLayoutFromXml($siteXML, $layout);
            $layoutFile = $template . '/' . $layout . '.html';

            if (!$Layout) {
                continue;
            }

            if (!file_exists($layoutFile)) {
                continue;
            }

            return $layout;
        }

        return false;
    }

    /**
     * Return all project templates that have a site.xml
     * -> consider template inheritance
     *
     * @return array<int, string>|false
     */
    protected function getProjectTemplates(Projects\Project $Project): bool|array
    {
        $name = $Project->getName();

        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        $templates = [];

        $template = OPT_DIR . $Project->getTemplate();
        $siteXML = $template . '/site.xml';

        if (file_exists($siteXML)) {
            $templates[] = $template;
        }

        try {
            $Package = QUI::getPackage((string)$Project->getTemplate());
            $Parent = $Package->getTemplateParent();

            if ($Parent) {
                $siteXML = (string)$Parent->getXMLFilePath('site.xml');

                if (file_exists($siteXML)) {
                    $templates[] = OPT_DIR . $Parent->getName();
                }
            }
        } catch (QUI\Exception) {
        }

        $this->templates[$name] = $templates;

        if (empty($templates)) {
            $this->templates[$name] = false;
        }

        return $this->templates[$name];
    }

    /**
     * Return the Body of the Template
     * -> body.html
     *
     * @param array<string, mixed> $params - body params
     *
     * @return string
     */
    public function getBody(array $params = []): string
    {
        /* @var $Project QUI\Projects\Project */
        /* @var $Site QUI\Projects\Site */
        /* @var $Engine EngineInterface */

        $this->setAttributes($params);

        $Project = $this->getAttribute('Project');
        $Site = $this->getAttribute('Site');
        $Engine = $this->getAttribute('Engine');

        $template = LIB_DIR . 'templates/standard.html';

        $siteType = $Site->getAttribute('type');
        $siteType = explode(':', $siteType);

        if (isset($siteType[1])) {
            $package = $siteType[0];
            $type = $siteType[1];

            // site template
            $siteTemplate = OPT_DIR . $package . '/' . $type . '.html';
            $siteStyle = OPT_DIR . $package . '/bin/' . $type . '.css';

            if (file_exists($siteStyle)) {
                $Engine->assign(
                    'siteStyle',
                    URL_OPT_DIR . $package . '/bin/' . $type . '.css'
                );
            }

            if (file_exists($siteTemplate)) {
                $template = $siteTemplate;
            }

            // project template
            $projectTemplate = USR_DIR . $Project->getName() . '/lib/' . $type . '.html';

            if (file_exists($projectTemplate)) {
                $template = $projectTemplate;
            }
        }

        if ($siteType[0] == 'standard') {
            // site template
            $siteTemplate = OPT_DIR . $Project->getTemplate() . '/standard.html';
            $siteStyle = OPT_DIR . $Project->getTemplate() . '/bin/standard.css';

            if (file_exists($siteStyle)) {
                $Engine->assign(
                    'siteStyle',
                    URL_OPT_DIR . $Project->getTemplate() . '/standard.css'
                );
            }

            if (file_exists($siteTemplate)) {
                $template = $siteTemplate;
            }
        }

        if (!file_exists($template)) {
            $template = LIB_DIR . 'templates/standard.html';
        }

        $Engine->assign([
            'template' => $template
        ]);

        return $Engine->fetch($template);
    }
}
