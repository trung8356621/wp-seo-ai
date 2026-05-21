<?php
/**
 * Plugin Name:       OmiChannel SEO AI Bridge
 * Plugin URI:        https://example.com/omi-seo-ai-bridge
 * Description:       Kết nối WordPress với Laravel Omnichannel Backend để đồng bộ nội dung SEO AI.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Omnichannel
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omi-seo-ai-bridge
 *
 * @package OmiSeoAiBridge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('OMI_SEO_AI_BRIDGE_VERSION', '1.0.1');
define('OMI_SEO_AI_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('OMI_SEO_AI_BRIDGE_URL', plugin_dir_url(__FILE__));
define('OMI_SEO_AI_BRIDGE_OPTION_READ', 'omi_seo_read_token');
define('OMI_SEO_AI_BRIDGE_OPTION_WRITE', 'omi_seo_write_token');
define('OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL', 'omi_seo_laravel_api_url');

require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-seo-plugin-resolver.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-post-images-extractor.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-renamer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-sync-provider.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-comment-review-publisher.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-rest-controller.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-laravel-push-sync.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-faq-shortcode.php';

add_action('rest_api_init', static function (): void {
    \OmiSeoAiBridge\Rest_Controller::register();
});

add_action('init', static function (): void {
    \OmiSeoAiBridge\Laravel_Push_Sync::register();
    \OmiSeoAiBridge\Faq_Shortcode::register();
});

/**
 * Đăng ký menu admin & xử lý lưu cài đặt.
 */
add_action('admin_menu', static function (): void {
    add_menu_page(
        __('SEO AI', 'omi-seo-ai-bridge'),
        __('SEO AI', 'omi-seo-ai-bridge'),
        'manage_options',
        'omi-seo-ai',
        'omi_seo_ai_bridge_render_admin_page',
        'dashicons-networking',
        58
    );
});

/**
 * Callback trang plugin: lưu form (POST), enqueue CSS, include view theo ?view=.
 */
function omi_seo_ai_bridge_render_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'omi-seo-ai-bridge'));
    }

    if (
        isset($_POST['omi_seo_ai_bridge_save'], $_POST['_wpnonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'omi_seo_ai_bridge_save_settings')
    ) {
        $read  = isset($_POST['omi_seo_read_token']) ? sanitize_text_field(wp_unslash($_POST['omi_seo_read_token'])) : '';
        $write = isset($_POST['omi_seo_write_token']) ? sanitize_text_field(wp_unslash($_POST['omi_seo_write_token'])) : '';
        $laravelUrl = isset($_POST['omi_seo_laravel_api_url'])
            ? rtrim(sanitize_text_field(wp_unslash($_POST['omi_seo_laravel_api_url'])), '/')
            : '';

        update_option(OMI_SEO_AI_BRIDGE_OPTION_READ, $read, false);
        update_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, $write, false);
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL, $laravelUrl, false);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => 'omi-seo-ai',
                    'view'    => 'settings',
                    'updated' => '1',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    if (
        isset($_POST['omi_seo_test_laravel'], $_POST['_wpnonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'omi_seo_ai_bridge_save_settings')
    ) {
        $result = \OmiSeoAiBridge\Laravel_Push_Sync::test_laravel_connection();
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'       => 'omi-seo-ai',
                    'view'       => 'settings',
                    'test_result' => $result['success'] ? 'ok' : 'fail',
                    'test_msg'   => rawurlencode((string) ($result['message'] ?? '')),
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    if (
        isset($_POST['omi_seo_push_post_id'], $_POST['_wpnonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'omi_seo_ai_bridge_save_settings')
    ) {
        $postId = max(0, (int) $_POST['omi_seo_push_post_id']);
        $result = $postId > 0
            ? \OmiSeoAiBridge\Laravel_Push_Sync::push_post_now($postId)
            : ['success' => false, 'message' => 'Nhập ID bài viết / sản phẩm WP.'];
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'omi-seo-ai',
                    'view'        => 'settings',
                    'push_result' => $result['success'] ? 'ok' : 'fail',
                    'push_msg'    => rawurlencode((string) ($result['message'] ?? '')),
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    wp_enqueue_style(
        'omi-seo-ai-bridge-admin',
        OMI_SEO_AI_BRIDGE_URL . 'assets/admin.css',
        [],
        OMI_SEO_AI_BRIDGE_VERSION
    );

    $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';

    if ($view === 'settings') {
        require OMI_SEO_AI_BRIDGE_PATH . 'views/settings.php';
        return;
    }

    require OMI_SEO_AI_BRIDGE_PATH . 'views/welcome.php';
}

/**
 * Helper: đã cấu hình đủ 2 token (không rỗng).
 */
function omi_seo_ai_bridge_is_connected(): bool
{
    $read  = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, '');
    $write = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, '');

    return $read !== '' && $write !== '';
}

/**
 * URL gốc Laravel (không slash cuối), VD: https://api.example.com
 */
function omi_seo_ai_bridge_laravel_api_url(): string
{
    $url = trim((string) get_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL, ''));

    return $url !== '' ? rtrim($url, '/') : '';
}

/**
 * Đủ token + URL Laravel để tự đẩy đồng bộ khi tạo/sửa bài.
 */
function omi_seo_ai_bridge_auto_push_enabled(): bool
{
    return omi_seo_ai_bridge_is_connected() && omi_seo_ai_bridge_laravel_api_url() !== '';
}
