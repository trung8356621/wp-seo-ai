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

    private const WP_INTERNAL_POST_TYPES = [
        'revision', 'nav_menu_item', 'attachment', 'custom_css',
        'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'wp_global_styles',
        'wp_pattern',
    ];

    /**
     * Public content post types registered on this WordPress installation.
     *
     * Includes built-in post/page/product and any public custom post types,
     * excluding WP internal/system types.
     *
     * @return list<array{name: string, label: string, builtin: bool}>
     */
    public static function public_content_post_types(): array
    {
        if (! did_action('init')) {
            return [
                ['name' => 'post', 'label' => 'Posts', 'builtin' => true],
                ['name' => 'page', 'label' => 'Pages', 'builtin' => true],
                ['name' => 'product', 'label' => 'Products', 'builtin' => false],
            ];
        }
        $types = get_post_types(['public' => true], 'objects');
        $result = [];
        foreach ($types as $pt) {
            if (in_array($pt->name, self::WP_INTERNAL_POST_TYPES, true)) {
                continue;
            }
            if ($pt->name === 'attachment') {
                continue;
            }
            $result[] = [
                'name' => (string) $pt->name,
                'label' => (string) ($pt->label ?: $pt->name),
                'builtin' => (bool) $pt->_builtin,
            ];
        }
        return $result;
    }

    /**
     * Post type slugs to sync (content only, no taxonomies).
     *
     * @return list<string>
     */
    public static function syncable_post_type_slugs(): array
    {
        return array_column(self::public_content_post_types(), 'name');
    }

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
            'post_types' => self::public_content_post_types(),
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
            'fields' => (string) ($args['fields'] ?? 'metadata'),
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
            'fields' => (string) ($args['fields'] ?? 'metadata'),
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

        $contentHash = $this->content_only_hash($post);
        $mapped['content_hash'] = $contentHash;
        $mapped['seo_meta_hash'] = $this->seo_meta_hash($mapped);

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
     * @param  array{mode: string, since: ?string, offset: int, run_token: ?string, include_unchanged?: bool, fields?: string}  $opts
     * @return array<string, mixed>
     */
    private function collect_batch(array $opts): array
    {
        $offset = max(0, (int) $opts['offset']);
        $forceFull = ($opts['mode'] ?? '') === 'force_full' || ! empty($opts['include_unchanged']);
        $metadataOnly = ($opts['fields'] ?? 'metadata') !== 'full';
        $queryArgs = [
            'post_type' => self::syncable_post_type_slugs() ?: ['post', 'page', 'product'],
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
            $mapped = $metadataOnly
                ? $provider->map_post_index_by_id((int) $post->ID)
                : $provider->map_post_by_id((int) $post->ID);
            if (! is_array($mapped)) {
                continue;
            }
            $mapped['content_hash'] = $this->content_only_hash($post);
            $mapped['seo_meta_hash'] = $this->seo_meta_hash($mapped);
            $articles[] = $mapped;
            if (! $metadataOnly) {
                foreach (Link_Catalog_Extractor::from_post($post) as $link) {
                    $links[] = $link;
                }
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
            'fields' => $metadataOnly ? 'metadata' : 'full',
            'articles' => $articles,
            'links' => $links,
            'provider_keywords' => $keywords,
            'scores' => $scores,
            'contacts_suggest' => [],
            'capability_ref' => ['schema' => Capability_Manifest::SCHEMA],
        ];
    }

    /**
     * Content identity hash — comparable with lightweight_manifest.
     */
    private function content_only_hash(\WP_Post $post): string
    {
        $raw = (string) $post->post_content.'|'.(string) $post->post_title.'|'.(string) $post->post_excerpt;

        return hash('sha256', $raw);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function seo_meta_hash(array $mapped): string
    {
        $seo = is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [];
        $robots = is_array($seo['robots'] ?? null) ? $seo['robots'] : [];

        return hash('sha256', implode('|', [
            (string) ($seo['seo_title'] ?? ''),
            (string) ($seo['meta_description'] ?? ''),
            (string) ($seo['focus_keyword'] ?? ''),
            (string) ($seo['canonical'] ?? $seo['canonical_url'] ?? ''),
            ($robots['index'] ?? true) ? '1' : '0',
            ($robots['follow'] ?? true) ? '1' : '0',
            (string) ($seo['schema_type'] ?? ''),
            (string) ($seo['plugin'] ?? ''),
        ]));
    }

    /**
     * @deprecated Combined hash — prefer content_only_hash + seo_meta_hash.
     *
     * @param  array<string, mixed>  $mapped
     */
    private function content_hash(\WP_Post $post, array $mapped): string
    {
        return hash('sha256', $this->content_only_hash($post).'|'.$this->seo_meta_hash($mapped));
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
        $byType = [];
        $total = 0;
        $slugs = self::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        foreach ($slugs as $postType) {
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
            'post_type' => self::syncable_post_type_slugs() ?: ['post', 'page', 'product'],
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
            $seo = Seo_Plugin_Resolver::for_post($postId);
            $entries[] = [
                'wordpress_id' => $postId,
                'modified_at' => gmdate('c', strtotime((string) $post->post_modified_gmt) ?: time()),
                'status' => (string) $post->post_status,
                'post_type' => (string) $post->post_type,
                'type' => $post->post_type === 'product' ? 'product' : 'article',
                'content_hash' => hash('sha256', $raw),
                'seo_meta_hash' => $this->seo_meta_hash(['seo' => $seo]),
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
