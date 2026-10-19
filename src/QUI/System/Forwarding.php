<?php

namespace QUI\System;

use DusanKasan\Knapsack\Collection;
use QUI;
use QUI\Config;
use QUI\Lock\Locker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use function debug_backtrace;
use function file_exists;
use function is_array;
use function trim;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Class Forwarding
 */
class Forwarding
{
    private const CONFIG_KEY = 'etc/forwarding.ini.php';

    /**
     * Create a forwarding entry
     *
     * @throws QUI\Exception
     */
    public static function create(string $from, string $target, int|string $httpCode = 301): void
    {
        self::validateSource($from);
        $httpCode = self::normalizeHttpCode($httpCode);

        self::changeConfig(static function (Config $Config) use ($from, $target, $httpCode): void {
            if ($Config->existValue($from)) {
                throw new QUI\Exception([
                    'quiqqer/core',
                    'exception.forwarding.already.exists'
                ]);
            }

            $Config->setValue($from, 'target', $target);
            $Config->setValue($from, 'code', $httpCode);
        });
    }

    /**
     * Return the forwarding config
     *
     * @throws QUI\Exception
     */
    public static function getConfig(): QUI\Config
    {
        $filename = CMS_DIR . self::CONFIG_KEY;

        if (!isset(QUI::$Configs[self::CONFIG_KEY]) && !file_exists($filename) && !@touch($filename)) {
            throw new QUI\Exception('Could not create the forwarding configuration.');
        }

        return QUI::getConfig(self::CONFIG_KEY);
    }

    /**
     * Update a forwarding entry
     *
     * @throws QUI\Exception
     */
    public static function update(string $from, string $target, int|string $httpCode = 301): void
    {
        self::validateSource($from);
        $httpCode = self::normalizeHttpCode($httpCode);

        self::changeConfig(static function (Config $Config) use ($from, $target, $httpCode): void {
            if (!$Config->existValue($from)) {
                throw new QUI\Exception(
                    [
                        'quiqqer/core',
                        'exception.forwarding.not.found'
                    ],
                    404
                );
            }

            $Config->setValue($from, 'target', $target);
            $Config->setValue($from, 'code', $httpCode);
        });
    }

    /**
     * Löscht ein forwarding eintrag
     *
     * @param array<int, string>|string $from
     *
     * @throws QUI\Exception
     */
    public static function delete(array|string $from): void
    {
        self::changeConfig(static function (Config $Config) use ($from): void {
            foreach (is_array($from) ? $from : [$from] as $source) {
                $Config->del($source);
            }
        });
    }

    /**
     * Return the list
     */
    public static function getList(bool $strict = false): Collection
    {
        try {
            $Config = self::getConfig();

            if ($strict) {
                $Config = self::readConfig($Config->getFilename());
            }

            return new Collection($Config->toArray());
        } catch (QUI\Exception $Exception) {
            if ($strict) {
                throw $Exception;
            }

            Log::writeException($Exception);
        }

        return new Collection([]);
    }

    /** Reject sources that cannot round-trip as an INI section name. */
    public static function validateSource(string $source): void
    {
        $valid = trim($source) !== '' && !preg_match('/[\x00-\x1f\x7f\[\]]/', $source);
        $parsed = $valid ? @parse_ini_string('[' . $source . "]\nvalue=\"test\"\n", true) : false;

        if ($parsed !== [$source => ['value' => 'test']]) {
            throw new QUI\Exception(
                'The forwarding source cannot be stored safely. '
                . 'Square brackets, control characters and invalid INI section names are not supported.',
                400
            );
        }
    }

