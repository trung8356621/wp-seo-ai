<?php

declare(strict_types=1);

/**
 * Offline diagnostic for post 68451 using public WP REST rendered content.
 * Resolves same-domain targets via shortlink / REST slug lookup.
 *
 * php tests/fixtures/run-post-68451-analysis.php
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

require_once dirname(__DIR__, 2).'/includes/class-post-analysis-service.php';

use OmiSeoAiBridge\Post_Analysis_Service;

$meta = json_decode((string) file_get_contents(__DIR__.'/post-68451-meta.json'), true);
$content = json_decode((string) file_get_contents(__DIR__.'/post-68451-content.json'), true);
$htmlRendered = (string) ($content['content']['rendered'] ?? '');

/**
 * Approximate raw post_content: drop same-document TOC fragment anchors
 * (usually injected by TOC plugins on the_content, not stored in post_content).
 */
function omi_strip_same_doc_fragment_links(string $html, string $permalink): string
{
    $permalink = rtrim($permalink, '/');
    $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is';

    return (string) preg_replace_callback($pattern, static function (array $m) use ($permalink): string {
        $href = html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($href, '#')) {
            return '';
        }
        $parts = parse_url($href);
        $frag = (string) ($parts['fragment'] ?? '');
        if ($frag === '') {
            return $m[0];
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $candidate = $host !== '' ? $scheme.'://'.$host.$path : $path;
        $candidate = rtrim($candidate, '/');
        $permPath = (string) (parse_url($permalink, PHP_URL_PATH) ?? '');
        if ($candidate === rtrim($permalink, '/') || $path === $permPath) {
            return '';
        }

        return $m[0];
    }, $html) ?? $html;
}

$permalink = (string) ($meta['link'] ?? 'https://mayhopphat.com/tin-tuc/vai-polyester-1680d.html');
$htmlApprox = omi_strip_same_doc_fragment_links($htmlRendered, $permalink);

$resolveCache = [];

function omi_http_get(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'omi-seo-ai-post-analysis/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    return is_string($body) ? $body : '';
}

function omi_resolve_target(string $absolute, string $hrefRaw): int
{
    global $resolveCache;
    unset($hrefRaw);
    $key = rtrim(strtok($absolute, '#') ?: $absolute, '/');
    if ($key === '' || $key === 'https://mayhopphat.com') {
        return 0;
    }
    if (isset($resolveCache[$key])) {
        return (int) $resolveCache[$key];
    }

    $html = omi_http_get($key);
    if ($html !== '') {
        if (preg_match('/body[^>]*class="([^"]+)"/i', $html, $bm)) {
            $classes = $bm[1];
            // Taxonomy archive — not a post; leave unresolved (0) but cache hint separately.
            if (str_contains($classes, 'tax-') || str_contains($classes, 'term-')) {
                $GLOBALS['resolveHints'][$key] = [
                    'unresolved_reason' => 'taxonomy_archive',
                    'body_class' => $classes,
                ];
                if (preg_match('/\bterm-(\d+)\b/', $classes, $tm)) {
                    $GLOBALS['resolveHints'][$key]['target_term_id'] = (int) $tm[1];
                }
                if (preg_match('/\btax-([a-z0-9_-]+)\b/', $classes, $tx)) {
                    $GLOBALS['resolveHints'][$key]['target_taxonomy'] = (string) $tx[1];
                }
                $resolveCache[$key] = 0;

                return 0;
            }
            if (preg_match('/\b(?:postid|page-id|product-id)-(\d+)\b/', $classes, $pm)) {
                $resolveCache[$key] = (int) $pm[1];

                return $resolveCache[$key];
            }
        }
        if (preg_match("/rel=['\"]shortlink['\"][^>]*href=['\"][^'\"]*\\?p=(\\d+)/i", $html, $m)) {
            $resolveCache[$key] = (int) $m[1];

            return $resolveCache[$key];
        }
    }

    $path = (string) (parse_url($key, PHP_URL_PATH) ?? '');
    if (str_contains($path, '/wp-content/uploads/')) {
        $GLOBALS['resolveHints'][$key] = ['unresolved_reason' => 'media_file'];
        $resolveCache[$key] = 0;

        return 0;
    }

    $slug = trim(basename($path, '.html'), '/');
    if ($slug !== '' && $slug !== '/') {
        foreach (['posts', 'pages'] as $type) {
            $api = 'https://mayhopphat.com/wp-json/wp/v2/'.$type.'?slug='.rawurlencode($slug).'&_fields=id,slug,link';
            $json = omi_http_get($api);
            $rows = json_decode($json, true);
            if (is_array($rows) && isset($rows[0]['id'])) {
                $resolveCache[$key] = (int) $rows[0]['id'];

                return $resolveCache[$key];
            }
        }
    }

    $resolveCache[$key] = 0;

    return 0;
}

$GLOBALS['resolveHints'] = [];
$resolveCache = [];

$service = new Post_Analysis_Service();

$enrichUnresolved = static function (array $analysis): array {
    $hints = is_array($GLOBALS['resolveHints'] ?? null) ? $GLOBALS['resolveHints'] : [];
    foreach ($analysis['links'] as $i => $link) {
        if (($link['link_type'] ?? '') !== 'internal_unresolved') {
            continue;
        }
        $key = rtrim(strtok((string) ($link['url'] ?? ''), '#') ?: (string) ($link['url'] ?? ''), '/');
        if ($key === 'https://mayhopphat.com' || $key === '') {
            $analysis['links'][$i]['unresolved_reason'] = 'front_page';
            continue;
        }
        $hint = $hints[$key] ?? null;
        if (! is_array($hint)) {
            $path = (string) (parse_url((string) ($link['url'] ?? ''), PHP_URL_PATH) ?? '');
            if (str_contains($path, '/wp-content/uploads/')) {
                $analysis['links'][$i]['unresolved_reason'] = 'media_file';
            }
            continue;
        }
        foreach (['unresolved_reason', 'target_term_id', 'target_taxonomy'] as $field) {
            if (isset($hint[$field])) {
                $analysis['links'][$i][$field] = $hint[$field];
            }
        }
        if (isset($hint['body_class'])) {
            $analysis['links'][$i]['evidence_body_class'] = $hint['body_class'];
        }
    }

    return $analysis;
};

