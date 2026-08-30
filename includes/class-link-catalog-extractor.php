<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Extract internal/external links from post content on WordPress (Source of Truth).
 * Delegates parsing/classification to Post_Analysis_Service.
 */
final class Link_Catalog_Extractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function from_post(\WP_Post $post): array
    {
        return (new Post_Analysis_Service())->catalog_links_for_post($post);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function from_html(string $html, int $sourcePostId = 0, string $siteHost = ''): array
    {
        if ($siteHost === '') {
            $siteHost = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');
        }
        $hash = hash('sha256', $html);
        $service = new Post_Analysis_Service();
        $links = $service->extract_links_from_html($html, $sourcePostId, $hash, $siteHost);
        $out = [];
        foreach ($links as $link) {
            $type = (string) ($link['link_type'] ?? '');
            $catalogType = in_array($type, ['internal', 'internal_unresolved'], true) ? 'internal' : 'external';
            $out[] = [
                'wordpress_id' => $sourcePostId,
                'url' => (string) ($link['url'] ?? ''),
                'canonical' => (string) ($link['url'] ?? ''),
                'title' => (string) ($link['anchor_text'] ?? ''),
                'anchor' => (string) ($link['anchor_text'] ?? ''),
                'type' => $catalogType,
                'link_type' => $type,
                'target_post_id' => $link['target_post_id'] ?? null,
                'content_hash' => $hash,
                'meta' => [
                    'anchor_text' => (string) ($link['anchor_text'] ?? ''),
                    'context_before' => $link['context_before'] ?? null,
                    'context_after' => $link['context_after'] ?? null,
                    'href_raw' => (string) ($link['href_raw'] ?? ''),
                ],
            ];
        }

        return $out;
    }

    public static function absolutize(string $href): string
    {
        return Post_Analysis_Service::absolutize($href);
    }
}
