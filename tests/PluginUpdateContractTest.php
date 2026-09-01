<?php

declare(strict_types=1);

/**
 * Standalone tests for GitHub Release plugin updater. No WordPress / PHPUnit required.
 *
 * php tests/PluginUpdateContractTest.php
 */

define('ABSPATH', __DIR__.'/');
define('OMI_SEO_AI_BRIDGE_VERSION', '1.0.74');
define('OMI_SEO_AI_BRIDGE_PATH', dirname(__DIR__).DIRECTORY_SEPARATOR);
define('OMI_SEO_AI_BRIDGE_BASENAME', 'wp-seo-ai/omi-seo-ai-bridge.php');

$omiTransients = [];
$omiOptions = [];
$omiHttpCalls = 0;

function get_transient(string $key): mixed
{
    return $GLOBALS['omiTransients'][$key] ?? false;
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    unset($ttl);
    $GLOBALS['omiTransients'][$key] = $value;

    return true;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['omiTransients'][$key]);

    return true;
}

function get_site_transient(string $key): mixed
{
    return $GLOBALS['omiSiteTransients'][$key] ?? false;
}

function set_site_transient(string $key, mixed $value, int $ttl = 0): bool
{
    unset($ttl);
    $GLOBALS['omiSiteTransients'][$key] = $value;

    return true;
}

function delete_site_transient(string $key): bool
{
    unset($GLOBALS['omiSiteTransients'][$key]);

    return true;
}

function wp_clean_plugins_cache(bool $clear_update_cache = true): void
{
    $GLOBALS['omiWpCleanPluginsCacheCalls'] = (int) ($GLOBALS['omiWpCleanPluginsCacheCalls'] ?? 0) + 1;
    if ($clear_update_cache) {
        unset($GLOBALS['omiSiteTransients']['update_plugins']);
    }
}

function wp_update_plugins(): void
{
    $GLOBALS['omiWpUpdatePluginsCalls'] = (int) ($GLOBALS['omiWpUpdatePluginsCalls'] ?? 0) + 1;
    // Simulate WP rebuild: filter pre_set runs via Plugin_Updater when present.
    $current = $GLOBALS['omiSiteTransients']['update_plugins'] ?? null;
    if (! is_object($current)) {
        $current = new stdClass();
    }
    if (! isset($current->checked) || ! is_array($current->checked)) {
        $current->checked = [
            OMI_SEO_AI_BRIDGE_BASENAME => (string) OMI_SEO_AI_BRIDGE_VERSION,
        ];
    }
    if (! empty($GLOBALS['omiPreSetUpdatePluginsFilter']) && is_callable($GLOBALS['omiPreSetUpdatePluginsFilter'])) {
        $current = ($GLOBALS['omiPreSetUpdatePluginsFilter'])($current);
    }
    $GLOBALS['omiSiteTransients']['update_plugins'] = $current;
}

