<?php
/**
 * Plugin Name:       TVH SEO AI Bridge
 * Description:       Kết nối WordPress với Laravel Omnichannel Backend để đồng bộ nội dung TVH SEO AI.
 * Version:           1.0.75
 * Author:            TVH
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('OMI_SEO_AI_BRIDGE_VERSION', '1.0.75');
define('OMI_SEO_AI_BRIDGE_OPTION_READ', 'omi_seo_read_token');
define('OMI_SEO_AI_BRIDGE_OPTION_WRITE', 'omi_seo_write_token');
define('OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL', 'omi_seo_laravel_api_url');
define('OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_DEV', 'omi_seo_laravel_api_url_dev');
define('OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_PRODUCTION', 'omi_seo_laravel_api_url_production');
define('OMI_SEO_AI_BRIDGE_OPTION_CONNECTION_HASH', 'omi_seo_connection_hash');
define('OMI_SEO_AI_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('OMI_SEO_AI_BRIDGE_URL', plugin_dir_url(__FILE__));
define('OMI_SEO_AI_BRIDGE_BASENAME', plugin_basename(__FILE__));

require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-seo-plugin-resolver.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/interface-seo-provider-adapter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/class-rank-math-provider-adapter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/class-yoast-provider-adapter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/class-aioseo-provider-adapter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/class-none-provider-adapter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/providers/class-seo-provider-adapter-registry.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-permalink-resolver.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-post-images-extractor.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-renamer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-variant-repair.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-binary-replacer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-sync-provider.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-capability-manifest.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-link-catalog-extractor.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-score-exporter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-site-sync-v2-provider.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-site-sync-outbox.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-polylang-sync.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-comment-review-publisher.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-schema-ld-exporter.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-virtual-comments.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-rest-debug.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-cache-purger.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-operation-store.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-github-release-client.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-bridge-update-service.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-link-health-engine.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-keyword-dictionary-store.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-local-seo-engine.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-rest-controller.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-laravel-push-sync.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-faq-shortcode.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-rank-math-faq-schema.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-admin-frontend-edit-link.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-redirection-manager.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-revision-manager.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-wp-cron-disabler.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-missed-schedule-fixer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-plugin-updater.php';

add_action('plugins_loaded', static function (): void {
    omi_seo_ai_bridge_maybe_migrate_laravel_url_options();
    \OmiSeoAiBridge\Plugin_Updater::boot(__FILE__);
}, 20);

add_action('rest_api_init', static function (): void {
    \OmiSeoAiBridge\Rest_Debug::register_fatal_logger();
    \OmiSeoAiBridge\Rest_Controller::register();
});

add_action('init', static function (): void {
    \OmiSeoAiBridge\Laravel_Push_Sync::register();
    \OmiSeoAiBridge\Site_Sync_Outbox::register();
    \OmiSeoAiBridge\Local_Seo_Engine::register();
    \OmiSeoAiBridge\Faq_Shortcode::register();
    \OmiSeoAiBridge\Rank_Math_Faq_Schema::register();
    \OmiSeoAiBridge\Virtual_Comments::register();
    \OmiSeoAiBridge\Admin_Frontend_Edit_Link::register();
    \OmiSeoAiBridge\Redirection_Manager::register();
    \OmiSeoAiBridge\Revision_Manager::register();
    \OmiSeoAiBridge\Wp_Cron_Disabler::register();
    \OmiSeoAiBridge\Missed_Schedule_Fixer::register();
});

add_action('admin_menu', static function (): void {
    add_menu_page(
        __('TVH SEO AI', 'omi-seo-ai-bridge'),
        __('TVH SEO AI', 'omi-seo-ai-bridge'),
        'manage_options',
        'omi-seo-ai',
        'omi_seo_ai_bridge_render_admin_page',
        'dashicons-networking',
        999
    );
}, 999);

add_filter('plugin_action_links_' . OMI_SEO_AI_BRIDGE_BASENAME, static function (array $links): array {
    $settingsUrl = admin_url('admin.php?page=omi-seo-ai&view=settings');
    $settingsLink = sprintf(
        '<a href="%s">%s</a>',
        esc_url($settingsUrl),
        esc_html__('Cài đặt', 'omi-seo-ai-bridge')
    );

    return array_merge([$settingsLink], $links);
});

add_action('admin_enqueue_scripts', static function (string $hook): void {
    unset($hook);

    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    if ($page !== 'omi-seo-ai') {
        return;
    }

    wp_enqueue_style(
        'omi-seo-ai-bridge-admin',
        OMI_SEO_AI_BRIDGE_URL . 'assets/admin.css',
        [],
        OMI_SEO_AI_BRIDGE_VERSION
    );

    if (isset($_GET['view']) && sanitize_key((string) wp_unslash($_GET['view'])) === 'repair-images') {
        wp_enqueue_script(
            'omi-seo-ai-image-variant-repair',
            OMI_SEO_AI_BRIDGE_URL . 'assets/image-variant-repair.js',
            [],
            OMI_SEO_AI_BRIDGE_VERSION,
            true
        );
    }
});

add_action('wp_ajax_omi_scan_image_variants', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Không có quyền truy cập.'], 403);
    }
    check_ajax_referer('omi_repair_image_variants', 'nonce');

    $page = max(1, (int) ($_POST['page'] ?? 1));
    wp_send_json_success((new \OmiSeoAiBridge\Attachment_Variant_Repair())->scan_page($page));
});

add_action('wp_ajax_omi_repair_image_variants', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Không có quyền truy cập.'], 403);
    }
    check_ajax_referer('omi_repair_image_variants', 'nonce');

    $rawIds = isset($_POST['ids']) ? (string) wp_unslash($_POST['ids']) : '';
    $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', $rawIds)))), 0, 10);
    $service = new \OmiSeoAiBridge\Attachment_Variant_Repair();
    $results = [];
    foreach ($ids as $attachmentId) {
        $results[] = $service->repair($attachmentId);
    }

    wp_send_json_success(['results' => $results]);
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'omi-seo-ai-align-fix',
        OMI_SEO_AI_BRIDGE_URL . 'assets/css/omi-align-fix.css',
        [],
        OMI_SEO_AI_BRIDGE_VERSION
    );
});

add_action('enqueue_block_editor_assets', static function (): void {
    wp_enqueue_style(
        'omi-seo-ai-align-fix-editor',
        OMI_SEO_AI_BRIDGE_URL . 'assets/css/omi-align-fix.css',
        [],
        OMI_SEO_AI_BRIDGE_VERSION
    );
});

add_filter('mce_css', static function (string $mceCss): string {
    $url = OMI_SEO_AI_BRIDGE_URL . 'assets/css/omi-align-fix.css';
    if (trim($mceCss) === '') {
        return $url;
    }

    return $mceCss . ',' . $url;
});

add_action('admin_init', static function (): void {
    if (! is_admin() || ! current_user_can('manage_options')) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
    if ($page !== 'omi-seo-ai' || $view !== 'settings') {
        return;
    }

    if (! isset($_POST['_wpnonce'])) {
        return;
    }
    $nonce = sanitize_text_field((string) wp_unslash($_POST['_wpnonce']));
    if (! wp_verify_nonce($nonce, 'omi_seo_ai_bridge_save_settings')) {
        return;
    }

    if (isset($_POST['omi_seo_save_settings'])) {
        $readToken = isset($_POST['omi_seo_read_token']) ? trim((string) wp_unslash($_POST['omi_seo_read_token'])) : '';
        $writeToken = isset($_POST['omi_seo_write_token']) ? trim((string) wp_unslash($_POST['omi_seo_write_token'])) : '';
        $laravelUrlDev = isset($_POST['omi_seo_laravel_api_url_dev'])
            ? omi_seo_ai_bridge_sanitize_laravel_url((string) wp_unslash($_POST['omi_seo_laravel_api_url_dev']))
            : '';
        $laravelUrlProduction = isset($_POST['omi_seo_laravel_api_url_production'])
            ? omi_seo_ai_bridge_sanitize_laravel_url((string) wp_unslash($_POST['omi_seo_laravel_api_url_production']))
            : '';

        update_option(OMI_SEO_AI_BRIDGE_OPTION_READ, $readToken, false);
        update_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, $writeToken, false);
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_DEV, $laravelUrlDev, false);
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_PRODUCTION, $laravelUrlProduction, false);
        update_option('omi_seo_ai_rest_log', isset($_POST['omi_seo_rest_log']) ? '1' : '0', false);
        update_option('omi_seo_ai_rest_debug', isset($_POST['omi_seo_rest_debug']) ? '1' : '0', false);
        update_option(
            \OmiSeoAiBridge\Admin_Frontend_Edit_Link::OPTION_ADMIN_BAR_ENABLED,
            isset($_POST['omi_seo_admin_bar_edit_enabled']) ? '1' : '0',
            false
        );
        update_option(
            \OmiSeoAiBridge\Redirection_Manager::OPTION_ENABLED,
            isset($_POST['omi_seo_redirections_enabled']) ? '1' : '0',
            false
        );

        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'settings',
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    if (isset($_POST['omi_seo_test_laravel'])) {
        $result = \OmiSeoAiBridge\Laravel_Push_Sync::test_laravel_connection();
        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'settings',
            'test_result' => ($result['success'] ?? false) ? 'ok' : 'fail',
            'test_msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    if (isset($_POST['omi_seo_push_post_id'])) {
        $postId = max(0, (int) ($_POST['omi_seo_push_post_id'] ?? 0));
        $result = $postId > 0
            ? \OmiSeoAiBridge\Laravel_Push_Sync::push_post_now($postId)
            : ['success' => false, 'message' => 'Nhập ID bài viết / sản phẩm WP.'];

        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'settings',
            'push_result' => ($result['success'] ?? false) ? 'ok' : 'fail',
            'push_msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    if (isset($_POST['omi_seo_manual_check_update'])) {
        wp_clean_plugins_cache(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $check = (new \OmiSeoAiBridge\Bridge_Update_Service())->check(true);
        $hasUpdate = (bool) ($check['update_available'] ?? false);
        $newVersion = (string) ($check['latest_version'] ?? '');
        $remoteStatus = (($check['ok'] ?? false) === true)
            ? ('GitHub OK'.($newVersion !== '' ? " (version {$newVersion})" : ''))
            : (string) ($check['message'] ?? 'Không đọc được GitHub Release.');

        $msg = $hasUpdate
            ? "Đã phát hiện bản cập nhật {$newVersion}. {$remoteStatus}"
            : "Chưa thấy bản cập nhật mới. {$remoteStatus}";

        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'settings',
            'updatecheck_result' => $hasUpdate ? 'ok' : 'fail',
            'updatecheck_msg' => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }
});

function omi_seo_ai_bridge_render_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'omi-seo-ai-bridge'));
    }

    $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'welcome';
    if ($view === 'settings') {
        include OMI_SEO_AI_BRIDGE_PATH . 'views/settings.php';
        return;
    }
    if ($view === 'repair-images') {
        include OMI_SEO_AI_BRIDGE_PATH . 'views/repair-image-variants.php';
        return;
    }
    if ($view === 'redirections') {
        include OMI_SEO_AI_BRIDGE_PATH . 'views/redirections.php';
        return;
    }
    if ($view === 'revision-cleanup') {
        include OMI_SEO_AI_BRIDGE_PATH . 'views/revision-cleanup.php';
        return;
    }

    include OMI_SEO_AI_BRIDGE_PATH . 'views/welcome.php';
}

function omi_seo_ai_bridge_is_connected(): bool
{
    $readToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, ''));
    $writeToken = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, ''));

    return $readToken !== '' && $writeToken !== '';
}

/**
 * Chuẩn hóa URL Laravel (bỏ slash cuối).
 */
