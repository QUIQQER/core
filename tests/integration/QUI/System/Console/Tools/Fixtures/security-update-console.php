<?php

// Exercise the production command exit status without changing the installation.
$core = dirname(__DIR__, 7);
define('QUIQQER_SYSTEM', true);
define('QUIQQER_AJAX', true);
if (getenv('GITLAB_CI') === 'true') {
    require dirname($core, 3) . '/bootstrap.php';
} else {
    require $core . '/tests/runtime-bootstrap.php';
}

$directory = $argv[1];
$Composer = new QUI\Composer\Composer($directory);
$Composer->setMode(QUI\Composer\Composer::MODE_CLI);
QUI::$PackageManager = new class ($Composer) extends QUI\Package\Manager {
    public function __construct(private readonly QUI\Composer\Composer $TestComposer)
    {
    }

    public function getComposer(): QUI\Composer\Composer
    {
        return $this->TestComposer;
    }

    public function refreshServerList(): void
    {
    }
};
$Tool = new class extends QUI\System\Console\Tools\SecurityUpdate {
    protected function setMaintenance(bool $enabled): void
    {
    }

    protected function isMaintenanceEnabled(): bool
    {
        return false;
    }

    protected function completeUpdate(): void
    {
    }
};
$Tool->execute();
echo 'command completed';
