<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

require_once 'bootstrap.php';

use QUI\Projects\Media;
use QUI\System\BinaryFileResponse;
use QUI\Utils\Security\SvgSanitizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Send the uniform response for unavailable or inaccessible media.
 */
function sendMediaNotFound(): never
{
    foreach (
        [
            'Accept-Ranges',
            'Content-Disposition',
            'Content-Length',
            'Content-Size',
            'Content-Type',
            'Expires',
            'Last-Modified',
            'Pragma',
            'X-Content-Type-Options'
        ] as $header
    ) {
        header_remove($header);
    }

    http_response_code(404);
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Length: 0');
    exit;
}

/**
 * Return mime_type of a file.
 */
function getMediaMimeType(string $file): string
{
    if (!is_file($file)) {
        return '';
    }

    if (function_exists('mime_content_type')) {
        return (string)mime_content_type($file);
    }

    if (function_exists('finfo_open') && function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME);

        if ($finfo === false) {
            return '';
        }

        $part = explode(';', (string)finfo_file($finfo, $file));
        finfo_close($finfo);

        return $part[0];
    }

    return '';
}

/**
 * Stream a media file after the item access check has succeeded.
 *
 * @throws QUI\Exception
 */
function sendMediaFile(
    string $path,
    string $mimeType,
    string $downloadName,
    bool $sharedCacheAllowed
): never {
    if (!is_file($path) || !is_readable($path)) {
        throw new QUI\Exception('Media file not found.', 404);
    }

    $detectedMimeType = getMediaMimeType($path);

    if ($mimeType === '') {
        $mimeType = $detectedMimeType;
    }

    $normalizedMimeType = strtolower(trim(explode(';', $mimeType)[0]));
    $normalizedDetectedMimeType = strtolower(trim(explode(';', $detectedMimeType)[0]));
    $isSvg = in_array($normalizedMimeType, ['image/svg', 'image/svg+xml'], true)
        || in_array($normalizedDetectedMimeType, ['image/svg', 'image/svg+xml'], true)
        || in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), ['svg', 'svgz'], true);

    if ($isSvg) {
        $svg = file_get_contents($path);
        $sanitizedSvg = is_string($svg) ? SvgSanitizer::sanitize($svg) : '';
        $modified = filemtime($path);

        if ($sanitizedSvg === '' || $modified === false) {
            throw new QUI\Exception('Invalid SVG media.', 404);
        }

        $fileSize = strlen($sanitizedSvg);

        QUI::getGlobalResponse()->sendHeaders();

        header('Content-Type: image/svg+xml');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $fileSize);
        header('Content-Size: ' . $fileSize);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($downloadName)) . '"');

        if ($sharedCacheAllowed) {
            header('Pragma: public');
            header('Cache-Control: public, must-revalidate, post-check=0, pre-check=0');
            header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        } else {
            header('Pragma: no-cache');
            header('Cache-Control: private, no-store, max-age=0');
            header('Expires: 0');
            header('Vary: Cookie');
        }

        echo $sanitizedSvg;
        exit;
    }

    $fileSize = filesize($path);

    if ($fileSize === false) {
        throw new QUI\Exception('Media file not readable.', 404);
    }

    if ($mimeType === '') {
        throw new QUI\Exception('Media MIME type not available.', 404);
    }

    $Response = new BinaryFileResponse(
        $path,
        Response::HTTP_OK,
        QUI::getGlobalResponse()->headers->all(),
        $sharedCacheAllowed
    );
    $Response->headers->set('Content-Type', $mimeType);
    $Response->headers->set('Content-Size', (string)$fileSize);
    $Response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_INLINE,
        basename($downloadName)
    );

    if ($sharedCacheAllowed) {
        $Response->headers->set('Pragma', 'public');
        $Response->headers->set('Cache-Control', 'public, must-revalidate, post-check=0, pre-check=0');
        $Response->headers->set('Expires', gmdate('D, d M Y H:i:s') . ' GMT');
    } else {
        $Response->headers->set('Pragma', 'no-cache');
        $Response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $Response->headers->set('Expires', '0');
        $Response->headers->set('Vary', 'Cookie');
    }

    $Response->prepare(QUI::getRequest());
    $Response->send();
    exit;
}

