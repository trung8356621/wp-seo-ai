<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persistent Site Sync V3 change ledger — hard-delete tombstones (and term upserts).
 *
 * Soft trash remains visible on wp_posts (status=trash) and is emitted by V3 delta
 * as op=delete. Hard delete removes the row, so CATCH-UP must read this ledger.
 *
 * Retention: 30 days (longer than worst-case multi-hour full sync).
 */
final class Site_Sync_Change_Log
{
    public const RESOURCE_CONTENT = 'content';

    public const RESOURCE_TERMS = 'terms';

    public const OP_DELETE = 'delete';

    public const OP_UPSERT = 'upsert';

    /** Days to keep ledger rows. */
    public const RETENTION_DAYS = 30;

    public const CRON_HOOK = 'omi_seo_ai_sync_changes_cleanup';

    /** @var list<array<string, mixed>>|null When set, bypass $wpdb (unit/integration harness). */
    private static ?array $memoryRows = null;

    private static int $memoryAutoId = 1;

    public static function register(): void
    {
        self::maybe_install();

        add_action('before_delete_post', [self::class, 'on_before_delete_post'], 5, 1);
        add_action('wp_trash_post', [self::class, 'on_trash_post'], 5, 1);
        add_action('pre_delete_term', [self::class, 'on_pre_delete_term'], 5, 3);
        add_action('created_term', [self::class, 'on_term_upsert'], 20, 3);
        add_action('edited_term', [self::class, 'on_term_upsert'], 20, 3);

        add_action(self::CRON_HOOK, [self::class, 'cleanup_expired']);
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Test-only: in-memory ledger (no $wpdb).
     */
    public static function use_memory_store_for_tests(bool $enabled = true): void
    {
        if ($enabled) {
            self::$memoryRows = [];
            self::$memoryAutoId = 1;

            return;
        }
        self::$memoryRows = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function memory_rows_for_tests(): array
    {
        return self::$memoryRows ?? [];
    }

    public static function table_name(): string
    {
        global $wpdb;

        if (self::$memoryRows !== null) {
            return 'wp_omi_seo_ai_sync_changes';
        }

        return $wpdb->prefix.'omi_seo_ai_sync_changes';
    }

    public static function maybe_install(): void
    {
        if (self::$memoryRows !== null) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            resource varchar(32) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            object_type varchar(64) NOT NULL DEFAULT '',
            operation varchar(16) NOT NULL,
            changed_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY resource_changed_id (resource, changed_at, id),
            KEY object_resource (object_id, resource)
        ) {$charset};";

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function on_before_delete_post(int $postId): void
    {
        if ($postId <= 0 || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        $types = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        if (! in_array((string) $post->post_type, $types, true)) {
            return;
        }

        if (Sync_Provider::is_sync_excluded_post($postId)) {
            return;
        }

        self::record(
            self::RESOURCE_CONTENT,
            $postId,
            (string) $post->post_type,
            self::OP_DELETE,
            [
                'content_type' => Content_Type_Map::resolve_content_type((string) $post->post_type),
                'status' => (string) $post->post_status,
                'source' => 'before_delete_post',
            ]
        );
    }

    public static function on_trash_post(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        $types = Site_Sync_V2_Provider::syncable_post_type_slugs() ?: ['post', 'page', 'product'];
        if (! in_array((string) $post->post_type, $types, true)) {
            return;
        }

        // Inventory semantics: trash = leave SEO Ops inventory (tombstone), not status=trash row.
        self::record(
            self::RESOURCE_CONTENT,
            $postId,
            (string) $post->post_type,
            self::OP_DELETE,
            [
                'content_type' => Content_Type_Map::resolve_content_type((string) $post->post_type),
                'status' => 'trash',
                'source' => 'wp_trash_post',
            ]
        );
    }

    /**
     * @param  int|string  $termId
     * @param  int|string  $ttId
     * @param  string      $taxonomy
     */
    public static function on_pre_delete_term($termId, $ttId, $taxonomy): void
    {
        unset($ttId);
        $termId = (int) $termId;
        $taxonomy = (string) $taxonomy;
        if ($termId <= 0 || $taxonomy === '') {
            return;
        }

        $allowed = ['category', 'post_tag', 'product_cat'];
        if (! in_array($taxonomy, $allowed, true)) {
            return;
        }

        self::record(
            self::RESOURCE_TERMS,
            $termId,
            $taxonomy,
            self::OP_DELETE,
            [
                'taxonomy' => $taxonomy,
                'content_type' => Content_Type_Map::resolve_content_type($taxonomy, true),
                'source' => 'pre_delete_term',
            ]
        );
    }

    /**
     * @param  int|string  $termId
     * @param  int|string  $ttId
     * @param  string      $taxonomy
     */
    public static function on_term_upsert($termId, $ttId, $taxonomy): void
    {
        unset($ttId);
        $termId = (int) $termId;
        $taxonomy = (string) $taxonomy;
        if ($termId <= 0 || $taxonomy === '') {
            return;
        }

        $allowed = ['category', 'post_tag', 'product_cat'];
        if (! in_array($taxonomy, $allowed, true)) {
            return;
        }

        self::record(
            self::RESOURCE_TERMS,
            $termId,
            $taxonomy,
            self::OP_UPSERT,
            [
                'taxonomy' => $taxonomy,
                'source' => 'term_saved',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $resource,
        int $objectId,
        string $objectType,
        string $operation,
        array $metadata = []
    ): void {
        if ($objectId <= 0 || $resource === '' || $operation === '') {
            return;
        }

        $changedAt = gmdate('Y-m-d H:i:s');
        $metaJson = $metadata !== [] ? wp_json_encode($metadata) : null;
        if ($metaJson === false) {
            $metaJson = null;
        }

        if (self::$memoryRows !== null) {
            self::$memoryRows[] = [
                'id' => self::$memoryAutoId++,
                'resource' => $resource,
                'object_id' => $objectId,
                'object_type' => $objectType,
                'operation' => $operation,
                'changed_at' => $changedAt,
                'metadata_json' => $metaJson,
            ];

            return;
        }

        global $wpdb;
        $wpdb->insert(
            self::table_name(),
            [
                'resource' => $resource,
                'object_id' => $objectId,
                'object_type' => $objectType,
                'operation' => $operation,
                'changed_at' => $changedAt,
                'metadata_json' => $metaJson,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Keyset: id > after_change_id AND changed_at >= sinceGmt, ORDER BY id ASC.
     *
     * @return list<array<string, mixed>>
     */
    public static function query_since(
        string $resource,
        string $sinceGmt,
        int $afterChangeId,
        int $limit,
        ?string $operation = null
    ): array {
        $limit = max(1, min(100, $limit));
        $afterChangeId = max(0, $afterChangeId);

        if (self::$memoryRows !== null) {
            $out = [];
            foreach (self::$memoryRows as $row) {
                if ((string) ($row['resource'] ?? '') !== $resource) {
                    continue;
                }
                if ((int) ($row['id'] ?? 0) <= $afterChangeId) {
                    continue;
                }
                if ((string) ($row['changed_at'] ?? '') < $sinceGmt) {
                    continue;
                }
                if ($operation !== null && (string) ($row['operation'] ?? '') !== $operation) {
                    continue;
                }
                $out[] = $row;
            }
            usort($out, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

            return array_slice($out, 0, $limit);
        }

        global $wpdb;
        $table = self::table_name();
        if ($operation !== null) {
            $sql = "SELECT id, resource, object_id, object_type, operation, changed_at, metadata_json
                FROM {$table}
                WHERE resource = %s
                AND operation = %s
                AND id > %d
                AND changed_at >= %s
                ORDER BY id ASC
                LIMIT %d";
            $prepared = $wpdb->prepare($sql, [$resource, $operation, $afterChangeId, $sinceGmt, $limit]);
        } else {
            $sql = "SELECT id, resource, object_id, object_type, operation, changed_at, metadata_json
                FROM {$table}
                WHERE resource = %s
                AND id > %d
                AND changed_at >= %s
                ORDER BY id ASC
                LIMIT %d";
            $prepared = $wpdb->prepare($sql, [$resource, $afterChangeId, $sinceGmt, $limit]);
        }

        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public static function cleanup_expired(): void
    {
        if (self::$memoryRows !== null) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
            self::$memoryRows = array_values(array_filter(
                self::$memoryRows,
                static fn (array $row): bool => (string) ($row['changed_at'] ?? '') >= $cutoff
            ));

            return;
        }

        global $wpdb;
        $table = self::table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE changed_at < %s", $cutoff));
    }

    /**
     * Map ledger row → V3 item.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function row_to_item(array $row): ?array
    {
        $objectId = (int) ($row['object_id'] ?? 0);
        if ($objectId <= 0) {
            return null;
        }

        $operation = (string) ($row['operation'] ?? '');
        $objectType = (string) ($row['object_type'] ?? '');
        $resource = (string) ($row['resource'] ?? '');
        $meta = [];
        if (! empty($row['metadata_json']) && is_string($row['metadata_json'])) {
            $decoded = json_decode($row['metadata_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $changedAt = (string) ($row['changed_at'] ?? '');
        $iso = $changedAt !== ''
            ? gmdate('c', strtotime($changedAt.' UTC') ?: time())
            : gmdate('c');

        if ($operation === self::OP_DELETE) {
            $item = [
                'op' => 'delete',
                'wp_id' => $objectId,
                'wp_post_type' => $objectType,
                'deleted_at' => $iso,
                'change_id' => (int) ($row['id'] ?? 0),
            ];
            if ($resource === self::RESOURCE_TERMS) {
                $item['wp_is_term'] = true;
                $item['taxonomy'] = $objectType;
                $item['content_type'] = (string) ($meta['content_type'] ?? Content_Type_Map::resolve_content_type($objectType, true));
            } else {
                $item['wp_is_term'] = false;
                $item['content_type'] = (string) ($meta['content_type'] ?? Content_Type_Map::resolve_content_type($objectType));
            }

            return $item;
        }

        if ($operation === self::OP_UPSERT && $resource === self::RESOURCE_TERMS) {
            return [
                'op' => 'upsert',
                'wp_id' => $objectId,
                'wp_post_type' => $objectType,
                'wp_is_term' => true,
                'taxonomy' => $objectType,
                'change_id' => (int) ($row['id'] ?? 0),
                '_ledger_upsert' => true,
            ];
        }

        return null;
    }
}
