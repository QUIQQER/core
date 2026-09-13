<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use QUI;
use QUI\Projects\ProjectTestHelper;
use QUI\REST\Server;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class MediaContentTest extends RestIntegrationTestCase
{
    public function testUploadDownloadReplacementAndFolderArchive(): void
    {
        $Project = ProjectTestHelper::getProject();
        $path = '/projects/' . $Project->getName() . '/media';
        $folder = $this->data($this->request('POST', $path . '/folders', [
            'parentId' => 1, 'name' => 'rest-content-' . bin2hex(random_bytes(4))
        ]), 201);
        $File = null;

        try {
            $Upload = (new ServerRequest('POST', '/api/quiqqer/core' . $path))
                ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
                ->withParsedBody(['parentId' => (string)$folder['id']])
                ->withUploadedFiles(['file' => new UploadedFile(
                    Utils::streamFor('initial content'),
                    15,
                    UPLOAD_ERR_OK,
                    'document.txt',
                    'text/plain'
                )]);
            $file = $this->data($this->dispatch($Upload), 201);
            $File = $Project->getMedia()->get($file['id']);
            self::assertSame('document', $file['name']);
            self::assertSame('document.txt', $file['filename']);
            self::assertSame($folder['id'], $file['parentId']);
            $contentPath = $path . '/' . $file['id'] . '/content';
            $Downloaded = $this->request('GET', $contentPath);
            self::assertSame(200, $Downloaded->getStatusCode());
            self::assertSame('initial content', (string)$Downloaded->getBody());
            self::assertStringStartsWith('attachment;', $Downloaded->getHeaderLine('Content-Disposition'));

            $Replace = (new ServerRequest('PUT', '/api/quiqqer/core' . $contentPath))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withBody(Utils::streamFor('replacement content'));
            $replaced = $this->data($this->dispatch($Replace));
            self::assertSame($file['id'], $replaced['id']);
            self::assertSame('replacement content', (string)$this->request('GET', $contentPath)->getBody());

            $sizePath = $path . '/' . $folder['id'] . '/size';
            self::assertSame(422, $this->request('GET', $sizePath . '?force=invalid')->getStatusCode());
            $size = $this->data($this->request('GET', $sizePath . '?force=true'));
            self::assertTrue($size['sizeKnown']);
            self::assertGreaterThanOrEqual(strlen('replacement content'), $size['sizeBytes']);
            self::assertSame($size, $this->data($this->request('GET', $sizePath)));

            $Archive = $this->request('GET', $path . '/' . $folder['id'] . '/content');
            self::assertSame(200, $Archive->getStatusCode(), (string)$Archive->getBody());
            self::assertSame('application/zip', $Archive->getHeaderLine('Content-Type'));
            self::assertStringStartsWith('PK', (string)$Archive->getBody());
            self::assertSame(204, $this->request('DELETE', $path . '/' . $file['id'])->getStatusCode());
            self::assertSame(404, $this->request('GET', $contentPath)->getStatusCode());
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $folder, $File): void {
                if ($File !== null) {
                    // Replacement can create a new cached object with the same ID.
                    try {
                        $Current = $Project->getMedia()->get($File->getId());
                    } catch (QUI\Exception) {
                        $Current = $File;
                    }

                    if (!$Current->isDeleted()) {
                        $Current->delete();
                    }

                    $Current->destroy();
                }

                $Project->getMedia()->get($folder['id'])->delete();
            });
        }
    }

    public function testMultipartRejectsPathNamesBeforeCreatingAFile(): void
    {
        $path = '/api/quiqqer/core/projects/' . ProjectTestHelper::getProject()->getName() . '/media';
        $Request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'multipart/form-data')
            ->withParsedBody(['parentId' => '1'])
            ->withUploadedFiles(['file' => new UploadedFile(
                Utils::streamFor('invalid'),
                7,
                UPLOAD_ERR_OK,
                '../outside.txt',
                'text/plain'
            )]);
        self::assertSame(422, $this->dispatch($Request)->getStatusCode());
    }

    private function dispatch(ServerRequest $Request): ResponseInterface
    {
        $Authentication = $this->createMock(AuthenticationInterface::class);
        $Authentication->method('authenticate')->willReturn($this->Root);
        $Server = new Server(['basePath' => '/api']);
        (new Provider($Authentication))->register($Server);
        return $Server->getSlim()->handle($Request);
    }
}
