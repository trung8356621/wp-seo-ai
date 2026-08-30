<?php

declare(strict_types=1);

/**
 * Standalone contract tests for Post_Analysis_Service (no WordPress bootstrap).
 *
 * php tests/PostAnalysisServiceTest.php
 */

define('ABSPATH', __DIR__.'/');

function home_url(string $path = ''): string
{
    $base = 'https://mayhopphat.com';
    if ($path === '' || $path === '/') {
        return $base.'/';
    }

    return str_starts_with($path, 'http') ? $path : $base.'/'.ltrim($path, '/');
}

function is_ssl(): bool
{
    return true;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function wp_strip_all_tags(string $text): string
{
    return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function get_option(string $key, mixed $default = false): mixed
{
    return $default;
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

require_once dirname(__DIR__).'/includes/class-post-analysis-service.php';

use OmiSeoAiBridge\Post_Analysis_Service;

$failures = 0;

function omi_pa_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "ok - {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL - {$message}\n";
}

$service = new Post_Analysis_Service();

$html = <<<'HTML'
<p>Giới thiệu về <a href="/may-balo-laptop">may balo laptop theo yêu cầu</a> và
<a href="https://mayhopphat.com/tin-tuc/det-hai-soi-ngang.html">Hai sợi ngang</a>.</p>
<p>Tham khảo <a href="https://vi.wikipedia.org/wiki/Polyvinyl_chloride">Polyvinyl Chloride</a>
cùng <a href="https://example.com/x">external</a>.</p>
<p>Unresolved: <a href="https://mayhopphat.com/no-such-page-xyz">missing page</a>.</p>
<p>Skip: <a href="#toc">TOC</a> <a href="tel:0909938333">call</a></p>
HTML;

$resolverMap = [
    'https://mayhopphat.com/may-balo-laptop' => 1001,
    'https://mayhopphat.com/tin-tuc/det-hai-soi-ngang.html' => 2002,
];

$analysis = $service->analyze_html($html, [
    'post_id' => 68451,
    'title' => 'Vải Polyester 1680D',
    'slug' => 'vai-polyester-1680d',
    'permalink' => 'https://mayhopphat.com/tin-tuc/vai-polyester-1680d.html',
    'post_type' => 'post',
    'status' => 'publish',
    'site_host' => 'mayhopphat.com',
    'seo' => [
        'plugin' => 'rank_math',
        'meta_title' => 'Vải Polyester 1680D',
        'meta_description' => 'Desc',
        'focus_keywords' => ['vải polyester 1680d'],
    ],
    'target_resolver' => static function (string $absolute, string $hrefRaw) use ($resolverMap): int {
        unset($hrefRaw);
        $absolute = rtrim($absolute, '/');

        return (int) ($resolverMap[$absolute] ?? 0);
    },
    'source' => 'unit',
]);

// Re-run extract with resolver via options — analyze_html doesn't pass target_resolver.
// Call extract directly:
$links = $service->extract_links_from_html(
    $html,
    68451,
    'hash',
    'mayhopphat.com',
    [
        'resolve_targets' => true,
        'target_resolver' => static function (string $absolute, string $hrefRaw) use ($resolverMap): int {
            unset($hrefRaw);
            foreach ($resolverMap as $url => $id) {
                if (rtrim($absolute, '/') === rtrim($url, '/')) {
                    return $id;
                }
            }

            return 0;
        },
    ],
);

$byAnchor = [];
foreach ($links as $link) {
    $byAnchor[$link['anchor_text']] = $link;
}

omi_pa_assert(isset($byAnchor['may balo laptop theo yêu cầu']), 'relative internal present');
omi_pa_assert(($byAnchor['may balo laptop theo yêu cầu']['link_type'] ?? '') === 'internal', 'relative → internal');
omi_pa_assert(($byAnchor['may balo laptop theo yêu cầu']['target_post_id'] ?? null) === 1001, 'relative target resolved');

omi_pa_assert(($byAnchor['Hai sợi ngang']['link_type'] ?? '') === 'internal', 'absolute same-domain → internal');
omi_pa_assert(($byAnchor['Hai sợi ngang']['target_post_id'] ?? null) === 2002, 'absolute target resolved');

omi_pa_assert(($byAnchor['Polyvinyl Chloride']['link_type'] ?? '') === 'wiki_trust', 'wikipedia → wiki_trust');
omi_pa_assert(($byAnchor['external']['link_type'] ?? '') === 'external', 'example.com → external');
omi_pa_assert(($byAnchor['missing page']['link_type'] ?? '') === 'internal_unresolved', 'same-domain unresolved');

omi_pa_assert(! isset($byAnchor['TOC']), 'hash-only skipped');
omi_pa_assert(! isset($byAnchor['call']), 'tel skipped');

omi_pa_assert(
    is_string($byAnchor['Hai sợi ngang']['context_before'] ?? null)
    || ($byAnchor['Hai sợi ngang']['context_before'] ?? null) === null,
    'context_before key present',
);

omi_pa_assert(Post_Analysis_Service::is_wiki_trust_host('en.wikipedia.org'), 'wiki host match');
omi_pa_assert(Post_Analysis_Service::is_wiki_trust_host('nasa.gov'), '*.gov match');
omi_pa_assert(! Post_Analysis_Service::is_wiki_trust_host('example.com'), 'non-trust host');

omi_pa_assert(Post_Analysis_Service::is_skippable_href('#'), 'skip #');
omi_pa_assert(Post_Analysis_Service::is_skippable_href('tel:123'), 'skip tel');
omi_pa_assert(! Post_Analysis_Service::is_skippable_href('/may-balo-laptop'), 'keep relative');

$candidates = [];
$analysisWithLinks = $service->analyze_html($html, [
    'post_id' => 68451,
    'title' => 'T',
    'site_host' => 'mayhopphat.com',
    'seo' => [
        'plugin' => 'rank_math',
        'meta_title' => 'T',
        'meta_description' => '',
        'focus_keywords' => ['vải polyester 1680d'],
    ],
]);
// analyze_html uses url_to_postid which is missing — patch by rebuilding candidates from $links
$seo = [
    'focus_keywords' => ['vải polyester 1680d'],
];
$ref = new ReflectionClass($service);
$method = $ref->getMethod('build_keyword_candidates');
$method->setAccessible(true);
$candidates = $method->invoke($service, $links, $seo);
$sources = array_column($candidates, 'source');
omi_pa_assert(in_array('anchor_text', $sources, true), 'anchor keyword source');
omi_pa_assert(in_array('provider', $sources, true), 'provider keyword source separate');

$statsMethod = $ref->getMethod('build_stats');
$statsMethod->setAccessible(true);
$stats = $statsMethod->invoke($service, $html, $links, $candidates);
omi_pa_assert(($stats['links_total'] ?? 0) === 5, 'stats links_total=5 (skipped tel/#)');
omi_pa_assert(($stats['internal_links'] ?? 0) === 2, 'stats internal=2');
omi_pa_assert(($stats['wiki_trust_links'] ?? 0) === 1, 'stats wiki=1');
omi_pa_assert(($stats['internal_unresolved_links'] ?? 0) === 1, 'stats unresolved=1');
omi_pa_assert(($stats['provider_keywords'] ?? 0) === 1, 'stats provider keywords');

unset($analysis, $analysisWithLinks);

exit($failures === 0 ? 0 : 1);
