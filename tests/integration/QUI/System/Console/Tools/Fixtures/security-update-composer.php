<?php

// Executed by the real Composer CLI adapter in a temporary working directory.
file_put_contents(__DIR__ . '/calls.jsonl', json_encode($argv, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

if (in_array('--help', $argv, true)) {
    echo "--patch-only\n";
    exit(0);
}

$scenario = trim(file_get_contents(__DIR__ . '/scenario'));
$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun && $scenario === 'no-updates') {
    fwrite(STDERR, "Nothing to modify in lock file\n");
    exit(0);
}

// A change summary can be emitted before Composer discovers an error.
fwrite(STDERR, "Lock file operations: 0 installs, 1 update, 0 removals\n");

if ($dryRun && $scenario === 'failed-preview') {
    fwrite(STDERR, "Dependency resolution failed after preview\n");
    exit(2);
}

if (!$dryRun) {
    file_put_contents(__DIR__ . '/installation-started', 'yes');
    if ($scenario === 'failed-installation') {
        fwrite(STDERR, "Class Symfony\\Component\\Finder\\Comparator\\NumberComparator not found\n");
        exit(1);
    }
}
