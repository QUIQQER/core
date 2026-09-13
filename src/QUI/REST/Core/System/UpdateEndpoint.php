<?php

namespace QUI\REST\Core\System;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Endpoint;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;
use QUI\System\Update\RunEntrypoint;
use QUI\System\Update\RunLauncherFactory;
use QUI\System\Update\RunRepository;
use QUI\System\Update\RunState;

abstract class UpdateEndpoint extends Endpoint
{
    protected const ACTION = 'history';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        User $User
    ): ResponseInterface {
        Permission::checkPermission('quiqqer.core.rest.system.updateAllowed', $User);
        $Repository = new RunRepository(self::root());
        $status = 200;

        switch (static::ACTION) {
            case 'prepare':
            case 'start':
                if ((string)$Request->getBody() !== '') {
                    Input::body($Request, []);
                }

                $data = self::prepare($Repository, static::ACTION === 'start', $User);
                $status = 202;
                break;

            case 'status':
                $State = self::state($Repository, $arguments['updateId']);
                $data = ['run' => $State->toPublicArray(), 'log' => self::log($State->getId())];
                break;

            case 'active':
                $runs = $Repository->cleanupAndFindActive(time(), 86400);
                $data = array_map(static fn(RunState $State) => $State->toPublicArray(), $runs['active']);
                break;

            case 'cancel':
                $State = self::state($Repository, $arguments['updateId']);
                $process = $State->getProcess();
                $pid = (int)($process['pid'] ?? 0);
                $signalSent = false;

                if ($State->getStatus() === RunState::STATUS_RUNNING && $pid > 0) {
                    $command = @file_get_contents('/proc/' . $pid . '/cmdline');
                    $expected = self::root() . $State->getId() . '/execute.php';

                    if (!is_string($command) || !str_contains($command, $expected) || !function_exists('posix_kill')) {
                        throw new ApiException('conflict', 'The running update process could not be verified.', 409);
                    }

                    $signalSent = posix_kill($pid, 15);

                    if (!$signalSent) {
                        throw new ApiException('conflict', 'The update process could not be stopped.', 409);
                    }
                }

                $State = $Repository->cancel($State->getId());
                $data = ['run' => $State->toPublicArray(), 'signalSent' => $signalSent];
                break;

            default:
                $limit = Input::integerQuery($Request, 'limit', 25, 1, 100);
                $data = array_map(static fn(RunState $State) => $State->toPublicArray(), $Repository->list($limit));
        }

        return JsonResponse::write($Response, [
            'data' => $data, 'maintenance' => ['active' => is_file(CMS_DIR . 'maintenance.html')]
        ], $status);
    }

    private static function root(): string
    {
        return rtrim(VAR_DIR, '/') . '/update/runs/';
    }

    private static function state(RunRepository $Repository, string $id): RunState
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new ApiException('invalid_input', 'Invalid update ID.');
        }

        if (!is_file(self::root() . $id . '/state.json')) {
            throw new ApiException('not_found', 'The update run does not exist.', 404);
        }

        return $Repository->load($id);
    }

    /** @return array<string, mixed> */
    private static function prepare(RunRepository $Repository, bool $start, User $User): array
    {
        $root = self::root();

        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new \RuntimeException('Could not create update directory.');
        }

        $Lock = fopen($root . '.rest-create.lock', 'c');

        if ($Lock === false) {
            throw new \RuntimeException('Could not open update lock.');
        }

        try {
            if (!flock($Lock, LOCK_EX | LOCK_NB)) {
                throw new ApiException('conflict', 'Another update request is being prepared.', 409);
            }

            $runs = $Repository->cleanupAndFindActive(time(), 86400);

            if ($runs['active'] !== []) {
                return ['created' => false, 'run' => $runs['active'][0]->toPublicArray()];
            }

            $Launch = RunLauncherFactory::createDefault()->create(null, [
                'type' => 'rest', 'userId' => $User->getUUID(), 'arguments' => []
            ]);
            $Run = $Launch->getRun();
            $State = $Run->getState();
            $started = false;

            if ($start) {
                $Started = (new RunEntrypoint())->startCliProcess(
                    $State->getId(),
                    $root,
                    $Run->getToken(),
                    $Repository
                );

                if ($Started !== null) {
                    $State = $Started;
                    $started = true;
                }
            }

            return [
                'created' => true, 'started' => $started, 'run' => $State->toPublicArray(),
                'token' => $Run->getToken(), 'webToken' => $Launch->getWebToken(), 'webUrl' => $Launch->getWebUrl()
            ];
        } finally {
            flock($Lock, LOCK_UN);
            fclose($Lock);
        }
    }

    private static function log(string $id): string
    {
        $path = self::root() . $id . '/runner.log';

        if (!is_file($path)) {
            return '';
        }

        $Handle = fopen($path, 'rb');

        if ($Handle === false) {
            return '';
        }

        try {
            $size = filesize($path) ?: 0;
            fseek($Handle, max(0, $size - 65536));
            $content = stream_get_contents($Handle, 65536);
        } finally {
            fclose($Handle);
        }

        $content = preg_replace('/\x1b\[[0-9;]*m/', '', $content ?: '') ?? '';
        $end = strpos($content, '{"success":');
        return rtrim($end === false ? $content : substr($content, 0, $end));
    }
}
