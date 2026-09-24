<?php

/**
 *  _______          _________ _______  _______  _______  _______
 * (  ___  )|\     /|\__   __/(  ___  )(  ___  )(  ____ \(  ____ )
 * | (   ) || )   ( |   ) (   | (   ) || (   ) || (    \/| (    )|
 * | |   | || |   | |   | |   | |   | || |   | || (__    | (____)|
 * | |   | || |   | |   | |   | |   | || |   | ||  __)   |     __)
 * | | /\| || |   | |   | |   | | /\| || | /\| || (      | (\ (
 * | (_\ \ || (___) |___) (___| (_\ \ || (_\ \ || (____/\| ) \ \__
 * (____\/_)(_______)\_______/(____\/_)(____\/_)(_______/|/   \__/
 *
 * @author www.pcsg.com (Henning Leutz)
 */

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

// Mailto
if (
    isset($_REQUEST['_url'])
    && str_contains($_REQUEST['_url'], '[mailto]')
    && str_contains($_REQUEST['_url'], '[at]')
) {
    $addr = str_replace('[mailto]', '', $_REQUEST['_url']);
    [$user, $host] = explode("[at]", $addr);

    if (!empty($user) && !empty($host)) {
        header("Location: mailto:$user@$host");
        exit;
    }
}

use QUI\System\Log;
use QUI\Utils\Security\Orthos;
use QUI\Utils\System\Debug;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

$isPageRequest = false;

