<?php

namespace QUI\System\Update;

use Throwable;

use function count;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_exists;
use function function_exists;
use function in_array;
use function is_resource;
use function ob_end_clean;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function rtrim;
use function shell_exec;
use function stream_get_contents;
use function system;
use function time;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;
use const PHP_EOL;

class RunEntrypoint
{
    /**
     * @param array<string, RunActionInterface> $actions
     * @param array<string, string>|null $query
     * @param array<int, string>|null $argv
     */
    public function execute(
        string $id,
        string $root,
        array $actions,
        ?array $query = null,
        ?array $argv = null,
        ?string $sapi = null,
        ?int $now = null,
        bool $includeCliCommand = true
    ): int {
        $sapi ??= (string)php_sapi_name();
        $query ??= $_GET;
        $argv ??= $_SERVER['argv'] ?? [];

        $token = '';

        try {
            $token = $this->getToken($sapi, $query, $argv);
            $repository = new RunRepository($root);

            if ($sapi !== 'cli' && ($query['foreground'] ?? '') !== '1') {
                $state = $this->startCliProcess($id, $root, $token, $repository);

                if ($state !== null) {
                    $this->sendResponse([
                        'success' => true,
                        'id' => $state->getId(),
                        'phase' => $state->getPhase(),
                        'status' => $state->getStatus(),
                        'process' => $state->getProcess(),
                        'message' => 'Update process started.'
                    ], $sapi);

                    return 0;
                }
            }

            $this->applyRunArguments($id, $repository, $sapi, $query, $argv);

            $processor = new RunProcessor($repository, $actions);

            if ($sapi === 'cli') {
                $loopCount = $this->getLoopCount($argv);

                if ($loopCount > 1) {
                    $state = $this->processCliLoop($processor, $id, $token, $loopCount, $now);

                    $this->sendResponse([
                        'success' => true,
                        'id' => $state->getId(),
                        'phase' => $state->getPhase(),
                        'status' => $state->getStatus()
                    ], $sapi);

                    return 0;
                }
            }

            $state = $processor->process($id, $token, $now);

            $this->sendResponse([
                'success' => true,
                'id' => $state->getId(),
                'phase' => $state->getPhase(),
                'status' => $state->getStatus()
            ], $sapi);

            return 0;
        } catch (Throwable $Exception) {
            $payload = [
                'success' => false,
                'error' => $Exception->getMessage()
            ];

            if ($sapi !== 'cli' && $includeCliCommand) {
                $payload['cliCommand'] = $this->createCliCommand($id, $root, $token);
            }

            $this->sendResponse($payload, $sapi, 500);

            return 1;
        }
    }

    public function startCliProcess(
        string $id,
        string $root,
        string $token,
        RunRepository $repository
    ): ?RunState {
        $now = time();
        $state = $repository->load($id);
        $state->assertAuthorized($token, $now);

        if ($this->isFinalState($state)) {
            return null;
        }

        $executeFile = $this->getExecuteFile($id, $root);

        if (!file_exists($executeFile)) {
            return null;
        }

        $command = $this->createCliCommand($id, $root, $token) . ' --loop=5';
        $logFile = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'runner.log';
        $process = $this->startBackgroundProcess($command, $logFile);

        if ($process === null) {
            return null;
        }

        // The runner records its own PID under its lock. A launcher write here
        // could overwrite a phase or terminal status already saved by the child.
        return $repository->load($id);
    }

    private function createCliCommand(string $id, string $root, string $token): string
    {
        return CliEnvironment::createShellPrefix()
            . escapeshellarg(RunLauncherFactory::resolveCliPhpBinary(PHP_BINARY))
            . ' '
            . escapeshellarg($this->getExecuteFile($id, $root))
            . ' '
            . escapeshellarg($token)
            . ' --yes';
    }