$analysisApprox = $enrichUnresolved($service->analyze_html($htmlApprox, [
    'post_id' => (int) ($meta['id'] ?? 68451),
    'title' => html_entity_decode((string) ($meta['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'slug' => (string) ($meta['slug'] ?? ''),
    'permalink' => $permalink,
    'post_type' => (string) ($meta['type'] ?? 'post'),
    'status' => (string) ($meta['status'] ?? 'publish'),
    'modified_gmt' => (string) ($meta['modified_gmt'] ?? ''),
    'featured_media_id' => (int) ($meta['featured_media'] ?? 0),
    'site_host' => 'mayhopphat.com',
    'seo' => [
        'plugin' => 'unknown_public_api',
        'meta_title' => html_entity_decode((string) ($meta['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'meta_description' => '',
        'focus_keywords' => [],
        'note' => 'Provider SEO meta not available via public REST; use on-site debug endpoint for Rank Math/Yoast.',
    ],
    'target_resolver' => 'omi_resolve_target',
    'source' => 'public_rest_rendered_minus_toc_fragments',
]));

$analysisFull = $enrichUnresolved($service->analyze_html($htmlRendered, [
    'post_id' => (int) ($meta['id'] ?? 68451),
    'title' => html_entity_decode((string) ($meta['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'slug' => (string) ($meta['slug'] ?? ''),
    'permalink' => $permalink,
    'post_type' => (string) ($meta['type'] ?? 'post'),
    'status' => (string) ($meta['status'] ?? 'publish'),
    'modified_gmt' => (string) ($meta['modified_gmt'] ?? ''),
    'featured_media_id' => (int) ($meta['featured_media'] ?? 0),
    'site_host' => 'mayhopphat.com',
    'seo' => [
        'plugin' => 'unknown_public_api',
        'meta_title' => html_entity_decode((string) ($meta['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'meta_description' => '',
        'focus_keywords' => [],
    ],
    'target_resolver' => 'omi_resolve_target',
    'source' => 'public_rest_rendered_full',
]));

$suspicious = [];
foreach ($analysisApprox['links'] as $link) {
    $type = (string) ($link['link_type'] ?? '');
    $anchor = (string) ($link['anchor_text'] ?? '');
    $url = (string) ($link['url'] ?? '');
    if ($type === 'internal_unresolved') {
        $suspicious[] = [
            'reason' => 'internal_unresolved',
            'unresolved_reason' => $link['unresolved_reason'] ?? null,
            'target_term_id' => $link['target_term_id'] ?? null,
            'target_taxonomy' => $link['target_taxonomy'] ?? null,
            'url' => $url,
            'anchor' => $anchor,
        ];
    }
    if ($anchor === '') {
        $suspicious[] = ['reason' => 'empty_anchor', 'url' => $url, 'anchor' => $anchor];
    }
    if ($type === 'external' && str_contains(strtolower($url), 'mayhopphat')) {
        $suspicious[] = ['reason' => 'external_but_mentions_site', 'url' => $url, 'anchor' => $anchor];
    }
    if (str_contains($url, 'wp-content/uploads')) {
        $suspicious[] = [
            'reason' => 'media_url_as_link',
            'url' => $url,
            'anchor' => $anchor,
            'link_type' => $type,
            'unresolved_reason' => $link['unresolved_reason'] ?? null,
        ];
    }
}

$payload = [
    'diagnostic_note' => [
        'primary' => 'analysis_approx_post_content — public REST content.rendered with same-document TOC fragment links removed (TOC usually not in post_content).',
        'also_included' => 'analysis_rendered_full — includes TOC plugin anchors for comparison.',
        'provider_seo' => 'Not readable anonymously; call GET /wp-json/omi-seo-ai/v1/debug/post-analysis/68451 with read token on the live site.',
        'permalink_actual' => $permalink,
        'permalink_expected' => 'https://mayhopphat.com/tin-tuc/vai-polyester-1680d.html',
    ],
    'summary' => [
        'post_id' => $analysisApprox['post_id'],
        'title' => $analysisApprox['title'],
        'slug' => $analysisApprox['slug'],
        'permalink' => $analysisApprox['permalink'],
        'post_type' => $analysisApprox['post_type'],
        'content_hash' => $analysisApprox['content_hash'],
        'stats' => $analysisApprox['stats'],
        'stats_rendered_full' => $analysisFull['stats'],
    ],
    'suspicious' => $suspicious,
    'analysis' => $analysisApprox,
    'analysis_rendered_full_stats_only' => $analysisFull['stats'],
    'analysis_rendered_full_links' => $analysisFull['links'],
];

$out = __DIR__.'/post-68451-analysis.json';
file_put_contents($out, wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

echo "Wrote {$out}\n";
echo 'approx stats: '.wp_json_encode($analysisApprox['stats'], JSON_UNESCAPED_UNICODE)."\n";
echo 'full stats: '.wp_json_encode($analysisFull['stats'], JSON_UNESCAPED_UNICODE)."\n";
echo 'suspicious: '.count($suspicious)."\n";