function omi_seo_ai_bridge_sanitize_laravel_url(string $url): string
{
    $url = trim(sanitize_text_field($url));

    return $url !== '' ? rtrim($url, '/') : '';
}

/**
 * URL Laravel cho môi trường dev (localhost / .local / .test).
 */
function omi_seo_ai_bridge_laravel_api_url_dev(): string
{
    $url = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_DEV, ''));

    return $url !== '' ? rtrim($url, '/') : '';
}

/**
 * URL Laravel cho môi trường production (domain public).
 */
function omi_seo_ai_bridge_laravel_api_url_production(): string
{
    $url = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_PRODUCTION, ''));

    return $url !== '' ? rtrim($url, '/') : '';
}

/**
 * WordPress đang chạy dev/local hay production.
 */
function omi_seo_ai_bridge_is_dev_environment(): bool
{
    if (function_exists('wp_get_environment_type')) {
        $type = wp_get_environment_type();
        if (in_array($type, ['local', 'development'], true)) {
            return true;
        }
        if (in_array($type, ['production', 'staging'], true)) {
            return false;
        }
    }

    return omi_seo_ai_bridge_wp_on_loopback();
}

/**
 * URL gốc Laravel đang dùng theo môi trường WP (không slash cuối).
 */
function omi_seo_ai_bridge_laravel_api_url(): string
{
    $devUrl = omi_seo_ai_bridge_laravel_api_url_dev();
    $productionUrl = omi_seo_ai_bridge_laravel_api_url_production();

    if (omi_seo_ai_bridge_is_dev_environment()) {
        return $devUrl !== '' ? $devUrl : $productionUrl;
    }

    return $productionUrl !== '' ? $productionUrl : $devUrl;
}

