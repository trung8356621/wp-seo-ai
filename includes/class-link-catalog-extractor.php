<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Extract internal/external links from post content on WordPress (Source of Truth).
 */
final class Link_Catalog_Extractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function from_post(\WP_Post $post): array
    {
        $html = (string) $post->post_content;
        if ($html === '') {
            return [];
        }

        $links = [];
        if (! class_exists(\DOMDocument::class)) {
            return self::regex_fallback($html, $post);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<?xml encoding="utf-8" ?><div>'.$html.'</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $anchors = $dom->getElementsByTagName('a');
        $siteHost = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        foreach ($anchors as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }
            $absolute = self::absolutize($href);
            $host = (string) wp_parse_url($absolute, PHP_URL_HOST);
            $type = ($siteHost !== '' && strcasecmp($host, $siteHost) === 0) ? 'internal' : 'external';
            $links[] = [
                'wordpress_id' => (int) $post->ID,
                'url' => $absolute,
                'canonical' => $absolute,
                'slug' => (string) $post->post_name,
                'title' => trim(wp_strip_all_tags($a->textContent ?? '')),
                'status' => (string) $post->post_status,
                'type' => $type,
                'content_hash' => hash('sha256', $absolute.'|'.(string) $post->post_modified_gmt),
                'updated_at' => gmdate('c', strtotime((string) $post->post_modified_gmt) ?: time()),
                'meta' => [
                    'anchor_text' => trim(wp_strip_all_tags($a->textContent ?? '')),
                    'source_post_type' => (string) $post->post_type,
                ],
            ];
        }

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function regex_fallback(string $html, \WP_Post $post): array
    {
        $links = [];
        if (! preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $match) {
            $href = trim((string) ($match[1] ?? ''));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $absolute = self::absolutize($href);
            $links[] = [
                'wordpress_id' => (int) $post->ID,
                'url' => $absolute,
                'canonical' => $absolute,
                'slug' => (string) $post->post_name,
                'title' => trim(wp_strip_all_tags((string) ($match[2] ?? ''))),
                'status' => (string) $post->post_status,
                'type' => 'link',
                'content_hash' => hash('sha256', $absolute.'|'.(string) $post->post_modified_gmt),
                'updated_at' => gmdate('c', strtotime((string) $post->post_modified_gmt) ?: time()),
            ];
        }

        return $links;
    }

    private static function absolutize(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (is_ssl() ? 'https:' : 'http:').$href;
        }
        if (str_starts_with($href, '/')) {
            return home_url($href);
        }

        return home_url('/'.ltrim($href, '/'));
    }
}
