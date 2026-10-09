<?php

declare(strict_types=1);

/**
 * php tests/CtaStylePresetContractTest.php
 */

define('ABSPATH', __DIR__.'/');

require dirname(__DIR__).'/includes/class-cta-style-preset.php';

use OmiSeoAiBridge\Cta_Style_Preset;

$failed = 0;

function cta_assert(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS {$message}\n";

        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

$soft = Cta_Style_Preset::preset('soft');
cta_assert(is_array($soft) && Cta_Style_Preset::validate($soft) === [], 'soft preset validates');
cta_assert(Cta_Style_Preset::validate(['layout' => 'grid', 'theme_color' => 'primary', 'background' => 'surface', 'text' => 'text', 'border' => 'none', 'spacing' => 16, 'radius' => 4, 'icon' => 'none']) !== [], 'unknown layout rejected');
cta_assert(Cta_Style_Preset::validate([
    'layout' => 'stack',
    'theme_color' => 'primary',
    'background' => 'javascript:alert(1)',
    'text' => 'text',
    'border' => 'none',
    'spacing' => 16,
    'radius' => 4,
    'icon' => 'none',
]) !== [], 'script-like value rejected');

$html = Cta_Style_Preset::render(
    'Xem thêm tại [website] hoặc [facebook].',
    'conversion',
    ['website' => 'https://shop.test']
);
cta_assert(str_contains($html, 'href="https://shop.test"'), 'configured website resolves');
cta_assert(! str_contains($html, '[facebook]'), 'missing alias is not printed raw');
cta_assert(! str_contains($html, 'facebook.com'), 'missing destination is not invented');
cta_assert(str_contains($html, 'data-cta-style="conversion"'), 'style preset is applied');
cta_assert(str_contains($html, '--primary-color'), 'Flatsome and theme palette fallback is present');
cta_assert(! str_contains(strtolower($html), 'javascript:'), 'javascript destinations stay out');

$unsafe = Cta_Style_Preset::render('Go [website].', 'soft', ['website' => 'javascript:alert(1)']);
cta_assert(! str_contains($unsafe, 'javascript:'), 'unsafe url dropped');

$nested = Cta_Style_Preset::render('Before [seo_ops_cta style="conversion"]nope[/seo_ops_cta] after [website].', 'soft', ['website' => 'https://shop.test']);
cta_assert(! str_contains($nested, '[seo_ops_cta'), 'nested shortcode markup is removed');
cta_assert(substr_count($nested, '<aside') === 1, 'nested shortcode does not create a second CTA');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}

echo "OK\n";
exit(0);
