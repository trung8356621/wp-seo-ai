<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$read_value    = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, '');
$write_value   = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, '');
$laravel_value = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_LARAVEL_URL, '');
$auto_push     = function_exists('omi_seo_ai_bridge_auto_push_enabled') && omi_seo_ai_bridge_auto_push_enabled();
$show_saved    = isset($_GET['updated']) && sanitize_key((string) wp_unslash($_GET['updated'])) === '1';
$last_push_at  = (string) get_option('omi_seo_last_push_at', '');
$last_push_ok  = (string) get_option('omi_seo_last_push_success', '');
$last_push_msg = (string) get_option('omi_seo_last_push_message', '');
$test_result   = isset($_GET['test_result']) ? sanitize_key((string) wp_unslash($_GET['test_result'])) : '';
$test_msg      = isset($_GET['test_msg']) ? sanitize_text_field((string) wp_unslash(rawurldecode((string) $_GET['test_msg']))) : '';
$push_result   = isset($_GET['push_result']) ? sanitize_key((string) wp_unslash($_GET['push_result'])) : '';
$push_msg      = isset($_GET['push_msg']) ? sanitize_text_field((string) wp_unslash(rawurldecode((string) $_GET['push_msg']))) : '';
$updatecheck_result = isset($_GET['updatecheck_result']) ? sanitize_key((string) wp_unslash($_GET['updatecheck_result'])) : '';
$updatecheck_msg = isset($_GET['updatecheck_msg']) ? sanitize_text_field((string) wp_unslash(rawurldecode((string) $_GET['updatecheck_msg']))) : '';
$localhost_warning = function_exists('omi_seo_ai_bridge_laravel_localhost_warning')
    ? omi_seo_ai_bridge_laravel_localhost_warning()
    : '';
?>

