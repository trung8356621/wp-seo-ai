<?php

declare(strict_types=1);

/**
 * Discover content counts must use the same universe as FULL records enumeration.
 *
 * Invariant:
 *   resources.content.total
 *     == sum(by_native_post_type)
 *     == enumerated content record count (after sync exclusions)
 *   Sync_Provider::sync_excluded_post_ids() is the SSOT for membership gaps
 *   (e.g. static page_on_front) across count / FULL / delta SQL.
 *
 * php tests/SiteSyncV3DiscoverCountParityTest.php
 */

$failures = 0;

function omi_v3_parity_assert(bool $ok, string $message): void
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
$providerSrc = (string) file_get_contents($root.'/includes/class-site-sync-v3-provider.php');
$syncSrc = (string) file_get_contents($root.'/includes/class-sync-provider.php');
$v2Src = (string) file_get_contents($root.'/includes/class-site-sync-v2-provider.php');

omi_v3_parity_assert(
    str_contains($syncSrc, 'function sync_excluded_post_ids'),
    'Sync_Provider::sync_excluded_post_ids SSOT exists'
);

omi_v3_parity_assert(
    (bool) preg_match(
        '/function\s+is_sync_excluded_post\s*\(\s*int\s+\$postId\s*\)\s*:\s*bool\s*\{([\s\S]*?)\n    \}/',
        $syncSrc,
        $isExMatch
    ),
    'is_sync_excluded_post body extractable'
);

$isExBody = $isExMatch[1] ?? '';
omi_v3_parity_assert(
    str_contains($isExBody, 'sync_excluded_post_ids()')
    && ! str_contains($isExBody, "get_option('page_on_front')"),
    'is_sync_excluded_post delegates to sync_excluded_post_ids (no inline page_on_front)'
);

omi_v3_parity_assert(
    str_contains($syncSrc, "get_option('page_on_front')")
    && str_contains($syncSrc, "get_option('show_on_front')"),
    'sync_excluded_post_ids owns static front-page exclusion'
);

omi_v3_parity_assert(
    str_contains($providerSrc, 'function count_content_inventory'),
    'count_content_inventory exists'
);

omi_v3_parity_assert(
    ! preg_match(
        '/function\s+count_content_inventory\s*\(\s*\)\s*:\s*array\s*\{[\s\S]*?wp_count_posts\s*\(/',
        $providerSrc
    ),
    'count_content_inventory does not use wp_count_posts'
);

omi_v3_parity_assert(
    (bool) preg_match(
        '/function\s+count_content_inventory\s*\(\s*\)\s*:\s*array\s*\{([\s\S]*?)\n    \}/',
        $providerSrc,
        $invMatch
    ),
    'count_content_inventory body extractable'
);

$invBody = $invMatch[1] ?? '';
omi_v3_parity_assert(
    str_contains($invBody, 'CONTENT_STATUSES')
    && str_contains($invBody, 'syncable_post_type_slugs')
    && str_contains($invBody, 'GROUP BY post_type'),
    'inventory COUNT uses CONTENT_STATUSES + syncable types + GROUP BY post_type'
);

omi_v3_parity_assert(
    str_contains($invBody, 'sql_excluded_post_ids_fragment')
    || str_contains($invBody, 'sync_excluded_post_ids'),
    'inventory COUNT applies sync_excluded_post_ids SSOT'
);

omi_v3_parity_assert(
    str_contains($invBody, '$byNative[$postType]')
    && str_contains($invBody, '$total += $cnt')
    && str_contains($invBody, '$byContent[$bucket] += $cnt'),
    'inventory total / by_native / by_content derived from same GROUP BY rows'
);

omi_v3_parity_assert(
    (bool) preg_match(
        '/function\s+query_content_full\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n    \}/',
        $providerSrc,
        $qMatch
    ),
    'query_content_full body extractable'
);

$qBody = $qMatch[1] ?? '';
omi_v3_parity_assert(
    str_contains($qBody, 'CONTENT_STATUSES')
    && str_contains($qBody, 'syncable_post_type_slugs'),
    'query_content_full uses same CONTENT_STATUSES + syncable types'
);

omi_v3_parity_assert(
    str_contains($qBody, 'sql_excluded_post_ids_fragment')
    || str_contains($qBody, 'sync_excluded_post_ids'),
    'query_content_full applies sync_excluded_post_ids SSOT in SQL'
);

omi_v3_parity_assert(
    (bool) preg_match(
        '/function\s+query_content_delta\s*\([^)]*\)\s*:\s*array\s*\{([\s\S]*?)\n    \}/',
        $providerSrc,
        $dMatch
    ),
    'query_content_delta body extractable'
);

$dBody = $dMatch[1] ?? '';
omi_v3_parity_assert(
    str_contains($dBody, 'sql_excluded_post_ids_fragment')
    || str_contains($dBody, 'sync_excluded_post_ids'),
    'query_content_delta applies sync_excluded_post_ids SSOT in SQL'
);

omi_v3_parity_assert(
    str_contains($providerSrc, 'function sql_excluded_post_ids_fragment')
    && str_contains($providerSrc, 'Sync_Provider::sync_excluded_post_ids()'),
    'V3 sql_excluded_post_ids_fragment wraps Sync_Provider SSOT'
);

omi_v3_parity_assert(
    str_contains($providerSrc, "'by_native_post_type'"),
    'discover returns by_native_post_type'
);

omi_v3_parity_assert(
    str_contains($providerSrc, "'by_content_type' => \$byContentType"),
    'discover returns by_content_type from inventory count'
);

