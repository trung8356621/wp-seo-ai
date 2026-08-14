<?php

declare(strict_types=1);

/**
 * Standalone tests for Cache_Purger. No WordPress / PHPUnit required.
 *
 * php tests/CachePurgerTest.php
 */

define('ABSPATH', __DIR__ . '/');

$omiActions = [];
$omiCleanedPosts = [];
$omiCleanedTerms = [];

function do_action(string $hook, mixed ...$args): void
{
    $GLOBALS['omiActions'][] = ['hook' => $hook, 'args' => $args];
}

function has_action(string $hook): bool
{
    unset($hook);

    return false;
}

function get_permalink(int $postId): string
{
    return 'https://example.test/p/' . $postId;
}

function clean_post_cache(int $postId): void
{
    $GLOBALS['omiCleanedPosts'][] = $postId;
}

function clean_term_cache(int $termId, string $taxonomy = ''): void
{
    $GLOBALS['omiCleanedTerms'][] = ['term_id' => $termId, 'taxonomy' => $taxonomy];
}

function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
}

require_once dirname(__DIR__) . '/includes/class-cache-purger.php';

use OmiSeoAiBridge\Cache_Purger;

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

function omi_reset_world(): void
{
    $GLOBALS['omiActions'] = [];
    $GLOBALS['omiCleanedPosts'] = [];
    $GLOBALS['omiCleanedTerms'] = [];
    Cache_Purger::reset();
}

$adapterCalls = [];

$countingAdapters = static function () use (&$adapterCalls): array {
    return [
        [
            'id'         => 'fake_page_cache',
            'purge_post' => static function (int $postId, string $url, array $extraUrls) use (&$adapterCalls): bool {
                $adapterCalls[] = [
                    'method'     => 'purge_post',
                    'post_id'    => $postId,
                    'url'        => $url,
                    'extra_urls' => $extraUrls,
                ];

                return true;
            },
            'purge_url'  => static function (string $url) use (&$adapterCalls): bool {
                $adapterCalls[] = ['method' => 'purge_url', 'url' => $url];

                return true;
            },
            'purge_all'  => static function () use (&$adapterCalls): bool {
                $adapterCalls[] = ['method' => 'purge_all'];

                return true;
            },
        ],
    ];
};

// Case A — Laravel update post success → CachePurger called once
omi_reset_world();
$adapterCalls = [];
Cache_Purger::use_adapters($countingAdapters());
$resultA = Cache_Purger::after_rest_success(200, ['success' => true, 'wp_post_id' => 123], 123, [
    'source' => 'editor-sync',
]);
omi_assert($resultA['purged'] === true, 'Case A: sync success marks cache purged');
omi_assert($adapterCalls === [[
    'method'     => 'purge_post',
    'post_id'    => 123,
    'url'        => 'https://example.test/p/123',
    'extra_urls' => [],
]], 'Case A: CachePurger invoked adapters exactly once');
$hooksA = array_column($GLOBALS['omiActions'], 'hook');
omi_assert(in_array('omi_seo_ai_after_sync', $hooksA, true), 'Case A: omi_seo_ai_after_sync fired');
omi_assert(in_array('omi_seo_ai_cache_purged', $hooksA, true), 'Case A: omi_seo_ai_cache_purged fired');

// Case B — sync fail → cache NOT purged
omi_reset_world();
$adapterCalls = [];
Cache_Purger::use_adapters($countingAdapters());
$resultB1 = Cache_Purger::after_rest_success(422, ['success' => false, 'message' => 'Post not found.'], 123);
$resultB2 = Cache_Purger::after_rest_success(200, ['success' => false], 123);
$resultB3 = Cache_Purger::after_rest_success(404, ['success' => true], 123);
omi_assert($resultB1['purged'] === false && $resultB2['purged'] === false && $resultB3['purged'] === false, 'Case B: failed REST writes do not purge');
omi_assert($adapterCalls === [], 'Case B: adapters never called on sync failure');
omi_assert($GLOBALS['omiActions'] === [], 'Case B: after_sync hook not fired on failure');

