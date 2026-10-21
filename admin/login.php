<?php

// phpcs:ignoreFile

use QUI\Projects\Media\Utils;

QUI::getEvents()->fireEvent('adminRequest');

$languages = QUI::availableLanguages();
$packages = QUI::getPackageManager()->getInstalled();
$defaultLanguage = QUI::conf('globals', 'standardLanguage');

if (!empty($defaultLanguage)) {
    QUI::getLocale()->setCurrent($defaultLanguage);
}

$authPackages = [];
$quiqqerLogo = QUI::getLocale()->get('quiqqer/core', 'menu.quiqqer.text');

$projectLogo = '';

try {
    $Standard = QUI::getProjectManager()->getStandard();

    $projectLogo = $Standard->getConfig('logo');
    $projectName = $Standard->getName();

    if (Utils::isMediaUrl($Standard->getConfig('logo'))) {
        $ProjectImage = Utils::getImageByUrl($Standard->getConfig('logo'));
        $projectLogo = '<img src="' . $ProjectImage->getSizeCacheUrl() . '" />';
    }
} catch (QUI\Exception) {
}

foreach ($packages as $package) {
    try {
        $Package = QUI::getPackage($package['name']);

        if (!$Package->isQuiqqerPackage()) {
            continue;
        }

        $auth = $Package->getProvider('auth');

        if (!empty($auth)) {
            $authPackages[] = $Package->getName();
        }
    } catch (QUI\Exception) {
    }
}