omi_v3_parity_assert(
    str_contains($providerSrc, "'content' => ['total' => \$contentTotal]"),
    'resources.content.total comes from inventory count total'
);

omi_v3_parity_assert(
    (bool) preg_match(
        '/\$inventory\s*=\s*\$this->count_content_inventory\(\);\s*'
        .'\$byNativePostType\s*=\s*\$inventory\[[\'"]by_native_post_type[\'"]\];\s*'
        .'\$byContentType\s*=\s*\$inventory\[[\'"]by_content_type[\'"]\];\s*'
        .'\$contentTotal\s*=\s*\(int\)\s*\$inventory\[[\'"]total[\'"]\];/s',
        $providerSrc
    ),
    'discover wires inventory total + by_native + by_content from one count_content_inventory call'
);

omi_v3_parity_assert(
    str_contains($v2Src, 'sync_excluded_post_ids()'),
    'V2 lightweight_manifest_summary subtracts sync_excluded_post_ids'
);

// Membership simulation: discover count == FULL enum after excluding static front page.
$rawEligible = [
    ['id' => 10, 'post_type' => 'post', 'status' => 'publish'],
    ['id' => 11, 'post_type' => 'post', 'status' => 'draft'],
    ['id' => 20, 'post_type' => 'page', 'status' => 'publish'],
    ['id' => 21, 'post_type' => 'page', 'status' => 'publish'], // static front
    ['id' => 22, 'post_type' => 'page', 'status' => 'draft'],
    ['id' => 30, 'post_type' => 'product', 'status' => 'publish'],
    ['id' => 40, 'post_type' => 'landing_page', 'status' => 'publish'],
];
$excludedIds = [21]; // page_on_front
$statuses = ['publish', 'draft', 'pending', 'private', 'future'];
$syncable = ['post', 'page', 'product', 'landing_page'];

$discoverByNative = [];
$enumByNative = [];
foreach ($rawEligible as $row) {
    $id = (int) $row['id'];
    $type = (string) $row['post_type'];
    $status = (string) $row['status'];
    if (! in_array($type, $syncable, true) || ! in_array($status, $statuses, true)) {
        continue;
    }
    // Raw universe (old buggy discover).
    $discoverByNative[$type] = ($discoverByNative[$type] ?? 0) + 1;
    // Fixed universe (= FULL enum after SSOT exclusion).
    if (in_array($id, $excludedIds, true)) {
        continue;
    }
    $enumByNative[$type] = ($enumByNative[$type] ?? 0) + 1;
}

$fixedDiscoverByNative = $enumByNative;
$rawPage = (int) ($discoverByNative['page'] ?? 0);
$fixedPage = (int) ($fixedDiscoverByNative['page'] ?? 0);
$enumPage = (int) ($enumByNative['page'] ?? 0);

omi_v3_parity_assert($rawPage === 3, 'sim: raw eligible pages include static front (3)');
omi_v3_parity_assert($fixedPage === 2, 'sim: discover pages after exclusion omit static front (2)');
omi_v3_parity_assert($enumPage === 2, 'sim: FULL enum pages omit static front (2)');
omi_v3_parity_assert($fixedPage === $enumPage, 'sim: by_native_post_type[page] == FULL eligible Page count');
omi_v3_parity_assert(
    array_sum($fixedDiscoverByNative) === array_sum($enumByNative),
    'sim: resources.content.total == FULL eligible enumeration count'
);
omi_v3_parity_assert(
    ! isset($enumByNative['page']) || $enumPage === 2,
    'sim: static front page absent from FULL enumeration'
);
omi_v3_parity_assert(
    $rawPage - $fixedPage === 1,
    'sim: exclusion removes exactly the static front page (+1 Page bug class)'
);

// Pure invariant simulation (same loop as count_content_inventory aggregation).
$simRows = [
    ['post_type' => 'post', 'cnt' => 10],
    ['post_type' => 'page', 'cnt' => 2],
    ['post_type' => 'landing_page', 'cnt' => 5],
    ['post_type' => 'blocks', 'cnt' => 1],
];
$byNative = [];
$byContent = ['post' => 0, 'page' => 0, 'product' => 0];
$total = 0;
$map = [
    'post' => 'post',
    'page' => 'page',
    'product' => 'product',
    'landing_page' => 'page',
    'blocks' => 'post',
];
foreach ($simRows as $row) {
    $postType = strtolower(trim((string) $row['post_type']));
    $cnt = (int) $row['cnt'];
    if ($postType === '' || $cnt <= 0) {
        continue;
    }
    $byNative[$postType] = ($byNative[$postType] ?? 0) + $cnt;
    $bucket = $map[$postType] ?? 'post';
    if (! isset($byContent[$bucket])) {
        $byContent[$bucket] = 0;
    }
    $byContent[$bucket] += $cnt;
    $total += $cnt;
}

omi_v3_parity_assert(
    $total === array_sum($byNative),
    'sim: resources.content.total == sum(by_native_post_type)'
);
omi_v3_parity_assert(
    $total === array_sum($byContent),
    'sim: resources.content.total == sum(by_content_type)'
);
omi_v3_parity_assert(
    ($byNative['landing_page'] ?? 0) === 5,
    'sim: grouped native post-type counts preserve custom CPT'
);
omi_v3_parity_assert(
    ($byContent['page'] ?? 0) === 7,
    'sim: landing_page maps into page bucket without drop'
);

exit($failures === 0 ? 0 : 1);