try {
    require_once 'bootstrap.php';

    if (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], 'index.php')) {
        QUI::getEvents()->fireEvent('errorHeaderShowBefore', [
            Response::HTTP_SEE_OTHER,
            $_SERVER['REQUEST_URI']
        ]);

        $Redirect = new RedirectResponse(URL_DIR);
        $Redirect->setStatusCode(Response::HTTP_SEE_OTHER);

        $Redirect->send();
        exit;
    }

    $Response = QUI::getGlobalResponse();
    $Request = QUI::getRequest();

    // UTF 8 Prüfung für umlaute in url
    if (isset($_REQUEST['_url'])) {
        $_REQUEST['_url'] = QUI\Utils\StringHelper::toUTF8($_REQUEST['_url']);
    }

    //\QUI\Utils\System\Debug::$run = true;
    Debug::marker('index start');

    // check if one projects exists
    if (!QUI::getProjectManager()->count()) {
        $Response->setStatusCode(Response::HTTP_NOT_FOUND);

        $Response->setContent(
            '<div style="text-align: center; margin-top: 100px;">
                <img src="' . URL_BIN_DIR . 'quiqqer_logo.png" style="max-width: 100%;" />
            </div>'
        );

        $Response->send();
        QUI::getEvents()->fireEvent('responseSent', [$Response]);
        exit;
    }

    // start
    $Rewrite = QUI::getRewrite();
    $Rewrite->exec();
    $isPageRequest = true;

    $Project = $Rewrite->getProject();
    $Site = $Rewrite->getSite();

    if ($Project === null || $Site === null) {
        throw new RuntimeException(
            'Rewrite did not initialize a project and site.'
        );
    }

    QUI::getLocale()->setCurrent(
        $Project->getLang()
    );


    // switch off language
    if (isset($_REQUEST['lang']) && $_REQUEST['lang'] == 'false') {
        $Response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        QUI::getLocale()->no_translation = true;
    }

    QUI::getTemplateManager()->assignGlobalParam('Project', $Project);
    QUI::getTemplateManager()->assignGlobalParam('Site', $Site);

    $Engine = QUI::getTemplateManager()->getEngine();

    $Site->load();

    if (isset($Locale)) {
        unset($Locale);
        $Locale = QUI::getLocale();
    }

    if (
        defined('LOGIN_FAILED')
        || isset($_POST['login'])
        || isset($_GET['logout'])
    ) {
        $Site->setAttribute('nocache', true);
    }

    /**
     * Referral System
     */
    if (isset($_REQUEST['ref'])) {
        QUI::getSession()->set('ref', Orthos::clear($_REQUEST['ref']));
    }

    /**
     * maintenance work
     */
    if (
        QUI::conf('globals', 'maintenance')
        && !(QUI::getUserBySession()->getId() && QUI::getUserBySession()->isSU())
    ) {
        $Response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
        $Response->headers->set('X-Powered-By', '');
        $Response->headers->set('Retry-After', '3600');

        $Smarty = QUI::getTemplateManager()->getEngine();

        $Smarty->assign([
            'Project' => $Project,
            'URL_DIR' => URL_DIR,
            'URL_BIN_DIR' => URL_BIN_DIR,
            'URL_LIB_DIR' => URL_LIB_DIR,
            'URL_VAR_DIR' => URL_VAR_DIR,
            'URL_OPT_DIR' => URL_OPT_DIR,
            'URL_USR_DIR' => URL_USR_DIR,
            'URL_TPL_DIR' => URL_USR_DIR . $Project->getName() . '/',
            'TPL_DIR' => OPT_DIR . $Project->getName() . '/',
        ]);

        $file = LIB_DIR . 'templates/maintenance.html';
        $pfile = USR_DIR . $Project->getName() . '/src/maintenance.html';

        if (file_exists($pfile)) {
            $file = $pfile;
        }

        $Response->setContent($Smarty->fetch($file));
        $Response->send();

        QUI::getEvents()->fireEvent('responseSent', [$Response]);
        exit;
    }

    // Event onstart
    QUI::getEvents()->fireEvent('start');
    Debug::marker('objekte initialisiert');

    if (!method_exists($Site, 'getCachePath')) {
        throw new QUI\Exception('No cache path available');
    }

    $siteCachePath = $Site->getCachePath() . '/' . md5(QUI::getRequest()->getRequestUri());

    // Check if user is allowed to view Site and set appropriate error code if not
    if ($Site instanceof QUI\Projects\Site\PermissionDenied) {
        $statusCode = (int)QUI::conf('globals', 'sitePermissionDeniedErrorCode');
        $Response->setStatusCode($statusCode);
    }

    // url query check
    // if url query exists, dont ask the cache and dont create the cache
    // @todo collect get query lists and consider the query params
    $query = $Request->getQueryString();

    if (is_string($query)) {
        parse_str($query, $query);
    }

    if (!is_array($query)) {
        $query = [];
    }

    if (isset($query['_url'])) {
        unset($query['_url']);
    }

    // if cache exists, and cache should also be used
    if (
        CACHE
        && !$Site->getAttribute('nocache')
        && !QUI::getUsers()->isAuth(QUI::getUserBySession())
        && empty($query)
        && $Rewrite->getHeaderCode() === 200
        && (defined('NO_INTERNAL_CACHE') && !NO_INTERNAL_CACHE)
        // api for modules, if modules says that no internal cache should be used
    ) {
        try {
            $cache_content = QUI\Cache\Manager::get($siteCachePath);
            $content = $Rewrite->outputFilter($cache_content);

            if (empty($content)) {
                throw new QUI\Exception('Empty content at ' . $Site->getId());
            }

            QUI::getEvents()->fireEvent('requestOutput', [&$content]);
            $Response->setContent($content);
            $Response->send();

            QUI::getEvents()->fireEvent('responseSent', [$Response]);
            exit;
        } catch (Exception $Exception) {
            Log::writeDebugException($Exception);
        }
    }

    // Template Content generating
    try {
        $Template = new QUI\Template();
        $content = $Template->fetchSite($Site);

        Debug::marker('fetch Template');

        $content = $Rewrite->outputFilter($content);
        $content = QUI\Control\Manager::setCSSToHead($content);
        Debug::marker('output done');

        QUI::getEvents()->fireEvent('requestOutput', [&$content]);

        if (empty($content)) {
            throw new QUI\Exception('Empty content at ' . $Site->getId(), 5001);
        }

        $Response->setContent($content);
        Debug::marker('content done');

        // cachefile erstellen
        if (
            !$Site->getAttribute('nocache')
            && !QUI::getUsers()->isAuth(QUI::getUserBySession())
            && empty($query)
            && $Rewrite->getHeaderCode() === 200
        ) {
            try {
                QUI\Cache\Manager::set($siteCachePath, $content);
            } catch (Exception $Exception) {
                Log::writeDebugException($Exception);
            }
        }

        if (Debug::$run) {
            Log::writeRecursive(Debug::output());
        }

        QUI::getSession()->set(
            'CURRENT_LANG',
            QUI::getLocale()->getCurrent()
        );
    } catch (QUI\Exception $Exception) {
        if ($Exception->getCode() == 404) {
            $Response->setStatusCode(Response::HTTP_NOT_FOUND);
        } else {
            $Response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 5001 is an internal "empty content" marker from this render path; do not log it as an error.
        if ($Exception->getCode() !== 5001) {
            if ($Exception instanceof QUI\Permissions\Exception) {
                Log::addInfo($Exception->getMessage());
            } else {
                Log::writeException($Exception);
            }
        }

        $Template = new QUI\Template();

        try {
            $content = $Template->fetchSite($Rewrite->getErrorSite());
        } catch (QUI\Exception $Exception) {
            $content = $Template->fetchSite($Project->firstChild());
        }

        $content = $Rewrite->outputFilter($content);
        $content = QUI\Control\Manager::setCSSToHead($content);

        $Response->setContent($content);
    }

    $Response->prepare(QUI::getRequest());
    $Response->send();

    QUI::getEvents()->fireEvent('responseSent', [$Response]);
} catch (Throwable $Exception) {
    // API handlers run during rewrite. Keep their PHP errors in the global handler.
    if (!$isPageRequest && !($Exception instanceof Exception)) {
        throw $Exception;
    }

    if ($Exception->getCode() === 705) { // site not found
        QUI\System\Log::addInfo($Exception->getMessage(), [
            'request' => $_REQUEST
        ]);
    } else {
        \QUI\Log\ErrorHandler::logUncaughtException($Exception);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo file_get_contents(
        dirname(__FILE__) . '/src/templates/error.html'
    );
}
