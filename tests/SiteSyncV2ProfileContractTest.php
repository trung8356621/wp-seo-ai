<?php

declare(strict_types=1);

/**
 * Standalone Site Sync V2 profile contract checks (no WordPress bootstrap).
 *
 * php tests/SiteSyncV2ProfileContractTest.php
 */

$failures = 0;

function omi_v2_assert(bool $ok, string $message): void
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
$providerPath = $root.'/includes/class-site-sync-v2-provider.php';
$restPath = $root.'/includes/class-rest-controller.php';

omi_v2_assert(is_file($providerPath), 'Site_Sync_V2_Provider file exists');
omi_v2_assert(is_file($restPath), 'Rest_Controller file exists');

$providerSrc = (string) file_get_contents($providerPath);
$restSrc = (string) file_get_contents($restPath);

omi_v2_assert(
    str_contains($providerSrc, "'site_name' => (string) get_bloginfo('name')"),
    'profile exposes site_name from get_bloginfo(name)'
);
omi_v2_assert(
    str_contains($providerSrc, "'short_description' => (string) get_bloginfo('description')"),
    'profile exposes short_description from get_bloginfo(description)'
);
omi_v2_assert(
    str_contains($providerSrc, 'schema_org_suggest'),
    'schema_org suggest remains for backward compatibility'
);
omi_v2_assert(
    str_contains($providerSrc, "'schema_org' => \$this->schema_org_suggest()"),
    'profile still returns schema_org block'
);
omi_v2_assert(
    str_contains($restSrc, 'handle_sync_v2_profile'),
    'REST handler for v2 profile exists'
);
omi_v2_assert(
    str_contains($restSrc, '/sync/v2/profile'),
    'REST route /sync/v2/profile registered'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failure(s)\n");
    exit(1);
}

echo "\nAll Site Sync V2 profile contract checks passed.\n";
