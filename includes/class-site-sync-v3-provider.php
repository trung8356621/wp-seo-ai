<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * site_sync.v3 discover + keyset records (content / terms).
 * Items never carry HTML body fields.
 *
 * FULL traversal is frozen by snapshot_bounds (content_max_id / term_max_id).
 * CATCH-UP (delta) merges post/term changes + persistent delete ledger.
 */
final class Site_Sync_V3_Provider
{
    public const SCHEMA = 'site_sync.v3';

    public const SCHEMA_VERSION = 3;

    private const DELTA_OVERLAP_SECONDS = 2;

    private const DEFAULT_LIMIT = 50;

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 100;

    /** @var list<string> */
    private const TERM_TAXONOMIES = ['category', 'post_tag', 'product_cat'];

    /**
     * Inventory statuses for FULL + active upserts.
     * trash is NOT inventory — delta emits op=delete (row still exists) + ledger.
     *
     * @var list<string>
     */
    private const CONTENT_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /**
     * @return array<string, mixed>
     */
    public function discover(): array
    {
        $snapshotAt = gmdate('c');
        $generatedAt = $snapshotAt;
        $byContentType = $this->count_by_content_type();
        $contentTotal = array_sum($byContentType);
        $termsTotal = $this->count_terms();
        $total = $contentTotal + $termsTotal;
        $bridgeVersion = defined('OMI_SEO_AI_BRIDGE_VERSION') ? (string) OMI_SEO_AI_BRIDGE_VERSION : '';
        $bounds = $this->compute_snapshot_bounds();
        $siteRevision = hash(
            'sha256',
            $snapshotAt.'|'.$total.'|'.$contentTotal.'|'.$termsTotal.'|'
            .$bounds['content_max_id'].'|'.$bounds['term_max_id'].'|'.$bridgeVersion
        );

        $manifest = Capability_Manifest::build();
        $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $info = Seo_Plugin_Resolver::site_info();

        return [
            'schema' => self::SCHEMA,
            'snapshot_at' => $snapshotAt,
            'generated_at' => $generatedAt,
            'schema_version' => self::SCHEMA_VERSION,
            'site_revision' => $siteRevision,
            'snapshot_bounds' => $bounds,
            'total' => $total,
            'by_content_type' => $byContentType,
            'resources' => [
                'content' => ['total' => $contentTotal],
                'terms' => ['total' => $termsTotal],
            ],
            'capabilities' => $capabilities,
            'profile' => [
                'site_name' => (string) get_bloginfo('name'),
                'language' => (string) ($info['locale'] ?? ''),
                'bridge_version' => $bridgeVersion !== '' ? $bridgeVersion : (string) ($info['bridge_version'] ?? ''),
                'seo_provider' => (string) ($info['active'] ?? 'none'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function records(array $args): array
    {
        $resource = (string) ($args['resource'] ?? '');
        $mode = (string) ($args['mode'] ?? 'full');
        $limit = (int) ($args['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < self::MIN_LIMIT) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min(self::MAX_LIMIT, max(self::MIN_LIMIT, $limit));

        if (! in_array($resource, ['content', 'terms'], true)) {
            return $this->error_payload('resource must be content|terms');
        }
        if (! in_array($mode, ['full', 'delta'], true)) {
            return $this->error_payload('mode must be full|delta');
        }

        $snapshotAt = isset($args['snapshot_at']) ? (string) $args['snapshot_at'] : '';
        if ($mode === 'full' && $snapshotAt === '') {
            return $this->error_payload('snapshot_at is required for full mode');
        }

        $cursor = is_array($args['cursor'] ?? null) ? $args['cursor'] : [];
        $since = isset($args['since']) ? (string) $args['since'] : '';
        $bounds = $this->normalize_snapshot_bounds($args);

        if ($mode === 'full') {
            $hasBounds = isset($args['snapshot_bounds'])
                || isset($args['content_max_id'])
                || isset($args['term_max_id'])
                || isset($args['snapshot_content_max_id'])
                || isset($args['snapshot_term_max_id']);
            if (! $hasBounds) {
                return $this->error_payload('snapshot_bounds is required for full mode');
            }
        }

        if ($resource === 'terms') {
            return $this->records_terms($mode, $limit, $cursor, $snapshotAt, $since, $bounds);
        }

        return $this->records_content($mode, $limit, $cursor, $snapshotAt, $since, $bounds);
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @param  array{content_max_id:int,term_max_id:int}  $bounds
     * @return array<string, mixed>
     */
    private function records_content(
        string $mode,
        int $limit,
        array $cursor,
        string $snapshotAt,
        string $since,
        array $bounds
    ): array {
        $meta = [
            'analysis_cache_hit' => 0,
            'analysis_cache_miss' => 0,
            'analysis_ms' => 0,
        ];

        if ($mode === 'delta') {
            if ($since === '') {
                return $this->error_payload('since is required for delta mode');
            }

            return $this->records_content_delta($limit, $cursor, $since, $meta);
        }

        $afterId = max(0, (int) ($cursor['after_id'] ?? 0));
        $maxId = (int) $bounds['content_max_id'];
        $rows = $this->query_content_full($afterId, $maxId, $limit);
        $items = [];
        foreach ($rows as $row) {
            $postId = (int) ($row['ID'] ?? 0);
            if ($postId <= 0 || Sync_Provider::is_sync_excluded_post($postId)) {
                continue;
            }
            // Frozen bound: never include IDs above snapshot membership.
            if ($postId > $maxId) {
                continue;
            }
            $post = get_post($postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $items[] = $this->build_content_upsert($post, $meta);
        }

        $lastId = $afterId;
        if ($items !== []) {
            $last = $items[array_key_last($items)];
            $lastId = (int) ($last['wp_id'] ?? $afterId);
        } elseif ($rows !== []) {
            $lastId = (int) ($rows[array_key_last($rows)]['ID'] ?? $afterId);
        }

        $hasMore = count($rows) >= $limit && $lastId < $maxId;

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => 'content',
            'mode' => $mode,
            'snapshot_at' => $snapshotAt !== '' ? $snapshotAt : null,
            'snapshot_bounds' => $bounds,
            'since' => null,
            'items' => $items,
            'next_cursor' => [
                'after_id' => $lastId,
                'after_change_id' => (int) ($cursor['after_change_id'] ?? 0),
                'deletes_exhausted' => true,
            ],
            'has_more' => $hasMore,
            'meta' => $meta,
        ];
    }

    /**
     * Delta: ledger deletes first (monotonic change_id), then post upserts/trash.
     *
     * @param  array<string, mixed>  $cursor
     * @param  array{analysis_cache_hit:int,analysis_cache_miss:int,analysis_ms:int}  $meta
     * @return array<string, mixed>
     */
    private function records_content_delta(int $limit, array $cursor, string $since, array &$meta): array
    {
        $afterChangeId = max(0, (int) ($cursor['after_change_id'] ?? 0));
        $deletesExhausted = (bool) ($cursor['deletes_exhausted'] ?? false);
        $afterId = max(0, (int) ($cursor['after_id'] ?? 0));
        $afterModifiedGmt = isset($cursor['after_modified_gmt'])
            ? (string) $cursor['after_modified_gmt']
            : '';
        $sinceGmt = $this->normalize_since_gmt($since);
        $items = [];

        if (! $deletesExhausted) {
            $ledgerRows = Site_Sync_Change_Log::query_since(
                Site_Sync_Change_Log::RESOURCE_CONTENT,
                $sinceGmt,
                $afterChangeId,
                $limit,
                Site_Sync_Change_Log::OP_DELETE
            );
            foreach ($ledgerRows as $row) {
                $item = Site_Sync_Change_Log::row_to_item($row);
                if ($item !== null) {
                    $items[] = $item;
                    $afterChangeId = max($afterChangeId, (int) ($row['id'] ?? 0));
                }
            }
            if (count($ledgerRows) >= $limit) {
                return [
                    'schema' => self::SCHEMA,
                    'schema_version' => self::SCHEMA_VERSION,
                    'resource' => 'content',
                    'mode' => 'delta',
                    'snapshot_at' => null,
                    'since' => $since,
                    'items' => $items,
                    'next_cursor' => [
                        'after_id' => $afterId,
                        'after_modified_gmt' => $afterModifiedGmt,
                        'after_change_id' => $afterChangeId,
                        'deletes_exhausted' => false,
                    ],
                    'has_more' => true,
                    'meta' => $meta,
                ];
            }
            $deletesExhausted = true;
        }

        $remaining = $limit - count($items);
        if ($remaining > 0) {
            $rows = $this->query_content_delta($afterId, $afterModifiedGmt, $remaining, $since);
            $seenDeleteIds = [];
            foreach ($items as $existing) {
                if (($existing['op'] ?? '') === 'delete') {
                    $seenDeleteIds[(int) ($existing['wp_id'] ?? 0)] = true;
                }
            }
            foreach ($rows as $row) {
                $postId = (int) ($row['ID'] ?? 0);
                if ($postId <= 0 || Sync_Provider::is_sync_excluded_post($postId)) {
                    continue;
                }
                $modifiedGmt = (string) ($row['post_modified_gmt'] ?? '');
                $status = (string) ($row['post_status'] ?? '');
                if ($status === 'trash') {
                    if (isset($seenDeleteIds[$postId])) {
                        $afterId = $postId;
                        $afterModifiedGmt = $modifiedGmt;
                        continue;
                    }
                    $items[] = [
                        'op' => 'delete',
                        'wp_id' => $postId,
                        'wp_post_type' => (string) ($row['post_type'] ?? ''),
                        'content_type' => Content_Type_Map::resolve_content_type((string) ($row['post_type'] ?? '')),
                        'wp_is_term' => false,
                        'status' => 'trash',
                    ];
                    $afterId = $postId;
                    $afterModifiedGmt = $modifiedGmt;
                    continue;
                }

                $post = get_post($postId);
                if (! $post instanceof \WP_Post) {
                    continue;
                }
                $items[] = $this->build_content_upsert($post, $meta);
                $afterId = $postId;
                $afterModifiedGmt = $modifiedGmt !== ''
                    ? $modifiedGmt
                    : (string) ($post->post_modified_gmt ?? '');
            }
            $postsHasMore = count($rows) >= $remaining;
        } else {
            $postsHasMore = false;
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => 'content',
            'mode' => 'delta',
            'snapshot_at' => null,
            'since' => $since,
            'items' => $items,
            'next_cursor' => [
                'after_id' => $afterId,
                'after_modified_gmt' => $afterModifiedGmt,
                'after_change_id' => $afterChangeId,
                'deletes_exhausted' => $deletesExhausted,
            ],
            'has_more' => $postsHasMore,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @param  array{content_max_id:int,term_max_id:int}  $bounds
     * @return array<string, mixed>
     */
    private function records_terms(
        string $mode,
        int $limit,
        array $cursor,
        string $snapshotAt,
        string $since,
        array $bounds
    ): array {
        if ($mode === 'delta') {
            if ($since === '') {
                return $this->error_payload('since is required for delta mode');
            }

            return $this->records_terms_delta($limit, $cursor, $since, $bounds);
        }

        $afterTermId = max(0, (int) ($cursor['after_term_id'] ?? 0));
        $maxId = (int) $bounds['term_max_id'];
        $rows = $this->query_terms_full($afterTermId, $maxId, $limit);
        $items = [];

        foreach ($rows as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            if ($termId <= 0 || $taxonomy === '' || $termId > $maxId) {
                continue;
            }
            $term = get_term($termId, $taxonomy);
            if (! $term instanceof \WP_Term || is_wp_error($term)) {
                continue;
            }
            $items[] = $this->build_term_upsert($term, $taxonomy);
        }

        $lastTermId = $afterTermId;
        if ($items !== []) {
            $lastTermId = (int) ($items[array_key_last($items)]['wp_id'] ?? $afterTermId);
        } elseif ($rows !== []) {
            $lastTermId = (int) ($rows[array_key_last($rows)]['term_id'] ?? $afterTermId);
        }

        $hasMore = count($rows) >= $limit && $lastTermId < $maxId;

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => 'terms',
            'mode' => $mode,
            'snapshot_at' => $snapshotAt !== '' ? $snapshotAt : null,
            'snapshot_bounds' => $bounds,
            'items' => $items,
            'next_cursor' => [
                'after_term_id' => $lastTermId,
                'after_change_id' => (int) ($cursor['after_change_id'] ?? 0),
                'deletes_exhausted' => true,
            ],
            'has_more' => $hasMore,
            'meta' => [
                'analysis_cache_hit' => 0,
                'analysis_cache_miss' => 0,
                'analysis_ms' => 0,
            ],
        ];
    }

    /**
     * Terms delta: changelog (delete + upsert) then new terms above snapshot max.
     *
     * @param  array<string, mixed>  $cursor
     * @param  array{content_max_id:int,term_max_id:int}  $bounds
     * @return array<string, mixed>
     */
    private function records_terms_delta(int $limit, array $cursor, string $since, array $bounds): array
    {
        $afterChangeId = max(0, (int) ($cursor['after_change_id'] ?? 0));
        $ledgerExhausted = (bool) ($cursor['deletes_exhausted'] ?? false);
        $afterTermId = max(0, (int) ($cursor['after_term_id'] ?? 0));
        $sinceGmt = $this->normalize_since_gmt($since);
        $snapshotTermMax = (int) $bounds['term_max_id'];
        $items = [];

        if (! $ledgerExhausted) {
            $ledgerRows = Site_Sync_Change_Log::query_since(
                Site_Sync_Change_Log::RESOURCE_TERMS,
                $sinceGmt,
                $afterChangeId,
                $limit,
                null
            );
            foreach ($ledgerRows as $row) {
                $op = (string) ($row['operation'] ?? '');
                if ($op === Site_Sync_Change_Log::OP_DELETE) {
                    $item = Site_Sync_Change_Log::row_to_item($row);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                } elseif ($op === Site_Sync_Change_Log::OP_UPSERT) {
                    $termId = (int) ($row['object_id'] ?? 0);
                    $taxonomy = (string) ($row['object_type'] ?? '');
                    $term = get_term($termId, $taxonomy);
                    if ($term instanceof \WP_Term && ! is_wp_error($term)) {
                        $items[] = $this->build_term_upsert($term, $taxonomy);
                    }
                }
                $afterChangeId = max($afterChangeId, (int) ($row['id'] ?? 0));
            }
            if (count($ledgerRows) >= $limit) {
                return [
                    'schema' => self::SCHEMA,
                    'schema_version' => self::SCHEMA_VERSION,
                    'resource' => 'terms',
                    'mode' => 'delta',
                    'snapshot_at' => null,
                    'since' => $since,
                    'items' => $items,
                    'next_cursor' => [
                        'after_term_id' => $afterTermId,
                        'after_change_id' => $afterChangeId,
                        'deletes_exhausted' => false,
                    ],
                    'has_more' => true,
                    'meta' => [
                        'analysis_cache_hit' => 0,
                        'analysis_cache_miss' => 0,
                        'analysis_ms' => 0,
                    ],
                ];
            }
            $ledgerExhausted = true;
        }

        // Safety net: terms created after snapshot with IDs above frozen max.
        $remaining = $limit - count($items);
        $newHasMore = false;
        if ($remaining > 0 && $snapshotTermMax > 0) {
            $floor = max($afterTermId, $snapshotTermMax);
            $rows = $this->query_terms_keyset_unbounded($floor, $remaining);
            foreach ($rows as $row) {
                $termId = (int) ($row['term_id'] ?? 0);
                $taxonomy = (string) ($row['taxonomy'] ?? '');
                if ($termId <= $snapshotTermMax || $taxonomy === '') {
                    continue;
                }
                $term = get_term($termId, $taxonomy);
                if (! $term instanceof \WP_Term || is_wp_error($term)) {
                    continue;
                }
                $items[] = $this->build_term_upsert($term, $taxonomy);
                $afterTermId = max($afterTermId, $termId);
            }
            $newHasMore = count($rows) >= $remaining;
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'resource' => 'terms',
            'mode' => 'delta',
            'snapshot_at' => null,
            'since' => $since,
            'items' => $items,
            'next_cursor' => [
                'after_term_id' => $afterTermId,
                'after_change_id' => $afterChangeId,
                'deletes_exhausted' => $ledgerExhausted,
            ],
            'has_more' => $newHasMore,
            'meta' => [
                'analysis_cache_hit' => 0,
                'analysis_cache_miss' => 0,
                'analysis_ms' => 0,
            ],
        ];
    }

    /**
     * @param  array{analysis_cache_hit:int,analysis_cache_miss:int,analysis_ms:int}  $meta
     * @return array<string, mixed>
     */
    private function build_content_upsert(\WP_Post $post, array &$meta): array
    {
        $postId = (int) $post->ID;
        $contentHash = Post_Analysis_Service::content_hash($post);
        $cacheHit = $this->is_analysis_cache_hit($postId, $contentHash);

        $started = microtime(true);
        $analysis = (new Post_Analysis_Service())->analyze($postId, true);
        $meta['analysis_ms'] += (int) round((microtime(true) - $started) * 1000);
        if ($cacheHit) {
            $meta['analysis_cache_hit']++;
        } else {
            $meta['analysis_cache_miss']++;
        }

        $seoRaw = is_array($analysis['seo'] ?? null)
            ? $analysis['seo']
            : Seo_Plugin_Resolver::for_post($postId);

        $provider = (string) ($seoRaw['plugin'] ?? 'none');
        $titleSeo = (string) ($seoRaw['meta_title'] ?? $seoRaw['seo_title'] ?? '');
        $description = (string) ($seoRaw['meta_description'] ?? '');
        $focusKeywords = $this->normalize_focus_keywords($seoRaw, $provider);

        $scoreExport = Score_Exporter::for_post($post);
        $providerScore = is_array($scoreExport) ? $scoreExport : null;

        $taxonomies = is_array($analysis['taxonomies'] ?? null)
            ? $analysis['taxonomies']
            : $this->light_taxonomy_map($postId);

        $links = $this->normalize_links(is_array($analysis['links'] ?? null) ? $analysis['links'] : []);

        $analysisVersion = (int) ($analysis['analysis_version'] ?? Post_Analysis_Service::VERSION);
        $analysisHash = $this->analysis_hash($analysis, $contentHash, $analysisVersion);

        return [
            'op' => 'upsert',
            'wp_id' => $postId,
            'wp_post_type' => (string) $post->post_type,
            'content_type' => Content_Type_Map::resolve_content_type((string) $post->post_type),
            'wp_is_term' => false,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'permalink' => Permalink_Resolver::for_post($post),
            'status' => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'content_hash' => $contentHash,
            'taxonomy' => $taxonomies,
            'seo' => [
                'provider' => $provider,
                'title' => $titleSeo,
                'description' => $description,
                'focus_keywords' => $focusKeywords,
                'provider_score' => $providerScore,
            ],
            'links' => $links,
            'analysis' => [
                'analysis_version' => $analysisVersion,
                'analysis_hash' => $analysisHash,
                'content_hash' => $contentHash,
                'cache_hit' => $cacheHit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function build_term_upsert(\WP_Term $term, string $taxonomy): array
    {
        return [
            'op' => 'upsert',
            'wp_id' => (int) $term->term_id,
            'wp_post_type' => $taxonomy,
            'content_type' => Content_Type_Map::resolve_content_type($taxonomy, true),
            'wp_is_term' => true,
            'title' => (string) $term->name,
            'slug' => (string) $term->slug,
            'permalink' => Permalink_Resolver::for_term($term, $taxonomy),
            'parent_term_id' => (int) $term->parent,
            'taxonomy' => $taxonomy,
            'status' => 'publish',
        ];
    }

    /**
     * @return array{content_max_id:int,term_max_id:int}
     */
    private function compute_snapshot_bounds(): array
    {
        global $wpdb;

        $types = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        $statuses = self::CONTENT_STATUSES;
        $typePlaceholders = implode(',', array_fill(0, count($types), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}
            WHERE post_type IN ({$typePlaceholders})
            AND post_status IN ({$statusPlaceholders})";
        $prepared = $wpdb->prepare($sql, array_merge($types, $statuses));
        $contentMax = is_string($prepared) ? (int) $wpdb->get_var($prepared) : 0;

        $taxonomies = $this->syncable_taxonomies();
        $termMax = 0;
        if ($taxonomies !== []) {
            $taxPlaceholders = implode(',', array_fill(0, count($taxonomies), '%s'));
            $termSql = "SELECT COALESCE(MAX(t.term_id), 0)
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                WHERE tt.taxonomy IN ({$taxPlaceholders})";
            $termPrepared = $wpdb->prepare($termSql, $taxonomies);
            $termMax = is_string($termPrepared) ? (int) $wpdb->get_var($termPrepared) : 0;
        }

        return [
            'content_max_id' => $contentMax,
            'term_max_id' => $termMax,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{content_max_id:int,term_max_id:int}
     */
    private function normalize_snapshot_bounds(array $args): array
    {
        $nested = is_array($args['snapshot_bounds'] ?? null) ? $args['snapshot_bounds'] : [];

        return [
            'content_max_id' => (int) ($nested['content_max_id'] ?? $args['content_max_id'] ?? $args['snapshot_content_max_id'] ?? 0),
            'term_max_id' => (int) ($nested['term_max_id'] ?? $args['term_max_id'] ?? $args['snapshot_term_max_id'] ?? 0),
        ];
    }

    /**
     * FULL keyset: ID > after_id AND ID <= max_id ORDER BY ID ASC LIMIT n.
     *
     * @return list<array{ID:int|string,post_type:string,post_status:string}>
     */
    private function query_content_full(int $afterId, int $maxId, int $limit): array
    {
        global $wpdb;

        if ($maxId <= 0 || $afterId >= $maxId) {
            return [];
        }

        $types = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        $statuses = self::CONTENT_STATUSES;
        $typePlaceholders = implode(',', array_fill(0, count($types), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT ID, post_type, post_status FROM {$wpdb->posts}
            WHERE ID > %d
            AND ID <= %d
            AND post_type IN ({$typePlaceholders})
            AND post_status IN ({$statusPlaceholders})
            ORDER BY ID ASC
            LIMIT %d";

        $params = array_merge([$afterId, $maxId], $types, $statuses, [$limit]);
        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Delta keyset on change time then ID:
     *   modified_gmt >= since
     *   AND (modified_gmt > after_modified OR (modified_gmt = after_modified AND ID > after_id))
     * ORDER BY post_modified_gmt ASC, ID ASC
     *
     * @return list<array{ID:int|string,post_type:string,post_status:string,post_modified_gmt?:string}>
     */
    private function query_content_delta(int $afterId, string $afterModifiedGmt, int $limit, string $since): array
    {
        global $wpdb;

        $types = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        $statuses = array_merge(self::CONTENT_STATUSES, ['trash']);
        $typePlaceholders = implode(',', array_fill(0, count($types), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sinceGmt = $this->normalize_since_gmt($since);
        $afterModifiedGmt = trim($afterModifiedGmt);
        if ($afterModifiedGmt === '') {
            $afterModifiedGmt = '1970-01-01 00:00:00';
        }

        $sql = "SELECT ID, post_type, post_status, post_modified_gmt FROM {$wpdb->posts}
            WHERE post_type IN ({$typePlaceholders})
            AND post_status IN ({$statusPlaceholders})
            AND post_modified_gmt >= %s
            AND (
                post_modified_gmt > %s
                OR (post_modified_gmt = %s AND ID > %d)
            )
            ORDER BY post_modified_gmt ASC, ID ASC
            LIMIT %d";

        $params = array_merge($types, $statuses, [$sinceGmt, $afterModifiedGmt, $afterModifiedGmt, $afterId, $limit]);
        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * FULL terms: term_id > after AND term_id <= max.
     *
     * @return list<array{term_id:int|string,taxonomy:string}>
     */
    private function query_terms_full(int $afterTermId, int $maxId, int $limit): array
    {
        global $wpdb;

        if ($maxId <= 0 || $afterTermId >= $maxId) {
            return [];
        }

        $taxonomies = $this->syncable_taxonomies();
        if ($taxonomies === []) {
            return [];
        }

        $taxPlaceholders = implode(',', array_fill(0, count($taxonomies), '%s'));
        $sql = "SELECT t.term_id, tt.taxonomy
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE t.term_id > %d
            AND t.term_id <= %d
            AND tt.taxonomy IN ({$taxPlaceholders})
            ORDER BY t.term_id ASC
            LIMIT %d";

        $params = array_merge([$afterTermId, $maxId], $taxonomies, [$limit]);
        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Unbounded keyset for catch-up new terms (after floor id).
     *
     * @return list<array{term_id:int|string,taxonomy:string}>
     */
    private function query_terms_keyset_unbounded(int $afterTermId, int $limit): array
    {
        global $wpdb;

        $taxonomies = $this->syncable_taxonomies();
        if ($taxonomies === []) {
            return [];
        }

        $taxPlaceholders = implode(',', array_fill(0, count($taxonomies), '%s'));
        $sql = "SELECT t.term_id, tt.taxonomy
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE t.term_id > %d
            AND tt.taxonomy IN ({$taxPlaceholders})
            ORDER BY t.term_id ASC
            LIMIT %d";

        $params = array_merge([$afterTermId], $taxonomies, [$limit]);
        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, int>
     */
    private function count_by_content_type(): array
    {
        $by = ['post' => 0, 'page' => 0, 'product' => 0];
        $slugs = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        foreach ($slugs as $postType) {
            $counts = wp_count_posts($postType);
            if (! is_object($counts)) {
                continue;
            }
            $n = 0;
            foreach (self::CONTENT_STATUSES as $status) {
                $n += (int) ($counts->{$status} ?? 0);
            }
            $bucket = Content_Type_Map::resolve_content_type((string) $postType);
            if (! isset($by[$bucket])) {
                $by[$bucket] = 0;
            }
            $by[$bucket] += $n;
        }

        return $by;
    }

    private function count_terms(): int
    {
        $total = 0;
        foreach ($this->syncable_taxonomies() as $taxonomy) {
            $count = wp_count_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);
            if (! is_wp_error($count)) {
                $total += (int) $count;
            }
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private function syncable_taxonomies(): array
    {
        $out = [];
        foreach (self::TERM_TAXONOMIES as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $out[] = $taxonomy;
            }
        }

        return $out;
    }

    private function normalize_since_gmt(string $since): string
    {
        $ts = strtotime($since);
        if ($ts === false) {
            $ts = time() - 86400;
        }
        $ts -= self::DELTA_OVERLAP_SECONDS;

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function is_analysis_cache_hit(int $postId, string $contentHash): bool
    {
        $cached = get_post_meta($postId, Post_Analysis_Service::META_CACHE, true);
        if (! is_array($cached)) {
            return false;
        }

        return (string) ($cached['content_hash'] ?? '') === $contentHash
            && (int) ($cached['analysis_version'] ?? 0) === Post_Analysis_Service::VERSION;
    }

    /**
     * @param  array<string, mixed>  $seoRaw
     * @return list<array{phrase:string,provider:string}>
     */
    private function normalize_focus_keywords(array $seoRaw, string $provider): array
    {
        $out = [];
        $raw = $seoRaw['focus_keywords'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (is_string($row)) {
                    $phrase = trim($row);
                    if ($phrase !== '') {
                        $out[] = ['phrase' => $phrase, 'provider' => $provider];
                    }
                    continue;
                }
                if (! is_array($row)) {
                    continue;
                }
                $phrase = trim((string) ($row['phrase'] ?? $row['keyword'] ?? ''));
                if ($phrase === '') {
                    continue;
                }
                $out[] = [
                    'phrase' => $phrase,
                    'provider' => (string) ($row['provider'] ?? $provider),
                ];
            }
        }

        if ($out === []) {
            $focus = trim((string) ($seoRaw['focus_keyword'] ?? ''));
            if ($focus !== '') {
                foreach (preg_split('/\s*,\s*/', $focus) ?: [] as $part) {
                    $part = trim((string) $part);
                    if ($part !== '') {
                        $out[] = ['phrase' => $part, 'provider' => $provider];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @return list<array{href:string,anchor_text:string,kind:string,target_wp_id:?int}>
     */
    private function normalize_links(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $href = trim((string) ($link['url'] ?? $link['href'] ?? $link['href_raw'] ?? ''));
            if ($href === '') {
                continue;
            }
            $linkType = (string) ($link['link_type'] ?? $link['kind'] ?? 'external');
            $kind = str_starts_with($linkType, 'internal') ? 'internal' : 'external';
            $target = $link['target_wp_id'] ?? $link['target_post_id'] ?? null;
            $targetWpId = is_numeric($target) && (int) $target > 0 ? (int) $target : null;

            $out[] = [
                'href' => $href,
                'anchor_text' => (string) ($link['anchor_text'] ?? ''),
                'kind' => $kind,
                'target_wp_id' => $targetWpId,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, list<array{id:int,name:string,slug:string}>>
     */
    private function light_taxonomy_map(int $postId): array
    {
        $taxonomies = get_object_taxonomies(get_post_type($postId) ?: 'post', 'names');
        if (! is_array($taxonomies)) {
            return [];
        }

        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            if ($taxonomy === 'post_format') {
                continue;
            }
            $terms = get_the_terms($postId, $taxonomy);
            if (! is_array($terms) || $terms === []) {
                continue;
            }
            $rows = [];
            foreach ($terms as $term) {
                if (! $term instanceof \WP_Term) {
                    continue;
                }
                $rows[] = [
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                ];
            }
            if ($rows !== []) {
                $out[$taxonomy] = $rows;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $analysis
     */
    private function analysis_hash(?array $analysis, string $contentHash, int $analysisVersion): string
    {
        $slim = [
            'analysis_version' => $analysisVersion,
            'content_hash' => $contentHash,
            'stats' => is_array($analysis['stats'] ?? null) ? $analysis['stats'] : [],
            'seo' => is_array($analysis['seo'] ?? null) ? [
                'plugin' => $analysis['seo']['plugin'] ?? null,
                'meta_title' => $analysis['seo']['meta_title'] ?? null,
                'meta_description' => $analysis['seo']['meta_description'] ?? null,
                'focus_keywords' => $analysis['seo']['focus_keywords'] ?? [],
            ] : [],
            'link_count' => is_array($analysis['links'] ?? null) ? count($analysis['links']) : 0,
            'taxonomies' => is_array($analysis['taxonomies'] ?? null) ? $analysis['taxonomies'] : [],
        ];
        $json = wp_json_encode($slim);

        return hash('sha256', is_string($json) ? $json : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function error_payload(string $message): array
    {
        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'success' => false,
            'error' => $message,
            'items' => [],
            'next_cursor' => null,
            'has_more' => false,
            'meta' => [
                'analysis_cache_hit' => 0,
                'analysis_cache_miss' => 0,
                'analysis_ms' => 0,
            ],
        ];
    }
}