    private function getExecuteFile(string $id, string $root): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'execute.php';
    }

    /**
     * @return array{pid: int, method: string}|null
     */
    private function startBackgroundProcess(string $command, string $logFile): ?array
    {
        $shellCommand = '(' . $command . ') > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

        $pid = $this->startBackgroundProcessWithProcOpen($shellCommand);

        if ($pid > 0) {
            return [
                'pid' => $pid,
                'method' => 'proc_open'
            ];
        }

        $pid = $this->startBackgroundProcessWithShellExec($shellCommand);

        if ($pid > 0) {
            return [
                'pid' => $pid,
                'method' => 'shell_exec'
            ];
        }

        $pid = $this->startBackgroundProcessWithExec($shellCommand);

        if ($pid > 0) {
            return [
                'pid' => $pid,
                'method' => 'exec'
            ];
        }

        $pid = $this->startBackgroundProcessWithSystem($shellCommand);

        if ($pid > 0) {
            return [
                'pid' => $pid,
                'method' => 'system'
            ];
        }

        return null;
    }

    private function startBackgroundProcessWithProcOpen(string $shellCommand): int
    {
        if (!function_exists('proc_open')) {
            return 0;
        }

        try {
            $process = @proc_open($shellCommand, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ], $pipes);
        } catch (Throwable) {
            return 0;
        }

        if (!is_resource($process)) {
            return 0;
        }

        $output = '';

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        foreach ([1, 2] as $pipeIndex) {
            $pipe = $pipes[$pipeIndex] ?? null;

            if (is_resource($pipe)) {
                $output .= stream_get_contents($pipe);
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);
        proc_close($process);

        $pid = (int)trim($output);

        if ($pid > 0) {
            return $pid;
        }

        return (int)$status['pid'];
    }

    private function startBackgroundProcessWithShellExec(string $shellCommand): int
    {
        if (!function_exists('shell_exec')) {
            return 0;
        }

        try {
            return (int)trim((string)@shell_exec($shellCommand));
        } catch (Throwable) {
            return 0;
        }
    }

    private function startBackgroundProcessWithExec(string $shellCommand): int
    {
        if (!function_exists('exec')) {
            return 0;
        }

        try {
            $output = [];
            @exec($shellCommand, $output);
        } catch (Throwable) {
            return 0;
        }

        if (count($output) === 0) {
            return 0;
        }

        return (int)trim((string)$output[0]);
    }

    private function startBackgroundProcessWithSystem(string $shellCommand): int
    {
        if (!function_exists('system')) {
            return 0;
        }

        try {
            ob_start();
            @system($shellCommand);
            $output = (string)ob_get_clean();
        } catch (Throwable) {
            ob_end_clean();

            return 0;
        }

        return (int)trim($output);
    }

    private function isFinalState(RunState $state): bool
    {
        return in_array(
            $state->getStatus(),
            [
                RunState::STATUS_FINISHED,
                RunState::STATUS_FAILED,
                RunState::STATUS_CANCELLED
            ],
            true
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<int, string> $argv
     */
    private function getToken(string $sapi, array $query, array $argv): string
    {
        if ($sapi === 'cli') {
            return (string)($argv[1] ?? '');
        }

        return (string)($query['token'] ?? '');
    }

    /**
     * @param array<string, string> $query
     * @param array<int, string> $argv
     */
    private function applyRunArguments(
        string $id,
        RunRepository $repository,
        string $sapi,
        array $query,
        array $argv
    ): void {
        if ($sapi === 'cli') {
            $this->applyArguments($id, $repository, array_slice($argv, 2));
            return;
        }

        $arguments = [];

        if (($query['yes'] ?? '') === '1' || ($query['yes'] ?? '') === 'true') {
            $arguments[] = '--yes';
        }

        if (($query['skip-filesystem-check'] ?? '') === '1') {
            $arguments[] = '--skip-filesystem-check';
        }

        if (isset($query['verbose']) && in_array($query['verbose'], ['1', '2', '3'], true)) {
            $arguments[] = str_repeat('v', (int)$query['verbose']);
            $arguments[count($arguments) - 1] = '-' . $arguments[count($arguments) - 1];
        }

        $this->applyArguments($id, $repository, $arguments);
    }

    /**
     * @param array<int, string> $argv
     */
    private function applyArguments(string $id, RunRepository $repository, array $argv): void
    {
        $arguments = [];

        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--loop=')) {
                continue;
            }

            if ($argument === '--yes' || $argument === '-y') {
                $arguments['yes'] = true;
                continue;
            }

            if ($argument === '--skip-filesystem-check') {
                $arguments['skip-filesystem-check'] = true;
                continue;
            }

            if ($argument === '-v' || $argument === '--verbose') {
                $arguments['verbose'] = true;
                continue;
            }

            if ($argument === '-vv' || $argument === '--vv') {
                $arguments['-vv'] = true;
                continue;
            }

            if ($argument === '-vvv' || $argument === '--vvv') {
                $arguments['-vvv'] = true;
            }
        }

        if (empty($arguments)) {
            return;
        }

        $state = $repository->load($id);
        $metadata = $state->getMetadata();
        $existingArguments = $metadata['arguments'] ?? [];

        if (!is_array($existingArguments)) {
            $existingArguments = [];
        }

        foreach ($arguments as $name => $value) {
            $existingArguments[$name] = $value;
        }

        $state->setMetadataValue('arguments', $existingArguments);
        $repository->save($state);
    }

    /**
     * @param array<int, string> $argv
     */
    private function getLoopCount(array $argv): int
    {
        foreach ($argv as $argument) {
            if (!str_starts_with($argument, '--loop=')) {
                continue;
            }

            return max(1, (int)substr($argument, 7));
        }

        return 1;
    }

    private function processCliLoop(
        RunProcessor $processor,
        string $id,
        string $token,
        int $loopCount,
        ?int $now
    ): RunState {
        for ($attempt = 0; $attempt < $loopCount; $attempt++) {
            $state = $processor->process($id, $token, $now);

            if ($state->getStatus() !== RunState::STATUS_RESTART_REQUIRED) {
                return $state;
            }

            $this->sendResponse([
                'success' => true,
                'id' => $state->getId(),
                'phase' => $state->getPhase(),
                'status' => $state->getStatus()
            ], 'cli');
        }

        throw new \RuntimeException('Update run still requires a restart after maximum attempts.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendResponse(array $payload, string $sapi, int $statusCode = 200): void
    {
        if ($sapi === 'cli') {
            $this->sendCliResponse($payload);
            return;
        }

        if ($sapi !== 'cli' && !headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendCliResponse(array $payload): void
    {
        if (($payload['success'] ?? false) === false) {
            $this->sendCliError((string)($payload['error'] ?? 'Unknown error'));
            return;
        }

        $status = (string)($payload['status'] ?? '');
        $phase = (string)($payload['phase'] ?? '');

        if ($status === RunState::STATUS_RESTART_REQUIRED) {
            echo '[2/6] Composer tool' . PHP_EOL;
            echo '  [OK] Composer updated' . PHP_EOL;
            echo '  [..] Continuing with system update' . PHP_EOL;
            return;
        }

        if ($status === RunState::STATUS_FINISHED) {
            echo '  [OK] Update finished.' . PHP_EOL;
            echo PHP_EOL;
            return;
        }

        echo 'Update status: ' . $status . ' (' . $phase . ')' . PHP_EOL;
    }

    private function sendCliError(string $message): void
    {
        $headline = 'Update failed';
        $lines = [$headline, $message];
        $width = 0;

        foreach ($lines as $line) {
            $width = max($width, strlen($line));
        }

        $border = str_repeat(' ', $width + 4);
        $redBackground = "\033[41;37m";
        $reset = "\033[0m";

        echo PHP_EOL;
        echo $redBackground . $border . $reset . PHP_EOL;

        foreach ($lines as $line) {
            echo $redBackground
                . '  '
                . str_pad($line, $width)
                . '  '
                . $reset
                . PHP_EOL;
        }

        echo $redBackground . $border . $reset . PHP_EOL;
    }
}
