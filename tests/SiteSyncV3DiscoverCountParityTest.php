<?php

declare(strict_types=1);

/**
 * Discover content counts must use the same universe as FULL records enumeration.
 *
 * Invariant (same inventory predicate):
 *   resources.content.total
 *     == sum(by_native_post_type)
 *     == enumerated content record count
 *   and grouped native counts == enumeration GROUP BY post_type.
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

// Pure invariant simulation (same loop as count_content_inventory).
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
