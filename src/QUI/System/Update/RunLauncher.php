<?php

namespace QUI\System\Update;

class RunLauncher
{
    public function __construct(
        private readonly RunRepository $repository,
        private readonly string $webRunnerUrl,
        private readonly string $phpBinary
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(?int $now = null, array $metadata = []): RunLaunch
    {
        return \QUI\System\MaintenanceLock::runWhenAvailable(fn(): RunLaunch => $this->createLocked($now, $metadata));
    }

    /** @param array<string, mixed> $metadata */
    private function createLocked(?int $now, array $metadata): RunLaunch
    {
        $run = $this->repository->create($now, $metadata);
        $webUrl = $this->createWebUrl($run);
        $webToken = bin2hex(random_bytes(32));
        $cliCommand = $this->createCliCommand($run);
        $state = $run->getState();

        $state->prepareWebAccess($webToken);
        $state->setMetadataValue('webUrl', $webUrl);
        $this->repository->save($state);

        return new RunLaunch(
            $run,
            $webUrl,
            $cliCommand,
            $webToken
        );
    }

    private function createWebUrl(Run $run): string
    {
        return $this->webRunnerUrl
            . '?id='
            . rawurlencode($run->getState()->getId());
    }

    private function createCliCommand(Run $run): string
    {
        return CliEnvironment::createShellPrefix()
            . escapeshellarg($this->phpBinary) . ' '
            . escapeshellarg($run->getExecuteFile()) . ' '
            . escapeshellarg($run->getToken());
    }
}