    private static function readConfig(string $filename): Config
    {
        $contents = file_get_contents($filename);

        if ($contents === false) {
            throw new QUI\Exception('Could not read the forwarding configuration.');
        }

        // PHP 8.2 accepts malformed headers that newer INI parsers reject or interpret differently.
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $contents)) as $line) {
            $line = trim($line);

            if (
                str_starts_with($line, '[')
                && !preg_match('/^\[[^\[\]\x00-\x1f\x7f]+\][ \t]*(?:;.*)?$/D', $line)
            ) {
                throw new QUI\Exception('Invalid forwarding configuration section header.');
            }
        }

        $Config = new Config($filename);

        foreach ($Config->toArray() as $source => $data) {
            self::validateSource((string)$source);
        }

        return $Config;
    }

    /** @param callable(Config): void $Change */
    private static function changeConfig(callable $Change): void
    {
        $filename = self::getConfig()->getFilename();

        Locker::synchronized('forwarding-config:' . $filename, static function () use ($filename, $Change): void {
            // Reload under the lock so a cached request cannot overwrite another writer's rules.
            $Config = self::readConfig($filename);
            $Change($Config);
            self::saveConfig($Config);
            QUI::$Configs[self::CONFIG_KEY] = $Config;
        });
    }

    /** Serialize and verify off-file before publishing the complete configuration. */
    private static function saveConfig(Config $Config): void
    {
        $filename = realpath($Config->getFilename());

        if ($filename === false || !is_writable($filename) || !is_writable(dirname($filename))) {
            throw new QUI\Exception('The forwarding configuration is not writable.');
        }

        $expected = [];

        foreach ($Config->toArray() as $source => $data) {
            if (!is_array($data)) {
                throw new QUI\Exception('Invalid forwarding configuration section.');
            }

            $expected[$source] = [];

            foreach ($data as $key => $value) {
                if (!is_scalar($value)) {
                    throw new QUI\Exception('Invalid forwarding configuration value.');
                }

                $expected[$source][$key] = (string)$value;
            }
        }

        $temporary = @tempnam(dirname($filename), '.forwarding-');

        if ($temporary === false) {
            throw new QUI\Exception('Could not create a temporary forwarding configuration.');
        }

        try {
            if (dirname($temporary) !== dirname($filename)) {
                throw new QUI\Exception('The temporary forwarding configuration must be on the same filesystem.');
            }

            $Config->save($temporary);
            $contents = file_get_contents($temporary);
            $Persisted = new Config($temporary);

            if (
                $contents === false
                || !str_starts_with($contents, ";<?php exit; ?>\n")
                || $Persisted->toArray() !== $expected
            ) {
                throw new QUI\Exception('The forwarding configuration could not be serialized without data loss.');
            }

            $permissions = fileperms($filename);

            if ($permissions === false || !@chmod($temporary, $permissions & 0777) || !@rename($temporary, $filename)) {
                throw new QUI\Exception('Could not replace the forwarding configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Forward,
     * If the Request matches to one of the global forwarding,
     * it forward the request and cancel the current
     */
    public static function forward(Request $Request): void
    {
        $forwarding = self::resolve($Request);

        if ($forwarding !== null) {
            self::redirect($forwarding);
        }
    }

    /**
     * Resolve a request to its first matching forwarding rule without sending a response.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(Request $Request): ?array
    {
        $list = [];

        try {
            $list = self::getConfig()->toArray();
        } catch (QUI\Exception $Exception) {
            Log::writeException($Exception);
        }

        $uri = $Request->getRequestUri();
        $host = $Request->getSchemeAndHttpHost();

        $request = $host . $uri;

        // directly found
        if (isset($list[$request]) && is_array($list[$request])) {
            return $list[$request];
        }

        $trimmedRequest = trim($request, '/');

        if (isset($list[$trimmedRequest]) && is_array($list[$trimmedRequest])) {
            return $list[$trimmedRequest];
        }


        // search
        foreach ($list as $from => $params) {
            if (!QUI\Utils\StringHelper::match($from, $request)) {
                continue;
            }

            if (is_array($params)) {
                return $params;
            }
        }

        return null;
    }

    /**
     * Execute a redirection
     *
     * @param array<string, mixed> $data
     */
    protected static function redirect(array $data): never
    {
        $Redirect = self::createRedirectResponse($data);

        $Redirect->send();
        exit;
    }

    /**
     * Build the response for a forwarding rule without sending it.
     *
     * @param array<string, mixed> $data
     */
    public static function createRedirectResponse(array $data): RedirectResponse
    {
        $code = self::normalizeHttpCode($data['code']);
        $target = $data['target'];

        if (empty($target)) {
            $target = URL_DIR;
        }

        $Redirect = new RedirectResponse($target);
        $Redirect->setStatusCode($code);

        return $Redirect;
    }

    private static function normalizeHttpCode(mixed $httpCode): int
    {
        $code = match ($httpCode) {
            '', 0, '0', 301, '301' => 301,
            302, '302' => 302,
            303, '303' => 303,
            307, '307' => 307,
            308, '308' => 308,
            default => null
        };

        if ($code !== null) {
            return $code;
        }

        Log::addError('Unsupported forwarding HTTP status; using 301.', [
            'httpCode' => $httpCode,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ]);

        return 301;
    }
}