try {
    $projectRequest = $_REQUEST['project'] ?? null;
    $idRequest = $_REQUEST['id'] ?? null;

    if (
        !is_string($projectRequest)
        || $projectRequest === ''
        || preg_match('/[\x00-\x1F\x7F\\\\]/', $projectRequest)
    ) {
        throw new QUI\Exception('Invalid media request.', 404);
    }

    QUI\Utils\Project::validateProjectName($projectRequest);

    if (!is_string($idRequest) && !is_int($idRequest)) {
        throw new QUI\Exception('Invalid media request.', 404);
    }

    $mediaId = filter_var($idRequest, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($mediaId === false || (string)$mediaId !== (string)$idRequest) {
        throw new QUI\Exception('Invalid media request.', 404);
    }

    $Project = QUI\Projects\Manager::getProject($projectRequest);
    $Media = $Project->getMedia();
    $File = $Media->get($mediaId);
    $SessionUser = QUI::getUserBySession();
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    $requestReferer = $_SERVER['HTTP_REFERER'] ?? '';
    $hasAdminReferer = is_string($requestHost)
        && $requestHost !== ''
        && is_string($requestReferer)
        && str_contains($requestReferer, $requestHost)
        && str_contains($requestReferer, URL_SYS_DIR);
    $isAdminRequest = isset($_REQUEST['quiadmin']) || $hasAdminReferer;
    $isAdmin = $isAdminRequest
        && QUI::getUsers()->isAuth($SessionUser)
        && $SessionUser->canUseBackend();

    // Central security boundary for every output and cache path below.
    if (!$File instanceof Media\Item || (!$File->isActive() && !$isAdmin)) {
        throw new QUI\Exception('Media item not available.', 404);
    }

    $File->checkPermission('quiqqer.projects.media.view', $SessionUser);
    $isPubliclyVisible = $File->hasPermission(
        'quiqqer.projects.media.view',
        QUI::getUsers()->getNobody()
    );

    if (Media\Utils::isFolder($File)) {
        sendMediaFile(
            BIN_DIR . '16x16/folder.png',
            'image/png',
            'folder.png',
            $isPubliclyVisible
        );
    }

    if (!is_file($File->getFullPath())) {
        throw new QUI\Exception('Media file not available.', 404);
    }

    $file = (string)$File->getAttribute('file');
    $image = false;

    if (
        !isset($_REQUEST['noresize'])
        && !isset($_REQUEST['maxwidth'])
        && !isset($_REQUEST['maxheight'])
        && $isAdmin
    ) {
        $_REQUEST['maxwidth'] = 500;
        $_REQUEST['maxheight'] = 500;
    }

    if ($isAdmin && $File instanceof Media\Image) {
        $maxWidth = filter_var($_REQUEST['maxwidth'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        $maxHeight = filter_var($_REQUEST['maxheight'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if ($maxWidth === false && $maxHeight === false) {
            $maxWidth = 500;
            $maxHeight = 500;
        }

        $maxCacheSize = (int)$Project->getConfig('media_maxImageCacheSize');

        if ($maxCacheSize <= 0) {
            $maxCacheSize = (int)$Project->getConfig('media_maxUploadSize');
        }

        if ($maxCacheSize <= 0) {
            $maxCacheSize = 4000;
        }

        if ($maxWidth !== false) {
            $maxWidth = min($maxWidth, $maxCacheSize);
        }

        if ($maxHeight !== false) {
            $maxHeight = min($maxHeight, $maxCacheSize);
        }

        $resizeSize = isset($_REQUEST['noresize'])
            ? $File->getResizeSize()
            : $File->getResizeSize($maxWidth, $maxHeight);
        $resizeWidth = (int)$resizeSize['width'];
        $resizeHeight = (int)$resizeSize['height'];

        $cacheDir = VAR_DIR . 'media/cache/admin/'
            . $Project->getName() . '/'
            . $Project->getLang() . '/';

        QUI\Utils\System\File::mkdir($cacheDir);

        $ext = pathinfo($File->getFullPath(), PATHINFO_EXTENSION);

        if ($File->getAttribute('mime_type') === 'image/svg+xml') {
            sendMediaFile(
                $File->getFullPath(),
                'image/svg+xml',
                $file,
                false
            );
        }

        $cacheFile = $cacheDir . $File->getId()
            . '__' . $resizeHeight . 'x'
            . $resizeWidth . '.' . $ext;

        if (getMediaMimeType($cacheFile) === 'image/svg+xml') {
            sendMediaFile($cacheFile, 'image/svg+xml', $file, false);
        }

        if (is_file($cacheFile)) {
            sendMediaFile($cacheFile, '', $file, false);
        }

        try {
            $Image = $Media->getImageManager()->read($File->getFullPath());
        } catch (\Exception $Exception) {
            QUI\System\Log::addDebug($Exception->getMessage());

            if (!$isPubliclyVisible) {
                throw new QUI\Exception('Protected media preview could not be created.', 404);
            }

            sendMediaFile(
                $File->getFullPath(),
                (string)$File->getAttribute('mime_type'),
                $file,
                false
            );
        }

        if (!isset($_REQUEST['noresize'])) {
            $Image->scaleDown($resizeWidth, $resizeHeight);
        }

        $Image->save($cacheFile);

        sendMediaFile($cacheFile, '', $file, false);
    }

    $resizeRequested = !isset($_REQUEST['noresize'])
        && Media\Utils::isImage($File)
        && (isset($_REQUEST['maxwidth']) || isset($_REQUEST['maxheight']));

    if ($resizeRequested) {
        $maxwidth = isset($_REQUEST['maxwidth']) ? (int)$_REQUEST['maxwidth'] : false;
        $maxheight = isset($_REQUEST['maxheight']) ? (int)$_REQUEST['maxheight'] : false;

        if (method_exists($File, 'createResizeCache')) {
            $image = $File->createResizeCache($maxwidth, $maxheight);
        }

        if (!$image && !$isPubliclyVisible) {
            throw new QUI\Exception('Protected media resize could not be created.', 404);
        }
    }

    if (!$image) {
        $image = $File->getFullPath();
    }

    sendMediaFile(
        $image,
        (string)$File->getAttribute('mime_type'),
        $file,
        $isPubliclyVisible
    );
} catch (QUI\Exception) {
    sendMediaNotFound();
} catch (Throwable $Exception) {
    QUI\System\Log::writeDebugException($Exception);
    sendMediaNotFound();
}