function plugin_basename(string $file): string
{
    unset($file);

    return (string) OMI_SEO_AI_BRIDGE_BASENAME;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool
{
    unset($priority, $accepted);
    if ($hook === 'pre_set_site_transient_update_plugins') {
        $GLOBALS['omiPreSetUpdatePluginsFilter'] = $callback;
    }

    return true;
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool
{
    unset($hook, $callback, $priority, $accepted);

    return true;
}

$GLOBALS['omiSiteTransients'] = [];
$GLOBALS['omiWpCleanPluginsCacheCalls'] = 0;
$GLOBALS['omiWpUpdatePluginsCalls'] = 0;
$GLOBALS['omiPreSetUpdatePluginsFilter'] = null;

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['omiOptions'][$key] ?? $default;
}

function update_option(string $key, mixed $value, mixed $autoload = null): bool
{
    unset($autoload);
    $GLOBALS['omiOptions'][$key] = $value;

    return true;
}

function get_plugin_data(string $file, bool $markup = true, bool $translate = true): array
{
    unset($file, $markup, $translate);

    $version = (string) ($GLOBALS['omiInstalledVersionOverride'] ?? OMI_SEO_AI_BRIDGE_VERSION);

    return ['Version' => $version];
}

function is_plugin_active(string $plugin): bool
{
    unset($plugin);

    return true;
}

function activate_plugin(string $plugin, string $redirect = '', bool $network_wide = false, bool $silent = false): void
{
    unset($plugin, $redirect, $network_wide, $silent);
}

require_once dirname(__DIR__).'/includes/class-operation-store.php';
require_once dirname(__DIR__).'/includes/class-github-release-client.php';
require_once dirname(__DIR__).'/includes/class-bridge-update-service.php';
require_once dirname(__DIR__).'/includes/class-plugin-updater.php';

use OmiSeoAiBridge\Bridge_Update_Service;
use OmiSeoAiBridge\GitHub_Release_Client;
use OmiSeoAiBridge\Operation_Store;
use OmiSeoAiBridge\Plugin_Updater;

$failures = 0;

function omi_assert(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL  {$message}\n";
}

function omi_release_payload(string $tag, ?string $assetName, int $size = 1000): array
{
    $assets = [];
    if ($assetName !== null) {
        $assets[] = [
            'name' => $assetName,
            'size' => $size,
            'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/'.$tag.'/'.$assetName,
        ];
    }

    return [
        'tag_name' => $tag,
        'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/'.$tag,
        'body' => 'Changelog '.$tag,
        'published_at' => '2026-08-14T00:00:00Z',
        'assets' => $assets,
    ];
}

function omi_http_ok(array $payload): callable
{
    return static function (string $url) use ($payload): array {
        unset($url);
        $GLOBALS['omiHttpCalls']++;

        return [
            'ok' => true,
            'status' => 200,
            'body' => json_encode($payload),
        ];
    };
}

$cache = [];
$cacheGet = static function (string $key) use (&$cache): mixed {
    return $cache[$key] ?? false;
};
$cacheSet = static function (string $key, mixed $value, int $ttl) use (&$cache): void {
    unset($ttl);
    $cache[$key] = $value;
};
$cacheDelete = static function (string $key) use (&$cache): void {
    unset($cache[$key]);
};

$GLOBALS['omiHttpCalls'] = 0;
$client = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.75', 'wp-seo-ai-1.0.75.zip')),
    $cacheGet,
    $cacheSet,
    $cacheDelete,
);
$service = new Bridge_Update_Service($client);
$check = $service->check(false);
omi_assert(($check['ok'] ?? false) === true, 'latest release ok');
omi_assert(($check['installed_version'] ?? '') === '1.0.74', 'installed 1.0.74');
omi_assert(($check['latest_version'] ?? '') === '1.0.75', 'latest 1.0.75');
omi_assert(($check['update_available'] ?? false) === true, 'update available when latest newer');
omi_assert($GLOBALS['omiHttpCalls'] === 1, 'first check hits GitHub');

$checkCached = $service->check(false);
omi_assert(($checkCached['from_cache'] ?? false) === true, 'normal check uses cache');
omi_assert($GLOBALS['omiHttpCalls'] === 1, 'cached check does not hit GitHub');

$checkForce = $service->check(true);
omi_assert(($checkForce['from_cache'] ?? false) === false, 'force refresh bypasses cache');
omi_assert($GLOBALS['omiHttpCalls'] === 2, 'force refresh hits GitHub');

$legacyClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.75', 'omi-seo-ai-bridge-1.0.75.zip')),
);
$legacy = (new Bridge_Update_Service($legacyClient))->check(true);
omi_assert(($legacy['ok'] ?? false) === true, 'legacy omi-seo-ai-bridge zip still accepted');
omi_assert(($legacy['latest_version'] ?? '') === '1.0.75', 'legacy zip reports latest');

$sameClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.74', 'wp-seo-ai-1.0.74.zip')),
);
$same = (new Bridge_Update_Service($sameClient))->check(true);
omi_assert(($same['update_available'] ?? true) === false, 'same version is not an update');
omi_assert(($same['latest_version'] ?? '') === '1.0.74', 'same version reports latest');

