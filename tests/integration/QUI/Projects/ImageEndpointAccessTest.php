<?php

namespace QUI\Projects;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use QUI\Projects\Fixtures\ExternalImageTestDouble;
use QUI\Projects\Media;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function array_merge;
use function bin2hex;
use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefilledrectangle;
use function imagepng;
use function iterator_to_array;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function stream_socket_get_name;
use function stream_socket_server;
use function str_contains;
use function str_starts_with;
use function str_replace;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function urlencode;
use function usleep;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

require_once __DIR__ . '/Fixtures/ExternalImageTestDouble.php';

class ImageEndpointAccessTest extends ProjectIntegrationTestCase
{
    private const ALLOWED_USER_ID = 9001;

    private static string $testDirectory;

    private static string $varDirectory;

    private static string $serverLog;

    private static string $rewriteSvg;

    private static string $invalidRewriteSvg;

    private static string $rewriteMediaFile;

    private static string $externalMaliciousSvg;

    private static string $externalInvalidSvg;

    private static int $serverPort;

    /** @var resource|null */
    private static mixed $serverProcess = null;

    private static int $activeImageId;

    private static int $inactiveImageId;

    private static int $svgId;

    private static int $uploadedMaliciousSvgId;

    private static int $storedMaliciousSvgId;

    private static int $invalidStoredSvgId;

    private static int $svgFallbackId;

    private static int $externalImageId;

    private static int $invalidExternalImageId;

    private static int $corruptImageId;

    private static int $mediaFileId;

    private static int $folderId;

    /** @var list<int> */
    private static array $mediaIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$testDirectory = sys_get_temp_dir() . '/quiqqer-image-endpoint-'
            . bin2hex(random_bytes(8));
        self::$varDirectory = getenv('GITLAB_CI') === 'true' ? self::$testDirectory . '/var/' : VAR_DIR;
        self::$serverLog = self::$testDirectory . '/server.log';

        foreach ([self::$testDirectory, self::$varDirectory . 'sessions', self::$varDirectory . 'logs'] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
        }

        self::createMediaFixtures();
        self::writeHttpEndpointWrapper();
        self::$serverPort = self::reserveServerPort();
        self::startHttpServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopHttpServer();
        self::deleteMediaFixtures();
        self::removeTestDirectory();

