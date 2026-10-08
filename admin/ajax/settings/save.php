<?php

/**
 * @param string $file
 * @param string $params - JSON Params
 *
 * @throws \QUI\Exception
 */

use QUI\System\Log;

QUI::getAjax()->registerFunction(
    'ajax_settings_save',
    static function ($file, $params): void {
        $jsonFiles = json_decode($file, true);
        $files = [];

        // realpath() resolves aliases and symlinks; dirname() alone would not provide canonical comparison paths.
        $coreConfigFile = realpath(__DIR__ . '/../../settings/conf.xml');
        $cacheConfigFile = realpath(__DIR__ . '/../../settings/cache.xml');

        if ($jsonFiles) {
            if (is_string($jsonFiles)) {
                $files = [$jsonFiles];
            } else {
                $files = $jsonFiles;
            }
        }

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $file = CMS_DIR . $file;
            }

            if (!file_exists($file)) {
                Log::addError(
                    QUI::getLocale()->get(
                        'quiqqer/core',
                        'exception.config.save.file.not.found'
                    )
                );

                continue;
            }

            $resolvedFile = realpath($file);

            if ($resolvedFile === false) {
                continue;
            }

            $file = $resolvedFile;
            $isCoreConfigFile = $coreConfigFile !== false && $file === $coreConfigFile;
            $isCacheConfigFile = $cacheConfigFile !== false && $file === $cacheConfigFile;

            if (is_string($params)) {
                $params = json_decode($params, true);
            }

            // csp data
            if (
                $isCoreConfigFile
                && isset($params['securityHeaders_csp'])
            ) {
                unset($params['securityHeaders_csp']);
            }

            // more bad workaround by hen
            // @todo need to fix that
            if ($isCacheConfigFile) {
                if (!empty($params['general']['cacheType'])) {
                    $cacheType = $params['general']['cacheType'];

                    $params['handlers'] = array_fill_keys([
                        'apc',
                        'filesystem',
                        'redis',
                        'memcache',
                        'mongo'
                    ], 0);

                    if (isset($params['handlers'][$cacheType])) {
                        $params['handlers'][$cacheType] = 1;
                    } else {
                        $params['handlers']['filesystem'] = 1;
                    }
                }
            }

            if ($isCoreConfigFile) {
                // Process lock credentials are handled by their dedicated settings endpoint.
                unset($params['locks']);

                // overwrite openssl settings is not allowed
                if (isset($params['openssl'])) {
                    unset($params['openssl']);
                }

                // overwrite database settings is not allowed
                if (isset($params['db'])) {
                    unset($params['db']);
                }

                if (empty($params['globals']['quiqqer_version'])) {
                    $params['globals']['quiqqer_version'] = QUI::conf('globals', 'quiqqer_version');
                }

                    // nonce check
                if (empty($params['globals']['nonce'])) {
                    throw new QUI\Exception('Could not save QUIQQER config');
                }

                $currentNonce = $params['globals']['nonce'];
                $oldNonce = QUI::conf('globals', 'nonce');

                if ($currentNonce !== $oldNonce) {
                    throw new QUI\Exception('Could not save QUIQQER config');
                }

                unset($params['globals']['nonce']);
            }

            QUI\Utils\Text\XML::setConfigFromXml($file, $params);
            QUI::getConfig('etc/conf.ini.php')->reload();

            QUI::getMessagesHandler()->addSuccess(
                QUI::getLocale()->get('quiqqer/core', 'message.config.saved')
            );

            // bad workaround by hen
            if (!$isCoreConfigFile) {
                continue;
            }

            if (isset($params['globals']['quiqqer_version'])) {
                try {
                    QUI::getPackageManager()->setQuiqqerVersion(
                        $params['globals']['quiqqer_version']
                    );
                } catch (UnexpectedValueException $Exception) {
                    QUI::getMessagesHandler()->addError($Exception->getMessage());
                }
            }

            // generate ./console
            QUI\Setup::generateFileLinks();

            # Save the current .htaccess content to see if the config changed
            $oldContent = "";

            if (file_exists(CMS_DIR . ".htaccess")) {
                $oldContent = file_get_contents(CMS_DIR . ".htaccess");
            }

            $Htaccess = new QUI\System\Console\Tools\Htaccess();
            $Htaccess->execute();


            $webserverConfig = QUI::conf("webserver", "type");

            if (
                $webserverConfig !== false && is_string($webserverConfig)
                && str_contains($webserverConfig, "apache")
            ) {
                continue;
            }

            # Compare new and old .htaccess
            try {
                $webServer = QUI\Utils\System\Webserver::detectInstalledWebserver();
            } catch (Exception) {
                $webServer = "";
            }

            if ($webServer === QUI\Utils\System\Webserver::WEBSERVER_APACHE) {
                continue;
            }


            if (empty($oldContent)) {
                continue;
            }

            // Regeneration can replace the file; do not reuse its cached status from before execute().
            clearstatcache(true, CMS_DIR . ".htaccess");

            if (!is_file(CMS_DIR . ".htaccess")) {
                continue;
            }

            $newContent = file_get_contents(CMS_DIR . ".htaccess");

            if ($newContent != $oldContent) {
                QUI::getMessagesHandler()->addInformation(
                    QUI::getLocale()->get(
                        "quiqqer/core",
                        "message.config.webserver.changed"
                    )
                );
            }
        }
    },
    ['file', 'params'],
    [
        'Permission::checkAdminUser',
        'quiqqer.settings'
    ]
);