$invalid = (new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('not-a-version', 'omi-seo-ai-bridge-1.0.75.zip')),
))->fetch_latest(true);
omi_assert(($invalid['ok'] ?? true) === false, 'invalid tag is not fatal');
omi_assert(($invalid['code'] ?? '') === 'github_invalid_tag', 'invalid tag code');

$missingAsset = (new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.75', null)),
))->fetch_latest(true);
omi_assert(($missingAsset['ok'] ?? true) === false, 'missing asset is not fatal');
omi_assert(($missingAsset['code'] ?? '') === 'github_asset_missing', 'missing asset code');

$unversionedAsset = (new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('1.0.84', 'wp-seo-ai.zip')),
))->fetch_latest(true);
omi_assert(($unversionedAsset['ok'] ?? true) === false, 'unversioned wp-seo-ai.zip rejected');
omi_assert(($unversionedAsset['code'] ?? '') === 'github_asset_missing', 'unversioned asset code');
omi_assert(($unversionedAsset['expected_asset'] ?? '') === 'wp-seo-ai-1.0.84.zip', 'unversioned diagnostic expected');
omi_assert(in_array('wp-seo-ai.zip', $unversionedAsset['found_assets'] ?? [], true), 'unversioned diagnostic found');

$wrongAsset = (new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('1.0.84', 'wp-seo-ai-1.0.83.zip')),
))->fetch_latest(true);
omi_assert(($wrongAsset['ok'] ?? true) === false, 'wrong-version asset rejected');
omi_assert(($wrongAsset['expected_asset'] ?? '') === 'wp-seo-ai-1.0.84.zip', 'wrong-version diagnostic expected');

$failCache = [];
$failCacheSetCalls = 0;
$failClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('1.0.84', 'wp-seo-ai.zip')),
    static function (string $key) use (&$failCache): mixed {
        return $failCache[$key] ?? false;
    },
    static function (string $key, mixed $value, int $ttl) use (&$failCache, &$failCacheSetCalls): void {
        unset($ttl);
        $failCacheSetCalls++;
        $failCache[$key] = $value;
    },
    static function (string $key) use (&$failCache): void {
        unset($failCache[$key]);
    },
);
$failClient->fetch_latest(true);
omi_assert($failCacheSetCalls === 0, 'invalid package is not cached');

$sourceZip = (new GitHub_Release_Client(
    omi_http_ok([
        'tag_name' => 'v1.0.75',
        'html_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/v1.0.75',
        'assets' => [[
            'name' => 'omi-seo-ai-bridge-1.0.75.zip',
            'size' => 1000,
            'browser_download_url' => 'https://github.com/trung8356621/wp-seo-ai/archive/refs/heads/main.zip',
        ]],
    ]),
))->fetch_latest(true);
omi_assert(($sourceZip['ok'] ?? true) === false, 'source archive URL is rejected');

$ghFail = (new GitHub_Release_Client(
    static fn (string $url): array => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'timeout'],
))->fetch_latest(true);
omi_assert(($ghFail['ok'] ?? true) === false, 'GitHub failure is not fatal');
omi_assert(($ghFail['code'] ?? '') === 'github_release_unavailable', 'GitHub failure code');

$rate = (new GitHub_Release_Client(
    static fn (string $url): array => ['ok' => true, 'status' => 403, 'body' => '{"message":"rate"}'],
))->fetch_latest(true);
omi_assert(($rate['code'] ?? '') === 'github_rate_limited', 'rate limit code');

$upgraderCalls = 0;
$upgrader = static function (string $package, string $version) use (&$upgraderCalls): array {
    unset($package, $version);
    $upgraderCalls++;

    return ['ok' => true, 'message' => ''];
};
$installClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.75', 'wp-seo-ai-1.0.75.zip')),
);
$installService = new Bridge_Update_Service($installClient, $upgrader);
$first = $installService->install('wp_plugin_update_01KTEST');
omi_assert(($first['ok'] ?? false) === true, 'install reports success');
omi_assert(($first['updated'] ?? false) === true, 'install marks updated');
omi_assert($upgraderCalls === 1, 'first install upgrades once');

