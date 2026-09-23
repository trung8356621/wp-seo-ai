<?php

declare(strict_types=1);

/**
 * Standalone Site Sync V3 multilingual / language-scope checks (no WordPress bootstrap).
 *
 * php tests/SiteSyncV3MultilingualContractTest.php
 */

$failures = 0;

function omi_v3_ml_assert(bool $ok, string $message): void
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
$provider = (string) file_get_contents($root.'/includes/class-site-sync-v3-provider.php');
$polylang = (string) file_get_contents($root.'/includes/class-polylang-sync.php');
$changeLog = (string) file_get_contents($root.'/includes/class-site-sync-change-log.php');
$rest = (string) file_get_contents($root.'/includes/class-rest-controller.php');

omi_v3_ml_assert(
    str_contains($provider, 'Polylang_Sync::multilingual_field_for_post'),
    'content upsert emits multilingual via Polylang_Sync'
);
omi_v3_ml_assert(
    str_contains($provider, "'current_lang'") === false
    || str_contains($provider, 'multilingual_field_for_post'),
    'does not invent current_lang without Polylang helper'
);
omi_v3_ml_assert(str_contains($provider, 'by_language'), 'discover includes by_language');
omi_v3_ml_assert(str_contains($provider, 'language_scope'), 'provider tracks language_scope');
omi_v3_ml_assert(
    str_contains($polylang, 'sql_posts_language_exists_fragment')
    && str_contains($polylang, 'count_content_by_language'),
    'Polylang_Sync exposes SQL language helpers'
);
omi_v3_ml_assert(
    str_contains($changeLog, 'multilingual_metadata_for_post')
    && str_contains($changeLog, 'row_matches_language'),
    'tombstones store + filter language'
);
omi_v3_ml_assert(
    str_contains($rest, "get_param('language')")
    || str_contains($rest, "'language' =>"),
    'REST forwards language to discover/records'
);

echo $failures === 0 ? "\nAll multilingual V3 checks passed.\n" : "\n{$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
