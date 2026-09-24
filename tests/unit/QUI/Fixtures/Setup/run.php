<?php

$entrypoint = $argv[1];
$_REQUEST = json_decode($argv[2], true);
$_SERVER['REQUEST_URI'] = $argv[3];
$_SERVER['argv'] = ['./console', $argv[4] ?? ''];
$GLOBALS['setupLoadedFiles'] = [];
ob_start();

register_shutdown_function(static function (): void {
    echo json_encode([
        'files' => $GLOBALS['setupLoadedFiles'],
        'body' => ob_get_clean(),
        'status' => http_response_code() ?: 200,
        'cmsDir' => defined('CMS_DIR') ? CMS_DIR : null
    ], JSON_THROW_ON_ERROR);
});

require $entrypoint;
