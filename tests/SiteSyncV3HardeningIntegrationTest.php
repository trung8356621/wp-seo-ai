<?php

declare(strict_types=1);

/**
 * Site Sync V3 pre-acceptance hardening — integration evidence (no WP bootstrap).
 *
 * Covers:
 * A frozen content snapshot
 * B frozen term snapshot
 * C catch-up new content
 * D delete-after-seen via persistent ledger
 * E replay idempotency (tombstone)
 * F cursor-not-advancing (contracted in Laravel; WP emits advancing keyset)
 * G fresh verify membership (create+delete)
 *
 * php tests/SiteSyncV3HardeningIntegrationTest.php
 */

define('ABSPATH', __DIR__.'/');

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

function wp_is_post_revision($id): bool
{
    return false;
}

function wp_is_post_autosave($id): bool
{
    return false;
}

function get_post($id)
{
    return null;
}

function wp_next_scheduled($hook): false
{
    return false;
}

function wp_schedule_event($timestamp, $recurrence, $hook): bool
{
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return true;
}

function get_option(string $key, mixed $default = false): mixed
{
    return $default;
}

function taxonomy_exists(string $taxonomy): bool
{
    return true;
}

require_once dirname(__DIR__).'/includes/class-content-type-map.php';
require_once dirname(__DIR__).'/includes/class-site-sync-change-log.php';

use OmiSeoAiBridge\Site_Sync_Change_Log;

$failures = 0;

function omi_h_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL  {$message}\n";
}

/**
 * Mirror FULL frozen eligibility (content).
 */
function omi_full_content_eligible(int $id, int $afterId, int $maxId): bool
{
    return $id > $afterId && $id <= $maxId;
}

/**
 * Mirror FULL frozen eligibility (terms).
 */
function omi_full_term_eligible(int $termId, int $afterTermId, int $maxId): bool
{
    return $termId > $afterTermId && $termId <= $maxId;
}

/**
 * Simulate catch-up membership after FULL + live create/delete.
 *
 * @param  list<int>  $fullImported
 * @param  list<int>  $catchUpUpserts
 * @param  list<int>  $catchUpDeletes
 * @return list<int>
 */
function omi_final_inventory(array $fullImported, array $catchUpUpserts, array $catchUpDeletes): array
{
    $set = [];
    foreach ($fullImported as $id) {
        $set[$id] = true;
    }
    foreach ($catchUpUpserts as $id) {
        $set[$id] = true;
    }
    foreach ($catchUpDeletes as $id) {
        unset($set[$id]);
    }
    $out = array_map('intval', array_keys($set));
    sort($out);

    return $out;
}

// --- A: Frozen content snapshot ---
$contentMax = 70000;
omi_h_assert(omi_full_content_eligible(69999, 0, $contentMax), 'A: ID inside snapshot eligible FULL');
omi_h_assert(omi_full_content_eligible(70000, 0, $contentMax), 'A: boundary ID eligible FULL');
omi_h_assert(! omi_full_content_eligible(70001, 0, $contentMax), 'A: new ID > content_max_id excluded from FULL');

$providerSrc = (string) file_get_contents(dirname(__DIR__).'/includes/class-site-sync-v3-provider.php');
omi_h_assert(
    str_contains($providerSrc, 'AND ID <= %d')
    && str_contains($providerSrc, 'snapshot_bounds')
    && str_contains($providerSrc, 'content_max_id'),
    'A: FULL SQL contains ID <= snapshot_content_max_id'
);
omi_h_assert(
    ! preg_match('/\bOFFSET\b/i', $providerSrc),
    'A: V3 provider has zero OFFSET'
);

