<?php

/**
 * This file contains the \QUI\Projects\Media\Image
 */

namespace QUI\Projects\Media;

use Exception;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Exceptions\EncoderException;
use QUI;
use QUI\ExceptionStack;
use QUI\Lock\Locker;
use QUI\Projects\Media;
use QUI\Projects\Media\Utils as MediaUtils;
use QUI\Security\PublicUrlFetcher;
use QUI\Utils\Security\SvgSanitizer;
use QUI\Utils\StringHelper;
use QUI\Utils\System\File as FileUtils;
use Symfony\Component\Lock\LockInterface;

use function array_key_exists;
use function array_map;
use function bin2hex;
use function date;
use function dirname;
use function end;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fread;
use function implode;
use function ini_get;
use function is_array;
use function is_numeric;
use function mb_strlen;
use function mb_substr;
use function md5_file;
use function max;
use function min;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function round;
use function set_time_limit;
use function sha1_file;
use function str_replace;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function wordwrap;

use const URL_DIR;
use const VAR_DIR;

/**
 * A media image
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Image extends Item implements QUI\Interfaces\Projects\Media\File
{
    private const IMAGE_CACHE_SIZE_ALIGNMENT = 8;
    private const IMAGE_CACHE_SIZE_STEP = 16;
    private const IMAGE_CACHE_EXACT_SIZE_THRESHOLD = 100;

    /**
     * Max image width & width for image cache creation
     */
    protected int $IMAGE_MAX_SIZE = 4000;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params, Media $Media)
    {
        parent::__construct($params, $Media);

        // read config
        $maxUploadImageSize = $this->getProject()->getConfig('media_maxUploadSize');
        $maxImageCacheSize = $this->getProject()->getConfig('media_maxImageCacheSize');

        if (!empty($maxUploadImageSize)) {
            $this->IMAGE_MAX_SIZE = (int)$maxUploadImageSize;
        }

        if (!empty($maxImageCacheSize)) {
            $this->IMAGE_MAX_SIZE = (int)$maxImageCacheSize;
        }

        if (empty($this->IMAGE_MAX_SIZE)) {
            $this->IMAGE_MAX_SIZE = 4000;
        }
    }

    /**
     * (non-PHPdoc)
     *
     * @throws QUI\Exception
     * @see QUI\Interfaces\Projects\Media\File::createCache()
     */
    public function createCache(): false | string
    {
        if (Media::$globalDisableMediaCacheCreation) {
            return false;
        }

        return $this->createSizeCache();
    }

    /**
     * Create a cache file with the new width and height
     *
     * @param false|integer $width - (optional)
     * @param false|integer $height - (optional)
     *
     * @return false|string - URL to the cache file
     *
     * @throws QUI\Exception
     * @throws ExceptionStack
     * @throws QUI\Permissions\Exception
     */
    public function createSizeCache(false | int $width = false, false | int $height = false): false | string
    {
        if (!$this->getAttribute('active')) {
            return false;
        }

        $this->checkPermission('quiqqer.projects.media.view');


        if ($this->getAttribute('mime_type') != 'image/svg+xml' && ($width || $height)) {
            $resizeSize = $this->getSizeCacheDimensions($width, $height);
            $width = $resizeSize['width'];
            $height = $resizeSize['height'];
        }

        $cacheFile = $this->getSizeCachePath($width, $height);
        $isSvg = in_array($this->getAttribute('mime_type'), ['image/svg', 'image/svg+xml'], true);

        if (!$isSvg && file_exists($cacheFile)) {
            return $cacheFile;
        }

        // The configured namespace identifies the installation across different server paths.
        $resource = str_starts_with($cacheFile, CMS_DIR) ? substr($cacheFile, strlen(CMS_DIR)) : $cacheFile;

        return Locker::synchronized('media-size-cache:' . $resource, function (LockInterface $Lock) use (
            $cacheFile,
            $width,
            $height,
            $isSvg
        ): string {
            // Another process may have generated this variant while we waited.
            clearstatcache(true, $cacheFile);
            $sanitizedSvg = null;

            if (file_exists($cacheFile)) {
                if (!$isSvg) {
                    return $cacheFile;
                }

                $cachedSvg = file_get_contents($cacheFile);
                $sanitizedSvg = is_string($cachedSvg) ? SvgSanitizer::sanitize($cachedSvg) : '';

                if ($sanitizedSvg === '') {
                    throw new QUI\Exception('Invalid SVG media.', ErrorCodes::FILE_IMAGE_CORRUPT);
                }

                if ($sanitizedSvg === $cachedSvg) {
                    return $cacheFile;
                }
            }

            FileUtils::mkdir(dirname($cacheFile));
            $temporaryFile = tempnam(dirname($cacheFile), '.quiqqer-image-');

            if ($temporaryFile === false) {
                throw new QUI\Exception('Unable to create temporary image cache file.');
            }

            try {
                // tempnam may fall back to the system directory; publication must stay on the same filesystem.
                if (dirname($temporaryFile) !== realpath(dirname($cacheFile))) {
                    throw new QUI\Exception('Unable to create temporary image cache file in the cache directory.');
                }

                $Image = null;

                if ($sanitizedSvg !== null) {
                    if (file_put_contents($temporaryFile, $sanitizedSvg) === false) {
                        throw new QUI\Exception('Invalid SVG media.', ErrorCodes::FILE_IMAGE_CORRUPT);
                    }
                } else {
                    $Image = $this->generateSizeCache($temporaryFile, $cacheFile, $width, $height);
                }

                // Never publish a partial image or a result from an expired lock owner.
                $Lock->refresh();

                if (!chmod($temporaryFile, 0666 & ~umask()) || !rename($temporaryFile, $cacheFile)) {
                    throw new QUI\Exception('Unable to publish image cache file.');
                }

                if ($Image !== null) {
                    QUI::getEvents()->fireEvent('mediaCreateSizeCache', [$this, $Image]);
                }

                return $cacheFile;
            } finally {
                if (file_exists($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }
        }, ttl: 1200.0); // Image rendering can run for up to 1000 seconds.
    }

    /** Render to a private file; the caller publishes it only once it is complete. */
    private function generateSizeCache(
        string $cacheFile,
        string $targetFile,
        false | int $width,
        false | int $height
    ): ?ImageInterface {
        $Media = $this->Media;
        $original = $this->getFullPath();
        $isSvg = in_array($this->getAttribute('mime_type'), ['image/svg', 'image/svg+xml'], true);

        // create cache folder
        FileUtils::mkdir(dirname($cacheFile));

        if ($isSvg) {
            $svg = file_get_contents($original);
            $sanitizedSvg = is_string($svg) ? SvgSanitizer::sanitize($svg) : '';

            if ($sanitizedSvg === '' || file_put_contents($cacheFile, $sanitizedSvg) === false) {
                throw new QUI\Exception('Invalid SVG media.', ErrorCodes::FILE_IMAGE_CORRUPT);
            }

            return null;
        }

        // quiqqer/core#782
        if ($this->getAttribute('mime_type') == 'image/gif' && $this->isAnimated()) {
            if (!copy($original, $cacheFile)) {
                throw new QUI\Exception('Unable to copy image cache file.');
            }

            return null;
        }

        $effects = $this->getEffects();

        if ($width === false && $height === false && empty($effects)) {
            if (!copy($original, $cacheFile)) {
                throw new QUI\Exception('Unable to copy image cache file.');
            }

            return null;
        }

        // create image
        $time = ini_get('max_execution_time');
        set_time_limit(1000);

        try {
            try {
                $Image = $Media->getImageManager()->read($original);
            } catch (Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
                if (!copy($original, $cacheFile)) {
                    throw new QUI\Exception('Unable to copy image cache file.');
                }

                return null;
            }


            if ($width || $height) {
                if (!$width) {
                    $width = null;
                }

                if (!$height) {
                    $height = null;
                }

                $Image->scaleDown(
                    $width === null ? null : (int)$width,
                    $height === null ? null : (int)$height
                );
            }

            // effects
            if (isset($effects['blur']) && is_numeric($effects['blur'])) {
                $blur = (int)$effects['blur'];

                if ($blur > 0 && $blur <= 100) {
                    $Image->blur($blur);
                }
            }

            if (isset($effects['brightness']) && is_numeric($effects['brightness'])) {
                $brightness = (int)$effects['brightness'];

                if ($brightness !== 0 && $brightness >= -100 && $brightness <= 100) {
                    $Image->brightness($brightness);
                }
            }

            if (isset($effects['contrast']) && is_numeric($effects['contrast'])) {
                $contrast = (int)$effects['contrast'];

                if ($contrast !== 0 && $contrast >= -100 && $contrast <= 100) {
                    $Image->contrast($contrast);
                }
            }

            if (isset($effects['greyscale']) && $effects['greyscale'] == 1) {
                $Image->greyscale();
            }

            // watermark
            $Watermark = $this->getWatermark();

            try {
                if ($Watermark) {
                    $pos = $this->getWatermarkPosition();
                    $ratio = $this->getWatermarkRatio();
                    $WatermarkImage = $Media->getImageManager()->read($Watermark->getFullPath());

                    switch ($pos) {
                        case "top-left":
                        case "top":
                        case "top-right":
                        case "left":
                        case "center":
                        case "right":
                        case "bottom-left":
                        case "bottom":
                        case "bottom-right":
                            $watermarkPosition = $pos;
                            break;

                        default:
                            $watermarkPosition = 'bottom-right';
                    }

                    // ratio calc
                    if (is_numeric($ratio)) {
                        $ratio = (float)$ratio;
                        $imageHeight = $Image->height();
                        $imageWidth = $Image->width();

                        $imageHeight = (int)($imageHeight * ($ratio / 100));
                        $imageWidth = (int)($imageWidth * ($ratio / 100));

                        $WatermarkImage->scaleDown($imageWidth, $imageHeight);
                    }

                    $Image->place($WatermarkImage, (string)$watermarkPosition);
                }
            } catch (Exception $Exception) {
                QUI\System\Log::addInfo($Exception->getMessage(), [
                    'file' => $this->getFullPath(),
                    'fileId' => $this->getId(),
                    'info' => 'watermark creation'
                ]);
            }

            // create folders
            FileUtils::mkdir(dirname($cacheFile));

            // The temporary file has no extension; preserve the requested output format.
            try {
                $Encoded = $Image->encodeByPath($targetFile);
            } catch (EncoderException) {
                $Encoded = $Image->encodeByMediaType();
            }

            $Encoded->save($cacheFile);

            return $Image;
        } finally {
            set_time_limit((int)$time);
        }
    }

    /**
     * Return the image path
     *
     * @throws QUI\Exception
     */
    public function getSizeCachePath(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): string {
        $Media = $this->Media;
        /* @var $Media QUI\Projects\Media */
        $cacheDir = CMS_DIR . $Media->getCacheDir();
        $file = $this->getAttribute('file');


        if (
            $this->hasPermission('quiqqer.projects.media.view') &&
            $this->hasPermission('quiqqer.projects.media.view', QUI::getUsers()->getNobody()) === false
        ) {
            $cacheDir = VAR_DIR . 'media/cache/permissions/' . $this->getProject()->getName() . '/';
        }


        if ($this->getAttribute('mime_type') == 'image/svg+xml') {
            return $cacheDir . $file;
        }

        if (!$maxWidth && !$maxHeight) {
            return $cacheDir . $file;
        }


        $extra = '';
        $params = $this->getSizeCacheDimensions($maxWidth, $maxHeight);
        $height = $params['height'];
        $width = $params['width'];

        if ($this->getAttribute('reflection')) {
            $extra = '_reflection';
        }


        if ($width || $height) {
            $part = explode('.', $file);
            $cacheFile = $cacheDir . $part[0] . '__' . $width . 'x' . $height . $extra . '.' .
                StringHelper::toLower(end($part));

            if (empty($height)) {
                $cacheFile = $cacheDir . $part[0] . '__' . $width . $extra . '.' .
                    StringHelper::toLower(end($part));
            }

            if ($this->getAttribute('reflection')) {
                $cacheFile = $cacheDir . $part[0] . '__' . $width . 'x' . $height . $extra . '.png';

                if (empty($height)) {
                    $cacheFile = $cacheDir . $part[0] . '__' . $width . $extra . '.png';
                }
            }
        } else {
            $cacheFile = $cacheDir . $file;
        }

        return $cacheFile;
    }

    /**
     * Return the physical dimensions of a size-cache image without generating it.
     *
     * The proportional image resize can differ from the rounded cache-path dimensions.
     * Use the same geometry as the renderer when describing responsive image candidates.
     *
     * @return array{width: int, height: int}
     *
     * @throws QUI\Exception
     */
    public function getSizeCacheImageDimensions(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): array {
        $width = (int)$this->getWidth();
        $height = (int)$this->getHeight();
        $mimeType = $this->getAttribute('mime_type');

        if (
            (!$maxWidth && !$maxHeight)
            || in_array($mimeType, ['image/svg', 'image/svg+xml'], true)
            || ($mimeType === 'image/gif' && $this->isAnimated())
        ) {
            return ['width' => $width, 'height' => $height];
        }

        $dimensions = $this->getSizeCacheDimensions($maxWidth, $maxHeight);
        $Size = (new Rectangle($width, $height))->scaleDown(
            $dimensions['width'] ?: null,
            $dimensions['height'] ?: null
        );

        return ['width' => $Size->width(), 'height' => $Size->height()];
    }

    /**
     * Return the canonical dimensions used for the cache path and resize bounds.
     *
     * Small target sizes remain exact to avoid visible quality loss for logos and
     * icons. Larger sizes retain the cache-size bucketing that limits the number
     * of generated variants.
     *
     * @return array{width: int, height: int}
     *
     * @throws QUI\Exception
     */
    private function getSizeCacheDimensions(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): array {
        if ($maxWidth > $this->IMAGE_MAX_SIZE) {
            $maxWidth = $this->IMAGE_MAX_SIZE;
        }

        if ($maxHeight > $this->IMAGE_MAX_SIZE) {
            $maxHeight = $this->IMAGE_MAX_SIZE;
        }

        $params = $this->getResizeSize($maxWidth, $maxHeight);
        $width = (int)$params['width'];
        $height = (int)$params['height'];
        $projectConfig = $this->getProject()->getConfig();

        if (!is_array($projectConfig)) {
            $projectConfig = [];
        }

        $roundingEnabled = true;
        $exactSizeThreshold = self::IMAGE_CACHE_EXACT_SIZE_THRESHOLD;

        if (array_key_exists('media_imageCacheSizeRounding', $projectConfig)) {
            $roundingEnabled = (int)$projectConfig['media_imageCacheSizeRounding'] !== 0;
        }

        if (array_key_exists('media_imageCacheExactSizeThreshold', $projectConfig)) {
            $configuredThreshold = (int)$projectConfig['media_imageCacheExactSizeThreshold'];
            $exactSizeThreshold = max(0, $configuredThreshold);
        }

        if (
            !$roundingEnabled
            || ($exactSizeThreshold > 0 && min($width, $height) <= $exactSizeThreshold)
        ) {
            return [
                'width' => $width,
                'height' => $height
            ];
        }

        if ($height > $width) {
            if ($height % self::IMAGE_CACHE_SIZE_ALIGNMENT !== 0) {
                $height = (int)QUI\Utils\Math::ceilUp($height, self::IMAGE_CACHE_SIZE_STEP);
            }

            $params = $this->getResizeSize(false, $height);
        } else {
            if ($width % self::IMAGE_CACHE_SIZE_ALIGNMENT !== 0) {
                $width = (int)QUI\Utils\Math::ceilUp($width, self::IMAGE_CACHE_SIZE_STEP);
            }

            $params = $this->getResizeSize($width);
        }

        return [
            'width' => (int)$params['width'],
            'height' => (int)$params['height']
        ];
    }

    /**
     * Return the Image specific max resize params
     *
     * @param bool|string|int $maxWidth - (optional)
     * @param bool|string|int $maxHeight - (optional)
     *
     * @return array<string, mixed> - array('width' => 100, 'height' => 100)
     *
     * @throws QUI\Exception
     */
    public function getResizeSize(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): array {
        if ($this->getAttribute('mime_type') == 'image/svg+xml') {
            return [
                'width' => false,
                'height' => false
            ];
        }

        $width = $this->getAttribute('image_width');
        $height = $this->getAttribute('image_height');

        if (!$width || !$height) {
            $info = FileUtils::getInfo($this->getFullPath(), [
                'imagesize' => true
            ]);

            $width = $info['width'] ?? 100;
            $height = $info['height'] ?? 100;
        }

        $maxConfigSize = $this->getProject()->getConfig('media_maxUploadSize');

        $newWidth = $width;
        $newHeight = $height;

        if (!$maxWidth) {
            $maxWidth = $width;
        }

        if (!$maxHeight) {
            $maxHeight = $height;
        }

        // max höhe breite auf 1200
        if ($maxWidth > $maxConfigSize && $maxConfigSize) {
            $maxWidth = $maxConfigSize;
        }

        if ($maxHeight > $maxConfigSize && $maxConfigSize) {
            $maxHeight = $maxConfigSize;
        }

        // Breite
        if ($newWidth > $maxWidth) {
            $resize_by_percent = ($maxWidth * 100) / $newWidth;

            $newHeight = (int)round(($newHeight * $resize_by_percent) / 100);
            $newWidth = $maxWidth;
        }

        // Höhe
        if ($newHeight > $maxHeight) {
            $resize_by_percent = ($maxHeight * 100) / $newHeight;

            $newWidth = (int)round(($newWidth * $resize_by_percent) / 100);
            $newHeight = $maxHeight;
        }

        return [
            'width' => $newWidth,
            'height' => $newHeight
        ];
    }

    /**
     * Is the image an animated image?
     * Thanks to https://stackoverflow.com/a/415942
     */
    public function isAnimated(): bool
    {
        $filename = $this->getFullPath();

        if (!($fh = @fopen($filename, 'rb'))) {
            return false;
        }

        $count = 0;
        //an animated gif contains multiple "frames", with each frame having a
        //header made up of:
        // * a static 4-byte sequence (\x00\x21\xF9\x04)
        // * 4 variable bytes
        // * a static 2-byte sequence (\x00\x2C)

        // We read through the file til we reach the end of the file, or we've found
        // at least 2 frame headers
        while (!feof($fh) && $count < 2) {
            $chunk = fread($fh, 1024 * 100); //read 100kb at a time
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', (string)$chunk, $matches);
        }

        fclose($fh);

        return $count > 1;
    }

    /**
     * Resize the image and aspect the ratio
     *
     * @return string - Path to the new Image
     */
    public function resize(int $newWidth = 0, int $newHeight = 0): string
    {
        $dir = CMS_DIR . $this->Media->getPath();
        $original = $dir . $this->getAttribute('file');

        if ($this->getAttribute('mime_type') == 'image/svg+xml') {
            return $original;
        }

        try {
            // create image
            $Image = $this->getMedia()->getImageManager()->read($original);
            $Image->scaleDown($newWidth, $newHeight);
            $Image->save($original);
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        return $original;
    }

    /**
     * Return the Watermark image file
     */
    public function getWatermark(): Image | false
    {
        // own watermark?
        $imageEffects = $this->getEffects();

        if (!isset($imageEffects['watermark'])) {
            $imageEffects['watermark'] = 'default';
        }

        if ($imageEffects['watermark'] === '') {
            return false;
        }

        if ($imageEffects['watermark'] == 'default') {
            try {
                $Project = $this->getProject();

                return Utils::getImageByUrl($Project->getConfig('media_watermark'));
            } catch (QUI\Exception) {
            }

            return false;
        }

        try {
            return Utils::getImageByUrl($imageEffects['watermark']);
        } catch (QUI\Exception) {
        }

        return false;
    }

    public function getWatermarkPosition(): string | bool
    {
        $imageEffects = $this->getEffects();

        if ($imageEffects && !empty($imageEffects['watermark_position'])) {
            return $imageEffects['watermark_position'];
        }

        // global watermark position?
        $Project = $this->getProject();

        if ($Project->getConfig('media_watermark_position')) {
            return $Project->getConfig('media_watermark_position');
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>|bool|string
     */
    public function getWatermarkRatio(): bool | array | string
    {
        $imageEffects = $this->getEffects();

        if ($imageEffects && !empty($imageEffects['watermark_ratio'])) {
            return $imageEffects['watermark_ratio'];
        }

        // global watermark position?
        $Project = $this->getProject();

        if ($Project->getConfig('media_watermark_ratio')) {
            return $Project->getConfig('media_watermark_ratio');
        }

        return false;
    }

    /**
     * Return the real height of the image
     *
     * @return integer|false
     * @throws QUI\Exception
     */
    public function getHeight(): false | int
    {
        if ($this->getAttribute('image_height')) {
            return (int)$this->getAttribute('image_height');
        }

        $data = FileUtils::getInfo($this->getFullPath(), [
            'imagesize' => true
        ]);

        if (isset($data['height'])) {
            return (int)$data['height'];
        }

        return false;
    }

    /**
     * Return the real with of the image
     *
     * @return integer|false
     * @throws QUI\Exception
     */
    public function getWidth(): false | int
    {
        if ($this->getAttribute('image_width')) {
            return (int)$this->getAttribute('image_width');
        }

        $data = FileUtils::getInfo($this->getFullPath(), [
            'imagesize' => true
        ]);

        if (isset($data['width'])) {
            return (int)$data['width'];
        }

        return false;
    }

    /**
     * Return the image url
     *
     * @throws QUI\Exception
     */
    public function getSizeCacheUrl(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): string {
        $cachePath = $this->getSizeCachePath($maxWidth, $maxHeight);
        $cacheUrl = str_replace(CMS_DIR, URL_DIR, $cachePath);

        if ($this->hasViewPermissionSet()) {
            $cacheUrl = URL_DIR . $this->getUrl();
        }

        if (!preg_match('/[^a-zA-Z0-9_\-.\/]/i', $cacheUrl)) {
            return $cacheUrl;
        }

        // thanks to http://php.net/manual/de/function.rawurlencode.php#100313
        // thanks to http://php.net/manual/de/function.rawurlencode.php#63751
        $encoded = implode(
            "/",
            array_map(static function ($part): string {
                $encoded = '';
                $length = mb_strlen($part);

                for ($i = 0; $i < $length; $i++) {
                    $str = mb_substr($part, $i, 1);

                    if (!preg_match('/[^a-zA-Z0-9_\-.]/i', $str)) {
                        $encoded .= $str;
                        continue;
                    }

                    $encoded .= '%' . wordwrap(bin2hex($str), 2, '%', true);
                }

                return $encoded;
            }, explode("/", $cacheUrl))
        );

        return $encoded;
    }

    /**
     * Creates a cache file and takes into account the maximum sizes
     * return the media url
     *
     * @throws QUI\Exception
     */
    public function createSizeCacheUrl(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): string {
        $params = $this->getResizeSize($maxWidth, $maxHeight);

        $cacheUrl = $this->createSizeCache(
            $params['width'],
            $params['height']
        );

        return str_replace(CMS_DIR, URL_DIR, (string)$cacheUrl);
    }

    /**
     * Creates a cache file and takes into account the maximum sizes
     *
     * @param bool|string|int $maxWidth
     * @param bool|string|int $maxHeight
     *
     * @return bool|string - Path to the file
     *
     * @throws ExceptionStack
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     */
    public function createResizeCache(
        bool | string | int $maxWidth = false,
        bool | string | int $maxHeight = false
    ): bool | string {
        $params = $this->getResizeSize($maxWidth, $maxHeight);

        return $this->createSizeCache(
            $params['width'],
            $params['height']
        );
    }

    /**
     * Generate the MD5 file hash and set it to the Database and to the Object
     *
     * @throws QUI\Exception
     */
    public function generateMD5(): void
    {
        if (!file_exists($this->getFullPath())) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.file.not.found', [
                    'file' => $this->getAttribute('file')
                ]),
                ErrorCodes::FILE_NOT_FOUND
            );
        }

        $md5 = md5_file($this->getFullPath());

        $this->setAttribute('md5hash', $md5);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['md5hash' => $md5],
            ['id' => $this->getId()]
        );
    }

    /**
     * Generate the SHA1 file hash and set it to the Database and to the Object
     *
     * @throws QUI\Exception
     */
    public function generateSHA1(): void
    {
        if (!file_exists($this->getFullPath())) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.file.not.found', [
                    'file' => $this->getAttribute('file')
                ]),
                ErrorCodes::FILE_NOT_FOUND
            );
        }

        $sha1 = sha1_file($this->getFullPath());

        $this->setAttribute('sha1hash', $sha1);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['sha1hash' => $sha1],
            ['id' => $this->getId()]
        );
    }

    /**
     * Hash methods
     */
    /**
     * @throws QUI\Exception
     */
    public function updateExternalImage(): void
    {
        $SessionUser = QUI::getUserBySession();
        $external = $this->getAttribute('external');
        $temporaryFile = null;

        if (empty($external)) {
            return;
        }

        try {
            $file = $this->fetchExternalUrl((string)$external);
            $original = $this->getFullPath();

            if ($file === '') {
                throw new QUI\Exception('The external image response is empty.');
            }

            $temporaryFile = tempnam(sys_get_temp_dir(), 'quiqqer-external-image-');

            if ($temporaryFile === false || file_put_contents($temporaryFile, $file) === false) {
                throw new QUI\Exception('The external image could not be validated.');
            }

            $fileInfo = FileUtils::getInfo($temporaryFile);
            $mimeType = (string)($fileInfo['mime_type'] ?? '');
            $mustBeSvg = in_array($mimeType, ['image/svg', 'image/svg+xml'], true);
            $mayBeSvg = $mustBeSvg || in_array(
                $mimeType,
                ['application/xml', 'application/xhtml+xml', 'text/html', 'text/plain', 'text/xml'],
                true
            );
            $sanitizedSvg = null;

            if ($mayBeSvg) {
                $sanitized = SvgSanitizer::sanitize($file);

                if ($sanitized !== '') {
                    $sanitizedSvg = $sanitized;
                    $file = $sanitized;

                    if (file_put_contents($temporaryFile, $sanitizedSvg) === false) {
                        throw new QUI\Exception('The external SVG could not be sanitized.');
                    }

                    $fileInfo = FileUtils::getInfo($temporaryFile);
                    $fileInfo['mime_type'] = 'image/svg+xml';
                    $mimeType = 'image/svg+xml';
                }
            }

            if ($mustBeSvg && $sanitizedSvg === null) {
                throw new QUI\Exception('The external SVG is invalid.', ErrorCodes::FILE_IMAGE_CORRUPT);
            }

            if (MediaUtils::getMediaTypeByMimeType($mimeType) !== 'image') {
                throw new QUI\Exception('The external resource is not an image.', ErrorCodes::FILE_IMAGE_CORRUPT);
            }

            if (file_put_contents($original, $file) === false) {
                throw new QUI\Exception('The external image could not be stored.');
            }

            // update image dimensions
            $imageWidth = null;
            $imageHeight = null;

            if (isset($fileInfo['width']) && $fileInfo['width']) {
                $imageWidth = (int)$fileInfo['width'];
            }

            if (isset($fileInfo['height']) && $fileInfo['height']) {
                $imageHeight = (int)$fileInfo['height'];
            }

            QUI::getDataBaseConnection()->update($this->Media->getTable(), [
                'e_date' => date('Y-m-d h:i:s'),
                'e_user' => $SessionUser->getUUID(),
                'mime_type' => $fileInfo['mime_type'],
                'image_width' => $imageWidth,
                'image_height' => $imageHeight,
                'type' => MediaUtils::getMediaTypeByMimeType($fileInfo['mime_type'])
            ], [
                'id' => $this->getId()
            ]);

            $this->setAttribute('mime_type', $fileInfo['mime_type']);
            $this->setAttribute('image_width', $imageWidth);
            $this->setAttribute('image_height', $imageHeight);
            $this->deleteCache();
        } catch (QUI\Exception) {
            $this->deactivate();
        } finally {
            if (is_string($temporaryFile) && file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    /**
     * @throws QUI\Exception
     */
    protected function fetchExternalUrl(string $url): string
    {
        return (new PublicUrlFetcher())->get($url);
    }

    /**
     * (non-PHPdoc)
     *
     * @throws QUI\Exception
     * @see QUI\Interfaces\Projects\Media\File::deleteCache()
     */
    public function deleteCache(): void
    {
        $Media = $this->Media;
        $cdir = CMS_DIR . $Media->getCacheDir();
        $file = $this->getAttribute('file');

        $cachefile = $cdir . $file;
        $cacheDirname = dirname($cachefile);

        $fileData = FileUtils::getInfo($this->getFullPath());
        $files = FileUtils::readDir($cacheDirname, true);
        $filename = $fileData['filename'];

        foreach ($files as $file) {
            $len = strlen($filename);

            // cache delete
            if (substr($file, 0, $len + 2) === $filename . '__') {
                FileUtils::unlink($cacheDirname . '/' . $file);
            }
        }

        FileUtils::unlink($cachefile);

        // delete admin cache, too
        $this->deleteAdminCache();
    }

    /**
     * Delete the admin cache
     */
    public function deleteAdminCache(): void
    {
        $Media = $this->Media;
        $Project = $Media->getProject();

        $cacheDir = VAR_DIR . 'media/cache/admin/' . $Project->getName() . '/' . $Project->getLang() . '/';
        $cacheName = $this->getId() . '__';

        $files = FileUtils::readDir($cacheDir);

        foreach ($files as $file) {
            if (str_starts_with($file, $cacheName)) {
                unlink($cacheDir . $file);
            }
        }
    }
}
