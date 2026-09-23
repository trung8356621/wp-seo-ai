<?php

declare(strict_types=1);

/**
 * Discover content counts must use the same universe as FULL records enumeration.
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

exit($failures === 0 ? 0 : 1);
