<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Local outbox for site_sync.v1 auto delta push.
 * Debounce hooks → one effective delta per post → async WP-Cron send with retry.
 */
final class Site_Sync_Outbox
{
    public const TABLE = 'omi_seo_sync_outbox';

    public const CRON_HOOK = 'omi_seo_ai_flush_sync_outbox';

    public const OPTION_SECRET = 'omi_seo_sync_callback_secret';

    public const MAX_ATTEMPTS = 8;

    public const FLUSH_LIMIT = 20;

    public const RETENTION_DAYS = 14;

    public const LOCK_TRANSIENT = 'omi_seo_sync_outbox_flush_lock';

    /** @var array<int, array{event:string,fields:list<string>}> */
    private static array $pending = [];

    public static function register(): void
    {
        add_action('init', [self::class, 'maybe_install_table'], 5);
        add_action('save_post', [self::class, 'on_save_post'], 100, 3);
        add_action('transition_post_status', [self::class, 'on_transition_status'], 100, 3);
        add_action('before_delete_post', [self::class, 'on_before_delete'], 100, 1);
        add_action('wp_trash_post', [self::class, 'on_trash'], 100, 1);
        add_action('untrash_post', [self::class, 'on_untrash'], 100, 1);
        add_action('updated_post_meta', [self::class, 'on_updated_meta'], 100, 4);
        add_action('set_object_terms', [self::class, 'on_set_terms'], 100, 6);
        add_action('shutdown', [self::class, 'persist_pending'], 998);
        add_action(self::CRON_HOOK, [self::class, 'process_outbox']);
        add_action('omi_seo_ai_cleanup_sync_outbox', [self::class, 'cleanup_retention']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'omi_seo_ai_every_minute', self::CRON_HOOK);
        }
        if (! wp_next_scheduled('omi_seo_ai_cleanup_sync_outbox')) {
            wp_schedule_event(time() + 3600, 'daily', 'omi_seo_ai_cleanup_sync_outbox');
        }

