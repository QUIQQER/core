<?php

// phpcs:ignoreFile

define('QUIQQER_SYSTEM', true);
define('QUIQQER_BACKEND', true);
require __DIR__ . '/header.php';

QUI::getEvents()->fireEvent('adminRequest');

// qui path
$qui_path = URL_OPT_DIR . 'bin/qui/';
$qui_extend = URL_OPT_DIR . 'bin/qui/extend/';

$config = QUI::backendGuiConfigs();
$Project = null;

try {
    $Project = QUI::getProjectManager()->getStandard();
} catch (QUI\Exception $Exception) {
}

// user avatar
$User = QUI::getUserBySession();
$Avatar = $User->getAvatar();
$avatar = '';

if ($Avatar) {
    $avatar = $Avatar->getSizeCacheUrl(60, 60);
}

?>
<!doctype html>
<!--[if lt IE 7 ]>
<html class="ie ie6" lang="<?php
echo $User->getLang(); ?>"> <![endif]-->
<!--[if IE 7 ]>
<html class="ie ie7" lang="<?php
echo $User->getLang(); ?>"> <![endif]-->
<!--[if IE 8 ]>
<html class="ie ie8" lang="<?php
echo $User->getLang(); ?>"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!-->
<html lang="<?php
echo $User->getLang(); ?>"> <!--<![endif]-->
<head>
    <title>QUIQQER - <?php
        echo HOST ?></title>

    <!-- favicon -->
    <?php
    QUI::getEvents()->fireEvent('adminLoadBegin');

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

    <link
            href="//fonts.googleapis.com/css?family=Open+Sans:400,700,400italic"
            rel="stylesheet"
            type="text/css"
    />

    <meta name="viewport"
          content="width=device-width, initial-scale=1, minimum-scale=1,maximum-scale=1"
    />

    <meta name="robots" content="noindex,nofollow"/>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="description"
          content="Modular design. Convenient backend  Fast implementation. The all around carefree Enterprise Content Management System."
    />

    <!-- [begin] css combine -->

    <link href="<?php echo URL_BIN_DIR; ?>css/variables.css" rel="stylesheet" type="text/css"/>
    <link href="<?php echo URL_BIN_DIR; ?>css/buttons.css" rel="stylesheet" type="text/css"/>

    <link href="<?php
    echo URL_BIN_DIR; ?>css/tables.css"
          rel="stylesheet"
          type="text/css"
    />

    <link href="<?php
    echo URL_BIN_DIR; ?>css/badges.css" rel="stylesheet" type="text/css"/>

    <?php

    echo QUI\FontAwesome\FontAwesome::getLinkHtml(6);

    ?>

    <!--[if (lt IE 9) & (!IEMobile)]>
    <link href="<?php
    echo $qui_extend; ?>unsemantic/ie.css"
          rel="stylesheet"
          type="text/css"
    />
    <![endif]-->

    <link
            href="<?php
            echo $qui_extend; ?>unsemantic/unsemantic-grid-responsive.css"
            rel="stylesheet"
            type="text/css"
    />

    <link
            href="<?php
            echo URL_OPT_DIR; ?>quiqqer/messages/bin/messages.css"
            rel="stylesheet"
            type="text/css"
    />

    <link href="<?php
    echo $qui_extend; ?>animate.min.css"
          rel="stylesheet"
          type="text/css"
    />

    <link href="<?php
    echo $qui_extend; ?>classes.css"
          rel="stylesheet"
          type="text/css"
    />

    <link href="<?php
    echo $qui_extend; ?>buttons.css"
          rel="stylesheet"
          type="text/css"
    />

    <link href="<?php
    echo $qui_extend; ?>elements.css"
          rel="stylesheet"
          type="text/css"
    />

    <link href="<?php
    echo URL_BIN_DIR; ?>css/style.css"
          rel="stylesheet"
          type="text/css"
    />

    <script type="text/javascript">
        /* <![CDATA[ */
        var USER = {
            isSU: <?php echo $User->isSU() ? 1 : 0; ?>,
            id: "<?php echo $User->getUUID() ?: 0; ?>",
            lang: "<?php echo $User->getLang(); ?>",
            name: "<?php echo $User->getName(); ?>",
            avatar: "<?php echo $avatar;?>",
            username: "<?php echo $User->getUsername(); ?>"
        };

        var URL_DIR = "<?php echo URL_DIR; ?>",
            URL_LIB_DIR = "<?php echo URL_LIB_DIR; ?>",
            URL_BIN_DIR = "<?php echo URL_BIN_DIR; ?>",
            URL_USR_DIR = "<?php echo URL_USR_DIR; ?>",
            URL_SYS_DIR = "<?php echo URL_SYS_DIR; ?>",
            URL_OPT_DIR = "<?php echo URL_OPT_DIR; ?>",
            URL_VAR_DIR = "<?php echo URL_VAR_DIR; ?>";

        var PHP = {
            upload_max_filesize: "<?php echo QUI\Utils\System::getUploadMaxFileSize(); ?>",
            memory_limit: <?php echo QUI\Utils\System::getMemoryLimit(); ?>
        };

        var QUIQQER_VERSION = "<?php echo QUI::getPackageManager()->getVersion(); ?>";
        var QUIQQER_HASH = "<?php echo QUI::getPackageManager()->getHash(); ?>";
        var QUIQQER_CONFIG = <?php echo json_encode($config); ?>;

        // Exceptions
        var QUIQQER_EXCEPTION_CODE_PACKAGE_NOT_LICENSED = <?php echo QUI::getPackageManager(
        )::EXCEPTION_CODE_PACKAGE_NOT_LICENSED; ?>;

        // standard project
        var QUIQQER_PROJECT = <?php echo json_encode([
            'name' => $Project ? $Project->getName() : '',
            'lang' => $Project ? $Project->getLang() : ''
        ]); ?>;

        var QUIQQER = {
            loginBranding: <?php echo json_encode(
                QUI\Users\Controls\Login::getBranding($Project),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ); ?>,
            csrfToken: <?php echo json_encode(QUI\Security\CsrfToken::get()); ?>,
            Rewrite: {
                URL_PARAM_SEPARATOR: "<?php echo QUI\Rewrite::URL_PARAM_SEPARATOR; ?>",
                URL_SPACE_CHARACTER: "<?php echo QUI\Rewrite::URL_SPACE_CHARACTER; ?>",
                URL_PROJECT_CHARACTER: "<?php echo QUI\Rewrite::URL_PROJECT_CHARACTER; ?>",
                SUFFIX: "<?php echo QUI\Rewrite::getDefaultSuffix(); ?>"
            },
            ajax: '<?php echo URL_SYS_DIR; ?>ajax.php',
            inAdministration: true,
            lu: "<?php echo QUI::getPackageManager()->getLastUpdateDate(); ?>",
            vMd5: "<?php echo md5(QUI::version()); ?>",

            installPackage: function(packageName, version, server) {
                return new Promise(function(resolve, reject) {
                    require(['Packages'], function(Packages) {
                        Packages.installPackage(packageName, version, server).then(resolve, reject);
                    });
                });
            },

            updatePackage: function(packageName, version) {
                return new Promise(function(resolve, reject) {
                    require(['Packages'], function(Packages) {
                        Packages.update(packageName, version).then(resolve, reject);
                    });
                });
            },

            getPackage: function(packageName) {
                return new Promise(function(resolve, reject) {
                    require(['Packages'], function(Packages) {
                        Packages.getPackage(packageName).then(resolve, reject);
                    });
                });
            }
        };

        /* ]]> */
    </script>

    <?php
    /**
     * locale file
     */

    $files = [];

    try {
        $files = QUI\Translator::getJSTranslationFiles($User->getLang());
    } catch (QUI\Exception) {
    }

    $locales = [];

    foreach (array_keys($files) as $package) {
        $locales[] = $package . '/' . $User->getLang();
    }

    echo '<script type="text/javascript">';
    echo '/* <![CDATA[ */';
    echo 'var QUIQQER_LOCALE = ' . json_encode($locales);
    echo '/* ]]> */';
    echo '</script>';

    QUI::getEvents()->fireEvent('adminLoad');
    ?>

