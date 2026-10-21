<?php

/**
 * This file contains \QUI\Request\Bundler
 */

namespace QUI\Request;

use Exception;
use QUI;
use QUI\Utils\Security\Orthos;

use function file_exists;
use function is_array;
use function json_decode;
use function json_encode;
use function realpath;
use function str_replace;

use const JSON_THROW_ON_ERROR;

/**
 * Class Bundler
 *
 * @author  www.pcsg.de (Henning Leutz)
 */
class Bundler
{
    /**
     * Files includes
     *
     * @var array<string, string|false>
     */
    protected array $includes = [];

    /**
     * Bundler constructor.
     */
    public function __construct()
    {
        QUI::getAjax();
    }

    /**
     * Read the $_REQUEST and create the response
     */
    public function response(): bool|string
    {
        if (!isset($_REQUEST['quiqqerBundle'])) {
            return '';
        }

        $result = [];
        $requests = $_REQUEST['quiqqerBundle'];

        foreach ($requests as $request) {
            try {
                $result[$request['rid']] = $this->parseRequest($request);
            } catch (Exception $Exception) {
                $result[$request['rid']]['Exception'] = [
                    'message' => $Exception->getMessage(),
                    'code' => $Exception->getCode(),
                    'type' => $Exception::class
                ];
            }
        }

        QUI::getEvents()->fireEvent('ajaxResult', [&$result]);

        try {
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (Exception $Exception) {
            QUI\System\Log::writeRecursive($result);
            QUI\System\Log::writeException($Exception);

            $result['Exception'] = [
                'message' => $Exception->getMessage(),
                'code' => $Exception->getCode(),
                'type' => $Exception::class
            ];

            return json_encode($result);
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     *
     * @throws QUI\Exception
     */
    protected function parseRequest(array $request): array
    {
        if (!isset($request['request'])) {
            throw new QUI\Exception('Bad Request', 400);
        }

        foreach ($request['params'] as $k => $value) {
            $request['params'][$k] = json_decode($value, true);
        }

        $function = $request['request'];

        if (!is_array($function)) {
            $function = [$function];
        }

        $result = [
            'maintenance' => QUI::conf('globals', 'maintenance') ? 1 : 0
        ];

        foreach ($function as $fun) {
            $this->includes($fun);
            $this->includesPackage($fun, $request);
            $this->includesProject($fun, $request);

            QUI::getAjax()::checkPermissions($fun);

            $data = QUI::getAjax()->callRequestFunction($fun, $request['params']);

            if (QUI\Ajax::isBackendCsrfProtectionRequired()) {
                $result['csrfToken'] = QUI\Security\CsrfToken::get();
            }

            // session close -> performance
            $SymfonySession = QUI::getSession()->getSymfonySession();

            if ($SymfonySession !== false) {
                $SymfonySession->save();
            }

            // maintenance flag
            $result[$fun] = $data;
        }

        // messages
        $result['message_handler'] = QUI::getMessagesHandler()->getMessagesAsArray(QUI::getUserBySession());
        QUI::getMessagesHandler()->clear();

        $result['jsCallbacks'] = QUI::getAjax()->getJsCallbacks();

        return $result;
    }

    /**
     * Include normal files
     *
     * @param string|array<int, string> $function - name of the function
     */
    protected function includes(string|array $function): void
    {
        if (is_array($function)) {
            foreach ($function as $f) {
                $this->includes($f);
            }

            return;
        }

        if (isset($this->includes[$function])) {
            return;
        }

        // admin ajax
        $file = OPT_DIR . 'quiqqer/core/admin/' . str_replace('_', '/', $function) . '.php';
        $file = Orthos::clearPath($file);
        $file = realpath($file);

        $dir = OPT_DIR . 'quiqqer/core/admin/';

        if (str_contains((string)$file, $dir) && file_exists((string)$file)) {
            require_once $file;

            $this->includes[$function] = $file;

            return;
        }


        $file = CMS_DIR . str_replace('_', '/', $function) . '.php';
        $file = Orthos::clearPath($file);
        $file = realpath($file);

        if (str_contains((string)$file, CMS_DIR) && file_exists((string)$file)) {
            require_once $file;

            $this->includes[$function] = $file;
        }
    }

    /**
     * Include package files
     *
     * @param string|array<int, string> $function - name of the function
     * @param array<string, mixed> $request - Request data
     */
    protected function includesPackage(string|array $function, array $request): void
    {
        if (!isset($request['params']['package'])) {
            return;
        }

        if (is_array($function)) {
            foreach ($function as $f) {
                $this->includesPackage($f, $request);
            }

            return;
        }

        if (isset($this->includes[$function])) {
            return;
        }

        $package = $request['params']['package'];
        $dir = OPT_DIR;

        $firstpart = 'package_' . str_replace('/', '_', $package);
        $ending = str_replace($firstpart, '', $function);

        $file = $dir . $package . str_replace('_', '/', $ending) . '.php';
        $file = Orthos::clearPath($file);
        $file = realpath($file);

        if (str_contains((string)$file, $dir) && file_exists((string)$file)) {
            require_once $file;
        }

        $this->includes[$function] = $file;
    }

    /**
     * Include projects files
     *
     * @param string|array<int, string> $function - name of the function
     * @param array<string, mixed> $request - Request data
     *
     * @throws QUI\Exception
     */
    protected function includesProject(string|array $function, array $request): void
    {
        if (!isset($request['params']['project'])) {
            return;
        }

        if (is_array($function)) {
            foreach ($function as $f) {
                $this->includesProject($f, $request);
            }

            return;
        }

        if (isset($this->includes[$function])) {
            return;
        }

        try {
            $Project = QUI::getProjectManager()->decode($request['params']['project']);
        } catch (QUI\Exception) {
            $Project = QUI::getProjectManager()->getProject(
                $request['params']['project']
            );
        }

        $projectDir = USR_DIR . $Project->getName();
        $firstpart = 'project_' . $Project->getName() . '_';

        $file = str_replace($firstpart, '', $function);
        $file = $projectDir . '/lib/' . str_replace('_', '/', $file) . '.php';
        $file = Orthos::clearPath($file);
        $file = realpath($file);

        $dir = $projectDir . '/lib/';

        if (str_contains((string)$file, $dir) && file_exists((string)$file)) {
            require_once $file;
        }

        $this->includes[$function] = $file;
    }
}
