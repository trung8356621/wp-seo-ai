<?php

declare(strict_types=1);

/**
 * php tests/LocalSeoEngineContractTest.php
 */

require_once dirname(__DIR__).'/includes/class-local-seo-engine.php';

use OmiSeoAiBridge\Local_Seo_Engine;

$failures = 0;

function omi_local_seo_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "ok - {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL - {$message}\n";
}

omi_local_seo_assert(
    Local_Seo_Engine::phrase_in_text('sản xuất balo quà tặng doanh nghiệp số lượng lớn', 'balo quà tặng doanh nghiệp'),
    'vietnamese phrase match',
);
omi_local_seo_assert(
    ! Local_Seo_Engine::phrase_in_text('balohàng', 'balo'),
    'no substring inside token',
);

exit($failures === 0 ? 0 : 1);
