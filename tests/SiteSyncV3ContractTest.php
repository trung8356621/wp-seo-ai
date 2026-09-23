<?php

declare(strict_types=1);

/**
 * Standalone Site Sync V3 contract checks (no WordPress bootstrap).
 *
 * php tests/SiteSyncV3ContractTest.php
 */

$failures = 0;

function omi_v3_assert(bool $ok, string $message): void
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
$providerPath = $root.'/includes/class-site-sync-v3-provider.php';
$restPath = $root.'/includes/class-rest-controller.php';
$manifestPath = $root.'/includes/class-capability-manifest.php';
$bootstrapPath = $root.'/omi-seo-ai-bridge.php';

omi_v3_assert(is_file($providerPath), 'Site_Sync_V3_Provider file exists');
omi_v3_assert(is_file($restPath), 'Rest_Controller file exists');

$providerSrc = (string) file_get_contents($providerPath);
$restSrc = (string) file_get_contents($restPath);
$manifestSrc = (string) file_get_contents($manifestPath);
$bootstrapSrc = (string) file_get_contents($bootstrapPath);

omi_v3_assert(
    str_contains($providerSrc, "public const SCHEMA = 'site_sync.v3'"),
    'SCHEMA site_sync.v3'
);
omi_v3_assert(
    str_contains($providerSrc, 'schema_version') && str_contains($providerSrc, 'SCHEMA_VERSION = 3'),
    'schema_version 3'
);
omi_v3_assert(
    str_contains($providerSrc, 'ID > %d') || str_contains($providerSrc, 'ID >'),
    'content keyset ID > after_id'
);
omi_v3_assert(
    str_contains($providerSrc, 'AND ID <= %d') && str_contains($providerSrc, 'content_max_id'),
    'content FULL frozen by content_max_id'
);
omi_v3_assert(
    str_contains($providerSrc, 'term_id > %d') || str_contains($providerSrc, 'term_id >'),
    'terms keyset term_id > after_term_id'
);
omi_v3_assert(
    str_contains($providerSrc, 'AND t.term_id <= %d') && str_contains($providerSrc, 'term_max_id'),
    'terms FULL frozen by term_max_id'
);
omi_v3_assert(
    str_contains($providerSrc, 'snapshot_bounds'),
    'discover/records expose snapshot_bounds'
);
omi_v3_assert(
    str_contains($providerSrc, 'Site_Sync_Change_Log'),
    'delta merges Site_Sync_Change_Log'
);
omi_v3_assert(
    ! preg_match('/\bOFFSET\s+\d/i', $providerSrc)
    && ! preg_match('/\bSQL_CALC_FOUND_ROWS\b/i', $providerSrc),
    'V3 provider has no SQL OFFSET pagination'
);
omi_v3_assert(
    ! preg_match("/['\"]offset['\"]\\s*=>/", $providerSrc),
    'V3 provider has no offset cursor arg'
);

if (preg_match(
    '/function\s+build_content_upsert\s*\([^)]*\)\s*(?::\s*[^{]+)?\{([\s\S]*?)\n    \}/',
    $providerSrc,
    $m
)) {
    $builderBody = $m[1];
    omi_v3_assert(! str_contains($builderBody, 'post_content'), 'build_content_upsert has no post_content');
    omi_v3_assert(! str_contains($builderBody, 'content.rendered'), 'build_content_upsert has no content.rendered');
    omi_v3_assert(str_contains($builderBody, "'op' => 'upsert'"), 'build_content_upsert emits upsert');
    omi_v3_assert(str_contains($builderBody, "'links'"), 'build_content_upsert includes links');
    omi_v3_assert(str_contains($builderBody, "'analysis'"), 'build_content_upsert includes analysis');
    omi_v3_assert(
        str_contains($builderBody, 'multilingual')
        && str_contains($builderBody, 'multilingual_field_for_post'),
        'build_content_upsert includes multilingual via Polylang helper'
    );
} else {
    omi_v3_assert(false, 'build_content_upsert method found');
}