$second = $installService->install('wp_plugin_update_01KTEST');
omi_assert(($second['replayed'] ?? false) === true, 'duplicate operation_id replays');
omi_assert($upgraderCalls === 1, 'duplicate operation_id does not upgrade twice');

$missingInstall = (new Bridge_Update_Service(
    new GitHub_Release_Client(omi_http_ok(omi_release_payload('v1.0.75', null))),
    $upgrader,
))->install('wp_plugin_update_missing_asset');
omi_assert(($missingInstall['ok'] ?? true) === false, 'missing asset skips install');
omi_assert($upgraderCalls === 1, 'missing asset does not call upgrader');

$restSrc = (string) file_get_contents(dirname(__DIR__).'/includes/class-rest-controller.php');
omi_assert(str_contains($restSrc, "/plugin-update/check"), 'check REST route exists');
omi_assert(str_contains($restSrc, "/plugin-update/install"), 'install REST route exists');
omi_assert(str_contains($restSrc, "handle_plugin_update_install"), 'install handler exists');
omi_assert(
    (bool) preg_match('/plugin-update\/install[\s\S]{0,400}authorize_write/', $restSrc),
    'install route uses write token',
);
omi_assert(
    (bool) preg_match('/plugin-update\/check[\s\S]{0,400}authorize/', $restSrc),
    'check route uses read token',
);
omi_assert(! str_contains($restSrc, 'permission_callback\' => [self::class, \'__return_true\']'), 'no public update routes');

$updaterSrc = (string) file_get_contents(dirname(__DIR__).'/includes/class-plugin-updater.php');
omi_assert(! str_contains($updaterSrc, '/api/seo/plugin/update-check'), 'updater has no Laravel update-check URL');
omi_assert(! str_contains($updaterSrc, 'fetch_legacy_laravel'), 'updater has no Laravel fallback');
omi_assert(str_contains($updaterSrc, 'force_canonical_source_dir'), 'updater remaps zip folder to install slug');
omi_assert(str_contains($updaterSrc, 'wp-seo-ai'), 'updater canonical slug is wp-seo-ai');

$bootstrapSrc = (string) file_get_contents(dirname(__DIR__).'/omi-seo-ai-bridge.php');
omi_assert(str_contains($bootstrapSrc, 'OMI_SEO_AI_BRIDGE_SLUG'), 'bootstrap canonical slug constant');
omi_assert(str_contains($bootstrapSrc, "'wp-seo-ai'"), 'bootstrap canonical folder is wp-seo-ai');
omi_assert(str_contains($bootstrapSrc, 'deactivate_plugins'), 'duplicate copy deactivates itself');
omi_assert(! str_contains($bootstrapSrc, '/api/seo/plugin/update-check'), 'settings check does not hit Laravel update-check');
omi_assert(str_contains($bootstrapSrc, 'Bridge_Update_Service'), 'settings check uses Bridge_Update_Service');
omi_assert(str_contains($bootstrapSrc, 'omi_seo_check_github_update'), 'settings has Check GitHub action');
omi_assert(str_contains($bootstrapSrc, 'Plugin_Updater::boot'), 'Plugin_Updater boots early');

$bridgeSrc = (string) file_get_contents(dirname(__DIR__).'/includes/class-bridge-update-service.php');
omi_assert(str_contains($bridgeSrc, 'refresh_wordpress_update_cache'), 'Bridge_Update_Service refreshes WP update cache');
omi_assert(str_contains($bridgeSrc, 'delete_site_transient'), 'force check deletes update_plugins transient');
omi_assert(str_contains($bridgeSrc, 'wp_update_plugins'), 'force check calls wp_update_plugins');
omi_assert(str_contains($updaterSrc, 'pre_set_site_transient_update_plugins'), 'Plugin_Updater hooks pre_set_site_transient_update_plugins');

