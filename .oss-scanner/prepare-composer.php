<?php

declare(strict_types=1);

// Configure the existing testing installation without booting QUIQQER against
// the external MySQL database used by GitLab CI.
$file = '/var/www/html/var/composer/composer.json';
$root = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$core = json_decode(file_get_contents('/opt/quiqqer-core/composer.json'), true, flags: JSON_THROW_ON_ERROR);

// The testing image includes an IP-restricted licensed package repository.
// Core and its test dependencies use public packages, so external scanner
// runners must not query the inherited licensed feed during dependency setup.
$root['repositories'] = array_values(array_filter(
    $root['repositories'],
    static fn (array $repository): bool =>
        strtolower((string)parse_url($repository['url'] ?? '', PHP_URL_HOST)) !== 'license.quiqqer.com'
));

// Composer in the testing image cannot load the asset server's combination of
// inline packages and metadata-url ("Undefined array key uid"). Its inline
// package list is complete, so use that unchanged metadata as a package repo.
foreach ($root['repositories'] as &$repository) {
    if (rtrim($repository['url'] ?? '', '/') !== 'https://assets.quiqqer.com') {
        continue;
    }

    $metadata = json_decode(
        file_get_contents('https://assets.quiqqer.com/packages.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $packages = [];
    foreach ($metadata['packages'] as $versions) {
        array_push($packages, ...array_values($versions));
    }
    $repository = ['type' => 'package', 'package' => $packages];
}
unset($repository);

// An explicit local version avoids depending on Git tags or fetching the target
// again. Composer must resolve the checkout supplied as the build context.
array_unshift($root['repositories'], [
    'type' => 'path',
    'url' => '/opt/quiqqer-core',
    'options' => [
        'symlink' => false,
        'versions' => ['quiqqer/core' => '2.999.0']
    ]
]);
$root['require']['quiqqer/core'] = '2.999.0';

// Composer does not install a dependency's require-dev section. Include Core's
// REST and OAuth test dependencies at the installation root, as the CI does.
foreach ($core['require-dev'] ?? [] as $package => $constraint) {
    $root['require-dev'][$package] = $constraint;
}

file_put_contents($file, json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
