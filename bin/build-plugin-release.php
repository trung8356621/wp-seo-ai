<?php

declare(strict_types=1);

/**
 * Build a versioned GitHub Release ZIP for wp-seo-ai.
 *
 * Usage:
 *   php bin/build-plugin-release.php 1.0.84
 *   php bin/build-plugin-release.php 1.0.84 --dist=D:/work/build
 *
 * Default output: D:/work/build/wp-seo-ai-{version}.zip (never inside the plugin repo).
 * Does not commit, push, or create a GitHub Release.
 */

$root = dirname(__DIR__);
$versionArg = $argv[1] ?? '';
$distRoot = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dist=')) {
        $distRoot = substr($arg, 7);
    }
}

if ($versionArg === '' || str_starts_with($versionArg, '--')) {
    fwrite(STDERR, "Usage: php bin/build-plugin-release.php {version} [--dist=path]\n");
    fwrite(STDERR, "Example: php bin/build-plugin-release.php 1.0.84\n");
    exit(1);
}

if (! defined('ABSPATH')) {
    define('ABSPATH', $root.DIRECTORY_SEPARATOR);
}

require_once $root.'/includes/class-github-release-client.php';
require_once $root.'/includes/class-release-package.php';

use OmiSeoAiBridge\Release_Package;

$result = Release_Package::build($root, $versionArg, $distRoot);

if (! ($result['ok'] ?? false)) {
    fwrite(STDERR, "RELEASE PACKAGE FAILED\n");
    foreach ($result['errors'] ?? [] as $error) {
        fwrite(STDERR, ' - '.$error."\n");
    }
    exit(1);
}

echo "RELEASE PACKAGE OK\n";
echo 'version: '.($result['version'] ?? '')."\n";
echo 'asset: '.($result['asset_name'] ?? '')."\n";
echo 'zip: '.($result['zip'] ?? '')."\n";
echo 'folder: '.($result['plugin_folder'] ?? '')."/\n";
echo 'main: '.($result['main_file'] ?? '')."\n";
foreach ($result['warnings'] ?? [] as $warning) {
    echo 'warning: '.$warning."\n";
}

exit(0);