// --- Native Plugins UI: check_for_update injects 1.0.85 for installed 1.0.84 ---
$GLOBALS['omiInstalledVersionOverride'] = '1.0.84';
$GLOBALS['omiPreSetUpdatePluginsFilter'] = null;
Plugin_Updater::boot(dirname(__DIR__).DIRECTORY_SEPARATOR.'omi-seo-ai-bridge.php');
omi_assert(is_callable($GLOBALS['omiPreSetUpdatePluginsFilter'] ?? null), 'Plugin_Updater registers pre_set filter');

$cachedRelease = [
    'ok' => true,
    'version' => '1.0.85',
    'package_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/download/1.0.85/wp-seo-ai-1.0.85.zip',
    'asset_name' => 'wp-seo-ai-1.0.85.zip',
    'expected_asset' => 'wp-seo-ai-1.0.85.zip',
    'found_assets' => ['wp-seo-ai-1.0.85.zip'],
    'release_url' => 'https://github.com/trung8356621/wp-seo-ai/releases/tag/1.0.85',
    'changelog' => '1.0.85',
    'checked_at' => '2026-08-31T00:00:00Z',
];
set_transient(GitHub_Release_Client::TRANSIENT_KEY, $cachedRelease);

$transient = new stdClass();
$transient->checked = [OMI_SEO_AI_BRIDGE_BASENAME => '1.0.84'];
$transient->response = [];
$filtered = ($GLOBALS['omiPreSetUpdatePluginsFilter'])($transient);
omi_assert(is_object($filtered), 'check_for_update returns object');
omi_assert(isset($filtered->response[OMI_SEO_AI_BRIDGE_BASENAME]), 'response key is exact installed basename');
$updateObj = $filtered->response[OMI_SEO_AI_BRIDGE_BASENAME];
omi_assert((string) ($updateObj->new_version ?? '') === '1.0.85', 'new_version = 1.0.85');
omi_assert(trim((string) ($updateObj->package ?? '')) !== '', 'package URL non-empty');
omi_assert(
    str_contains((string) $updateObj->package, 'wp-seo-ai-1.0.85.zip'),
    'package URL is canonical versioned zip'
);
omi_assert((string) ($updateObj->plugin ?? '') === OMI_SEO_AI_BRIDGE_BASENAME, 'update plugin basename matches');

// --- Force GitHub check synchronizes WP update_plugins cache ---
$GLOBALS['omiWpUpdatePluginsCalls'] = 0;
$GLOBALS['omiWpCleanPluginsCacheCalls'] = 0;
$GLOBALS['omiSiteTransients']['update_plugins'] = (object) ['stale_marker' => true, 'checked' => []];
$forceClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('1.0.85', 'wp-seo-ai-1.0.85.zip')),
);
$forceService = new Bridge_Update_Service($forceClient);
$forceCheck = $forceService->check(true);
omi_assert(($forceCheck['ok'] ?? false) === true, 'force check ok for 1.0.85');
omi_assert(($forceCheck['update_available'] ?? false) === true, 'force check sees update vs 1.0.84');
omi_assert(($forceCheck['wordpress_update_cache_refreshed'] ?? false) === true, 'force check refreshed WP update cache');
omi_assert(($forceCheck['plugin_basename'] ?? '') === OMI_SEO_AI_BRIDGE_BASENAME, 'force check reports plugin basename');
omi_assert($GLOBALS['omiWpCleanPluginsCacheCalls'] >= 1, 'wp_clean_plugins_cache called');
omi_assert($GLOBALS['omiWpUpdatePluginsCalls'] >= 1, 'wp_update_plugins called');
$rebuilt = $GLOBALS['omiSiteTransients']['update_plugins'] ?? null;
omi_assert(is_object($rebuilt), 'update_plugins transient rebuilt');
omi_assert(! isset($rebuilt->stale_marker), 'stale update_plugins marker cleared');
omi_assert(
    isset($rebuilt->response[OMI_SEO_AI_BRIDGE_BASENAME]),
    'rebuilt transient contains update for installed basename'
);
omi_assert(
    (string) ($rebuilt->response[OMI_SEO_AI_BRIDGE_BASENAME]->new_version ?? '') === '1.0.85',
    'rebuilt transient new_version 1.0.85'
);

$GLOBALS['omiInstalledVersionOverride'] = null;

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK\n";
