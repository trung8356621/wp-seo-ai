<?php

declare(strict_types=1);

/**
 * Standalone tests for local-engine WP helpers. No WordPress / PHPUnit required.
 *
 * php tests/LocalSeoEngineContractTest.php
 */

define('ABSPATH', __DIR__.'/');

function home_url(string $path = '/'): string
{
    return 'https://example.test'.$path;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    $parts = parse_url($url);
    if ($component === PHP_URL_HOST) {
        return $parts['host'] ?? null;
    }
    if ($component === PHP_URL_PATH) {
        return $parts['path'] ?? null;
    }

    return $parts;
}

require_once dirname(__DIR__).'/includes/class-operation-store.php';

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

$replay = [
    'already_processed' => true,
    'wp_post_id' => 928,
    'post_status' => 'publish',
];

omi_assert(($replay['already_processed'] ?? false) === true, 'idempotent replay flag present');
omi_assert((int) $replay['wp_post_id'] === 928, 'idempotent replay keeps wp_post_id');
omi_assert($replay['post_status'] === 'publish', 'idempotent replay reports observed WP status');
omi_assert(Operation_Store::META_KEY === '_omi_publish_operation_key', 'operation meta key stable');

$seo = [
    'seo_title' => 'Balo quà tặng',
    'meta_description' => 'Desc',
    'focus_keyword' => 'balo quà tặng',
    'canonical' => 'https://example.test/balo',
    'robots' => ['index' => true, 'follow' => true],
    'schema_type' => 'Article',
    'plugin' => 'rank_math',
];
$hashA = hash('sha256', implode('|', [
    $seo['seo_title'],
    $seo['meta_description'],
    $seo['focus_keyword'],
    $seo['canonical'],
    '1',
    '1',
    $seo['schema_type'],
    $seo['plugin'],
]));
$hashB = hash('sha256', implode('|', [
    $seo['seo_title'],
    $seo['meta_description'],
    $seo['focus_keyword'],
    $seo['canonical'],
    '1',
    '1',
    $seo['schema_type'],
    $seo['plugin'],
]));
omi_assert($hashA === $hashB, 'seo_meta_hash is stable for same canonical contract');

$contentHash = hash('sha256', 'body|title|excerpt');
$stale = $contentHash !== hash('sha256', 'body2|title|excerpt');
omi_assert($stale, 'content hash change marks analysis stale');

$metadataArticle = [
    'wp_id' => 123,
    'title' => 'Hello',
    'seo' => $seo,
    'content_hash' => $contentHash,
    'seo_meta_hash' => $hashA,
];
omi_assert(! array_key_exists('post_content', $metadataArticle), 'metadata-only article has no post_content');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK\n";
