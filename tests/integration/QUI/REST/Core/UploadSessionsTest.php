<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace QUI\REST\Core;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use QUI;
use QUI\Projects\ProjectTestHelper;
use QUI\REST\Core\Project\Media\UploadSessions;
use QUI\REST\Server;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class UploadSessionsTest extends RestIntegrationTestCase
{
    public function testSessionValidatesContentAndFinalizesOnlyOnce(): void
    {
        $Project = ProjectTestHelper::getProject();
        $base = '/projects/' . $Project->getName() . '/media';
        $folder = $this->data($this->request('POST', $base . '/folders', [
            'parentId' => 1, 'name' => 'rest-session-' . bin2hex(random_bytes(5))
        ]), 201);
        $session = $this->data($this->request('POST', $base . '/uploads', [
            'parentId' => $folder['id'], 'filename' => 'session.txt', 'maxBytes' => 10,
            'allowedMimeTypes' => ['text/plain']
        ]), 201);
        $path = $base . '/uploads/' . $session['id'];
        $fileId = null;

        try {
            self::assertSame(409, $this->request('POST', $path . '/finalize')->getStatusCode());
            self::assertSame(413, $this->put($path . '/content', 'too much content')->getStatusCode());
            self::assertSame('pending', $this->data($this->request('GET', $path))['status']);
            $uploaded = $this->data($this->put($path . '/content', 'test data'));
            self::assertSame('uploaded', $uploaded['status']);
            self::assertSame(9, $uploaded['size']);
            $file = $this->data($this->request('POST', $path . '/finalize'));
            $fileId = $file['id'];
            self::assertSame($fileId, $this->data($this->request('POST', $path . '/finalize'))['id']);
            self::assertSame('test data', (string)$this->request('GET', $base . '/' . $fileId . '/content')->getBody());
            self::assertSame(409, $this->put($path . '/content', 'changed')->getStatusCode());
            $OtherProject = $this->request('GET', '/projects/other/media/uploads/' . $session['id']);
            self::assertSame(404, $OtherProject->getStatusCode());

            $other = $this->createUser();
            self::assertSame(204, $this->request('PUT', '/users/' . $other['uuid'] . '/password', [
                'password' => 'upload-session-test-123!'
            ])->getStatusCode());
            $this->data($this->request('POST', '/users/activate', ['userIds' => [$other['uuid']]]));
            $Other = QUI::getUsers()->get($other['uuid']);
            $Other->refresh();
            QUI::getPermissionManager()->setPermissions($Other, ['quiqqer.core.rest.canUse' => true], $this->Root);
            self::assertSame(404, $this->request('GET', $path, null, $Other)->getStatusCode());
            UploadSessions::withSession(
                $this->Root,
                $Project->getName(),
                $session['id'],
                static function (array &$state): void {
                    $state['expiresAt'] = time() - 1;
                }
            );
            self::assertSame(410, $this->request('GET', $path)->getStatusCode());
        } finally {
            ProjectTestHelper::runAsSystemUser(function () use ($Project, $folder, $fileId): void {
                if ($fileId !== null) {
                    $File = $Project->getMedia()->get($fileId);
                    $File->delete();
                    $File->destroy();
                }

                $Project->getMedia()->get($folder['id'])->delete();
            });
            $directory = QUI::getTemp()->createFolder('rest-upload-sessions')
                . hash('sha256', (string)$this->Root->getUUID()) . '/' . $session['id'];
            QUI\Utils\System\File::deleteDir($directory);
        }
    }

    private function put(string $path, string $content): \Psr\Http\Message\ResponseInterface
    {
        $Authentication = $this->createMock(AuthenticationInterface::class);
        $Authentication->method('authenticate')->willReturn($this->Root);
        $Server = new Server(['basePath' => '/api']);
        (new Provider($Authentication))->register($Server);
        $Request = (new ServerRequest('PUT', '/api/quiqqer/core' . $path))
            ->withHeader('Content-Type', 'application/octet-stream')->withBody(Utils::streamFor($content));
        return $Server->getSlim()->handle($Request);
    }
}
