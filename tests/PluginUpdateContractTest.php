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
define('OMI_SEO_AI_BRIDGE_BASENAME', 'omi-seo-ai-bridge/omi-seo-ai-bridge.php');

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

    return ['Version' => (string) OMI_SEO_AI_BRIDGE_VERSION];
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

use OmiSeoAiBridge\Bridge_Update_Service;
use OmiSeoAiBridge\GitHub_Release_Client;
use OmiSeoAiBridge\Operation_Store;

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
    omi_http_ok(omi_release_payload('v1.0.75', 'omi-seo-ai-bridge-1.0.75.zip')),
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

$sameClient = new GitHub_Release_Client(
    omi_http_ok(omi_release_payload('v1.0.74', 'omi-seo-ai-bridge-1.0.74.zip')),
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
    omi_http_ok(omi_release_payload('v1.0.75', 'omi-seo-ai-bridge-1.0.75.zip')),
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
omi_assert(str_contains($updaterSrc, 'Bridge_Update_Service'), 'updater uses GitHub Bridge_Update_Service');

$bootstrapSrc = (string) file_get_contents(dirname(__DIR__).'/omi-seo-ai-bridge.php');
omi_assert(! str_contains($bootstrapSrc, '/api/seo/plugin/update-check'), 'settings check does not hit Laravel update-check');
omi_assert(str_contains($bootstrapSrc, 'Bridge_Update_Service'), 'settings check uses Bridge_Update_Service');
omi_assert(str_contains($bootstrapSrc, 'omi_seo_check_github_update'), 'settings has Check GitHub action');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK\n";