/**
 * Mã workspace SEO (connection_hash) lưu sau khi ping Laravel thành công.
 */
function omi_seo_ai_bridge_connection_hash(): string
{
    $hash = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_CONNECTION_HASH, ''));

    return preg_match('/^[a-zA-Z0-9]{32,64}$/', $hash) ? $hash : '';
}

/**
 * @param  non-empty-string  $hash
 */
function omi_seo_ai_bridge_save_connection_hash(string $hash): void
{
    if (! preg_match('/^[a-zA-Z0-9]{32,64}$/', $hash)) {
        return;
    }

    update_option(OMI_SEO_AI_BRIDGE_OPTION_CONNECTION_HASH, $hash, false);
}

/**
 * URL gốc ứng dụng Laravel (bỏ /api) theo mode: null = active, dev, production.
 */
function omi_seo_ai_bridge_laravel_app_base_url(?string $mode = null): string
{
    $apiUrl = match ($mode) {
        'dev' => omi_seo_ai_bridge_laravel_api_url_dev(),
        'production' => omi_seo_ai_bridge_laravel_api_url_production(),
        default => omi_seo_ai_bridge_laravel_api_url(),
    };

    $base = rtrim(trim($apiUrl), '/');
    if ($base === '') {
        return '';
    }

    if (str_ends_with(strtolower($base), '/api')) {
        $base = substr($base, 0, -4);
    }

    return $base;
}