        add_filter('cron_schedules', static function (array $schedules): array {
            if (! isset($schedules['omi_seo_ai_every_minute'])) {
                $schedules['omi_seo_ai_every_minute'] = [
                    'interval' => 60,
                    'display' => 'Every minute (OMI SEO Sync Outbox)',
                ];
            }

            return $schedules;
        });
    }

    public static function maybe_install_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $version = (string) get_option('omi_seo_sync_outbox_schema', '');
        if ($version === '2') {
            return;
        }

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wordpress_id bigint(20) unsigned NOT NULL,
            event_type varchar(64) NOT NULL,
            operation_id varchar(64) NOT NULL,
            idempotency_key varchar(128) NOT NULL,
            payload longtext NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_next (status, next_attempt_at),
            KEY wordpress_id (wordpress_id),
            KEY status_updated (status, updated_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('omi_seo_sync_outbox_schema', '2', false);
    }

    public static function on_save_post(int $postId, $post, bool $update): void
    {
        if (Laravel_Push_Sync::is_suppressed()) {
            return;
        }
        if (! self::is_eligible_post($postId, $post)) {
            return;
        }
        self::queue($postId, $update ? 'article.updated' : 'article.created', ['content', 'title', 'status']);
    }

    public static function on_transition_status(string $new, string $old, $post): void
    {
        if (! $post instanceof \WP_Post || $new === $old) {
            return;
        }
        if (! self::is_eligible_post((int) $post->ID, $post)) {
            return;
        }
        self::queue((int) $post->ID, 'article.updated', ['status']);
    }

    public static function on_before_delete(int $postId): void
    {
        if (! self::is_eligible_post($postId, null)) {
            return;
        }
        self::queue($postId, 'article.deleted', ['status']);
    }

    public static function on_trash(int $postId): void
    {
        if (! self::is_eligible_post($postId, null)) {
            return;
        }
        self::queue($postId, 'article.trashed', ['status']);
    }

    public static function on_untrash(int $postId): void
    {
        if (! self::is_eligible_post($postId, null)) {
            return;
        }
        self::queue($postId, 'article.restored', ['status']);
    }

    /**
     * @param mixed $metaValue
     * @param mixed $prevValue
     */
    public static function on_updated_meta(int $metaId, int $postId, string $metaKey, $metaValue): void
    {
        unset($metaId, $metaValue);
        if (! self::is_eligible_post($postId, null)) {
            return;
        }
        if (str_starts_with($metaKey, '_omi_seo_ai')) {
            return;
        }
        $seoKeys = [
            'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_seo_score',
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_linkdex',
            '_thumbnail_id',
        ];
        if (! in_array($metaKey, $seoKeys, true)
            && ! str_contains($metaKey, 'rank_math')
            && ! str_contains($metaKey, 'yoast')
        ) {
            return;
        }
        $fields = $metaKey === '_thumbnail_id' ? ['featured_image'] : ['seo_metadata'];
        if (str_contains($metaKey, 'focus')) {
            $fields[] = 'provider_keyword';
        }
        self::queue($postId, 'seo_metadata.changed', $fields);
    }

    /**
     * @param list<int> $terms
     * @param list<int> $ttIds
     * @param bool|string $append
     */
    public static function on_set_terms(int $objectId, array $terms, array $ttIds, string $taxonomy, $append, array $oldTtIds): void
    {
        unset($terms, $ttIds, $append, $oldTtIds);
        if (! in_array($taxonomy, ['category', 'post_tag', 'product_cat', 'product_tag'], true)) {
            return;
        }
        if (! self::is_eligible_post($objectId, null)) {
            return;
        }
        self::queue($objectId, 'taxonomy.changed', ['taxonomy']);
    }

    /**
     * @param list<string> $fields
     */
    private static function queue(int $postId, string $event, array $fields): void
    {
        if ((string) get_post_meta($postId, '_omi_seo_ai_skip_push', true) === '1') {
            return;
        }
        if (! isset(self::$pending[$postId])) {
            self::$pending[$postId] = ['event' => $event, 'fields' => []];
        }
        // Prefer stronger lifecycle events.
        $priority = [
            'article.deleted' => 100,
            'article.trashed' => 90,
            'article.restored' => 80,
            'article.created' => 70,
            'article.updated' => 50,
            'permalink.changed' => 40,
            'seo_metadata.changed' => 30,
            'taxonomy.changed' => 20,
        ];
        $current = self::$pending[$postId]['event'];
        if (($priority[$event] ?? 0) >= ($priority[$current] ?? 0)) {
            self::$pending[$postId]['event'] = $event;
        }
        self::$pending[$postId]['fields'] = array_values(array_unique([
            ...self::$pending[$postId]['fields'],
            ...$fields,
        ]));
    }

    public static function persist_pending(): void
    {
        if (self::$pending === []) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];

        foreach ($pending as $postId => $info) {
            self::enqueue_row((int) $postId, (string) $info['event'], $info['fields']);
        }

        // Kick async soon without blocking editor response.
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
    }

    /**
     * @param list<string> $fields
     */
    private static function enqueue_row(int $postId, string $event, array $fields): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $provider = new Site_Sync_V2_Provider();
        $snapshot = $provider->item_for_post($postId);
        $post = get_post($postId);
        $hashes = self::compute_hashes($post, is_array($snapshot) ? $snapshot : []);

        if ($event === 'article.updated' && isset($snapshot['articles'][0]['permalink'])) {
            // Detect permalink change via previous hash option.
            $prev = (string) get_post_meta($postId, '_omi_seo_ai_last_permalink', true);
            $curr = (string) ($snapshot['articles'][0]['permalink'] ?? '');
            if ($prev !== '' && $curr !== '' && $prev !== $curr) {
                $event = 'permalink.changed';
                $fields[] = 'permalink';
            }
            if ($curr !== '') {
                update_post_meta($postId, '_omi_seo_ai_last_permalink', $curr);
            }
        }

        $operationId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('omi_', true);
        $idempotencyKey = hash('sha256', implode('|', [
            $postId,
            $event,
            $hashes['content_hash'],
            $hashes['seo_meta_hash'],
            $hashes['link_hash'],
            $hashes['taxonomy_hash'],
        ]));

        $payload = [
            'schema' => Capability_Manifest::SCHEMA,
            'mode' => 'delta',
            'site_url' => home_url('/'),
            'wordpress_id' => $postId,
            'event_type' => $event,
            'origin' => 'wordpress_outbox',
            'operation_id' => $operationId,
            'provider' => (string) (Seo_Plugin_Resolver::site_info()['active'] ?? 'none'),
            'changed_fields' => array_values(array_unique($fields)),
            'hashes' => $hashes,
            'snapshot_version' => Capability_Manifest::SCHEMA,
            'occurred_at' => gmdate('c'),
            'run_token' => null,
            'cursor' => null,
            'has_more' => false,
            'articles' => is_array($snapshot['articles'] ?? null) ? $snapshot['articles'] : [],
            'links' => is_array($snapshot['links'] ?? null) ? $snapshot['links'] : [],
            'provider_keywords' => is_array($snapshot['provider_keywords'] ?? null) ? $snapshot['provider_keywords'] : [],
            'scores' => is_array($snapshot['scores'] ?? null) ? $snapshot['scores'] : [],
            'contacts_suggest' => [],
            'capability_ref' => ['schema' => Capability_Manifest::SCHEMA],
        ];

        $now = gmdate('Y-m-d H:i:s');
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1",
            $idempotencyKey
        ));
        if ($exists) {
            return;
        }

        $wpdb->insert($table, [
            'wordpress_id' => $postId,
            'event_type' => $event,
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'payload' => wp_json_encode($payload) ?: '{}',
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function process_outbox(): void
    {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return;
        }
        set_transient(self::LOCK_TRANSIENT, '1', 55);

        try {
            global $wpdb;
            $table = $wpdb->prefix.self::TABLE;
            $now = gmdate('Y-m-d H:i:s');
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status IN ('pending','failed')
                       AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                     ORDER BY id ASC
                     LIMIT %d",
                    $now,
                    self::FLUSH_LIMIT
                ),
                ARRAY_A
            );
            if (! is_array($rows) || $rows === []) {
                return;
            }

            foreach ($rows as $row) {
                self::send_row($row);
            }
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public static function cleanup_retention(): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE status IN ('completed','dead_letter')
               AND updated_at < %s
             LIMIT 500",
            $cutoff
        ));
    }

    /**
     * @return array{pending:int,failed:int,dead_letter:int,completed:int,healthy:bool,message:string}
     */
    public static function health(): array
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $counts = [
            'pending' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'completed' => 0,
        ];
        foreach (array_keys($counts) as $status) {
            $counts[$status] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }
        $secretOk = self::callback_secret() !== '';
        $urlOk = self::delta_endpoint_url() !== '';
        $healthy = $secretOk && $urlOk && $counts['dead_letter'] < 50;
        $message = ! $urlOk
            ? 'Callback endpoint chưa cấu hình'
            : (! $secretOk ? 'Callback secret chưa cấu hình' : ($healthy ? 'Outbox healthy' : 'Outbox degraded'));

        return array_merge($counts, [
            'healthy' => $healthy,
            'message' => $message,
            'max_attempts' => self::MAX_ATTEMPTS,
            'flush_limit' => self::FLUSH_LIMIT,
        ]);
    }

    public static function retry_dead_letter(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $updated = $wpdb->update($table, [
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], [
            'id' => $id,
            'status' => 'dead_letter',
        ]);

        return $updated !== false && (int) $updated > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function send_row(array $row): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $id = (int) ($row['id'] ?? 0);
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $payloadJson = (string) ($row['payload'] ?? '{}');
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            $wpdb->update($table, [
                'status' => 'dead_letter',
                'attempts' => $attempts,
                'last_error' => 'Invalid payload JSON',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);

            return;
        }

        $url = self::delta_endpoint_url();
        $readToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));
        if ($url === '' || $readToken === '') {
            $wpdb->update($table, [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + self::backoff_seconds($attempts)),
                'last_error' => 'Missing Laravel URL or read token',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);

            return;
        }

        $timestamp = (string) time();
        $nonce = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('n_', true);
        $body = wp_json_encode($payload);
        if (! is_string($body)) {
            return;
        }
        $secret = self::callback_secret();
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);

        $args = [
            'timeout' => 15,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$readToken,
                'X-Seo-Read-Token' => $readToken,
                'X-Omi-Timestamp' => $timestamp,
                'X-Omi-Nonce' => $nonce,
                'X-Omi-Signature' => $signature,
                'X-Omi-Operation-Id' => (string) ($row['operation_id'] ?? ''),
                'X-Omi-Idempotency-Key' => (string) ($row['idempotency_key'] ?? ''),
            ],
            'body' => $body,
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            self::mark_failed($id, $attempts, $response->get_error_message());

            return;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $respBody = (string) wp_remote_retrieve_body($response);
        $json = json_decode($respBody, true);
        if ($code >= 200 && $code < 300 && is_array($json) && ($json['success'] ?? false)) {
            $wpdb->update($table, [
                'status' => 'completed',
                'attempts' => $attempts,
                'last_error' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);

            return;
        }

        $message = 'HTTP '.$code.': '.mb_substr(is_array($json) ? (string) ($json['message'] ?? $respBody) : $respBody, 0, 400);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $wpdb->update($table, [
                'status' => 'dead_letter',
                'attempts' => $attempts,
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);

            return;
        }
        self::mark_failed($id, $attempts, $message);
    }

    private static function mark_failed(int $id, int $attempts, string $message): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE;
        $wpdb->update($table, [
            'status' => 'failed',
            'attempts' => $attempts,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + self::backoff_seconds($attempts)),
            'last_error' => mb_substr($message, 0, 500),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    private static function backoff_seconds(int $attempts): int
    {
        return min(3600, (int) (30 * (2 ** max(0, $attempts - 1))));
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{content_hash:string,seo_meta_hash:string,link_hash:string,taxonomy_hash:string}
     */
    private static function compute_hashes(?\WP_Post $post, array $snapshot): array
    {
        $article = is_array($snapshot['articles'][0] ?? null) ? $snapshot['articles'][0] : [];
        $seo = is_array($article['seo'] ?? null) ? $article['seo'] : [];
        $links = is_array($snapshot['links'] ?? null) ? $snapshot['links'] : [];
        $linkUrls = array_map(static fn ($l): string => (string) ($l['url'] ?? ''), $links);
        sort($linkUrls);
        $tax = is_array($article['category_ids'] ?? null) ? $article['category_ids'] : [];
        sort($tax);

        return [
            'content_hash' => (string) ($article['content_hash'] ?? hash('sha256', ($post->post_content ?? '').'|'.($post->post_title ?? ''))),
            'seo_meta_hash' => hash('sha256', wp_json_encode($seo) ?: ''),
            'link_hash' => hash('sha256', implode(',', $linkUrls)),
            'taxonomy_hash' => hash('sha256', implode(',', array_map('strval', $tax))),
        ];
    }

    private static function is_eligible_post(int $postId, $post): bool
    {
        if ($postId <= 0 || Sync_Provider::is_sync_excluded_post($postId)) {
            return false;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return false;
        }
        if (! $post instanceof \WP_Post) {
            $post = get_post($postId);
        }
        if (! $post instanceof \WP_Post) {
            // deleted posts still enqueue delete/trash
            return true;
        }

        return in_array($post->post_type, ['post', 'page', 'product'], true);
    }

    private static function delta_endpoint_url(): string
    {
        if (! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return '';
        }
        $base = omi_seo_ai_bridge_laravel_api_url();
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/').'/api/seo-wp-bridge/delta-event';
    }

    public static function callback_secret(): string
    {
        $secret = trim((string) get_option(self::OPTION_SECRET, ''));
        if ($secret !== '') {
            return $secret;
        }
        // Fallback: derive from read token so existing installs work until rotation UI set.
        $token = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));

        return $token !== '' ? hash('sha256', 'omi-sync-'.$token) : 'omi-sync-unconfigured';
    }
}
