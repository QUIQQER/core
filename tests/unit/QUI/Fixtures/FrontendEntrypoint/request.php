<?php

require_once dirname(__DIR__, 7) . '/autoload.php';

ob_start();
require dirname(__DIR__, 5) . '/index.php';
$body = ob_get_clean();
$response = [
    'status' => http_response_code(),
    'body' => $body,
    'infoLogs' => $GLOBALS['frontendEntrypointInfoLogs'] ?? []
];

echo json_encode($response, JSON_THROW_ON_ERROR);