/**
 * Gọi ping Laravel để lấy connection_hash (cache 1 giờ nếu đã có).
 */
function omi_seo_ai_bridge_maybe_refresh_connection_hash(bool $force = false): bool
{
    if (! $force && omi_seo_ai_bridge_connection_hash() !== '') {
        return true;
    }

    if (! $force) {
        $lastAttempt = (int) get_transient('omi_seo_connection_hash_refresh');
        if ($lastAttempt > 0 && (time() - $lastAttempt) < HOUR_IN_SECONDS) {
            return omi_seo_ai_bridge_connection_hash() !== '';
        }
    }

    set_transient('omi_seo_connection_hash_refresh', time(), HOUR_IN_SECONDS);

    if (! class_exists(\OmiSeoAiBridge\Laravel_Push_Sync::class)) {
        return false;
    }

    $result = \OmiSeoAiBridge\Laravel_Push_Sync::test_laravel_connection();

    return ($result['success'] ?? false) && omi_seo_ai_bridge_connection_hash() !== '';
}

/**
 * Migrate option URL cũ (1 field) sang dev/production.
 */
function omi_seo_ai_bridge_maybe_migrate_laravel_url_options(): void
{
    $legacyUrl = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL, ''));
    if ($legacyUrl === '') {
        return;
    }

    $devUrl = omi_seo_ai_bridge_laravel_api_url_dev();
    $productionUrl = omi_seo_ai_bridge_laravel_api_url_production();
    if ($devUrl !== '' || $productionUrl !== '') {
        return;
    }

    $legacyUrl = rtrim($legacyUrl, '/');
    if (omi_seo_ai_bridge_is_loopback_host(omi_seo_ai_bridge_host_from_url($legacyUrl))) {
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_DEV, $legacyUrl, false);
    } else {
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL_PRODUCTION, $legacyUrl, false);
    }
}

/**
 * Đủ token + URL Laravel để tự đẩy đồng bộ khi tạo/sửa bài.
 */
function omi_seo_ai_bridge_auto_push_enabled(): bool
{
    return omi_seo_ai_bridge_is_connected() && omi_seo_ai_bridge_laravel_api_url() !== '';
}

/**
 * Host localhost / loopback (Laravel URL hoặc site WP).
 */
function omi_seo_ai_bridge_is_loopback_host(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    return str_ends_with($host, '.local') || str_ends_with($host, '.test');
}

function omi_seo_ai_bridge_host_from_url(string $url): string
{
    $host = wp_parse_url($url, PHP_URL_HOST);

    return is_string($host) ? strtolower($host) : '';
}

function omi_seo_ai_bridge_wp_on_loopback(): bool
{
    return omi_seo_ai_bridge_is_loopback_host(omi_seo_ai_bridge_host_from_url(home_url('/')));
}

/**
 * Production WP không thể gọi Laravel trên localhost máy dev.
 */
function omi_seo_ai_bridge_laravel_localhost_mismatch(): bool
{
    $laravelUrl = omi_seo_ai_bridge_laravel_api_url();
    if ($laravelUrl === '') {
        return false;
    }

    if (! omi_seo_ai_bridge_is_loopback_host(omi_seo_ai_bridge_host_from_url($laravelUrl))) {
        return false;
    }

    return ! omi_seo_ai_bridge_wp_on_loopback();
}

/**
 * Thông báo khi cấu hình localhost trên WP production.
 */
function omi_seo_ai_bridge_laravel_localhost_warning(): string
{
    if (! omi_seo_ai_bridge_laravel_localhost_mismatch()) {
        return '';
    }

    return sprintf(
        /* translators: 1: WP site URL, 2: Laravel URL example */
        __(
            'WordPress đang chạy tại %1$s — không thể kết nối tới Laravel qua localhost/127.0.0.1 vì đó là máy chủ hosting, không phải máy dev của bạn. Hãy nhập URL public vào field «LARAVEL API URL (Production)», hoặc dùng tunnel (ngrok, Cloudflare Tunnel) nếu cần trỏ tạm từ production.',
            'omi-seo-ai-bridge'
        ),
        home_url('/')
    );
}