?>
<!doctype html>
<!--[if lt IE 7 ]>
<html class="ie ie6" lang="de"> <![endif]-->
<!--[if IE 7 ]>
<html class="ie ie7" lang="de"> <![endif]-->
<!--[if IE 8 ]>
<html class="ie ie8" lang="de"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!-->
<html lang="de"> <!--<![endif]-->
<head>
    <?php
    QUI::getEvents()->fireEvent('adminLoadBegin');
    ?>

    <title>
        QUIQQER Content Management System - <?php
        echo HOST ?> -
    </title>

    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1,maximum-scale=1"/>

    <!-- favicon -->
    <?php
    $favicon = QUI::conf('globals', 'favicon');

    if ($favicon) {
        try {
            $Favicon = QUI\Projects\Media\Utils::getImageByUrl($favicon);
            $attributes = $Favicon->getAttributes();
            $type = $attributes['mime_type'];

            ?>
            <link rel="shortcut icon" href="<?php
            echo $Favicon->getSizeCacheUrl(62, 62); ?>"
                  type="<?php
                  echo $type; ?>">

            <link rel="icon" href="<?php
            echo $Favicon->getSizeCacheUrl(16, 16); ?>" sizes="16x16"
                  type="<?php
                  echo $type; ?>">
            <link rel="icon" href="<?php
            echo $Favicon->getSizeCacheUrl(32, 32); ?>" sizes="32x32"
                  type="<?php
                  echo $type; ?>">
            <link rel="icon" href="<?php
            echo $Favicon->getSizeCacheUrl(48, 48); ?>" sizes="48x48"
                  type="<?php
                  echo $type; ?>">
            <link rel="icon" href="<?php
            echo $Favicon->getSizeCacheUrl(62, 62); ?>" sizes="62x62"
                  type="<?php
                  echo $type; ?>">
            <?php
        } catch (QUI\Exception) {
        }
    }
    ?>

    <?php

    /**
     * locale file
     */
    $files = [];

    foreach ($authPackages as $package) {
        foreach ($languages as $lang) {
            $files[] = 'locale/' . $package . '/' . $lang;
        }
    }

    echo '<script type="text/javascript">';
    echo '/* <![CDATA[ */';
    echo 'var QUIQQER_LOCALE = ' . json_encode($files) . ';';
    echo 'var QUIQQER_LANGUAGES = ' . json_encode($languages) . ';';
    echo 'var QUIQQER_IS_ADMIN_LOGIN = true;';
    echo 'var QUIQQER = ' . json_encode([
        'loginBranding' => QUI\Users\Controls\Login::getBranding($Standard ?? null),
        'csrfToken' => QUI\Security\CsrfToken::get(),
        'inAdministration' => true,
        'vMd5' => md5(QUI::version())
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
    echo 'var QUIQQER_CONFIG = {globals: {no_ajax_bundler: 1}}';
    echo '/* ]]> */';
    echo '</script>';
    ?>

    <link href="<?php echo URL_BIN_DIR; ?>css/variables.css" rel="stylesheet"/>
    <link href="<?php echo URL_BIN_DIR; ?>css/buttons.css" rel="stylesheet"/>

    <style type="text/css">
        :root {
            --color-primary: #2f8fc6;
            --color-primary-dark: #0e3145;
            --color-primary-accent: #fff;

            --color-secondary: #44565f;
            --color-secondary-accent: #fff;

            --text-body: #1e2021;
            --text-muted: #6d7b86;

            --radius: 1rem;
            --radius-sm: 0.25rem;
        }


        * {
            -webkit-box-sizing: border-box;
            -moz-box-sizing: border-box;
            box-sizing: border-box;
        }

        html, body {
            background: #ffffff;
            color: var(--text-body);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            margin: 0;
            padding: 0;
            text-align: left;
            width: 100%;
            line-height: 1.5;
        }

        h1, h2, h3 {
            line-height: 1.1;
        }

        img {
            max-width: 100%;
        }

        .container {
            min-height: 100svh;
        }

        .loginBox {
            position: relative;
            max-width: 1000px;
            width: 100%;
            background-color: #fff;
        }

        @media screen and (min-width: 768px) {
            .container {
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                background-color: #fafafa;
                background-image: linear-gradient(45deg, #fafafa, #eee);
            }

            .loginBox {
                display: flex;
                -webkit-box-shadow: 0 80px 30px -50px rgba(0, 0, 0, 0.1);
                box-shadow: 0 80px 30px -50px rgba(0, 0, 0, 0.1);
                border-radius: var(--radius);
                min-height: min(90vh, 720px);
            }
        }

        /* aside (left side) */
        .loginBox__aside {
            padding: 2rem;
            flex-grow: 1;
            width: 400px;
            display: flex;
            border-radius: var(--radius);
            background-repeat: no-repeat, repeat;
            background-size: 100% auto, 100% 150%;
            color: var(--color-primary-accent);
            background-image: url('<?php echo URL_BIN_DIR . 'images/admin-login-image.svg'; ?>'), linear-gradient(-10deg, var(--color-primary), var(--color-primary-dark));
            background-color: #2f8fc6;
            background-position: 80% bottom, 0 0;
            transition: 1s ease;
        }

        @media screen and (max-width: 767px) {
            .loginBox__aside {
                display: none;
            }
        }

        .loginBox__aside-footer {
            position: absolute;
            left: 1rem;
            bottom: 1rem;
            color: var(--text-body);
        }

        .loginBox__aside-footer .logo,
        .loginBox__main-footer .logo {
            font-size: 0.625rem;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .loginBox__aside-footer img,
        .loginBox__main-footer img {
            height: 24px;
        }

        /* main content (right side) */
        .loginBox__main {
            padding: 4rem 2rem;
            flex-grow: 1;
            flex-basis: 500px;
            position: relative;
        }

        .mainContent {
            max-width: 20rem;
            margin-inline: auto;
        }

        .mainContent .slogan__login {
            margin-bottom: 1rem;
            text-align: center;
        }

        .mainContent .logo {
            text-align: center;
            margin-top: 1rem;
            margin-bottom: 3rem;
        }

        .mainContent .logo img {
            max-height: 100px;
        }

        /* login control */
        .mainContent .login-container {
            min-height: 260px;
            display: grid;
        }

        .mainContent .quiqqer-login-auth label span {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .mainContent .login-container input {
            line-height: 1.5rem;
            padding: 0.5rem 1rem;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
        }

        .mainContent button {
            line-height: 1.5;
            font-size: 16px;
            border: none;
            padding: 0.5rem 1.5rem;
            cursor: pointer;
            border-radius: var(--radius-sm);
        }

        .mainContent [type="submit"] {
            color: var(--color-primary-accent);
            background-color: var(--color-primary);
            width: 100%;
        }

        .mainContent button[type="reset"],
        .mainContent button[name="cancel"] {
            background-color: var(--color-secondary);
            color: var(--color-secondary-accent);
        }

        /* lang switch */
        .quiqqer-language-switch {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.875rem;
        }

        .quiqqer-language-switch > .qui-select {
            border: none;
            display: flex;
            justify-content: flex-end;
        }

        .quiqqer-language-switch > .qui-select .text {
            width: auto;
            margin-right: 0.5em;
        }

        /* main footer */
        .loginBox__main-footer {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            width: calc(100% - 2rem);
            display: flex;
        }

        @media screen and (min-width: 768px) {
            .loginBox__main-footer .logo {
                display: none;
            }
        }

        /* license */
        .licenseToggleButton {
            margin-left: auto;
            font-size: 0.75rem;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .licenseToggleButton .fa {
            margin-right: 0.25em;
        }

        .license {
            font-size: 0.75rem;
            margin-top: 2rem;
            max-width: 25rem;
            margin-inline: auto;
            color: var(--text-muted);
            overflow: hidden;
        }

        .license__text > a {
            color: inherit;
            text-decoration: underline;
        }

        @media screen and (max-width: 767px) {
            .loginBox__main {
                min-height: 100vh;
                min-height: 100svh;
            }

            .mainContent .logo {
                margin-bottom: 2rem;
            }
        }

    </style>


    <?php

    echo QUI\FontAwesome\EventHandler::fontawesome();

    ?>

    <script src="<?php
    echo URL_OPT_DIR; ?>bin/quiqqer-asset/requirejs/requirejs/require.js"></script>
    <script src="<?php
    echo URL_OPT_DIR; ?>bin/qui/qui/lib/mootools-core.js"></script>
    <script src="<?php
    echo URL_OPT_DIR; ?>bin/qui/qui/lib/mootools-more.js"></script>
    <script src="<?php
    echo URL_OPT_DIR; ?>bin/qui/qui/lib/moofx.js"></script>

    <script type="text/javascript">

        const URL_DIR = '<?php echo URL_DIR; ?>',
            URL_BIN_DIR = '<?php echo URL_BIN_DIR; ?>',
            URL_OPT_DIR = '<?php echo URL_OPT_DIR; ?>',
            URL_SYS_DIR = '<?php echo URL_SYS_DIR; ?>';

        let LANGUAGE = null;

        // require config
        require.config({
            baseUrl: '<?php echo URL_BIN_DIR; ?>QUI/',
            paths: {
                'package': "<?php echo URL_OPT_DIR; ?>",
                'qui': '<?php echo URL_OPT_DIR; ?>bin/qui/qui',
                'locale': '<?php echo URL_VAR_DIR; ?>locale/bin',
                'Ajax': '<?php echo URL_BIN_DIR; ?>QUI/Ajax',
                'URL_OPT_DIR': "<?php echo URL_OPT_DIR; ?>",
                'URL_BIN_DIR': "<?php echo URL_BIN_DIR; ?>",

                'Mustache': URL_OPT_DIR + 'bin/quiqqer-asset/mustache/mustache/mustache.min',
                'URI': URL_OPT_DIR + 'bin/quiqqer-asset/urijs/urijs/src/URI',
                'IPv6': URL_OPT_DIR + 'bin/quiqqer-asset/urijs/urijs/src/IPv6',
                'punycode': URL_OPT_DIR + 'bin/quiqqer-asset/urijs/urijs/src/punycode',
                'SecondLevelDomains': URL_OPT_DIR + 'bin/quiqqer-asset/urijs/urijs/src/SecondLevelDomains'
            },
            waitSeconds: 0,
            catchError: true,
            map: {
                '*': {
                    'css': '<?php echo URL_OPT_DIR; ?>bin/qui/qui/lib/css.js'
                }
            }
        });

        function getCurrentLanguage() {
            if (LANGUAGE) {
                return LANGUAGE;
            }

            let lang = 'en';

            if ('language' in navigator) {
                lang = navigator.language;
            } else {
                if ('browserLanguage' in navigator) {
                    lang = navigator.browserLanguage;
                } else {
                    if ('systemLanguage' in navigator) {
                        lang = navigator.systemLanguage;
                    } else {
                        if ('userLanguage' in navigator) {
                            lang = navigator.userLanguage;
                        }
                    }
                }
            }

            LANGUAGE = lang.substr(0, 2);

            return LANGUAGE;
        }

        function setLanguage(lang) {
            if (!QUIQQER_LANGUAGES.contains(lang)) {
                lang = QUIQQER_LANGUAGES[0];
            }

            return new Promise(function (resolve) {
                require([
                    'qui/QUI',
                    'utils/Session',
                    'Locale',
                    'locale/quiqqer/core/' + lang
                ], function (QUI, Session, QUILocale) {
                    QUILocale.setCurrent(lang);
                    Session.set('quiqqer-user-language', lang).catch(function (err) {
                        // doesn't matter
                    });

                    document.getElements('.slogan__login').set(
                        'html',
                        QUILocale.get('quiqqer/core', 'loginBox.header.text')
                    );

                    document.getElements('.slogan__title h1').set(
                        'html',
                        QUILocale.get('quiqqer/core', 'loginBox.aside.title')
                    );

                    document.getElements('.slogan__title p').set(
                        'html',
                        QUILocale.get('quiqqer/core', 'loginBox.aside.slogan')
                    );

                    document.getElements('.logo__text').set(
                        'html',
                        QUILocale.get('quiqqer/core', 'loginBox.powered')
                    );

                    document.getElements('.licenseToggleButton-license').set(
                        'html',
                        QUILocale.get('quiqqer/core', 'loginBox.license')
                    );

                    const LoginElement = document.getElement('.quiqqer-login');

                    if (!LoginElement) {
                        resolve();
                        return;
                    }

                    QUI.Controls.getById(LoginElement.get('data-quiid')).refresh();

                    resolve();
                });
            });
        }

        // init
        require([
            'qui/QUI',
            'controls/users/Login'
        ].append(QUIQQER_LOCALE || []), function (QUI, Login) {
            QUI.setAttributes({
                'control-loader-type': 'line-scale',
                'control-loader-color': '#2f8fc8'
            });

            setLanguage(getCurrentLanguage()).then(function () {
                document.getElement('.login-container').set('html', '');

                const LogIn = new Login({
                    onSuccess: window.onSuccess
                }).inject(document.getElement('.login-container'));


                // chrome workaround - because of state saving
                // sometimes, chrome don't load all events on a back up'd tab
                (function () {
                    const Form = LogIn.getElm().getElement('form');

                    if (!Form) {
                        LogIn.refresh();
                        return;
                    }

                    if (!parseInt(Form.getStyle('opacity'))) {
                        LogIn.refresh();
                    }
                }).delay(2000);
            });

            document.querySelector('.licenseToggleButton').addEventListener('click', () => {
                const License = document.getElement('.license');

                if (License.offsetHeight === 0) {
                    showLicenseText();
                } else {
                    hideLicenseText();
                }
            });
        });

        function onSuccess(Login) {
            require([
                'Ajax',
                'Locale'
            ], function (QUIAjax, QUILocale) {
                // check if admin user
                QUIAjax.get('ajax_user_canUseBackend', function (canUseAdmin) {
                    if (canUseAdmin === false) {
                        QUI.getMessageHandler().then(function (MH) {
                            MH.addError(
                                QUILocale.get(
                                    'quiqqer/core',
                                    'exception.permission.no.admin'
                                )
                            );
                        });

                        Login.Loader.hide();
                        return;
                    }

                    moofx(document.getElement('.container')).animate({
                        opacity: 0
                    }, {
                        duration: 200,
                        callback: function () {
                            window.location.reload();
                        }
                    });
                });
            });
        }

        /**
         * Show license text
         */
        function showLicenseText() {
            const License = document.getElement('.license'),
                Inner = document.getElement('.license__text');

            Inner.style.visibility = null;

            moofx(License).animate({
                height: Inner.offsetHeight
            }, {
                duration: 300,
                callback: function () {
                    License.style.height = null;
                }
            });
        }

        /**
         * Hide license text
         */
        function hideLicenseText() {
            const License = document.getElement('.license'),
                Inner = document.getElement('.license__text');

            moofx(License).animate({
                height: 0
            }, {
                duration: 300,
                callback: function () {
                    Inner.style.visibility = null;

                }
            });
        }

    </script>

    <?php
    QUI::getEvents()->fireEvent('adminLogin');
    ?>
</head>
<body>

<div class="container">
    <div class="loginBox">
        <div class="loginBox__aside">
            <div class="slogan__title">
                <h1>
                    <span class="fa fa-spinner fa-spin"></span>
                </h1>

                <p>
                    <span class="fa fa-spinner fa-spin"></span>
                </p>
            </div>

            <div class="loginBox__aside-footer">
                <?php
                if (!empty($projectLogo)) {
                    echo '<div class="logo logo--quiqqer"><span class="logo__text">Powered by</span>';
                    echo $quiqqerLogo;
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        <div class="loginBox__main">
            <div class="quiqqer-language-switch"></div>

            <div class="mainContent">
                <?php

                if (!empty($projectLogo)) {
                    echo '<div class="logo logo--project">';
                    echo $projectLogo;
                    echo '</div>';
                } else {
                    echo '<div class="logo logo--quiqqer">';
                    echo $quiqqerLogo;
                    echo '</div>';
                }

                ?>

                <p class="slogan__login">
                    <span class="fa fa-spinner fa-spin"></span>
                </p>

                <div class="login-container"></div>
            </div>

            <div class="license" style="height: 0;">
                <div class="license__text" style="visibility: hidden;">
                    QUIQQER Copyright(C) <?php
                    echo date('Y'); ?> PCSG - Computer & Internet Service OHG - <a href="https://www.pcsg.de"
                                                                                   target="_blank">www.pcsg.de</a>.
                    This program comes with ABSOLUTELY NO WARRANTY;
                    This is free software, and you are welcome to redistribute it under certain conditions;
                    Visit <a href="https://www.quiqqer.com" target="_blank">www.quiqqer.com</a> for details.
                </div>
            </div>

            <div class="loginBox__main-footer">
                <?php
                if (!empty($projectLogo)) {
                    echo '<div class="logo logo--quiqqer"><span class="logo__text">Powered by</span>';
                    echo $quiqqerLogo;
                    echo '</div>';
                }
                ?>

                <span role="button" class="licenseToggleButton">
                    <span class="fa fa-copyright"></span>
                    <span class="licenseToggleButton-license"></span>
                </span>
            </div>
        </div>
    </div>
</div>

<?php
if (defined('LOGIN_FAILED')) { ?>
    <script type="text/javascript">
        require(['qui/QUI'], function () {
            QUI.getMessageHandler().then(function (MH) {
                MH.addError("<?php echo LOGIN_FAILED; ?>");
            });
        });
    </script>
    <?php
} ?>

<script>
    var LoginContainer = document.getElement('.quiqqer-language-switch'),
        needle = [
            'qui/controls/buttons/Select',
            'Locale'
        ];

    for (let i = 0, len = QUIQQER_LANGUAGES.length; i < len; i++) {
        needle.push('locale/quiqqer/core/' + QUIQQER_LANGUAGES[i]);
    }

    require(needle, function (QUISelect, QUILocale) {
        const Select = new QUISelect({
            events: {
                onChange: function () {
                    setLanguage(Select.getValue());
                }
            }
        }).inject(LoginContainer);

        let i, len, lang, text;
        const current = QUILocale.getCurrent();

        for (i = 0, len = QUIQQER_LANGUAGES.length; i < len; i++) {
            lang = QUIQQER_LANGUAGES[i];
            QUILocale.setCurrent(lang);

            text = QUILocale.get('quiqqer/core', 'language.' + lang);

            if (!QUILocale.exists('quiqqer/core', 'language.' + lang)) {
                QUILocale.setCurrent('en');
                text = QUILocale.get('quiqqer/core', 'language.' + lang);
            }

            Select.appendChild(
                text,
                lang,
                URL_BIN_DIR + '16x16/flags/' + lang + '.png'
            );
        }

        QUILocale.setCurrent(current);
        Select.setValue(getCurrentLanguage());
    }, function () {

    });
</script>

<?php
QUI::getEvents()->fireEvent('adminLoginFooter');
?>

</body>
</html>
