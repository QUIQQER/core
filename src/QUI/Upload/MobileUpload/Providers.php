<?php

declare(strict_types=1);

namespace QUI\Upload\MobileUpload;

use Closure;
use QUI;

/**
 * Resolve mobileUpload declarations through the Core package provider API.
 */
final class Providers
{
    /**
     * @var Closure(): iterable<string>
     */
    private readonly Closure $loader;

    /**
     * @param null|callable(): iterable<string> $loader
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = Closure::fromCallable($loader ?? $this->discover(...));
    }

    public function get(string $class): ProviderInterface
    {
        $class = ltrim($class, '\\');

        foreach (($this->loader)() as $candidate) {
            if (ltrim($candidate, '\\') !== $class) {
                continue;
            }

            if (!is_a($class, ProviderInterface::class, true)) {
                throw new QUI\Exception('Invalid mobile upload provider.', 503);
            }

            return new $class();
        }

        throw new QUI\Exception('Mobile upload provider is not registered.', 403);
    }

    /**
     * @return iterable<string>
     */
    private function discover(): iterable
    {
        foreach (QUI::getPackageManager()->getInstalled() as $package) {
            $name = $package['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            foreach (QUI::getPackage($name)->getProvider('mobileUpload') as $class) {
                if (is_string($class)) {
                    yield $class;
                }
            }
        }
    }
}
