<?php

declare(strict_types=1);

use QUI\Upload\MobileUpload\Labels;

define('QUIQQER_SYSTEM', true);
require_once dirname(__DIR__, 7) . '/header.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob:; "
    . "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$language = QUI::getRequest()->getPreferredLanguage(QUI::availableLanguages())
    ?? QUI::getLocale()->getCurrent();
$labels = Labels::get($language);
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assets = URL_OPT_DIR . 'quiqqer/core/bin/QUI/controls/upload/mobileUpload/';
$coreStyles = URL_OPT_DIR . 'quiqqer/core/bin/css/';
?>
<!doctype html>
<html lang="<?= $escape($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($labels['title']) ?></title>
    <link rel="stylesheet" href="<?= $escape($coreStyles . 'variables.css') ?>">
    <link rel="stylesheet" href="<?= $escape($coreStyles . 'buttons.css') ?>">
    <link rel="stylesheet" href="<?= $escape($assets . 'Mobile.css') ?>">
    <script src="<?= $escape($assets . 'Mobile.js') ?>" defer></script>
</head>
<body class="quiqqer-mobile-upload-page">
<main data-name="mobile-upload" class="quiqqer-mobile-upload">
    <header class="quiqqer-mobile-upload-header">
        <span class="quiqqer-mobile-upload-symbol" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <rect x="6" y="2" width="12" height="20" rx="3"/>
                <path d="M10 5h4M11 19h2M12 15V8m-3 3 3-3 3 3"/>
            </svg>
        </span>
        <h1><?= $escape($labels['title']) ?></h1>
        <p class="quiqqer-mobile-upload-intro"><?= $escape($labels['mobileIntro']) ?></p>
        <p data-name="reference"></p>
    </header>
    <p data-name="status" role="status" aria-live="polite"><?= $escape($labels['loading']) ?></p>
    <p data-name="error" role="alert" hidden></p>
    <form data-name="form" hidden>
        <fieldset data-name="controls">
            <legend><?= $escape($labels['documents']) ?></legend>
            <div class="quiqqer-mobile-upload-actions">
                <button type="button" data-name="camera" class="btn">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m8 5 2-3h4l2 3h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                    <strong><?= $escape($labels['camera']) ?></strong>
                    <span><?= $escape($labels['cameraHint']) ?></span>
                </button>
                <button type="button" data-name="choose" class="btn">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M8 13h8m-8 4h5"/>
                    </svg>
                    <strong><?= $escape($labels['files']) ?></strong>
                    <span><?= $escape($labels['filesHint']) ?></span>
                </button>
            </div>
            <p data-name="limits"></p>
            <input type="file" data-name="camera-input" accept="image/*" capture="environment" hidden>
            <input type="file" data-name="file-input" multiple hidden>
            <ul data-name="files"></ul>
            <p data-name="empty"><?= $escape($labels['empty']) ?></p>
            <button type="submit" data-name="send" class="btn btn-primary" disabled>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 16V3m-5 5 5-5 5 5M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/>
                </svg>
                <?= $escape($labels['send']) ?>
            </button>
            <progress data-name="progress" max="100" value="0" hidden aria-label="<?= $escape($labels['uploading']) ?>"></progress>
        </fieldset>
        <button type="button" data-name="done" class="btn btn-secondary-outline">
            <?= $escape($labels['done']) ?>
        </button>
    </form>
    <ul data-name="receipts" aria-live="polite"></ul>
    <script type="application/json" data-name="labels"><?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
</main>
</body>
</html>
