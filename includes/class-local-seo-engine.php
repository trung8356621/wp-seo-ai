<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Incremental local index + link graph + dictionary anchor matching.
 * Laravel orchestrates batches; this class never scans the full site in one request.
 */
final class Local_Seo_Engine
{
    public const BATCH_SIZE = 12;

    public const OPTION_DIRTY = 'omi_seo_local_index_dirty';

    public const OPTION_SNAPSHOT = 'omi_seo_link_analysis_snapshot';

    public static function register(): void
    {
        add_action('save_post', [self::class, 'mark_post_dirty'], 20, 2);
        add_action('deleted_post', [self::class, 'drop_post'], 20, 1);
        add_action('trashed_post', [self::class, 'drop_post'], 20, 1);
    }

    public static function mark_post_dirty(int $postId, mixed $post = null): void
    {
        if ($postId <= 0 || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if ($post instanceof \WP_Post && ! in_array($post->post_type, ['post', 'page', 'product'], true)) {
            return;
        }
        $dirty = get_option(self::OPTION_DIRTY, []);
        if (! is_array($dirty)) {
            $dirty = [];
        }
        $dirty[(string) $postId] = time();
        if (count($dirty) > 500) {
            $dirty = array_slice($dirty, -500, 500, true);
        }
        update_option(self::OPTION_DIRTY, $dirty, false);
    }

    public static function drop_post(int $postId): void
    {
        global $wpdb;
        self::ensure_schema();
        $wpdb->delete($wpdb->prefix.'omi_seo_post_index', ['post_id' => $postId], ['%d']);
        $wpdb->delete($wpdb->prefix.'omi_seo_link_graph', ['source_post_id' => $postId], ['%d']);
        $wpdb->delete($wpdb->prefix.'omi_seo_link_opportunities', ['source_post_id' => $postId], ['%d']);
    }

    public static function mark_dictionary_stale(): void
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return;
        }
        self::ensure_schema();
        $wpdb->query('UPDATE '.$wpdb->prefix.'omi_seo_link_opportunities SET stale = 1');
    }

    /**
     * @return array<string, mixed>
     */
    public function process_batch(int $cursor, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(50, $limit));
        $cursor = max(0, $cursor);
        self::ensure_schema();

        $query = new \WP_Query([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => ['publish'],
            'posts_per_page' => $limit,
            'offset' => $cursor,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        ]);

        $indexed = 0;
        $opportunities = 0;
        $orphans = 0;
        $internal = 0;
        $dictionary = Keyword_Dictionary_Store::current();
        $dictVersion = is_array($dictionary) ? (string) ($dictionary['version'] ?? '') : '';
        $dictHash = is_array($dictionary) ? (string) ($dictionary['hash'] ?? '') : '';

        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $this->index_post($post, is_array($dictionary) ? $dictionary : []);
            $indexed++;
        }

        $snapshot = $this->snapshot_counts($dictVersion, $dictHash);
        $internal = (int) ($snapshot['internal_links'] ?? 0);
        $orphans = (int) ($snapshot['orphan_pages'] ?? 0);
        $opportunities = (int) ($snapshot['opportunities'] ?? 0);

        $nextCursor = $cursor + count($query->posts);
        $total = (int) $query->found_posts;
        $done = $nextCursor >= $total || $query->posts === [];
        if ($done) {
            update_option(self::OPTION_SNAPSHOT, $snapshot, false);
        }

        return [
            'cursor' => $cursor,
            'next_cursor' => $nextCursor,
            'done' => $done,
            'batch_size' => $limit,
            'posts_in_batch' => $indexed,
            'total_posts' => $total,
            'indexed_posts' => (int) ($snapshot['indexed_posts'] ?? $indexed),
            'internal_links' => $internal,
            'orphan_pages' => $orphans,
            'opportunities' => $opportunities,
            'dictionary_version' => $dictVersion,
            'dictionary_hash' => $dictHash,
            'last_analyzed_at' => gmdate('c'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dictionary
     */
    public function index_post(\WP_Post $post, array $dictionary): void
    {
        global $wpdb;
        self::ensure_schema();
        $postId = (int) $post->ID;
        $hash = hash('sha256', (string) $post->post_content.'|'.(string) $post->post_title);
        $permalink = Permalink_Resolver::for_post($postId);
        $now = current_time('mysql', true);

        $wpdb->replace($wpdb->prefix.'omi_seo_post_index', [
            'post_id' => $postId,
            'post_type' => (string) $post->post_type,
            'title' => (string) $post->post_title,
            'permalink' => $permalink,
            'content_hash' => $hash,
            'modified_at' => (string) $post->post_modified_gmt,
            'indexed_at' => $now,
        ]);

        $wpdb->delete($wpdb->prefix.'omi_seo_link_graph', ['source_post_id' => $postId], ['%d']);
        $existingTargets = [];
        foreach (Link_Catalog_Extractor::from_post($post) as $link) {
            $url = trim((string) ($link['url'] ?? $link['href'] ?? ''));
            if ($url === '') {
                continue;
            }
            $targetPostId = function_exists('url_to_postid') ? (int) url_to_postid($url) : 0;
            $internal = $targetPostId > 0 || (($link['type'] ?? '') === 'internal');
            $meta = is_array($link['meta'] ?? null) ? $link['meta'] : [];
            $anchor = trim((string) ($meta['anchor_text'] ?? $link['title'] ?? $link['anchor'] ?? ''));
            $wpdb->insert($wpdb->prefix.'omi_seo_link_graph', [
                'source_post_id' => $postId,
                'target_post_id' => $targetPostId > 0 ? $targetPostId : null,
                'target_url' => mb_substr($url, 0, 500),
                'anchor' => mb_substr($anchor, 0, 255),
                'anchor_normalized' => mb_strtolower($anchor),
                'link_type' => $internal ? 'internal' : 'external',
                'content_hash' => $hash,
                'indexed_at' => $now,
            ]);
            if ($targetPostId > 0) {
                $existingTargets[$targetPostId] = true;
            }
        }

        $wpdb->delete($wpdb->prefix.'omi_seo_link_opportunities', ['source_post_id' => $postId], ['%d']);
        $this->match_anchors($post, $hash, $dictionary, $existingTargets);
    }

    /**
     * @param  array<string, mixed>  $dictionary
     * @param  array<int, bool>  $existingTargets
     */
    private function match_anchors(\WP_Post $post, string $contentHash, array $dictionary, array $existingTargets): void
    {
        global $wpdb;
        $clusters = is_array($dictionary['clusters'] ?? null) ? $dictionary['clusters'] : [];
        if ($clusters === []) {
            return;
        }
        $text = html_entity_decode(wp_strip_all_tags((string) $post->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $sourceId = (int) $post->ID;
        $dictVersion = (string) ($dictionary['version'] ?? '');

        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $clusterKey = (string) ($cluster['cluster_key'] ?? '');
            $candidates = is_array($cluster['anchor_candidates'] ?? null)
                ? $cluster['anchor_candidates']
                : [];
            $primary = trim((string) ($cluster['primary'] ?? ''));
            foreach ($candidates as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase === '' || mb_strlen($phrase) < 4) {
                    continue;
                }
                if (! self::phrase_in_text($textNorm, mb_strtolower($phrase))) {
                    continue;
                }
                $targetId = $this->resolve_target($cluster, $primary, $sourceId);
                if ($targetId <= 0 || $targetId === $sourceId) {
                    continue;
                }
                if (isset($existingTargets[$targetId])) {
                    continue;
                }
                $target = get_post($targetId);
                if (! $target instanceof \WP_Post || $target->post_status !== 'publish') {
                    continue;
                }
                $wpdb->insert($wpdb->prefix.'omi_seo_link_opportunities', [
                    'source_post_id' => $sourceId,
                    'target_post_id' => $targetId,
                    'anchor' => mb_substr($phrase, 0, 255),
                    'cluster_key' => mb_substr($clusterKey, 0, 120),
                    'score' => 70,
                    'reason' => 'phrase_in_content',
                    'source_content_hash' => $contentHash,
                    'dictionary_version' => mb_substr($dictVersion, 0, 64),
                    'stale' => 0,
                    'detected_at' => current_time('mysql', true),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $cluster
     */
    private function resolve_target(array $cluster, string $primary, int $sourceId): int
    {
        unset($cluster);
        if ($primary === '') {
            return 0;
        }
        $found = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => 'publish',
            's' => $primary,
            'posts_per_page' => 5,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        foreach ($found as $id) {
            $id = (int) $id;
            if ($id !== $sourceId) {
                return $id;
            }
        }

        return 0;
    }

    public static function phrase_in_text(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_])/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot_counts(string $dictVersion, string $dictHash): array
    {
        global $wpdb;
        $indexTable = $wpdb->prefix.'omi_seo_post_index';
        $graphTable = $wpdb->prefix.'omi_seo_link_graph';
        $oppTable = $wpdb->prefix.'omi_seo_link_opportunities';

        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$indexTable}");
        $internal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$graphTable} WHERE link_type = 'internal'");
        $opportunities = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$oppTable} WHERE stale = 0");
        $orphans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$indexTable} i
             WHERE i.post_type IN ('post','page','product')
             AND NOT EXISTS (
                SELECT 1 FROM {$graphTable} g
                WHERE g.target_post_id = i.post_id AND g.link_type = 'internal'
             )"
        );

        return [
            'indexed_posts' => $indexed,
            'internal_links' => $internal,
            'orphan_pages' => max(0, $orphans),
            'opportunities' => $opportunities,
            'broken_links' => 0,
            'dictionary_version' => $dictVersion,
            'dictionary_hash' => $dictHash,
            'last_analyzed_at' => gmdate('c'),
        ];
    }

    public static function ensure_schema(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $index = $wpdb->prefix.'omi_seo_post_index';
        $graph = $wpdb->prefix.'omi_seo_link_graph';
        $opp = $wpdb->prefix.'omi_seo_link_opportunities';
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$index} (
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(32) NOT NULL,
            title text NULL,
            permalink varchar(500) NULL,
            content_hash varchar(64) NOT NULL,
            modified_at datetime NULL,
            indexed_at datetime NULL,
            PRIMARY KEY  (post_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$graph} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NULL,
            target_url varchar(500) NOT NULL,
            anchor varchar(255) NULL,
            anchor_normalized varchar(255) NULL,
            link_type varchar(16) NOT NULL,
            content_hash varchar(64) NULL,
            indexed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY source (source_post_id),
            KEY target (target_post_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$opp} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            anchor varchar(255) NOT NULL,
            cluster_key varchar(120) NULL,
            score int NOT NULL DEFAULT 0,
            reason varchar(64) NULL,
            source_content_hash varchar(64) NULL,
            dictionary_version varchar(64) NULL,
            stale tinyint(1) NOT NULL DEFAULT 0,
            detected_at datetime NULL,
            PRIMARY KEY  (id),
            KEY source (source_post_id)
        ) {$charset};");
    }
}
