<?php

define('CMS_DIR', $argv[1]);
define('VAR_DIR', $argv[2]);
require dirname(__DIR__, 5) . '/src/autoload.php';

set_exception_handler(static function (Throwable $Exception) use ($argv): void {
    error_log($Exception->getMessage(), 3, $argv[3]);
    exit(255);
});

$Console = new class extends QUI\System\Console {
    public function __construct()
    {
    }

    public function writeLn(string $msg = '', bool|string $color = false, bool|string $bg = false): void
    {
        echo $msg . PHP_EOL;
    }

    public function clearMsg(): void
    {
    }
};

$Method = new ReflectionMethod(QUI\System\Console::class, 'executeWithTerminalProgress');
$Method->invoke($Console, static function (): void {
    throw new QUI\System\MaintenanceBusyException([
        [
            'operation' => 'System setup',
            'pid' => 12345,
            'startedAt' => 1793000000
        ]
    ]);
});