omi_v3_assert(
    str_contains($providerSrc, 'by_language')
    && str_contains($providerSrc, "'multilingual'"),
    'discover exposes multilingual + by_language'
);
omi_v3_assert(
    str_contains($providerSrc, 'sql_posts_language_fragment')
    || str_contains($providerSrc, 'sql_posts_language_exists_fragment'),
    'V3 content queries support language scope'
);
omi_v3_assert(
    str_contains($restSrc, "'language'")
    && str_contains($restSrc, 'handle_sync_v3_discover'),
    'REST V3 discover/records forward language'
);

$changeLogPath = $root.'/includes/class-site-sync-change-log.php';
omi_v3_assert(is_file($changeLogPath), 'Site_Sync_Change_Log file exists');
$changeLogSrc = (string) file_get_contents($changeLogPath);
omi_v3_assert(
    str_contains($changeLogSrc, 'multilingual_metadata_for_post')
    && str_contains($changeLogSrc, 'row_matches_language'),
    'change-log tombstones preserve language identity'
);

omi_v3_assert(
    str_contains($providerSrc, 'after_id') && str_contains($providerSrc, 'after_term_id'),
    'cursor uses after_id / after_term_id'
);
omi_v3_assert(
    str_contains($providerSrc, 'analysis_cache_hit')
    && str_contains($providerSrc, 'analysis_cache_miss')
    && str_contains($providerSrc, 'analysis_ms'),
    'instrumentation meta keys present'
);
omi_v3_assert(
    str_contains($providerSrc, "op' => 'delete'") || str_contains($providerSrc, '"op" => "delete"'),
    'delta trash emits delete op'
);

omi_v3_assert(
    str_contains($restSrc, "/sync/v3/discover")
    && str_contains($restSrc, 'handle_sync_v3_discover'),
    'REST registers GET sync/v3/discover'
);
omi_v3_assert(
    str_contains($restSrc, "/sync/v3/records")
    && str_contains($restSrc, 'handle_sync_v3_records'),
    'REST registers POST sync/v3/records'
);

omi_v3_assert(
    str_contains($manifestSrc, "'site_sync_v3'")
    && str_contains($manifestSrc, 'site_sync.v3'),
    'Capability_Manifest includes site_sync_v3'
);

omi_v3_assert(
    str_contains($providerSrc, 'by_native_post_type'),
    'discover exposes by_native_post_type'
);
omi_v3_assert(
    str_contains($providerSrc, 'function count_content_inventory'),
    'count_content_inventory replaces wp_count_posts aggregate'
);
omi_v3_assert(
    str_contains($providerSrc, 'GROUP BY post_type'),
    'content inventory counts GROUP BY post_type'
);
omi_v3_assert(
    ! preg_match('/function\s+count_by_content_type[\s\S]*?wp_count_posts\s*\(/', $providerSrc),
    'count_by_content_type path does not call wp_count_posts'
);
// Predicate parity: inventory COUNT uses same status/type lists as query_content_full.
omi_v3_assert(
    str_contains($providerSrc, 'CONTENT_STATUSES')
    && substr_count($providerSrc, 'syncable_post_type_slugs') >= 2,
    'inventory count and records share syncable types + CONTENT_STATUSES'
);
omi_v3_assert(
    str_contains($providerSrc, 'sql_excluded_post_ids_fragment')
    && str_contains($providerSrc, 'Sync_Provider::sync_excluded_post_ids()'),
    'inventory/FULL/delta share sync_excluded_post_ids SSOT via SQL fragment'
);

omi_v3_assert(
    str_contains($bootstrapSrc, 'class-site-sync-v3-provider.php'),
    'bootstrap requires v3 provider after v2'
);

$v2Pos = strpos($bootstrapSrc, 'class-site-sync-v2-provider.php');
$v3Pos = strpos($bootstrapSrc, 'class-site-sync-v3-provider.php');
omi_v3_assert(
    $v2Pos !== false && $v3Pos !== false && $v3Pos > $v2Pos,
    'v3 require is after v2 require'
);

exit($failures === 0 ? 0 : 1);