        parent::tearDownAfterClass();
    }

    public function testActivePublicImageIsDeliveredWithPublicCacheHeaders(): void
    {
        $Response = self::requestImage(self::$activeImageId);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertNotSame('', $Response['body']);
        self::assertStringStartsWith('image/png', $Response['headers']['content-type'] ?? '');
        self::assertStringContainsString('public', $Response['headers']['cache-control'] ?? '');
    }

    public function testClientSendfileHeadersDoNotChangeMediaDelivery(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [], [
            'X-Sendfile-Type: X-Sendfile',
            'X-Accel-Mapping: /=/attacker/',
            'QUIQQER-SENDFILE-TYPE: X-Sendfile',
            'Range: bytes=2-5'
        ]);

        self::assertSame(206, $Response['status'], self::failureMessage($Response));
        self::assertSame('2345', $Response['body']);
        self::assertArrayNotHasKey('x-sendfile', $Response['headers']);
        self::assertArrayNotHasKey('x-accel-redirect', $Response['headers']);
    }

    public function testConfiguredSendfilePreservesMediaAuthorization(): void
    {
        foreach (['X-Sendfile', 'X-Accel-Redirect'] as $type) {
            $params = ['_sendfile' => $type, '_access' => 'protected'];
            $denied = self::requestImage(self::$mediaFileId, $params);
            self::assertUniformNotFound($denied);
            self::assertArrayNotHasKey('x-sendfile', $denied['headers']);
            self::assertArrayNotHasKey('x-accel-redirect', $denied['headers']);

            // The PHP test server does not consume offload headers. HEAD inspects the handoff without a body.
            $allowed = self::requestImage(
                self::$mediaFileId,
                $params + ['_user' => 'allowed'],
                [],
                true
            );
            self::assertSame(200, $allowed['status'], self::failureMessage($allowed));
            self::assertArrayHasKey(strtolower($type), $allowed['headers']);
            self::assertSame('10', $allowed['headers']['content-length'] ?? '');
            self::assertStringContainsString('private', $allowed['headers']['cache-control'] ?? '');
            self::assertSame('', $allowed['body']);
        }
    }

    public function testConfiguredSendfileDoesNotExposeInactiveMedia(): void
    {
        $Response = self::requestImage(self::$inactiveImageId, ['_sendfile' => 'X-Sendfile']);
        self::assertUniformNotFound($Response);
        self::assertArrayNotHasKey('x-sendfile', $Response['headers']);
    }

    public function testRewriteMediaSupportsConfiguredOffload(): void
    {
        $Response = self::request(['_rewrite_media' => '1', '_sendfile' => 'X-Accel-Redirect'], [], true);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertArrayHasKey('x-accel-redirect', $Response['headers']);
        self::assertSame('10', $Response['headers']['content-length'] ?? '');
        self::assertSame('', $Response['body']);
    }

    public function testConfiguredOffloadDoesNotBypassSvgSanitization(): void
    {
        foreach (['X-Sendfile', 'X-Accel-Redirect'] as $type) {
            $media = self::requestImage(self::$storedMaliciousSvgId, ['_sendfile' => $type]);
            $rewrite = self::request(['_rewrite_svg' => '1', '_sendfile' => $type]);

            foreach ([$media, $rewrite] as $Response) {
                self::assertSafeSvgResponse($Response);
                self::assertArrayNotHasKey('x-sendfile', $Response['headers']);
                self::assertArrayNotHasKey('x-accel-redirect', $Response['headers']);
            }
        }
    }

    public function testProtectedImageIsDeliveredToAuthorizedUserWithPrivateHeaders(): void
    {
        $Response = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'allowed'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertNotSame('', $Response['body']);
        self::assertStringContainsString('private', $Response['headers']['cache-control'] ?? '');
        self::assertStringContainsString('no-store', $Response['headers']['cache-control'] ?? '');
        self::assertStringNotContainsString('public', $Response['headers']['cache-control'] ?? '');
    }

    public function testProtectedImageReturnsUniformEmpty404ForNobody(): void
    {
        $Response = self::requestImage(self::$activeImageId, [
            '_access' => 'protected'
        ]);

        self::assertUniformNotFound($Response);
    }

    public function testInactiveImageReturns404AlthoughPhysicalFileExists(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$inactiveImageId);
        self::assertFileExists($Image->getFullPath());

        self::assertUniformNotFound(self::requestImage(self::$inactiveImageId));
    }

    public function testAuthorizedBackendUserCanPreviewInactiveImage(): void
    {
        $Response = self::requestImage(self::$inactiveImageId, [
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertNotSame('', $Response['body']);
        self::assertStringStartsWith('image/png', $Response['headers']['content-type'] ?? '');
        self::assertStringContainsString('private', $Response['headers']['cache-control'] ?? '');
        self::assertStringNotContainsString('public', $Response['headers']['cache-control'] ?? '');
    }

    public function testProtectedSvgIsNotDelivered(): void
    {
        $Response = self::requestImage(self::$svgId, [
            '_access' => 'protected',
            '_user' => 'nobody',
            'quiadmin' => '1'
        ]);

        self::assertUniformNotFound($Response);
        self::assertStringNotContainsString('<svg', $Response['body']);
    }

    public function testHarmlessSvgIsDeliveredInlineWithSecurityHeader(): void
    {
        self::assertSafeSvgResponse(self::requestImage(self::$svgId, ['noresize' => '1']));
    }

    public function testUploadedMaliciousSvgIsSanitizedBeforeStorage(): void
    {
        $Svg = self::getTestProject()->getMedia()->get(self::$uploadedMaliciousSvgId);
        $storedSvg = file_get_contents($Svg->getFullPath());

        self::assertIsString($storedSvg);
        self::assertSafeSvgMarkup($storedSvg);
        self::assertSafeSvgResponse(self::requestImage(self::$uploadedMaliciousSvgId, ['noresize' => '1']));
    }

    public function testMalformedSvgUploadIsRejected(): void
    {
        $source = self::$testDirectory . '/malformed-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>');

        try {
            ProjectTestHelper::runAsSystemUser(
                static fn () => self::getTestProject()->getMedia()->firstChild()->uploadFile($source)
            );
            self::fail('Malformed SVG upload was accepted.');
        } catch (\QUI\Exception $Exception) {
            self::assertSame(Media\ErrorCodes::FILE_IMAGE_CORRUPT, $Exception->getCode());
        } finally {
            unlink($source);
        }
    }

    public function testPreviouslyStoredMaliciousSvgIsSanitizedOnDirectOutput(): void
    {
        self::assertSafeSvgResponse(self::requestImage(self::$storedMaliciousSvgId, ['noresize' => '1']));
    }

    public function testPreviouslyStoredMaliciousSvgIsSanitizedForAdminPreview(): void
    {
        self::assertSafeSvgResponse(self::requestImage(self::$storedMaliciousSvgId, [
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]));
    }

    public function testPreviouslyStoredMaliciousSvgIsSanitizedForAjaxAdminPreview(): void
    {
        self::assertSafeSvgResponse(self::requestImage(self::$storedMaliciousSvgId, [
            '_ajax_preview' => '1',
            '_user' => 'backend-allowed'
        ]));
    }

    public function testExternalSvgRefreshIsSanitizedBeforeStorageAndAjaxPreview(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$externalImageId);
        $externalSvg = file_get_contents(self::$externalMaliciousSvg);

        self::assertInstanceOf(Media\Image::class, $Image);
        self::assertIsString($externalSvg);

        $Image = new ExternalImageTestDouble($Image->getAttributes(), $Image->getMedia(), $externalSvg);

        ProjectTestHelper::runAsSystemUser(static function () use ($Image): void {
            $Image->setAttribute('external', 'https://images.example.test/malicious.svg');
            $Image->save();
            $Image->updateExternalImage();
        });

        $storedSvg = file_get_contents($Image->getFullPath());

        self::assertIsString($storedSvg);
        self::assertSame('image/svg+xml', $Image->getAttribute('mime_type'));
        self::assertSafeSvgMarkup($storedSvg);
        self::assertSafeSvgResponse(self::requestImage(self::$externalImageId, [
            '_ajax_preview' => '1',
            '_user' => 'backend-allowed'
        ]));
    }

    public function testInvalidExternalSvgDoesNotReplaceExistingImage(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$invalidExternalImageId);
        $original = file_get_contents($Image->getFullPath());
        $externalSvg = file_get_contents(self::$externalInvalidSvg);

        self::assertInstanceOf(Media\Image::class, $Image);
        self::assertIsString($original);
        self::assertIsString($externalSvg);

        $Image = new ExternalImageTestDouble($Image->getAttributes(), $Image->getMedia(), $externalSvg);

        ProjectTestHelper::runAsSystemUser(static function () use ($Image): void {
            $Image->setAttribute('external', 'https://images.example.test/invalid.svg');
            $Image->save();
            $Image->updateExternalImage();
        });

        self::assertSame($original, file_get_contents($Image->getFullPath()));
        self::assertFalse((bool)$Image->getAttribute('active'));
    }

    public function testExternalLoopbackRefreshDoesNotReplaceExistingImage(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$externalImageId);
        $original = file_get_contents($Image->getFullPath());
        $externalUrl = 'http://127.0.0.1:' . self::$serverPort . '/' . basename(self::$externalMaliciousSvg);

        self::assertInstanceOf(Media\Image::class, $Image);
        self::assertIsString($original);

        ProjectTestHelper::runAsSystemUser(static function () use ($Image, $externalUrl): void {
            $Image->setAttribute('external', $externalUrl);
            $Image->save();
            $Image->updateExternalImage();
        });

        self::assertSame($original, file_get_contents($Image->getFullPath()));
        self::assertFalse((bool)$Image->getAttribute('active'));
    }

    public function testLocalFileExternalRefreshDoesNotReplaceExistingImage(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$invalidExternalImageId);
        $original = file_get_contents($Image->getFullPath());
        $externalUrl = 'file://' . self::$externalInvalidSvg;

        self::assertInstanceOf(Media\Image::class, $Image);
        self::assertIsString($original);

        ProjectTestHelper::runAsSystemUser(static function () use ($Image, $externalUrl): void {
            $Image->setAttribute('external', $externalUrl);
            $Image->save();
            $Image->updateExternalImage();
        });

        self::assertSame($original, file_get_contents($Image->getFullPath()));
        self::assertFalse((bool)$Image->getAttribute('active'));
    }

    public function testMalformedStoredSvgReturnsEmpty404(): void
    {
        self::assertUniformNotFound(self::requestImage(self::$invalidStoredSvgId, ['noresize' => '1']));
    }

    public function testExistingSvgResizeCacheIsSanitizedBeforeOutput(): void
    {
        $Svg = self::getTestProject()->getMedia()->get(self::$storedMaliciousSvgId);
        $cacheFile = $Svg->getSizeCachePath(false, false);
        $cacheDirectory = dirname($cacheFile);

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        file_put_contents($cacheFile, self::maliciousSvg());

        self::assertSafeSvgResponse(self::requestImage(self::$storedMaliciousSvgId, [
            'maxwidth' => '17'
        ]));

        $cachedSvg = file_get_contents($cacheFile);
        self::assertIsString($cachedSvg);
        self::assertSafeSvgMarkup($cachedSvg);
    }

    public function testRewriteCacheOutputSanitizesExistingSvg(): void
    {
        self::assertSafeSvgResponse(self::request(['_rewrite_svg' => '1']));
    }

    public function testRewriteCacheOutputRejectsMalformedSvg(): void
    {
        $Response = self::request(['_rewrite_invalid_svg' => '1']);

        self::assertSame(404, $Response['status'], self::failureMessage($Response));
        self::assertSame('', $Response['body']);
    }

    public function testDirectMediaFileOutputHonorsByteRange(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [], ['Range: bytes=2-5']);

        self::assertSame(206, $Response['status'], self::failureMessage($Response));
        self::assertSame('2345', $Response['body']);
        self::assertSame('bytes', $Response['headers']['accept-ranges'] ?? null);
        self::assertSame('bytes 2-5/10', $Response['headers']['content-range'] ?? null);
        self::assertSame('4', $Response['headers']['content-length'] ?? null);
    }

    public function testRewriteMediaFileOutputHonorsSuffixByteRange(): void
    {
        $Response = self::request(['_rewrite_media' => '1'], ['Range: bytes=-3']);

        self::assertSame(206, $Response['status'], self::failureMessage($Response));
        self::assertSame('789', $Response['body']);
        self::assertSame('bytes', $Response['headers']['accept-ranges'] ?? null);
        self::assertSame('bytes 7-9/10', $Response['headers']['content-range'] ?? null);
        self::assertSame('3', $Response['headers']['content-length'] ?? null);
    }

    public function testExistingAdminCacheContainingSvgIsSanitizedBeforeOutput(): void
    {
        $Project = self::getTestProject();
        $Image = $Project->getMedia()->get(self::$activeImageId);
        $cacheDirectory = self::$varDirectory . 'media/cache/admin/'
            . $Project->getName() . '/' . $Project->getLang() . '/';
        self::assertInstanceOf(Media\Image::class, $Image);

        $dimensions = $Image->getResizeSize(500, 500);
        $cacheFile = $cacheDirectory . self::$activeImageId
            . '__' . $dimensions['height'] . 'x' . $dimensions['width'] . '.png';

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }

        file_put_contents($cacheFile, self::maliciousSvg());

        self::assertSafeSvgResponse(self::requestImage(self::$activeImageId, [
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]));
    }

    public function testAdminPreviewUsesCanonicalDimensionsForCacheKey(): void
    {
        $Image = self::getTestProject()->getMedia()->get(self::$activeImageId);
        self::assertInstanceOf(Media\Image::class, $Image);
        $Image->deleteAdminCache();

        foreach ([100, 200, 100_000] as $dimension) {
            $Response = self::requestImage(self::$activeImageId, [
                '_user' => 'backend-allowed',
                'quiadmin' => '1',
                'maxwidth' => (string)$dimension,
                'maxheight' => (string)$dimension
            ]);

            self::assertSame(200, $Response['status'], self::failureMessage($Response));
        }

        $Project = self::getTestProject();
        $cacheDirectory = self::$varDirectory . 'media/cache/admin/'
            . $Project->getName() . '/' . $Project->getLang() . '/';
        $dimensions = $Image->getResizeSize(100, 100);
        $expectedCacheFile = $cacheDirectory . self::$activeImageId
            . '__' . $dimensions['height'] . 'x' . $dimensions['width'] . '.png';
        $cacheFiles = [];

        foreach (self::getFilesBelow($cacheDirectory) as $cacheFile) {
            if (str_starts_with(basename($cacheFile), self::$activeImageId . '__')) {
                $cacheFiles[] = $cacheFile;
            }
        }

        self::assertSame([$expectedCacheFile], $cacheFiles);
    }

    public function testPreviewProcessingNeverOutputsMalformedSvgOriginal(): void
    {
        $Response = self::requestImage(self::$svgFallbackId, [
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]);

        self::assertStringNotContainsString('svg-fallback-secret', $Response['body']);
        self::assertStringNotContainsString('<script', $Response['body']);

        if ($Response['status'] === 404) {
            self::assertUniformNotFound($Response);
            return;
        }

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertStringStartsWith('image/png', $Response['headers']['content-type'] ?? '');
    }

    public function testExistingResizeCacheCannotBypassAcl(): void
    {
        $allowed = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'allowed',
            'maxwidth' => '37'
        ]);
        self::assertSame(200, $allowed['status'], self::failureMessage($allowed));

        $cacheFiles = self::getFilesBelow(self::$varDirectory . 'media/cache/permissions');
        self::assertNotSame([], $cacheFiles, 'The authorized request must create the protected resize cache.');

        $denied = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            'maxwidth' => '37'
        ]);
        self::assertUniformNotFound($denied);
    }

    public function testExistingAdminCacheCannotBypassAcl(): void
    {
        $allowed = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]);
        self::assertSame(200, $allowed['status'], self::failureMessage($allowed));

        $cacheFiles = self::getFilesBelow(self::$varDirectory . 'media/cache/admin');
        self::assertNotSame([], $cacheFiles, 'The authorized request must create the admin cache.');

        $denied = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'backend-denied',
            'quiadmin' => '1'
        ]);
        self::assertUniformNotFound($denied);
    }

    public function testFailedProtectedAdminResizeDoesNotReturnOriginal(): void
    {
        $Response = self::requestImage(self::$corruptImageId, [
            '_access' => 'protected',
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]);

        self::assertUniformNotFound($Response);
        self::assertStringNotContainsString('protected-original-secret', $Response['body']);
    }

    public function testQuiadminParameterDoesNotGrantAccess(): void
    {
        self::assertUniformNotFound(self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            'quiadmin' => '1'
        ]));
    }

    public function testForeignOrManipulatedRefererDoesNotGrantAccess(): void
    {
        self::assertUniformNotFound(self::requestImage(
            self::$activeImageId,
            ['_access' => 'protected'],
            ['Referer: https://attacker.invalid/admin/']
        ));

        self::assertUniformNotFound(self::requestImage(
            self::$activeImageId,
            ['_access' => 'protected'],
            ['Referer: http://127.0.0.1:' . self::$serverPort . '/admin/']
        ));
    }

    public function testBackendUserWithoutItemPermissionGetsNoPreview(): void
    {
        self::assertUniformNotFound(self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'backend-denied',
            'quiadmin' => '1'
        ]));
    }

    public function testAuthorizedBackendUserGetsPrivatePreview(): void
    {
        $Response = self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            '_user' => 'backend-allowed',
            'quiadmin' => '1'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertNotSame('', $Response['body']);
        self::assertStringContainsString('private', $Response['headers']['cache-control'] ?? '');
        self::assertStringNotContainsString('public', $Response['headers']['cache-control'] ?? '');
    }

    public function testBackendMediaDownloadWithoutItemPermissionIsRejected(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [
            '_ajax_download' => '1',
            '_access' => 'protected',
            '_user' => 'backend-denied'
        ]);

        self::assertStringStartsWith('application/json', $Response['headers']['content-type'] ?? '');
        self::assertGreaterThanOrEqual(400, $Response['status'], self::failureMessage($Response));
        self::assertSame(403, json_decode($Response['body'], true)['code'] ?? null, self::failureMessage($Response));
        self::assertStringNotContainsString('0123456789', $Response['body']);
        self::assertArrayNotHasKey('content-disposition', $Response['headers']);
    }

    public function testAuthorizedBackendUserCanDownloadMediaFile(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [
            '_ajax_download' => '1',
            '_access' => 'protected',
            '_user' => 'backend-allowed'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertSame('0123456789', $Response['body']);
        self::assertStringContainsString('attachment', $Response['headers']['content-disposition'] ?? '');
    }

    public function testBackendMediaFolderDownloadWithoutItemPermissionIsRejected(): void
    {
        $Response = self::requestImage(self::$folderId, [
            '_ajax_folder_download' => '1',
            '_access' => 'protected',
            '_user' => 'backend-denied'
        ]);

        self::assertStringStartsWith('application/json', $Response['headers']['content-type'] ?? '');
        self::assertGreaterThanOrEqual(400, $Response['status'], self::failureMessage($Response));
        self::assertSame(403, json_decode($Response['body'], true)['code'] ?? null, self::failureMessage($Response));
        self::assertArrayNotHasKey('content-disposition', $Response['headers']);
    }

    public function testAuthorizedBackendUserCanDownloadMediaFolder(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('The ZIP extension is not available.');
        }

        $Response = self::requestImage(self::$folderId, [
            '_ajax_folder_download' => '1',
            '_access' => 'protected',
            '_user' => 'backend-allowed'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertStringStartsWith('PK', $Response['body']);
        self::assertStringContainsString('attachment', $Response['headers']['content-disposition'] ?? '');
    }

    public function testBackendMediaPreviewWithoutItemPermissionIsRejected(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [
            '_ajax_preview' => '1',
            '_access' => 'protected',
            '_user' => 'backend-denied'
        ]);

        self::assertStringStartsWith('application/json', $Response['headers']['content-type'] ?? '');
        self::assertGreaterThanOrEqual(400, $Response['status'], self::failureMessage($Response));
        self::assertSame(403, json_decode($Response['body'], true)['code'] ?? null, self::failureMessage($Response));
        self::assertStringNotContainsString('0123456789', $Response['body']);
        self::assertArrayNotHasKey('accept-ranges', $Response['headers']);
        self::assertArrayNotHasKey('content-range', $Response['headers']);
    }

    public function testAuthorizedBackendUserCanPreviewMediaFile(): void
    {
        $Response = self::requestImage(self::$mediaFileId, [
            '_ajax_preview' => '1',
            '_access' => 'protected',
            '_user' => 'backend-allowed'
        ]);

        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertSame('0123456789', $Response['body']);
        self::assertArrayHasKey('content-type', $Response['headers']);
    }

    public function testProtectedFolderDoesNotReturnFolderIcon(): void
    {
        self::assertUniformNotFound(self::requestImage(self::$folderId, [
            '_access' => 'protected'
        ]));
    }

    public function testDeniedRequestDoesNotCreateResizeCache(): void
    {
        $before = self::getFilesBelow(self::$varDirectory . 'media/cache/permissions');

        self::assertUniformNotFound(self::requestImage(self::$activeImageId, [
            '_access' => 'protected',
            'maxwidth' => '83',
            'maxheight' => '61'
        ]));

        self::assertSame($before, self::getFilesBelow(self::$varDirectory . 'media/cache/permissions'));
    }

    public function testInvalidAndUnknownIdentifiersUseSame404Response(): void
    {
        $denied = self::requestImage(self::$activeImageId, ['_access' => 'protected']);
        $Requests = [
            ['id' => (string)self::$activeImageId],
            ['project' => self::getTestProjectName()],
            ['project' => 'unknown_project', 'id' => (string)self::$activeImageId],
            ['project' => 'invalid/project', 'id' => (string)self::$activeImageId],
            ['project' => self::getTestProjectName(), 'id' => '0'],
            ['project' => self::getTestProjectName(), 'id' => '-1'],
            ['project' => self::getTestProjectName(), 'id' => '1.5'],
            ['project' => self::getTestProjectName(), 'id' => '999999999'],
            ['project[]' => self::getTestProjectName(), 'id' => (string)self::$activeImageId],
            ['project' => self::getTestProjectName(), 'id[]' => (string)self::$activeImageId]
        ];

        foreach ($Requests as $params) {
            $Response = self::request($params);
            self::assertSame($denied['status'], $Response['status']);
            self::assertSame($denied['body'], $Response['body']);
            self::assertSame(
                $denied['headers']['cache-control'] ?? null,
                $Response['headers']['cache-control'] ?? null
            );
            self::assertArrayNotHasKey('content-disposition', $Response['headers']);
        }
    }

    public function testDeniedResponseHasNoSharedOrFileSpecificHeaders(): void
    {
        $Response = self::requestImage(self::$activeImageId, ['_access' => 'protected']);

        self::assertUniformNotFound($Response);
        self::assertStringNotContainsString('public', $Response['headers']['cache-control'] ?? '');
        self::assertArrayNotHasKey('content-type', $Response['headers']);
        self::assertArrayNotHasKey('content-disposition', $Response['headers']);
        self::assertArrayNotHasKey('last-modified', $Response['headers']);
    }

    /**
     * @param array<string, string> $params
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function requestImage(int $id, array $params = [], array $headers = [], bool $head = false): array
    {
        return self::request(array_merge([
            'project' => self::getTestProjectName(),
            'id' => (string)$id
        ], $params), $headers, $head);
    }

    /**
     * @param array<string, string> $params
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function request(array $params, array $headers = [], bool $head = false): array
    {
        $query = [];

        foreach ($params as $key => $value) {
            $query[] = urlencode($key) . '=' . urlencode($value);
        }

        $Curl = curl_init(
            'http://127.0.0.1:' . self::$serverPort . '/index.php?' . implode('&', $query)
        );

        if ($Curl === false) {
            throw new RuntimeException('Could not initialize image endpoint request.');
        }

        $responseHeaders = [];
        curl_setopt_array($Curl, [
            CURLOPT_HEADERFUNCTION => static function ($Curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');

                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($line, $separator + 1));
                }

                return strlen($line);
            },
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOBODY => $head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20
        ]);

        $body = curl_exec($Curl);
        $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
        curl_close($Curl);

        if ($body === false) {
            throw new RuntimeException('Image endpoint request failed.' . self::getServerLog());
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body
        ];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $Response
     */
    private static function assertUniformNotFound(array $Response): void
    {
        self::assertSame(404, $Response['status'], self::failureMessage($Response));
        self::assertSame('', $Response['body']);
        self::assertStringContainsString('private', $Response['headers']['cache-control'] ?? '');
        self::assertStringContainsString('no-store', $Response['headers']['cache-control'] ?? '');
        self::assertArrayNotHasKey('content-disposition', $Response['headers']);
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $Response
     */
    private static function assertSafeSvgResponse(array $Response): void
    {
        self::assertSame(200, $Response['status'], self::failureMessage($Response));
        self::assertStringStartsWith('image/svg+xml', $Response['headers']['content-type'] ?? '');
        self::assertSame('nosniff', $Response['headers']['x-content-type-options'] ?? '');
        self::assertSafeSvgMarkup($Response['body']);
    }

    private static function assertSafeSvgMarkup(string $svg): void
    {
        $Document = new DOMDocument();
        self::assertTrue($Document->loadXML($svg, LIBXML_NONET));
        self::assertNull($Document->doctype);
        self::assertSame('svg', $Document->documentElement?->tagName);

        $XPath = new DOMXPath($Document);
        $activeElements = $XPath->query(
            '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            . '"script" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            . '"style" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            . '"foreignobject" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            . '"iframe" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            . '"object" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="embed"]'
        );

        self::assertNotFalse($activeElements);
        self::assertSame(0, $activeElements->length);

        $Elements = $XPath->query('//*');
        self::assertNotFalse($Elements);

        foreach ($Elements as $Element) {
            if (!$Element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($Element->attributes) as $Attribute) {
                $name = strtolower($Attribute->nodeName);
                $value = strtolower(trim((string)$Attribute->nodeValue));

                self::assertFalse(str_starts_with($name, 'on'));
                self::assertNotSame('style', $name);
                self::assertStringNotContainsString('javascript:', $value);
                self::assertStringNotContainsString('data:', $value);
                self::assertStringNotContainsString('@import', $value);
                if (!str_starts_with($name, 'xmlns')) {
                    self::assertStringNotContainsString('https://', $value);
                    self::assertStringNotContainsString('http://', $value);
                    self::assertStringNotContainsString('file:', $value);
                }

                if (str_contains($name, 'href') && $value !== '') {
                    self::assertStringStartsWith('#', $value);
                }
            }
        }
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $Response
     */
    private static function failureMessage(array $Response): string
    {
        return $Response['body'] . self::getServerLog();
    }

    private static function createMediaFixtures(): void
    {
        $Project = self::getTestProject();
        $Media = $Project->getMedia();
        $Root = $Media->firstChild();
        $prefix = 'image-endpoint-' . bin2hex(random_bytes(4));
        $activePng = self::$testDirectory . '/' . $prefix . '-active.png';
        $inactivePng = self::$testDirectory . '/' . $prefix . '-inactive.png';
        $corruptPng = self::$testDirectory . '/' . $prefix . '-corrupt.png';
        $svg = self::$testDirectory . '/' . $prefix . '.svg';
        $uploadedMaliciousSvg = self::$testDirectory . '/' . $prefix . '-uploaded-malicious.svg';
        $storedMaliciousSvg = self::$testDirectory . '/' . $prefix . '-stored-malicious.svg';
        $invalidStoredSvg = self::$testDirectory . '/' . $prefix . '-stored-invalid.svg';
        $svgFallbackPng = self::$testDirectory . '/' . $prefix . '-svg-fallback.png';
        $externalPng = self::$testDirectory . '/' . $prefix . '-external.png';
        $invalidExternalPng = self::$testDirectory . '/' . $prefix . '-external-invalid.png';
        $mediaFile = self::$testDirectory . '/' . $prefix . '-media.mp4';
        self::$rewriteSvg = self::$testDirectory . '/' . $prefix . '-rewrite.svg';
        self::$invalidRewriteSvg = self::$testDirectory . '/' . $prefix . '-rewrite-invalid.svg';
        self::$rewriteMediaFile = self::$testDirectory . '/' . $prefix . '-rewrite.mp4';
        self::$externalMaliciousSvg = self::$testDirectory . '/' . $prefix . '-external-malicious.svg';
        self::$externalInvalidSvg = self::$testDirectory . '/' . $prefix . '-external-malformed.svg';

        self::createPng($activePng, 96, 64);
        self::createPng($inactivePng, 80, 50);
        self::createPng($corruptPng, 72, 48);
        self::createPng($svgFallbackPng, 72, 48);
        self::createPng($externalPng, 48, 32);
        self::createPng($invalidExternalPng, 48, 32);
        file_put_contents(
            $svg,
            '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20"/></svg>'
        );
        file_put_contents($uploadedMaliciousSvg, self::maliciousSvg());
        file_put_contents(self::$rewriteSvg, self::maliciousSvg());
        file_put_contents(self::$externalMaliciousSvg, self::maliciousSvg());
        file_put_contents(
            self::$externalInvalidSvg,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>external-invalid</script>'
        );
        file_put_contents(
            self::$invalidRewriteSvg,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>rewrite-secret</script>'
        );
        file_put_contents($storedMaliciousSvg, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        file_put_contents($invalidStoredSvg, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        file_put_contents($mediaFile, '0123456789');
        file_put_contents(self::$rewriteMediaFile, '0123456789');

        ProjectTestHelper::runAsSystemUser(
            static function () use (
                $Root,
                $activePng,
                $inactivePng,
                $corruptPng,
                $svg,
                $uploadedMaliciousSvg,
                $storedMaliciousSvg,
                $invalidStoredSvg,
                $svgFallbackPng,
                $externalPng,
                $invalidExternalPng,
                $mediaFile,
                $prefix
            ): void {
                $Folder = $Root->createFolder($prefix . '-folder');
                $Folder->activate();
                self::$folderId = $Folder->getId();
                file_put_contents($Folder->getFullPath() . 'archive-secret.txt', 'folder-archive-secret');

                $Active = $Root->uploadFile($activePng);
                $Active->activate();
                self::$activeImageId = $Active->getId();

                $Inactive = $Root->uploadFile($inactivePng);
                self::$inactiveImageId = $Inactive->getId();

                $Svg = $Root->uploadFile($svg);
                $Svg->activate();
                self::$svgId = $Svg->getId();

                $UploadedMaliciousSvg = $Root->uploadFile($uploadedMaliciousSvg);
                $UploadedMaliciousSvg->activate();
                self::$uploadedMaliciousSvgId = $UploadedMaliciousSvg->getId();

                $StoredMaliciousSvg = $Root->uploadFile($storedMaliciousSvg);
                $StoredMaliciousSvg->activate();
                self::$storedMaliciousSvgId = $StoredMaliciousSvg->getId();
                file_put_contents($StoredMaliciousSvg->getFullPath(), self::maliciousSvg());

                $InvalidStoredSvg = $Root->uploadFile($invalidStoredSvg);
                $InvalidStoredSvg->activate();
                self::$invalidStoredSvgId = $InvalidStoredSvg->getId();
                file_put_contents(
                    $InvalidStoredSvg->getFullPath(),
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>invalid-svg-secret</script>'
                );

                $Corrupt = $Root->uploadFile($corruptPng);
                $Corrupt->activate();
                self::$corruptImageId = $Corrupt->getId();
                file_put_contents($Corrupt->getFullPath(), 'protected-original-secret');

                $SvgFallback = $Root->uploadFile($svgFallbackPng);
                $SvgFallback->activate();
                self::$svgFallbackId = $SvgFallback->getId();
                file_put_contents(
                    $SvgFallback->getFullPath(),
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>svg-fallback-secret</script>'
                );

                $ExternalImage = $Root->uploadFile($externalPng);
                $ExternalImage->activate();
                self::$externalImageId = $ExternalImage->getId();

                $InvalidExternalImage = $Root->uploadFile($invalidExternalPng);
                $InvalidExternalImage->activate();
                self::$invalidExternalImageId = $InvalidExternalImage->getId();

                $MediaFile = $Root->uploadFile($mediaFile);
                $MediaFile->activate();
                self::$mediaFileId = $MediaFile->getId();
            }
        );

        self::$mediaIds = [
            self::$activeImageId,
            self::$inactiveImageId,
            self::$svgId,
            self::$uploadedMaliciousSvgId,
            self::$storedMaliciousSvgId,
            self::$invalidStoredSvgId,
            self::$corruptImageId,
            self::$svgFallbackId,
            self::$externalImageId,
            self::$invalidExternalImageId,
            self::$mediaFileId
        ];
    }

    private static function maliciousSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
            . 'width="20" height="20" onload="alert(1)">'
            . '<script>alert(document.domain)</script>'
            . '<style>@import url(https://attacker.invalid/a.css);</style>'
            . '<foreignObject><iframe src="https://attacker.invalid/"/></foreignObject>'
            . '<image href="https://attacker.invalid/a.png"/>'
            . '<use xlink:HrEf="javascript:alert(2)"/>'
            . '<rect width="20" height="20" onclick="alert(3)"/>'
            . '</svg>';
    }

    private static function deleteMediaFixtures(): void
    {
        if (!isset(self::$activeImageId)) {
            return;
        }

        $Media = self::getTestProject()->getMedia();

        ProjectTestHelper::runAsSystemUser(static function () use ($Media): void {
            foreach (self::$mediaIds as $id) {
                try {
                    $Item = $Media->get($id);
                    $Item->delete();
                    $Item->destroy();
                } catch (\Throwable) {
                }
            }

            try {
                $Folder = $Media->get(self::$folderId);
                $Folder->delete();
                $Folder->destroy();
            } catch (\Throwable) {
            }
        });
    }

    private static function createPng(string $file, int $width, int $height): void
    {
        $Image = imagecreatetruecolor($width, $height);
        $Color = imagecolorallocate($Image, 40, 120, 200);
        imagefilledrectangle($Image, 0, 0, $width - 1, $height - 1, $Color);
        imagepng($Image, $file);
        imagedestroy($Image);
    }

    private static function writeHttpEndpointWrapper(): void
    {
        $wrapper = <<<'PHP'
<?php

if (isset($_GET['health'])) {
    http_response_code(204);
    exit;
}

define('QUIQQER_SYSTEM', true);
// Match the real Ajax entrypoint before bootstrapping the request.
if (isset($_GET['_ajax_preview']) || isset($_GET['_ajax_download']) || isset($_GET['_ajax_folder_download'])) {
    define('QUIQQER_AJAX', true);
}

if (getenv('GITLAB_CI') === 'true') {
    define('VAR_DIR', %VAR_DIRECTORY%);
}

require %BOOTSTRAP_FILE%;

// Simulate trusted CGI parameters only inside this temporary test endpoint.
if (isset($_GET['_sendfile'])) {
    QUI::getRequest()->server->set('QUIQQER_SENDFILE_TYPE', $_GET['_sendfile']);
    QUI::getRequest()->server->set('QUIQQER_ACCEL_MAPPING', '/=/__quiqqer_files/');
}

$svgSanitizerAutoload = getenv('QUIQQER_SVG_TEST_AUTOLOAD');

if (is_string($svgSanitizerAutoload) && is_file($svgSanitizerAutoload)) {
    require $svgSanitizerAutoload;
}

$userMode = is_string($_GET['_user'] ?? null) ? $_GET['_user'] : 'nobody';
$User = new class ($userMode) extends QUI\Users\Nobody {
    public function __construct(private string $mode)
    {
    }

    public function getUUID(): string | int
    {
        if (str_contains($this->mode, 'allowed')) {
            return %ALLOWED_USER_ID%;
        }

        return $this->mode === 'backend-denied' ? 9002 : '';
    }

    public function getId(): false | int
    {
        if (str_contains($this->mode, 'allowed')) {
            return %ALLOWED_USER_ID%;
        }

        return $this->mode === 'backend-denied' ? 9002 : false;
    }

    public function getGroups(bool $array = true): array
    {
        return [];
    }

    public function canUseBackend(): bool
    {
        return str_starts_with($this->mode, 'backend-');
    }
};

$Nobody = new class () extends QUI\Users\Nobody {
    public function getGroups(bool $array = true): array
    {
        return [];
    }
};

QUI::$Users = new class ($User, $Nobody) extends QUI\Users\Manager {
    public function __construct(
        private QUI\Interfaces\Users\User $User,
        private QUI\Users\Nobody $Nobody
    ) {
    }

    public function getUserBySession(): QUI\Interfaces\Users\User
    {
        return $this->User;
    }

    public function getNobody(): QUI\Users\Nobody
    {
        return $this->Nobody;
    }

    public function isSystemUser(mixed $User): bool
    {
        return false;
    }
};

QUI::$Rights = new class () extends QUI\Permissions\Manager {
    public function __construct()
    {
    }

    public function getMediaPermissions($MediaItem): array
    {
        return [
            'quiqqer.projects.media.view' => ($_GET['_access'] ?? '') === 'protected'
                ? 'u%ALLOWED_USER_ID%'
                : false
        ];
    }

    public function getPermissionData(string $permission): array
    {
        return ['type' => 'users_and_groups'];
    }
};

$MediaPermissions = new ReflectionProperty(QUI\Projects\Media::class, 'mediaPermissions');
$MediaPermissions->setValue(null, true);
QUI\Permissions\Permission::setUser($User);

if (isset($_GET['_rewrite_svg'])) {
    QUI\Rewrite::sendFileWithRange(%REWRITE_SVG%, 'image/svg+xml');
}

if (isset($_GET['_rewrite_invalid_svg'])) {
    QUI\Rewrite::sendFileWithRange(%INVALID_REWRITE_SVG%, 'image/svg+xml');
}

if (isset($_GET['_rewrite_media'])) {
    QUI\Rewrite::sendFileWithRange(%REWRITE_MEDIA_FILE%, 'video/mp4');
}

chdir(%CORE_DIRECTORY%);

if (isset($_GET['_ajax_preview'])) {
    QUI::$Ajax = new QUI\Ajax();
    require %AJAX_PREVIEW_ENDPOINT%;

    $callables = QUI\Ajax::getRegisteredCallables();
    $preview = $callables['ajax_media_file_preview']['callable'];
    $preview((string)($_GET['project'] ?? ''), (string)($_GET['id'] ?? ''));
    exit;
}

if (isset($_GET['_ajax_download'])) {
    QUI::$Ajax = new QUI\Ajax();
    require %AJAX_DOWNLOAD_ENDPOINT%;

    $callables = QUI\Ajax::getRegisteredCallables();
    $download = $callables['ajax_media_file_download']['callable'];
    $download((string)($_GET['project'] ?? ''), (string)($_GET['id'] ?? ''));
    exit;
}

if (isset($_GET['_ajax_folder_download'])) {
    QUI::$Ajax = new QUI\Ajax();
    require %AJAX_FOLDER_DOWNLOAD_ENDPOINT%;

    $callables = QUI\Ajax::getRegisteredCallables();
    $download = $callables['ajax_media_folder_download']['callable'];
    $download((string)($_GET['project'] ?? ''), (string)($_GET['id'] ?? ''));
    exit;
}

require %IMAGE_ENDPOINT%;
PHP;

        $wrapper = str_replace(
            [
                '%VAR_DIRECTORY%',
                '%BOOTSTRAP_FILE%',
                '%CORE_DIRECTORY%',
                '%IMAGE_ENDPOINT%',
                '%AJAX_PREVIEW_ENDPOINT%',
                '%AJAX_DOWNLOAD_ENDPOINT%',
                '%AJAX_FOLDER_DOWNLOAD_ENDPOINT%',
                '%ALLOWED_USER_ID%',
                '%REWRITE_SVG%',
                '%INVALID_REWRITE_SVG%',
                '%REWRITE_MEDIA_FILE%'
            ],
            [
                var_export(self::$varDirectory, true),
                var_export(CMS_DIR . 'bootstrap.php', true),
                var_export(dirname(__DIR__, 4), true),
                var_export(dirname(__DIR__, 4) . '/image.php', true),
                var_export(dirname(__DIR__, 4) . '/admin/ajax/media/file/preview.php', true),
                var_export(dirname(__DIR__, 4) . '/admin/ajax/media/file/download.php', true),
                var_export(dirname(__DIR__, 4) . '/admin/ajax/media/folder/download.php', true),
                (string)self::ALLOWED_USER_ID,
                var_export(self::$rewriteSvg, true),
                var_export(self::$invalidRewriteSvg, true),
                var_export(self::$rewriteMediaFile, true)
            ],
            $wrapper
        );

        file_put_contents(self::$testDirectory . '/index.php', $wrapper);
    }

    private static function reserveServerPort(): int
    {
        $errno = 0;
        $error = '';
        $Socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($Socket === false) {
            throw new RuntimeException('Could not reserve image endpoint server port: ' . $error);
        }

        $address = stream_socket_get_name($Socket, false);
        fclose($Socket);

        if ($address === false || ($separator = strrpos($address, ':')) === false) {
            throw new RuntimeException('Could not determine image endpoint server port.');
        }

        return (int)substr($address, $separator + 1);
    }

    private static function startHttpServer(): void
    {
        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . self::$serverPort,
            '-t',
            self::$testDirectory
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$serverLog, 'a'],
            2 => ['file', self::$serverLog, 'a']
        ];

        self::$serverProcess = proc_open($command, $descriptors, $pipes, self::$testDirectory);

        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('Could not start image endpoint HTTP server.');
        }

        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $Curl = curl_init('http://127.0.0.1:' . self::$serverPort . '/index.php?health=1');

            if ($Curl !== false) {
                curl_setopt_array($Curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 1
                ]);
                curl_exec($Curl);
                $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
                curl_close($Curl);

                if ($status === 204) {
                    return;
                }
            }

            usleep(20_000);
        }

        throw new RuntimeException('Image endpoint HTTP server did not become ready.' . self::getServerLog());
    }

    private static function stopHttpServer(): void
    {
        if (!is_resource(self::$serverProcess)) {
            return;
        }

        proc_terminate(self::$serverProcess);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $status = proc_get_status(self::$serverProcess);

            if (!$status['running']) {
                break;
            }

            usleep(10_000);
        }

        proc_close(self::$serverProcess);
        self::$serverProcess = null;
    }

    /**
     * @return list<string>
     */
    private static function getFilesBelow(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($Iterator as $File) {
            if ($File->isFile()) {
                $files[] = $File->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function getServerLog(): string
    {
        return is_file(self::$serverLog)
            ? "\nImage endpoint HTTP server log:\n" . file_get_contents(self::$serverLog)
            : '';
    }

    private static function removeTestDirectory(): void
    {
        if (!is_dir(self::$testDirectory)) {
            return;
        }

        $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$testDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($Iterator as $File) {
            if ($File->isDir() && !$File->isLink()) {
                rmdir($File->getPathname());
                continue;
            }

            unlink($File->getPathname());
        }

        rmdir(self::$testDirectory);
    }
}