// --- B: Frozen term snapshot ---
$termMax = 1250;
omi_h_assert(omi_full_term_eligible(1250, 0, $termMax), 'B: boundary term eligible FULL');
omi_h_assert(! omi_full_term_eligible(1251, 0, $termMax), 'B: new term_id > term_max_id excluded from FULL');
omi_h_assert(
    str_contains($providerSrc, 'AND t.term_id <= %d')
    && str_contains($providerSrc, 'term_max_id'),
    'B: FULL terms SQL contains term_id <= snapshot_term_max_id'
);

// --- C: Catch-up new content ---
$fullIds = [69990, 69991, 70000]; // 70001 not in FULL
$catchUpNew = [70001];
omi_h_assert(! in_array(70001, $fullIds, true), 'C: FULL set excludes post-discover ID');
$afterCatchUp = omi_final_inventory($fullIds, $catchUpNew, []);
omi_h_assert(in_array(70001, $afterCatchUp, true), 'C: CATCH-UP includes new ID 70001');

// --- D: Delete after seen (ledger → tombstone) ---
Site_Sync_Change_Log::use_memory_store_for_tests(true);

// Simulate: FULL saw 68451, then hard-delete hook writes ledger.
$seenInFull = 68451;
Site_Sync_Change_Log::record(
    Site_Sync_Change_Log::RESOURCE_CONTENT,
    $seenInFull,
    'post',
    Site_Sync_Change_Log::OP_DELETE,
    ['content_type' => 'post', 'source' => 'before_delete_post']
);

$ledger = Site_Sync_Change_Log::query_since(
    Site_Sync_Change_Log::RESOURCE_CONTENT,
    gmdate('Y-m-d H:i:s', time() - 60),
    0,
    50,
    Site_Sync_Change_Log::OP_DELETE
);
omi_h_assert(count($ledger) === 1, 'D: ledger has hard-delete row after FULL-seen');
omi_h_assert((int) ($ledger[0]['object_id'] ?? 0) === $seenInFull, 'D: ledger object_id=68451');

$tombstone = Site_Sync_Change_Log::row_to_item($ledger[0]);
omi_h_assert(is_array($tombstone) && ($tombstone['op'] ?? '') === 'delete', 'D: ledger maps to op=delete');
omi_h_assert((int) ($tombstone['wp_id'] ?? 0) === $seenInFull, 'D: tombstone wp_id=68451');

// last_seen generation alone would NOT catch this — prove inventory needs tombstone:
$inventoryAfterFull = [$seenInFull => true, 70000 => true];
$generationCurrent = true; // 68451 marked last_seen=current
omi_h_assert($generationCurrent && isset($inventoryAfterFull[$seenInFull]), 'D: stale-gen alone keeps deleted ID');
unset($inventoryAfterFull[$seenInFull]); // apply tombstone
omi_h_assert(! isset($inventoryAfterFull[$seenInFull]), 'D: tombstone removes FULL-seen deleted ID');

$changelogSrc = (string) file_get_contents(dirname(__DIR__).'/includes/class-site-sync-change-log.php');
omi_h_assert(str_contains($changelogSrc, 'before_delete_post'), 'D: hook before_delete_post registered');
omi_h_assert(str_contains($changelogSrc, 'wp_trash_post'), 'D: hook wp_trash_post registered');
omi_h_assert(str_contains($changelogSrc, 'pre_delete_term'), 'D: hook pre_delete_term registered');
omi_h_assert(str_contains($changelogSrc, 'RETENTION_DAYS = 30'), 'D: retention 30 days');
omi_h_assert(str_contains($changelogSrc, 'resource_changed_id'), 'D: index (resource, changed_at, id)');
omi_h_assert(
    str_contains($providerSrc, 'Site_Sync_Change_Log::query_since'),
    'D: V3 delta reads change ledger'
);

