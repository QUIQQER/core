<?php

$dir = str_replace('quiqqer/core/src/QUI/Export/bin', '', __DIR__);

require_once $dir . 'header.php';

$body = file_get_contents('php://input');
$body = json_decode((string)$body, true);

if (
    !$body
    || !isset($body['data'])
    || !isset($body['data']['header'])
    || !isset($body['data']['data'])
) {
    exit;
}

// header
$header = [];

foreach ($body['data']['header'] as $key => $entry) {
    $header[] = html_entity_decode($entry['header']);
}

// data
$data = [];

foreach ($body['data']['data'] as $entry) {
    $entry = array_values($entry);

    foreach ($entry as $k => $v) {
        if (is_string($v)) {
            $entry[$k] = $v;
            continue;
        }

        if (is_array($v) && count($v) === 1) {
            $entry[$k] = current($v);
            continue;
        }

        $entry[$k] = json_encode($v);
    }

    $data[] = $entry;
}

// css file
if (!isset($body['cssFile']) || !$body['cssFile']) {
    $cssFile = HOST . URL_OPT_DIR . 'quiqqer/core/src/QUI/Export/bin/exportPrint.css';
} else {
    if (!str_starts_with($body['cssFile'], 'http')) {
        $body['cssFile'] = HOST . $body['cssFile'];
    }

    $cssFile = HOST . $body['cssFile'];
}

// render
$Smarty = QUI::getTemplateManager()->getEngine();

$Smarty->assign([
    'cssFile' => $cssFile,
    'header' => $header,
    'data' => $data
]);

$output = $Smarty->fetch(dirname(__FILE__) . '/exportPrint.html');


// name
$name = 'export';

if (!empty($body['name'])) {
    $name = $body['name'];
}

$filename = $name . '.html';

header("Content-Type: text/html; charset=utf-16");
header('Content-Transfer-Encoding: binary');
header("Content-Disposition: attachment; filename=\"$filename\"");

echo $output;
