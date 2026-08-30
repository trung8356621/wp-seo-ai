<?php

declare(strict_types=1);

/**
 * Release package + GitHub asset naming contract.
 *
 * php tests/ReleasePackageContractTest.php
 */

define('ABSPATH', __DIR__.'/');

require_once dirname(__DIR__).'/includes/class-github-release-client.php';
require_once dirname(__DIR__).'/includes/class-release-package.php';

use OmiSeoAiBridge\GitHub_Release_Client;
use OmiSeoAiBridge\Release_Package;

$failures = 0;

function omi_rel_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL  {$message}\n";
}

$root = dirname(__DIR__);
$versions = Release_Package::read_source_versions($root);
omi_rel_assert($versions['header'] !== '', 'plugin header Version present');
omi_rel_assert($versions['constant'] !== '', 'OMI_SEO_AI_BRIDGE_VERSION present');
omi_rel_assert($versions['header'] === $versions['constant'], 'header Version == constant');
omi_rel_assert(Release_Package::parse_version_arg($versions['header']) !== null, 'header version is x.y.z');

omi_rel_assert(
    Release_Package::default_dist_root($root) === 'D:/work/build'
    || str_ends_with(Release_Package::default_dist_root($root), '/build'),
    'default dist root is D:/work/build (outside plugin)',
);
omi_rel_assert(
    ! str_contains(Release_Package::default_dist_root($root), '/wp-seo-ai/'),
    'default dist root is not inside plugin tree',
);

$v = $versions['header'];
omi_rel_assert(
    GitHub_Release_Client::expected_asset_name($v) === 'wp-seo-ai-'.$v.'.zip',
    'canonical expected asset name',
);
omi_rel_assert(
    Release_Package::expected_asset_name($v) === GitHub_Release_Client::expected_asset_name($v),
    'Release_Package reuses GitHub_Release_Client naming',
);
omi_rel_assert(
    GitHub_Release_Client::legacy_asset_name($v) === 'omi-seo-ai-bridge-'.$v.'.zip',
    'legacy asset naming helper',
);

$client = new GitHub_Release_Client();

$okPayload = [
    'tag_name' => '1.0.84',
    'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/1.0.84',
    'body' => 'test',
    'published_at' => '2026-08-30T00:00:00Z',
    'assets' => [[
        'name' => 'wp-seo-ai-1.0.84.zip',
        'size' => 2048,
        'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/1.0.84/wp-seo-ai-1.0.84.zip',
    ]],
];
$ok = $client->normalize_release($okPayload);
omi_rel_assert(($ok['ok'] ?? false) === true, 'fixture versioned asset ok');
omi_rel_assert(($ok['version'] ?? '') === '1.0.84', 'fixture version parsed');
omi_rel_assert(trim((string) ($ok['package_url'] ?? '')) !== '', 'fixture package_url populated');
omi_rel_assert(($ok['expected_asset'] ?? '') === 'wp-seo-ai-1.0.84.zip', 'fixture expected_asset');

$unversioned = $client->normalize_release([
    'tag_name' => '1.0.84',
    'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/1.0.84',
    'assets' => [[
        'name' => 'wp-seo-ai.zip',
        'size' => 2048,
        'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/1.0.84/wp-seo-ai.zip',
    ]],
]);
omi_rel_assert(($unversioned['ok'] ?? true) === false, 'wp-seo-ai.zip rejected');
omi_rel_assert(($unversioned['code'] ?? '') === 'github_asset_missing', 'wp-seo-ai.zip => github_asset_missing');
omi_rel_assert(($unversioned['expected_asset'] ?? '') === 'wp-seo-ai-1.0.84.zip', 'diagnostic expected_asset');
omi_rel_assert(
    in_array('wp-seo-ai.zip', $unversioned['found_assets'] ?? [], true),
    'diagnostic found_assets includes wp-seo-ai.zip',
);
omi_rel_assert(($unversioned['release_version'] ?? '') === '1.0.84', 'diagnostic release_version');

