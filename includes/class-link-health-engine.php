<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Incremental local link health. Internal URLs resolve via WP; no site-wide HTTP crawl.
 */
final class Link_Health_Engine
{
    public const BATCH_SIZE = 8;

    /**
     * @return array<string, mixed>
     */
    public function process_batch(int $cursor, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(25, $limit));
        $cursor = max(0, $cursor);

        $query = new \WP_Query([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => $limit,
            'offset' => $cursor,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ]);

        $items = [];
        $broken = 0;
        $checked = 0;
        $homeHost = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');

        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            if (Sync_Provider::is_sync_excluded_post((int) $post->ID)) {
                continue;
            }

            $analysis = (new Post_Analysis_Service())->analyze((int) $post->ID, true);
            $analysisLinks = is_array($analysis['links'] ?? null) ? $analysis['links'] : [];
            $resolved = [];
            foreach ($analysisLinks as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $checked++;
                $row = $this->map_analysis_link($link, $homeHost);
                if (($row['status'] ?? '') === 'broken_candidate') {
                    $broken++;
                }
                $resolved[] = $row;
            }

            $items[] = [
                'wp_post_id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'content_hash' => (string) ($analysis['content_hash'] ?? Post_Analysis_Service::content_hash($post)),
                'links' => $resolved,
                'stats' => is_array($analysis['stats'] ?? null) ? $analysis['stats'] : [],
            ];
        }

        $nextCursor = $cursor + count($query->posts);
        $total = (int) $query->found_posts;
        $done = $nextCursor >= $total || $query->posts === [];

        return [
            'cursor' => $cursor,
            'next_cursor' => $done ? $nextCursor : $nextCursor,
            'done' => $done,
            'batch_size' => $limit,
            'posts_in_batch' => count($items),
            'total_posts' => $total,
            'links_checked' => $checked,
            'broken_candidates' => $broken,
            'items' => $items,
        ];
    }

    /**
     * Map Post_Analysis_Service link row → link-health batch contract.
     *
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function map_analysis_link(array $link, string $homeHost): array
    {
        unset($homeHost);
        $url = trim((string) ($link['url'] ?? ''));
        $linkType = (string) ($link['link_type'] ?? 'unknown');
        $targetId = (int) ($link['target_post_id'] ?? 0);
        $anchor = (string) ($link['anchor_text'] ?? '');

        if ($url === '') {
            return [
                'url' => '',
                'anchor' => $anchor,
                'link_type' => 'unknown',
                'status' => 'broken_candidate',
                'target_post_id' => 0,
            ];
        }

        if ($linkType === 'wiki_trust' || $linkType === 'external') {
            return [
                'url' => $url,
                'anchor' => $anchor,
                'link_type' => $linkType,
                'status' => $linkType === 'external' ? 'external_pending' : 'ok',
                'target_post_id' => 0,
            ];
        }

        if ($linkType === 'internal' && $targetId > 0) {
            return [
                'url' => $url,
                'anchor' => $anchor,
                'link_type' => 'internal',
                'status' => 'ok',
                'target_post_id' => $targetId,
            ];
        }

        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '/' || $path === '') {
            return [
                'url' => $url,
                'anchor' => $anchor,
                'link_type' => 'internal',
                'status' => 'ok',
                'target_post_id' => 0,
            ];
        }

        return [
            'url' => $url,
            'anchor' => $anchor,
            'link_type' => 'internal',
            'status' => 'broken_candidate',
            'target_post_id' => 0,
        ];
    }
}
