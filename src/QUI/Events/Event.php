<?php

/**
 * This file contains \QUI\Events\Event
 */

namespace QUI\Events;

use QUI;
use ReflectionMethod;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function explode;
use function gettype;
use function is_array;
use function is_callable;
use function is_string;
use function preg_replace;
use function str_contains;
use function ucfirst;
use function usort;

/**
 * Events Handling
 * Extends a class with the events interface
 */
class Event implements QUI\Interfaces\Events
{
    /**
     * @var array<string, array<int, array{
     *     callable: callable,
     *     priority: int,
     *     package: string
     * }>>
     */
    protected array $events = [];

    /**
     * @var array<string, bool>
     */
    protected array $currentRunning = [];

    /**
     * @var array<string, bool>
     */
    protected array $ignore = [];

    /**
     * Return all registered runtime events.
     *
     * @return array<string, array<int, array{
     *     callable: callable,
     *     priority: int,
     *     package: string
     * }>>
     */
    public function getList(): array
    {
        return $this->events;
    }

    /**
     * Add multiple runtime events at once.
     *
     * @param array<string, callable|string|array{0: callable|string, 1: int, 2: string}> $events
     */
    public function addEvents(array $events): void
    {
        foreach ($events as $event => $fn) {
            if (is_array($fn) && isset($fn[2])) {
                $this->addEvent($event, $fn[0], $fn[1], $fn[2]);
                continue;
            }

            if (is_array($fn) && !is_callable($fn)) {
                QUI\System\Log::addDebug('Event error :: $fn is not callable', [
                    'fn' => $fn
                ]);

                continue;
            }

            $this->addEvent($event, $fn);
        }
    }

    /**
     * Add a runtime event listener.
     *
     * @param string $event Event name such as `onSave`
     * @param callable|string $fn Event handler
     * @param int $priority Lower values run earlier
     * @param string $package Owning package name
     */
    public function addEvent(
        string $event,
        callable | string $fn,
        int $priority = 0,
        string $package = ''
    ): void {
        if (!isset($this->events[$event])) {
            $this->events[$event] = [];
        }

        // don't add double events
        foreach ($this->events[$event] as $params) {
            if ($params['callable'] == $fn) {
                return;
            }
        }

        if (!is_callable($fn)) {
            QUI\System\Log::addDebug('Event error :: $fn is not callable', [
                'fn' => $fn
            ]);
            return;
        }

        $this->events[$event][] = [
            'callable' => $fn,
            'priority' => $priority,
            'package' => $package
        ];
    }

    /**
     * Remove multiple runtime events.
     *
     * @param array<string, callable|false> $events
     */
    public function removeEvents(array $events): void
    {
        foreach ($events as $event => $fn) {
            $this->removeEvent($event, $fn);
        }
    }

    /**
     * Remove a runtime event listener.
     *
     * @param string $event Event name
     * @param callable|string|false $fn Specific handler or `false` to remove the whole event
     */
    public function removeEvent(string $event, callable | string | false $fn = false): void
    {
        if (!isset($this->events[$event])) {
            return;
        }

        if (!$fn) {
            unset($this->events[$event]);

            return;
        }

        foreach ($this->events[$event] as $k => $_fn) {
            if ($_fn['callable'] == $fn) {
                unset($this->events[$event][$k]);
            }
        }

        if (empty($this->events[$event])) {
            unset($this->events[$event]);
        }
    }

    /**
     * Fire an event with optional arguments.
     *
     * @param string $event Event name such as `onComplete`
     * @param false|array<array-key, mixed> $args Event arguments; when provided they must be an array
     *
     * @return array<string, mixed> Event results indexed by callback name
     * @throws QUI\ExceptionStack
     */
    public function fireEvent(
        string $event,
        false | array $args = false,
        bool $force = false
    ): array {
        $results = [];

        if (!str_starts_with($event, 'on')) {
            $event = 'on' . ucfirst($event);
        }


        // recursion check
        if (
            isset($this->currentRunning[$event])
            && $this->currentRunning[$event]
            && $force === false
        ) {
            return $results;
        }

        if (!isset($this->events[$event])) {
            return $results;
        }

        $this->currentRunning[$event] = true;

        $Stack = null;
        $events = $this->events[$event];

        // sort
        usort($events, static function (array $a, array $b): int {
            if ($a['priority'] == $b['priority']) {
                return 0;
            }

            return $a['priority'] < $b['priority'] ? -1 : 1;
        });

        // execute events
        foreach ($events as $data) {
            $fn = $data['callable'];
            $pkg = $data['package'];

            if (isset($this->ignore[$pkg])) {
                continue;
            }

            try {
                if (!is_string($fn)) {
                    if ($args === false) {
                        $fn();
                        continue;
                    }

                    call_user_func_array($fn, $args);
                    continue;
                }

                $normalizedFn = preg_replace('/[\\\\]{2,}/', '\\', $fn);

                if (is_callable($normalizedFn)) {
                    $fn = $normalizedFn;
                }

                if ($args === false) {
                    $results[$fn] = call_user_func($fn);
                    continue;
                }

                if (str_contains($fn, '::')) {
                    $parts = explode('::', $fn);
                    $className = $parts[0];
                    $methodName = $parts[1];

                    $reflectionMethod = new ReflectionMethod($className, $methodName);

                    if (!$reflectionMethod->getNumberOfParameters()) {
                        $results[$fn] = call_user_func($fn);
                        continue;
                    }
                }

                $results[$fn] = call_user_func_array($fn, $args);
            } catch (QUI\Exception $Exception) {
                if ($Stack === null) {
                    $Stack = new QUI\ExceptionStack();
                }

                $message = '[' . $event . '] ' . (is_string($fn) ? $fn : gettype($fn))
                    . ' :: ' . $Exception->getMessage();

                $Clone = new QUI\Exception(
                    $message,
                    $Exception->getCode(),
                    [
                        'trace' => $Exception->getTraceAsString(),
                        'file' => $Exception->getFile(),
                        'line' => $Exception->getLine(),
                        'event' => $event,
                        'fn' => is_string($fn) ? $fn : ''
                    ],
                    $Exception
                );

                $Stack->addException($Clone);
            } catch (Throwable $Exception) {
                if ($Stack === null) {
                    $Stack = new QUI\ExceptionStack();
                }

                $message = '[' . $event . '] ' . (is_string($fn) ? $fn : gettype($fn))
                    . ' :: ' . $Exception->getMessage();

                $Clone = new QUI\Exception(
                    $message,
                    (int)$Exception->getCode(),
                    [
                        'trace' => $Exception->getTraceAsString(),
                        'file' => $Exception->getFile(),
                        'line' => $Exception->getLine(),
                        'event' => $event,
                        'functionType' => gettype($fn),
                        'fn' => is_string($fn) ? $fn : ''
                    ]
                );

                $Stack->addException($Clone);
            }
        }

        $this->currentRunning[$event] = false;

        if ($Stack !== null && !$Stack->isEmpty()) {
            throw $Stack;
        }

        return $results;
    }

    //region ignore

    /**
     * Ignore all handlers of a package while firing events.
     */
    public function ignore(string $packageName): void
    {
        $this->ignore[$packageName] = true;
    }

    /**
     * Resets the ignore list
     */
    public function clearIgnore(): void
    {
        $this->ignore = [];
    }

    //endregion
}