</head>
<body class="<?php
echo $User->getLang(); ?>">

<div id="wrapper">
    <!--
        <div class="qui-logo-container grid-100 grid-parent">
            <a href="/admin/">
                <img src="<?php
    echo URL_BIN_DIR; ?>quiqqer_logo_mini.png"
                    title="QUIQQER - Open Source Management System for Entrepreneurs"
                />
            </a>
        </div>
        -->

    <div class="qui-menu-container grid-100 grid-parent"></div>
    <div class="qui-workspace-container grid-100 grid-parent"></div>
</div>

<noscript>
    <div class="error"
         style="position: absolute; z-index: 100000; width: 100%; height: 30px; line-height: 30px; color: red; text-align: center;">
        JavaScript ist in Ihrem Browser nicht aktiviert. Bitte aktivieren Sie JavaScript
    </div>
</noscript>

<script src="<?php
echo URL_OPT_DIR; ?>bin/quiqqer-asset/requirejs/requirejs/require.js"></script>
<script src="<?php
echo URL_OPT_DIR; ?>bin/qui/qui/lib/mootools-core.js"></script>
<script src="<?php
echo URL_OPT_DIR; ?>bin/qui/qui/lib/mootools-more.js"></script>
<script src="<?php
echo URL_OPT_DIR; ?>bin/qui/qui/lib/moofx.js"></script>

<!-- load the quiqqer admin -->
<script src="<?php
echo URL_BIN_DIR; ?>QUI/init.js"></script>

<?php
QUI::getEvents()->fireEvent('adminLoadFooter');
?>

</body>
</html>