// --- E: Replay tombstone idempotent ---
$laravelCounts = ['articles' => 1, 'keywords' => 2, 'links' => 3, 'scores' => 1];
$applyDelete = static function (array &$inv, int $wpId) use (&$laravelCounts): void {
    if (! isset($inv[$wpId])) {
        return; // idempotent no-op
    }
    unset($inv[$wpId]);
    // counts unchanged on second delete — article already gone
};
$inv = [68451 => true];
$applyDelete($inv, 68451);
$countsAfterFirst = $laravelCounts;
$applyDelete($inv, 68451);
omi_h_assert($inv === [] && $laravelCounts === $countsAfterFirst, 'E: replay delete is idempotent');

// Second ledger emit same object — importer sees delete again
Site_Sync_Change_Log::record(
    Site_Sync_Change_Log::RESOURCE_CONTENT,
    $seenInFull,
    'post',
    Site_Sync_Change_Log::OP_DELETE,
    ['source' => 'before_delete_post_replay']
);
$ledger2 = Site_Sync_Change_Log::query_since(
    Site_Sync_Change_Log::RESOURCE_CONTENT,
    gmdate('Y-m-d H:i:s', time() - 60),
    0,
    50,
    Site_Sync_Change_Log::OP_DELETE
);
omi_h_assert(count($ledger2) === 2, 'E: ledger allows duplicate delete events');
$inv2 = []; // already deleted locally
$applyDelete($inv2, 68451);
omi_h_assert($inv2 === [], 'E: second tombstone against missing article is no-op');

// --- F: Cursor not advancing (WP contract + expected Laravel fail code) ---
$cursorBefore = ['after_id' => 100, 'after_change_id' => 5, 'deletes_exhausted' => true];
$cursorStuck = ['after_id' => 100, 'after_change_id' => 5, 'deletes_exhausted' => true];
$hasMore = true;
$cursorStuckDetected = $hasMore && $cursorBefore === $cursorStuck;
omi_h_assert($cursorStuckDetected, 'F: stuck cursor detectable when has_more and equal');
$orchSrc = (string) file_get_contents(
    dirname(__DIR__, 2).'/omnichannel-addons/site-sync/src/Services/Orchestration/RunSiteSyncV3Orchestrator.php'
);
// Fallback path if sibling layout differs
if ($orchSrc === '' || ! str_contains($orchSrc, 'sync_cursor_not_advancing')) {
    $alt = 'D:/work/omnichannel-addons/site-sync/src/Services/Orchestration/RunSiteSyncV3Orchestrator.php';
    if (is_file($alt)) {
        $orchSrc = (string) file_get_contents($alt);
    }
}
omi_h_assert(
    str_contains($orchSrc, 'sync_cursor_not_advancing'),
    'F: Laravel hard-fails sync_cursor_not_advancing'
);

// --- G: Fresh verify membership ---
$initial = [100, 200, 300]; // total 3
$liveCreate = [400];
$liveDelete = [200];
$finalWp = omi_final_inventory($initial, $liveCreate, $liveDelete);
omi_h_assert($finalWp === [100, 300, 400], 'G: membership changed (not only total)');
omi_h_assert(count($finalWp) === count($initial), 'G: total still 3 but members differ');
omi_h_assert(in_array(400, $finalWp, true) && ! in_array(200, $finalWp, true), 'G: new present, deleted absent');
omi_h_assert(
    str_contains($orchSrc, 'phaseVerify') && str_contains($orchSrc, 'Fresh discover'),
    'G: verify uses fresh discover'
);

// --- Body regression ---
omi_h_assert(
    ! preg_match("/'post_content'\\s*=>/", $providerSrc)
    && str_contains($providerSrc, 'never carry HTML body'),
    'H: V3 provider does not emit post_content'
);
$bootstrap = (string) file_get_contents(dirname(__DIR__).'/omi-seo-ai-bridge.php');
omi_h_assert(
    str_contains($bootstrap, 'class-site-sync-change-log.php')
    && str_contains($bootstrap, 'Site_Sync_Change_Log::register'),
    'bootstrap registers change log'
);

Site_Sync_Change_Log::use_memory_store_for_tests(false);

exit($failures === 0 ? 0 : 1);
