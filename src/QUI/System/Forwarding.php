<?php

namespace QUI\System;

use DusanKasan\Knapsack\Collection;
use QUI;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use function debug_backtrace;
use function file_exists;
use function file_put_contents;
use function is_array;
use function trim;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Class Forwarding
 */
class Forwarding
{
    /**
     * Create a forwarding entry
     *
     * @throws QUI\Exception
     */
    public static function create(string $from, string $target, int|string $httpCode = 301): void
    {
        $config = self::getConfig()->toArray();

        if (isset($config[$from])) {
            throw new QUI\Exception([
                'quiqqer/core',
                'exception.forwarding.already.exists'
            ]);
        }

        $httpCode = self::normalizeHttpCode($httpCode);

        self::getConfig()->setValue($from, 'target', $target);
        self::getConfig()->setValue($from, 'code', $httpCode);
        self::getConfig()->save();
    }

    /**
     * Return the forwarding config
     *
     * @throws QUI\Exception
     */
    public static function getConfig(): QUI\Config
    {
        if (!file_exists(CMS_DIR . 'etc/forwarding.ini.php')) {
            file_put_contents(CMS_DIR . 'etc/forwarding.ini.php', '');
        }

        return QUI::getConfig('etc/forwarding.ini.php');
    }

    /**
     * Update a forwarding entry
     *
     * @throws QUI\Exception
     */
    public static function update(string $from, string $target, int|string $httpCode = 301): void
    {
        $config = self::getConfig()->toArray();

        if (!isset($config[$from])) {
            throw new QUI\Exception(
                [
                    'quiqqer/core',
                    'exception.forwarding.not.found'
                ],
                404
            );
        }

        $httpCode = self::normalizeHttpCode($httpCode);

        self::getConfig()->setValue($from, 'target', $target);
        self::getConfig()->setValue($from, 'code', $httpCode);
        self::getConfig()->save();
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
        if (is_array($from)) {
            foreach ($from as $f) {
                self::getConfig()->del($f);
            }
        } else {
            self::getConfig()->del($from);
        }

        self::getConfig()->save();
    }

    /**
     * Return the list
     */
    public static function getList(): Collection
    {
        try {
            return new Collection(self::getConfig()->toArray());
        } catch (QUI\Exception $Exception) {
            Log::writeException($Exception);
        }

        return new Collection([]);
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