// Case C — post + meta + gallery + SEO in one request → purge exactly once
omi_reset_world();
$adapterCalls = [];
Cache_Purger::use_adapters($countingAdapters());
Cache_Purger::purge_post(55, ['source' => 'virtual-comments-mid-request']);
$resultC = Cache_Purger::after_rest_success(200, [
    'success'     => true,
    'seo_applied' => true,
    'faq_count'   => 2,
], 55, ['source' => 'editor-sync']);
omi_assert($resultC['purged'] === true, 'Case C: later success still reports purged');
omi_assert(count($adapterCalls) === 1, 'Case C: adapters ran exactly once for post+meta+gallery+SEO');
omi_assert($adapterCalls[0]['post_id'] === 55, 'Case C: single purge targeted the synced post');

// Case D — no cache plugin installed → no fatal, sync still success
omi_reset_world();
$resultD = Cache_Purger::after_rest_success(200, ['success' => true], 10, ['source' => 'editor-sync']);
omi_assert($resultD['purged'] === true, 'Case D: purge completes without cache plugins');
omi_assert($resultD['providers'] === ['wordpress'], 'Case D: only native WordPress invalidation ran');
omi_assert($GLOBALS['omiCleanedPosts'] === [10], 'Case D: clean_post_cache called');

// Case E — one provider throws, remaining continue, sync still success
omi_reset_world();
$adapterCalls = [];
Cache_Purger::use_adapters([
    [
        'id'         => 'boom',
        'purge_post' => static function (): bool {
            throw new RuntimeException('provider exploded');
        },
    ],
    [
        'id'         => 'ok',
        'purge_post' => static function () use (&$adapterCalls): bool {
            $adapterCalls[] = 'ok';

            return true;
        },
    ],
]);
$resultE = Cache_Purger::after_rest_success(200, ['success' => true], 77);
omi_assert($resultE['purged'] === true, 'Case E: overall purge still succeeds after provider exception');
omi_assert($resultE['providers'] === ['ok'], 'Case E: remaining providers continue');
omi_assert($adapterCalls === ['ok'], 'Case E: healthy provider still ran');

// Extra: failed provider must not break a later successful REST response contract
omi_reset_world();
Cache_Purger::use_adapters([
    [
        'id'         => 'boom',
        'purge_post' => static function (): bool {
            throw new RuntimeException('provider exploded');
        },
    ],
]);
$syncStillOk = ['success' => true, 'wp_post_id' => 9];
Cache_Purger::after_rest_success(200, $syncStillOk, 9);
omi_assert($syncStillOk['success'] === true, 'Case E: Laravel success payload is unchanged');

// Global purge is skipped on failed rename-style writes
omi_reset_world();
$adapterCalls = [];
Cache_Purger::use_adapters($countingAdapters());
$resultAllFail = Cache_Purger::after_rest_success_all(422, ['success' => false]);
$resultAllOk = Cache_Purger::after_rest_success_all(200, ['success' => true, 'posts_updated' => 3]);
omi_assert($resultAllFail['purged'] === false, 'Global purge skipped when write failed');
omi_assert($resultAllOk['providers'] === ['fake_page_cache'], 'Global purge runs once after successful write');

// Architecture: REST mutation success path + no mid-request virtual-comment purge
$restController = file_get_contents(dirname(__DIR__) . '/includes/class-rest-controller.php');
$virtualComments = file_get_contents(dirname(__DIR__) . '/includes/class-virtual-comments.php');
$bootstrap = file_get_contents(dirname(__DIR__) . '/omi-seo-ai-bridge.php');
omi_assert(is_string($restController) && str_contains($restController, 'Cache_Purger::after_rest_success'), 'REST controller hooks Cache_Purger on successful writes');
omi_assert(is_string($restController) && str_contains($restController, "'source' => 'editor-sync'"), 'editor-sync success path triggers cache purge');
omi_assert(is_string($restController) && str_contains($restController, "'source' => 'post-media'"), 'post media success path triggers cache purge');
omi_assert(is_string($virtualComments) && ! str_contains($virtualComments, 'purge_page_caches_for_post'), 'virtual comments no longer purge cache mid-save');
omi_assert(is_string($bootstrap) && str_contains($bootstrap, 'class-cache-purger.php'), 'plugin bootstrap loads Cache_Purger');

echo "\n";
if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo "All Cache_Purger tests passed.\n";
exit(0);
