<?php

namespace QUI\Utils {
    /** Controls shell availability without depending on the installed utils package. */
    final class System
    {
        public static bool $shell = false;

        public static function isShellFunctionEnabled(string $function): bool
        {
            return self::$shell;
        }
    }
}

namespace {
    require dirname(__DIR__, 4) . '/src/QUI/Locale.php';

    $directory = sys_get_temp_dir() . '/quiqqer-locale-command-' . bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $previousPath = getenv('PATH');

    try {
        // The real Locale method executes this controlled `locale -a` command.
        file_put_contents($directory . '/locale', <<<'SH'
#!/bin/sh
printf '%s\n' en_US.utf8 de_CH.utf8 C de_DE.utf8 en_GB.utf8 de_AT.utf8
SH);
        chmod($directory . '/locale', 0700);
        putenv('PATH=' . $directory);
        \QUI\Utils\System::$shell = $argv[1] === '1';
        $Locale = new \QUI\Locale();
        $first = $Locale->getLocalesByLang($argv[2]);

        // A cached result must survive a change to the available system locales.
        file_put_contents($directory . '/locale', "#!/bin/sh\nprintf '%s\\n' changed\n");
        $second = $Locale->getLocalesByLang($argv[2]);
        echo json_encode([$first, $second], JSON_THROW_ON_ERROR);
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        unlink($directory . '/locale');
        rmdir($directory);
    }
}
