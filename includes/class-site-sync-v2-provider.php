<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * site_sync.v1 Snapshot / Delta / Profile producer.
 */
final class Site_Sync_V2_Provider
{
    public const BATCH_SIZE = 25;

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $info = Seo_Plugin_Resolver::site_info();
        $contacts = $this->discover_contacts();

        return [
            'site_url' => (string) ($info['site_url'] ?? home_url('/')),
            'wordpress_version' => (string) ($info['wordpress_version'] ?? ''),
            'bridge_version' => defined('OMI_SEO_AI_BRIDGE_VERSION') ? (string) OMI_SEO_AI_BRIDGE_VERSION : '',
            'permalink' => is_array($info['permalink'] ?? null) ? $info['permalink'] : [],
            'short_description' => (string) get_bloginfo('description'),
            'tone' => '',
            'cta_intro' => '',
            'contacts' => $contacts,
            'schema_org' => $this->schema_org_suggest(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function delta(array $args = []): array
    {
        $cursor = isset($args['cursor']) ? (string) $args['cursor'] : '';
        $since = $this->cursor_to_since($cursor);

        return $this->collect_batch([
            'mode' => 'delta',
            'since' => $since,
            'offset' => $this->cursor_offset($cursor),
            'run_token' => isset($args['run_token']) ? (string) $args['run_token'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function batches(array $args = []): array
    {
        $cursor = isset($args['cursor']) ? (string) $args['cursor'] : '';
        $mode = (string) ($args['mode'] ?? 'snapshot');
        $includeUnchanged = ! empty($args['include_unchanged']);
        $forceFull = $mode === 'force_full' || $includeUnchanged;

        // force_full / include_unchanged: full enumeration, never modified-since / delta cursor.
        if ($forceFull) {
            $mode = 'force_full';
        } elseif (! in_array($mode, ['snapshot', 'delta'], true)) {
            $mode = 'snapshot';
        }

        return $this->collect_batch([
            'mode' => $mode,
            'since' => ($mode === 'delta' && ! $forceFull) ? $this->cursor_to_since($cursor) : null,
            'offset' => $forceFull && ($cursor === '' || $cursor === 'null')
                ? 0
                : $this->cursor_offset($cursor),
            'run_token' => isset($args['run_token']) ? (string) $args['run_token'] : null,
            'include_unchanged' => $forceFull,
        ]);
    }

    /**
     * Full v2 item envelope for a single post (callback after save/publish).
     *
     * @return array<string, mixed>
     */
    public function item_for_post(int $postId): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post || Sync_Provider::is_sync_excluded_post($postId)) {
            return [];
        }

        $provider = new Sync_Provider();
        $mapped = $provider->map_post_by_id($postId);
        if (! is_array($mapped)) {
            return [];
        }

        $contentHash = $this->content_hash($post, $mapped);
        $mapped['content_hash'] = $contentHash;

        $seo = is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [];
        $keywords = [];
        $focus = trim((string) ($seo['focus_keyword'] ?? ''));
        if ($focus !== '') {
            $keywords[] = [
                'wordpress_id' => $postId,
                'phrase' => $focus,
                'source' => 'provider',
                'provider' => (string) ($seo['plugin'] ?? 'unknown'),
            ];
        }

        $scores = [];
        $score = Score_Exporter::for_post($post);
        if ($score !== null) {
            $scores[] = $score;
        }

        return [
            'schema' => Capability_Manifest::SCHEMA,
            'mode' => 'delta',
            'run_token' => null,
            'site_id_hint' => null,
            'cursor' => null,
            'has_more' => false,
            'profile' => null,
            'articles' => [$mapped],
            'links' => Link_Catalog_Extractor::from_post($post),
            'provider_keywords' => $keywords,
            'scores' => $scores,
            'contacts_suggest' => [],
            'capability_ref' => ['schema' => Capability_Manifest::SCHEMA],
        ];
    }

    /**
     * @param  array{mode: string, since: ?string, offset: int, run_token: ?string, include_unchanged?: bool}  $opts
     * @return array<string, mixed>
     */
    private function collect_batch(array $opts): array
    {
        $offset = max(0, (int) $opts['offset']);
        $forceFull = ($opts['mode'] ?? '') === 'force_full' || ! empty($opts['include_unchanged']);
        $queryArgs = [
            'post_type' => ['post', 'page', 'product'],
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => self::BATCH_SIZE,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
        ];

        // force_full must never apply modified-since — enumerate entire supported catalog.
        if (! $forceFull && ! empty($opts['since'])) {
            $queryArgs['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after' => (string) $opts['since'],
                'inclusive' => false,
            ]];
        }

        $query = new \WP_Query($queryArgs);
        $provider = new Sync_Provider();
        $articles = [];
        $links = [];
        $keywords = [];
        $scores = [];

        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            if (Sync_Provider::is_sync_excluded_post((int) $post->ID)) {
                continue;
            }
            $mapped = $provider->map_post_by_id((int) $post->ID);
            if (! is_array($mapped)) {
                continue;
            }
            $mapped['content_hash'] = $this->content_hash($post, $mapped);
            $articles[] = $mapped;
            foreach (Link_Catalog_Extractor::from_post($post) as $link) {
                $links[] = $link;
            }
            $seo = is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [];
            $focus = trim((string) ($seo['focus_keyword'] ?? ''));
            if ($focus !== '') {
                $keywords[] = [
                    'wordpress_id' => (int) $post->ID,
                    'phrase' => $focus,
                    'source' => 'provider',
                    'provider' => (string) ($seo['plugin'] ?? 'unknown'),
                ];
            }
            $score = Score_Exporter::for_post($post);
            if ($score !== null) {
                $scores[] = $score;
            }
        }

        $nextOffset = $offset + count($articles);
        $totalCount = (int) $query->found_posts;
        $hasMore = $totalCount > $nextOffset;
        $cursor = $this->encode_cursor(
            $forceFull ? null : ($opts['since'] ?? null),
            $nextOffset
        );

        return [
            'schema' => Capability_Manifest::SCHEMA,
            'mode' => $opts['mode'],
            'run_token' => $opts['run_token'],
            'site_id_hint' => null,
            'cursor' => $cursor,
            'has_more' => $hasMore,
            'total_count' => $totalCount,
            'include_unchanged' => $forceFull,
            'profile' => null,
            'articles' => $articles,
            'links' => $links,
            'provider_keywords' => $keywords,
            'scores' => $scores,
            'contacts_suggest' => [],
            'capability_ref' => ['schema' => Capability_Manifest::SCHEMA],
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function content_hash(\WP_Post $post, array $mapped): string
    {
        $seo = is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [];

        return hash('sha256', implode('|', [
            (string) $post->ID,
            (string) $post->post_modified_gmt,
            (string) $post->post_title,
            (string) $post->post_content,
            (string) ($seo['seo_title'] ?? ''),
            (string) ($seo['meta_description'] ?? ''),
            (string) ($seo['focus_keyword'] ?? ''),
        ]));
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function discover_contacts(): array
    {
        $out = [];
        $email = sanitize_email((string) get_option('admin_email'));
        if ($email !== '') {
            $out[] = ['type' => 'email', 'value' => $email];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema_org_suggest(): array
    {
        return [
            'name' => (string) get_bloginfo('name'),
            'url' => home_url('/'),
        ];
    }

    private function cursor_to_since(string $cursor): ?string
    {
        if ($cursor === '') {
            return gmdate('Y-m-d H:i:s', time() - 86400 * 30);
        }
        $decoded = json_decode(base64_decode($cursor) ?: '', true);
        if (! is_array($decoded)) {
            return null;
        }
        $since = $decoded['since'] ?? null;

        return is_string($since) && $since !== '' ? $since : null;
    }

    private function cursor_offset(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }
        $decoded = json_decode(base64_decode($cursor) ?: '', true);
        if (! is_array($decoded)) {
            return 0;
        }

        return max(0, (int) ($decoded['offset'] ?? 0));
    }

    /**
     * @return string Base64 JSON cursor {since, offset}
     */
    private function encode_cursor(?string $since, int $offset): string
    {
        $payload = json_encode([
            'since' => $since,
            'offset' => max(0, $offset),
        ], JSON_UNESCAPED_SLASHES);

        return base64_encode(is_string($payload) ? $payload : '{}');
    }

    /**
     * Fast counts-only summary for bootstrap preview UI (no per-post hashing).
     *
     * @return array{entries: list<array<string, mixed>>, totals: array<string, int>, by_type: array<string, int>, summary: bool}
     */
    public function lightweight_manifest_summary(): array
    {
        $byType = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        $total = 0;
        foreach (['post', 'page', 'product'] as $postType) {
            $counts = wp_count_posts($postType);
            if (! is_object($counts)) {
                continue;
            }
            foreach (['publish', 'draft', 'pending', 'private', 'future'] as $status) {
                $n = (int) ($counts->{$status} ?? 0);
                $byType[$postType] += $n;
                $total += $n;
            }
        }

        return [
            'entries' => [],
            'totals' => ['entries' => $total],
            'by_type' => $byType,
            'summary' => true,
        ];
    }

    /**
     * Lightweight manifest for reconciliation (no full body).
     *
     * @return array{entries: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function lightweight_manifest(): array
    {
        $query = new \WP_Query([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);

        $entries = [];
        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            if (Sync_Provider::is_sync_excluded_post($postId)) {
                continue;
            }
            $post = get_post($postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }
            // Preview/reconcile hash: content checksum only — avoid full item_for_post (too slow).
            $raw = (string) $post->post_content.'|'.(string) $post->post_title.'|'.(string) $post->post_excerpt;
            $entries[] = [
                'wordpress_id' => $postId,
                'modified_at' => gmdate('c', strtotime((string) $post->post_modified_gmt) ?: time()),
                'status' => (string) $post->post_status,
                'post_type' => (string) $post->post_type,
                'type' => $post->post_type === 'product' ? 'product' : 'article',
                'content_hash' => hash('sha256', $raw),
                'seo_meta_hash' => '',
                'link_hash' => '',
                'taxonomy_hash' => '',
            ];
        }

        return [
            'entries' => $entries,
            'totals' => ['entries' => count($entries)],
        ];
    }
}