<div class="wrap omi-seo-ai-bridge-wrap">
    <div class="omi-seo-ai-bridge-card">
        <h2 style="margin:0 0 14px;"><?php esc_html_e('Cài đặt kết nối Laravel', 'omi-seo-ai-bridge'); ?></h2>

        <?php if ($localhost_warning !== '') : ?>
            <div class="omi-seo-ai-bridge-notice omi-seo-ai-bridge-notice--info" role="alert">
                <?php echo esc_html($localhost_warning); ?>
            </div>
        <?php endif; ?>

        <?php if ($show_saved) : ?>
            <div class="omi-seo-ai-bridge-notice" role="status">
                <?php esc_html_e('Đã lưu API key và URL Laravel.', 'omi-seo-ai-bridge'); ?>
            </div>
        <?php endif; ?>

        <?php if ($test_result !== '') : ?>
            <div class="omi-seo-ai-bridge-notice<?php echo $test_result === 'ok' ? '' : ' omi-seo-ai-bridge-notice--warn'; ?>" role="status">
                <?php echo esc_html($test_msg !== '' ? $test_msg : ($test_result === 'ok' ? __('Kết nối OK.', 'omi-seo-ai-bridge') : __('Kết nối thất bại.', 'omi-seo-ai-bridge'))); ?>
            </div>
        <?php endif; ?>

        <?php if ($push_result !== '') : ?>
            <div class="omi-seo-ai-bridge-notice<?php echo $push_result === 'ok' ? '' : ' omi-seo-ai-bridge-notice--warn'; ?>" role="status">
                <?php echo esc_html($push_msg !== '' ? $push_msg : ($push_result === 'ok' ? __('Đẩy thành công.', 'omi-seo-ai-bridge') : __('Đẩy thất bại.', 'omi-seo-ai-bridge'))); ?>
            </div>
        <?php endif; ?>

        <?php if ($updatecheck_result !== '') : ?>
            <div class="omi-seo-ai-bridge-notice<?php echo $updatecheck_result === 'ok' ? '' : ' omi-seo-ai-bridge-notice--warn'; ?>" role="status">
                <?php echo esc_html($updatecheck_msg !== '' ? $updatecheck_msg : __('Đã chạy kiểm tra update thủ công.', 'omi-seo-ai-bridge')); ?>
            </div>
        <?php endif; ?>

        <?php if ($last_push_at !== '') : ?>
            <p class="description" style="margin-bottom: 16px;">
                <?php
                printf(
                    /* translators: 1: time, 2: status, 3: message */
                    esc_html__('Lần đẩy gần nhất: %1$s — %2$s. %3$s', 'omi-seo-ai-bridge'),
                    esc_html($last_push_at),
                    $last_push_ok === '1' ? esc_html__('thành công', 'omi-seo-ai-bridge') : esc_html__('lỗi', 'omi-seo-ai-bridge'),
                    esc_html($last_push_msg)
                );
                ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>" class="omi-seo-ai-bridge-form">
            <?php wp_nonce_field('omi_seo_ai_bridge_save_settings'); ?>

            <div class="omi-seo-ai-bridge-row">
                <label for="omi_seo_read_token"><?php esc_html_e('API READ TOKEN', 'omi-seo-ai-bridge'); ?></label>
                <input
                    type="text"
                    name="omi_seo_read_token"
                    id="omi_seo_read_token"
                    class="omi-seo-ai-bridge-input"
                    value="<?php echo esc_attr($read_value); ?>"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>

            <div class="omi-seo-ai-bridge-row">
                <label for="omi_seo_write_token"><?php esc_html_e('API WRITE TOKEN', 'omi-seo-ai-bridge'); ?></label>
                <input
                    type="text"
                    name="omi_seo_write_token"
                    id="omi_seo_write_token"
                    class="omi-seo-ai-bridge-input"
                    value="<?php echo esc_attr($write_value); ?>"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>

            <div class="omi-seo-ai-bridge-row">
                <label for="omi_seo_laravel_api_url"><?php esc_html_e('LARAVEL API URL', 'omi-seo-ai-bridge'); ?></label>
                <input
                    type="url"
                    name="omi_seo_laravel_api_url"
                    id="omi_seo_laravel_api_url"
                    class="omi-seo-ai-bridge-input"
                    value="<?php echo esc_attr($laravel_value); ?>"
                    placeholder="http://127.0.0.1:8000"
                    autocomplete="off"
                    spellcheck="false"
                />
                <p class="description" style="margin-top: 8px;">
                    <?php esc_html_e('Production phải dùng domain/public URL của Laravel, không dùng localhost.', 'omi-seo-ai-bridge'); ?>
                </p>
            </div>

            <?php
            $rest_log_enabled = (bool) get_option('omi_seo_ai_rest_log', true);
            $rest_debug_enabled = (bool) get_option('omi_seo_ai_rest_debug', false);
            $rest_log_path = class_exists(\OmiSeoAiBridge\Rest_Debug::class)
                ? \OmiSeoAiBridge\Rest_Debug::log_path()
                : '';
            ?>
            <div class="omi-seo-ai-bridge-row">
                <label><?php esc_html_e('REST debug (OMI SEO AI)', 'omi-seo-ai-bridge'); ?></label>
                <label style="display:block;margin-bottom:6px;">
                    <input type="checkbox" name="omi_seo_rest_log" value="1" <?php checked($rest_log_enabled); ?> />
                    <?php esc_html_e('Ghi log REST vào wp-content/omi-seo-ai-rest.log', 'omi-seo-ai-bridge'); ?>
                </label>
                <label style="display:block;">
                    <input type="checkbox" name="omi_seo_rest_debug" value="1" <?php checked($rest_debug_enabled); ?> />
                    <?php esc_html_e('Trả chi tiết lỗi JSON khi WP_DEBUG hoặc bật tùy chọn này', 'omi-seo-ai-bridge'); ?>
                </label>
                <?php if ($rest_log_path !== '') : ?>
                    <p class="description" style="margin-top: 8px;">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: log file path */
                                __('Log file: %s', 'omi-seo-ai-bridge'),
                                $rest_log_path,
                            ),
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="omi-seo-ai-bridge-row omi-seo-ai-bridge-row--status">
                <label><?php esc_html_e('Tự đồng bộ khi lưu WP', 'omi-seo-ai-bridge'); ?></label>
                <div class="omi-seo-ai-bridge-status-row">
                    <?php if ($auto_push) : ?>
                        <span class="omi-seo-ai-bridge-badge omi-seo-ai-bridge-badge--ok">
                            <span class="omi-seo-ai-bridge-badge__dot" aria-hidden="true"></span>
                            <?php esc_html_e('BẬT', 'omi-seo-ai-bridge'); ?>
                        </span>
                    <?php else : ?>
                        <span class="omi-seo-ai-bridge-badge omi-seo-ai-bridge-badge--off">
                            <span class="omi-seo-ai-bridge-badge__dot" aria-hidden="true"></span>
                            <?php esc_html_e('TẮT', 'omi-seo-ai-bridge'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="omi-seo-ai-bridge-submit-wrap">
                <button type="submit" name="omi_seo_save_settings" value="1" class="button button-primary">
                    <?php esc_html_e('Cập nhật API key', 'omi-seo-ai-bridge'); ?>
                </button>
            </div>
        </form>

        <hr style="margin: 28px 0; border: 0; border-top: 1px solid #e5e7eb;" />

        <h3 style="margin: 0 0 12px;"><?php esc_html_e('Kiểm tra đồng bộ Laravel', 'omi-seo-ai-bridge'); ?></h3>
        <p class="description" style="margin-bottom: 16px;">
            <?php esc_html_e('Dùng http://127.0.0.1:8000 chỉ khi WordPress và Laravel cùng máy. Production phải dùng URL public.', 'omi-seo-ai-bridge'); ?>
        </p>

        <div class="omi-seo-ai-bridge-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>">
                <?php wp_nonce_field('omi_seo_ai_bridge_save_settings'); ?>
                <button type="submit" name="omi_seo_test_laravel" value="1" class="button button-secondary">
                    <?php esc_html_e('Kiểm tra kết nối Laravel', 'omi-seo-ai-bridge'); ?>
                </button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>">
                <?php wp_nonce_field('omi_seo_ai_bridge_save_settings'); ?>
                <button type="submit" name="omi_seo_manual_check_update" value="1" class="button button-secondary">
                    <?php esc_html_e('Check update thủ công', 'omi-seo-ai-bridge'); ?>
                </button>
            </form>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>" class="omi-seo-ai-bridge-form" style="max-width: 420px;">
            <?php wp_nonce_field('omi_seo_ai_bridge_save_settings'); ?>
            <div class="omi-seo-ai-bridge-row">
                <label for="omi_seo_push_post_id"><?php esc_html_e('Đẩy thử theo WP Post ID', 'omi-seo-ai-bridge'); ?></label>
                <input
                    type="number"
                    name="omi_seo_push_post_id"
                    id="omi_seo_push_post_id"
                    class="omi-seo-ai-bridge-input"
                    min="1"
                    placeholder="1246"
                />
            </div>
            <div class="omi-seo-ai-bridge-submit-wrap">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Đẩy ngay lên Laravel', 'omi-seo-ai-bridge'); ?>
                </button>
            </div>
        </form>
    </div>
</div>