$wrongVersion = $client->normalize_release([
    'tag_name' => '1.0.84',
    'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/1.0.84',
    'assets' => [[
        'name' => 'wp-seo-ai-1.0.83.zip',
        'size' => 2048,
        'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/1.0.84/wp-seo-ai-1.0.83.zip',
    ]],
]);
omi_rel_assert(($wrongVersion['ok'] ?? true) === false, 'wrong-version ZIP rejected');
omi_rel_assert(($wrongVersion['code'] ?? '') === 'github_asset_missing', 'wrong-version => github_asset_missing');
omi_rel_assert(
    in_array('wp-seo-ai-1.0.83.zip', $wrongVersion['found_assets'] ?? [], true),
    'diagnostic lists wrong-version asset',
);

$legacyOk = $client->normalize_release([
    'tag_name' => 'v1.0.84',
    'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/v1.0.84',
    'assets' => [[
        'name' => 'omi-seo-ai-bridge-1.0.84.zip',
        'size' => 1024,
        'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/v1.0.84/omi-seo-ai-bridge-1.0.84.zip',
    ]],
]);
omi_rel_assert(($legacyOk['ok'] ?? false) === true, 'legacy versioned ZIP still accepted for read');

$tmp = sys_get_temp_dir().'/omi-seo-release-test-'.bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
$badZip = $tmp.'/wp-seo-ai.zip';
$zip = new ZipArchive();
omi_rel_assert($zip->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'create temp unversioned zip');
$zip->addFromString('wp-seo-ai/omi-seo-ai-bridge.php', "<?php\n/**\n * Version: 1.0.84\n */\ndefine('OMI_SEO_AI_BRIDGE_VERSION', '1.0.84');\n");
$zip->close();
$badVerify = Release_Package::verify_zip($badZip, '1.0.84');
omi_rel_assert(($badVerify['ok'] ?? true) === false, 'verify rejects unversioned filename');

$goodName = $tmp.'/wp-seo-ai-1.0.84.zip';
$zip = new ZipArchive();
omi_rel_assert($zip->open($goodName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'create temp versioned zip');
$zip->addFromString(
    'wp-seo-ai/omi-seo-ai-bridge.php',
    "<?php\n/**\n * Plugin Name: TVH SEO AI Bridge\n * Version: 1.0.84\n */\ndefine('OMI_SEO_AI_BRIDGE_VERSION', '1.0.84');\n",
);
$zip->close();
$goodVerify = Release_Package::verify_zip($goodName, '1.0.84');
omi_rel_assert(($goodVerify['ok'] ?? false) === true, 'verify accepts canonical versioned zip');

$mismatchZip = $tmp.'/wp-seo-ai-1.0.84.zip';
$zip = new ZipArchive();
$zip->open($mismatchZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString(
    'wp-seo-ai/omi-seo-ai-bridge.php',
    "<?php\n/**\n * Version: 1.0.83\n */\ndefine('OMI_SEO_AI_BRIDGE_VERSION', '1.0.83');\n",
);
$zip->close();
$mismatchVerify = Release_Package::verify_zip($mismatchZip, '1.0.84');
omi_rel_assert(($mismatchVerify['ok'] ?? true) === false, 'verify rejects header/constant mismatch vs release');

// Cleanup
foreach ([$badZip, $goodName, $mismatchZip] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
@rmdir($tmp);

$updaterSrc = (string) file_get_contents($root.'/includes/class-plugin-updater.php');
omi_rel_assert(str_contains($updaterSrc, 'force_canonical_source_dir'), 'canonical source remap exists');
omi_rel_assert(str_contains($updaterSrc, "'wp-seo-ai'"), 'canonical install folder wp-seo-ai');

$clientSrc = (string) file_get_contents($root.'/includes/class-github-release-client.php');
omi_rel_assert(str_contains($clientSrc, 'found_assets'), 'client exposes found_assets diagnostic');
omi_rel_assert(str_contains($clientSrc, 'expected_asset'), 'client exposes expected_asset diagnostic');
omi_rel_assert(
    (bool) preg_match('/\(\$cached\[\'ok\'\].*=== true/', $clientSrc),
    'only successful releases are cached',
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK\n";
