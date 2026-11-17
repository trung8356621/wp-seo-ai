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
cta_assert(str_contains($html, '>Website</a>'), 'website anchor uses platform label');
cta_assert(str_contains($html, '[facebook]'), 'missing channel keeps original token');
cta_assert(! str_contains($html, 'facebook.com'), 'missing destination is not invented');
cta_assert(str_contains($html, 'data-cta-style="conversion"'), 'style preset is applied');
cta_assert(str_contains($html, '--primary-color'), 'Flatsome and theme palette fallback is present');
cta_assert(! str_contains(strtolower($html), 'javascript:'), 'javascript destinations stay out');

$ctaTokens = Cta_Style_Preset::render(
    'Chat [[cta:zalo]] hoặc [[cta:address]].',
    'soft',
    [
        'zalo' => 'https://zalo.me/123?ref=x',
        'address' => '12 Nguyen Hue',
    ]
);
cta_assert(str_contains($ctaTokens, '<a href="https://zalo.me/123?ref=x">Zalo</a>'), '[[cta:zalo]] becomes labeled anchor');
cta_assert(str_contains($ctaTokens, '12 Nguyen Hue'), 'address resolves as plain text');
cta_assert(! str_contains($ctaTokens, 'google.com/maps'), 'address does not become maps link');
cta_assert(! str_contains($ctaTokens, '<a href="12 Nguyen Hue">'), 'address is not hyperlinked');

$unsafe = Cta_Style_Preset::render('Go [website].', 'soft', ['website' => 'javascript:alert(1)']);
cta_assert(! str_contains($unsafe, 'href="javascript:'), 'unsafe url dropped from href');

$nested = Cta_Style_Preset::render('Before [seo_ops_cta style="conversion"]nope[/seo_ops_cta] after [website].', 'soft', ['website' => 'https://shop.test']);
cta_assert(! str_contains($nested, '[seo_ops_cta'), 'nested shortcode markup is removed');
cta_assert(substr_count($nested, '<aside') === 1, 'nested shortcode does not create a second CTA');

$contactHtml = Cta_Style_Preset::render(
    'Call [[cta:phone_1]] / [[cta:phone_2]], mail [[cta:email]] / [[cta:email_1]] / [[cta:email_2]], chat [[cta:zalo]] / [[cta:facebook]], visit [[cta:address]].',
    'soft',
    [
        'phone_1' => '0988693337',
        'phone_2' => '0901167668',
        'email' => 'info@example.test',
        'email_1' => 'info@example.test',
        'email_2' => 'sales@example.test',
        'zalo' => 'https://zalo.me/0901167668',
        'facebook' => 'https://www.facebook.com/page',
        'address' => '48/1 Duong So 3',
    ]
);
cta_assert(str_contains($contactHtml, '<a href="tel:0988693337">0988693337</a>'), 'phone_1 renders tel link');
cta_assert(str_contains($contactHtml, '<a href="tel:0901167668">0901167668</a>'), 'phone_2 renders tel link');
cta_assert(substr_count($contactHtml, '<a href="mailto:info@example.test">Email</a>') === 2, 'email and email_1 render mailto link');
cta_assert(str_contains($contactHtml, '<a href="mailto:sales@example.test">Email</a>'), 'email_2 renders mailto link');
cta_assert(str_contains($contactHtml, '<a href="https://zalo.me/0901167668">Zalo</a>'), 'zalo renders labeled link');
cta_assert(str_contains($contactHtml, '<a href="https://www.facebook.com/page">Facebook</a>'), 'facebook renders labeled link');
cta_assert(str_contains($contactHtml, '48/1 Duong So 3') && ! str_contains($contactHtml, 'href="48/1'), 'address remains plain text');

$unsafeContacts = Cta_Style_Preset::render(
    'Bad [[cta:phone_1]] [[cta:email_1]]',
    'soft',
    [
        'phone_1' => 'javascript:alert(1)',
        'email_1' => 'not-an-email',
    ]
);
cta_assert(! str_contains($unsafeContacts, '<a '), 'invalid phone and email do not render anchor');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}

echo "OK\n";
exit(0);
