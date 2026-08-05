<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Đẩy bài viết / danh mục mới (hoặc cập nhật) lên Laravel khi lưu trên WordPress.
 */
final class Laravel_Push_Sync
{
    private const SKIP_META = '_omi_seo_ai_skip_push';

    private static bool $suppressed = false;

    /** @var array<int, true> */
    private static $queuedPostIds = [];

    /** @var array<string, true> */
    private static $queuedTermKeys = [];

    /**
     * Lifecycle sync: trash / force_delete / restore.
     *
     * @var array<int, array{action:string,type:string,wp_post_type:string}>
     */
    private static $queuedLifecycle = [];

    public static function register(): void
    {
        add_action('save_post', [self::class, 'on_save_post'], 99, 3);
        add_action('wp_after_insert_post', [self::class, 'on_after_insert_post'], 99, 4);
        add_action('wp_trash_post', [self::class, 'on_trash_post'], 99, 1);
        add_action('before_delete_post', [self::class, 'on_before_delete_post'], 99, 1);
        add_action('untrash_post', [self::class, 'on_untrash_post'], 99, 1);
        add_action('created_term', [self::class, 'on_term_saved'], 99, 3);
        add_action('edited_term', [self::class, 'on_term_saved'], 99, 3);
        add_action('shutdown', [self::class, 'flush_queue'], 999);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_after_product_object_save', [self::class, 'on_wc_product_saved'], 99, 1);
            add_action('woocommerce_update_product', [self::class, 'on_wc_product_id'], 99, 1);
            add_action('woocommerce_new_product', [self::class, 'on_wc_product_id'], 99, 1);
        }
    }

    public static function suppress(bool $suppressed): void
    {
        self::$suppressed = $suppressed;
    }

    public static function is_suppressed(): bool
    {
        return self::$suppressed;
    }

    public static function on_save_post(int $postId, $post, bool $update): void
    {
        unset($update);

        if (self::$suppressed) {
            return;
        }

        if (! $post instanceof \WP_Post) {
            $post = get_post($postId);
        }
        if (! $post instanceof \WP_Post) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if ((string) get_post_meta($postId, self::SKIP_META, true) === '1') {
            return;
        }

        $supportedTypes = ['post', 'page', 'product'];
        if (! in_array($post->post_type, $supportedTypes, true)) {
            return;
        }

        $allowedStatuses = ['publish', 'draft', 'pending', 'future', 'private'];
        if (! in_array($post->post_status, $allowedStatuses, true)) {
            return;
        }

        if (Sync_Provider::is_sync_excluded_post($postId)) {
            return;
        }

        self::queue_post($postId);
    }

    /**
     * @param \WP_Post  $post
     * @param bool      $update
     * @param \WP_Post|null $postBefore
     */
    public static function on_after_insert_post(int $postId, $post, bool $update, $postBefore): void
    {
        unset($update, $postBefore);
        self::on_save_post($postId, $post, true);
    }

    /**
     * @param \WC_Product $product
     */
    public static function on_wc_product_saved($product): void
    {
        if (self::$suppressed) {
            return;
        }

        if (! is_object($product) || ! method_exists($product, 'get_id')) {
            return;
        }

        self::queue_post((int) $product->get_id());
    }

  /** @param int|\WC_Product $product */
    public static function on_wc_product_id($product): void
    {
        if (self::$suppressed) {
            return;
        }

        $postId = is_object($product) && method_exists($product, 'get_id')
            ? (int) $product->get_id()
            : (int) $product;

        if ($postId > 0) {
            self::queue_post($postId);
        }
    }

    public static function on_term_saved(int $termId, int $ttId, string $taxonomy): void
    {
        unset($ttId);

        $supported = ['category', 'product_cat'];
        if (! in_array($taxonomy, $supported, true)) {
            return;
        }

        self::$queuedTermKeys[$taxonomy . ':' . $termId] = true;
    }

    public static function on_trash_post(int $postId): void
    {
        self::queue_lifecycle($postId, 'trash');
    }

    public static function on_before_delete_post(int $postId): void
    {
        // Move-to-trash chỉ gọi wp_trash_post; before_delete_post = xóa vĩnh viễn.
        self::queue_lifecycle($postId, 'force_delete');
    }

    public static function on_untrash_post(int $postId): void
    {
        self::queue_lifecycle($postId, 'restore');
    }

    private static function queue_post(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        self::$queuedPostIds[$postId] = true;
    }

    private static function queue_lifecycle(int $postId, string $action): void
    {
        if (self::$suppressed || $postId <= 0) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if ((string) get_post_meta($postId, self::SKIP_META, true) === '1') {
            return;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        $supportedTypes = ['post', 'page', 'product'];
        if (! in_array($post->post_type, $supportedTypes, true)) {
            return;
        }

        if (Sync_Provider::is_sync_excluded_post($postId)) {
            return;
        }

        $priority = [
            'restore' => 1,
            'trash' => 2,
            'force_delete' => 3,
        ];
        $existing = self::$queuedLifecycle[$postId] ?? null;
        $existingPriority = is_array($existing)
            ? (int) ($priority[(string) ($existing['action'] ?? '')] ?? 0)
            : 0;
        $nextPriority = (int) ($priority[$action] ?? 0);
        if ($existing !== null && $nextPriority < $existingPriority) {
            return;
        }

        // Không đẩy nội dung upsert cho cùng post khi đang trash/xóa.
        if ($action === 'trash' || $action === 'force_delete') {
            unset(self::$queuedPostIds[$postId]);
        }

        self::$queuedLifecycle[$postId] = [
            'action' => $action,
            'type' => $post->post_type === 'product' ? 'product' : 'article',
            'wp_post_type' => (string) $post->post_type,
        ];
    }

    public static function flush_queue(): void
    {
        if (
            self::$queuedPostIds === []
            && self::$queuedTermKeys === []
            && self::$queuedLifecycle === []
        ) {
            return;
        }

        $postIds = array_map('intval', array_keys(self::$queuedPostIds));
        $termKeys = array_keys(self::$queuedTermKeys);
        $lifecycle = self::$queuedLifecycle;

        self::$queuedPostIds = [];
        self::$queuedTermKeys = [];
        self::$queuedLifecycle = [];

        $canPush = self::can_push();
        if (! $canPush['ok']) {
            self::record_push_result(false, $canPush['message']);

            return;
        }

        $provider = new Sync_Provider();
        $items = [];

        foreach ($lifecycle as $wpId => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $items[] = [
                'wp_id' => (int) $wpId,
                'type' => (string) ($meta['type'] ?? 'article'),
                'wp_post_type' => (string) ($meta['wp_post_type'] ?? 'post'),
                'action' => (string) ($meta['action'] ?? 'trash'),
            ];
        }

        foreach ($postIds as $postId) {
            if (isset($lifecycle[$postId])) {
                continue;
            }
            $mapped = $provider->map_post_by_id((int) $postId);
            if (is_array($mapped)) {
                $items[] = $mapped;
            }
        }

        foreach ($termKeys as $key) {
            $parts = explode(':', (string) $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$taxonomy, $termId] = $parts;
            $mapped = $provider->map_term_by_id($taxonomy, (int) $termId);
            if (is_array($mapped)) {
                $items[] = $mapped;
            }
        }

        if ($items === []) {
            self::record_push_result(false, 'Không map được dữ liệu bài viết (post_id: ' . implode(',', $postIds) . ').');

            return;
        }

        self::send_items($items);
        self::send_snapshot_callbacks($postIds);
    }

    /**
     * @param list<int> $postIds
     */
    private static function send_snapshot_callbacks(array $postIds): void
    {
        $url = self::snapshot_callback_url();
        $readToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));
        if ($url === '' || $readToken === '') {
            return;
        }

        $v2 = new Site_Sync_V2_Provider();
        foreach ($postIds as $postId) {
            $payload = $v2->item_for_post((int) $postId);
            if ($payload === []) {
                continue;
            }
            $payload['site_url'] = home_url('/');
            $body = wp_json_encode($payload);
            if (! is_string($body)) {
                continue;
            }
            $args = [
                'timeout' => 20,
                'blocking' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $readToken,
                    'X-Seo-Read-Token' => $readToken,
                ],
                'body' => $body,
            ];
            wp_remote_post($url, $args);
        }
    }

    private static function snapshot_callback_url(): string
    {
        if (! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return '';
        }
        $base = omi_seo_ai_bridge_laravel_api_url();
        if ($base === '') {
            return '';
        }

        return $base . '/api/seo-wp-bridge/snapshot-callback';
    }

    /**
     * Đẩy ngay một bài (dùng từ trang cài đặt / REST test).
     *
     * @return array{success:bool,message:string,http_code?:int}
     */
    public static function push_post_now(int $postId): array
    {
        $canPush = self::can_push();
        if (! $canPush['ok']) {
            self::record_push_result(false, $canPush['message']);

            return ['success' => false, 'message' => $canPush['message']];
        }

        if (Sync_Provider::is_sync_excluded_post($postId)) {
            $message = 'Trang chủ WordPress không được đồng bộ lên Laravel.';
            self::record_push_result(false, $message);

            return ['success' => false, 'message' => $message];
        }

        $mapped = (new Sync_Provider())->map_post_by_id($postId);
        if (! is_array($mapped)) {
            $message = 'Không tìm thấy bài viết WP #' . $postId;
            self::record_push_result(false, $message);

            return ['success' => false, 'message' => $message];
        }

        return self::send_items([$mapped]);
    }

    /**
     * @return array{success:bool,message:string,http_code?:int}
     */
    public static function test_laravel_connection(): array
    {
        $canPush = self::can_push();
        if (! $canPush['ok']) {
            return ['success' => false, 'message' => $canPush['message']];
        }

        if (function_exists('omi_seo_ai_bridge_laravel_localhost_mismatch') && omi_seo_ai_bridge_laravel_localhost_mismatch()) {
            $warning = function_exists('omi_seo_ai_bridge_laravel_localhost_warning')
                ? omi_seo_ai_bridge_laravel_localhost_warning()
                : 'Không thể dùng localhost khi WordPress chạy trên hosting production.';

            return ['success' => false, 'message' => $warning];
        }

        $readToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));
        $url = omi_seo_ai_bridge_laravel_api_url() . '/api/seo-wp-bridge/ping?site_url=' . rawurlencode(home_url('/'));

        $args = [
            'timeout'  => 15,
            'blocking' => true,
            'headers'  => [
                'Accept'           => 'application/json',
                'Authorization'    => 'Bearer ' . $readToken,
                'X-Seo-Read-Token' => $readToken,
            ],
        ];

        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === 'localhost' || $host === '127.0.0.1' || substr($host, -5) === '.test') {
            $args['sslverify'] = false;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $message = 'Không kết nối được Laravel: ' . $response->get_error_message();
            if (function_exists('omi_seo_ai_bridge_laravel_localhost_mismatch') && omi_seo_ai_bridge_laravel_localhost_mismatch()) {
                $hint = function_exists('omi_seo_ai_bridge_laravel_localhost_warning')
                    ? omi_seo_ai_bridge_laravel_localhost_warning()
                    : '';
                if ($hint !== '') {
                    $message .= ' ' . $hint;
                }
            }

            return ['success' => false, 'message' => $message];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        $message = is_array($json) ? (string) ($json['message'] ?? $body) : $body;

        if ($code >= 200 && $code < 300 && is_array($json) && ($json['success'] ?? false)) {
            $connectionHash = trim((string) ($json['connection_hash'] ?? ''));
            if ($connectionHash !== '' && function_exists('omi_seo_ai_bridge_save_connection_hash')) {
                omi_seo_ai_bridge_save_connection_hash($connectionHash);
            }

            return ['success' => true, 'message' => $message, 'http_code' => $code];
        }

        return [
            'success' => false,
            'message' => 'HTTP ' . $code . ': ' . mb_substr($message, 0, 300),
            'http_code' => $code,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool,message:string,http_code?:int}
     */
    private static function send_items(array $items): array
    {
        $url = self::push_endpoint_url();
        $readToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));
        if ($url === '' || $readToken === '') {
            return ['success' => false, 'message' => 'Thiếu URL Laravel hoặc Read token.'];
        }

        $body = wp_json_encode([
            'site_url' => home_url('/'),
            'items'    => $items,
        ]);

        if (! is_string($body)) {
            return ['success' => false, 'message' => 'Không mã hóa JSON payload.'];
        }

        $args = [
            'timeout'  => 20,
            'blocking' => true,
            'headers'  => [
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'Authorization'    => 'Bearer ' . $readToken,
                'X-Seo-Read-Token' => $readToken,
            ],
            'body' => $body,
        ];

        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === 'localhost' || $host === '127.0.0.1' || substr($host, -5) === '.test') {
            $args['sslverify'] = false;
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $message = 'HTTP error: ' . $response->get_error_message();
            self::log_error($message, $items);
            self::record_push_result(false, $message);

            return ['success' => false, 'message' => $message];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        $json = json_decode($responseBody, true);
        $message = is_array($json) ? (string) ($json['message'] ?? $responseBody) : $responseBody;

        if ($code >= 200 && $code < 300 && is_array($json) && ($json['success'] ?? false)) {
            self::record_push_result(true, $message);

            return ['success' => true, 'message' => $message, 'http_code' => $code];
        }

        $failMessage = 'HTTP ' . $code . ': ' . mb_substr($message, 0, 500);
        self::log_error($failMessage, $items);
        self::record_push_result(false, $failMessage);

        return ['success' => false, 'message' => $failMessage, 'http_code' => $code];
    }

    private static function record_push_result(bool $success, string $message): void
    {
        update_option('omi_seo_last_push_at', gmdate('c'), false);
        update_option('omi_seo_last_push_success', $success ? '1' : '0', false);
        update_option('omi_seo_last_push_message', mb_substr($message, 0, 500), false);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function log_error(string $message, array $items): void
    {
        if (! (defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        $wpIds = array_map(static function (array $item): int {
            return (int) ($item['wp_id'] ?? 0);
        }, $items);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[omi-seo-ai-bridge] Laravel push failed: ' . $message . ' | wp_ids=' . implode(',', $wpIds));
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private static function can_push(): array
    {
        if (! function_exists('omi_seo_ai_bridge_is_connected') || ! omi_seo_ai_bridge_is_connected()) {
            return [
                'ok' => false,
                'message' => 'Chưa cấu hình đủ Read token và Write token trong SEO AI → Cài đặt.',
            ];
        }

        if (self::push_endpoint_url() === '') {
            return [
                'ok' => false,
                'message' => 'Chưa nhập LARAVEL API URL cho môi trường hiện tại (Dev hoặc Production).',
            ];
        }

        if (function_exists('omi_seo_ai_bridge_laravel_localhost_mismatch') && omi_seo_ai_bridge_laravel_localhost_mismatch()) {
            $warning = function_exists('omi_seo_ai_bridge_laravel_localhost_warning')
                ? omi_seo_ai_bridge_laravel_localhost_warning()
                : 'LARAVEL API URL localhost không dùng được trên WordPress production.';

            return ['ok' => false, 'message' => $warning];
        }

        return ['ok' => true, 'message' => ''];
    }

    private static function push_endpoint_url(): string
    {
        if (! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return '';
        }

        $base = omi_seo_ai_bridge_laravel_api_url();
        if ($base === '') {
            return '';
        }

        return $base . '/api/seo-wp-bridge/push-content';
    }
}
