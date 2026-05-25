
 * Plugin Name:       TVH TVH SEO AI Bridge
 * Description:       Kết nối WordPress với Laravel Omnichannel Backend để đồng bộ nội dung TVH SEO AI.
 * Version:           1.0.6
 * Author:            TVH
define('OMI_SEO_AI_BRIDGE_VERSION', '1.0.6');
define('OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL', 'omi_seo_laravel_api_url');

require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-seo-plugin-resolver.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-post-images-extractor.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-renamer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-attachment-binary-replacer.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-sync-provider.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-comment-review-publisher.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-virtual-comments.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-rest-controller.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-laravel-push-sync.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-faq-shortcode.php';
require_once OMI_SEO_AI_BRIDGE_PATH . 'includes/class-plugin-updater.php';

add_action('plugins_loaded', static function (): void {
    \OmiSeoAiBridge\Plugin_Updater::boot(__FILE__);
}, 20);

add_action('rest_api_init', static function (): void {
    \OmiSeoAiBridge\Rest_Controller::register();
});

add_action('init', static function (): void {
    \OmiSeoAiBridge\Laravel_Push_Sync::register();
    \OmiSeoAiBridge\Faq_Shortcode::register();
    \OmiSeoAiBridge\Virtual_Comments::register();
});
        __('TVH SEO AI', 'omi-seo-ai-bridge'),
        __('TVH SEO AI', 'omi-seo-ai-bridge'),
        'dashicons-networking'
        $laravelUrl = isset($_POST['omi_seo_laravel_api_url'])
            ? rtrim(sanitize_text_field(wp_unslash($_POST['omi_seo_laravel_api_url'])), '/')
            : '';
        update_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL, $laravelUrl, false);

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