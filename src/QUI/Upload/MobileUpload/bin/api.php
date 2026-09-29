<?php

declare(strict_types=1);

use QUI\Upload\MobileUpload\Form;
use QUI\Upload\MobileUpload\Manager;

define('QUIQQER_SYSTEM', true);
require_once dirname(__DIR__, 7) . '/header.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        echo Form::render();
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new QUI\Exception('Method not allowed.', 405);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $pattern = '/\ABearer ([a-f0-9]{64})\.([a-f0-9]{64})\z/D';

    if (!is_string($authorization) || !preg_match($pattern, $authorization, $matches)) {
        throw new QUI\Exception('Invalid upload access.', 410);
    }

    [, $id, $token] = $matches;
    $Manager = new Manager();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'info':
            $result = $Manager->info($id, $token);
            break;

        case 'close':
            $Manager->close($id, $token);
            $result = ['closed' => true];
            break;

        case 'upload':
            $file = $_FILES['file'] ?? null;
            $uploadId = $_POST['uploadId'] ?? null;

            if (is_array($file) && in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new QUI\Exception('Upload exceeds the server limit.', 413);
            }

            if (
                !is_array($file)
                || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['tmp_name'])
                || !is_uploaded_file($file['tmp_name'])
                || !is_string($file['name'])
                || !is_string($uploadId)
            ) {
                throw new QUI\Exception('Invalid upload.', 400);
            }

            $result = $Manager->upload($id, $token, $uploadId, $file['tmp_name'], $file['name']);
            break;

        default:
            throw new QUI\Exception('Invalid request or upload too large.', 400);
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $Exception) {
    $knownStatuses = [400, 401, 403, 404, 405, 409, 410, 413, 415, 429, 440];
    $status = in_array($Exception->getCode(), $knownStatuses, true) ? (int)$Exception->getCode() : 500;

    if ($status === 401 || $status === 440) {
        $status = 403;
    }

    if ($status === 500) {
        QUI\System\Log::writeException($Exception);
    }

    http_response_code($status);

    // Public responses must not reveal provider details, filesystem paths or customer data.
    echo json_encode([
        'error' => 'Upload could not be completed.',
        'code' => $status
    ]);
}
