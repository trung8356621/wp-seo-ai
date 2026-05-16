<?php
/**
 * Màn hình Settings – SEO AI Bridge
 *
 * @package OmiSeoAiBridge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_connected = function_exists('omi_seo_ai_bridge_is_connected') && omi_seo_ai_bridge_is_connected();
$welcome_url  = admin_url('admin.php?page=omi-seo-ai');
$read_value   = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, '');
$write_value  = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, '');
$show_saved   = isset($_GET['updated']) && $_GET['updated'] === '1';
?>
<div class="wrap omi-seo-ai-bridge-wrap">
    <div class="omi-seo-ai-bridge-card omi-seo-ai-bridge-card--wide">
        <a class="omi-seo-ai-bridge-back" href="<?php echo esc_url($welcome_url); ?>">
            <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
            <?php esc_html_e('Quay lại', 'omi-seo-ai-bridge'); ?>
        </a>

        <h2><?php esc_html_e('Trạng thái kết nối', 'omi-seo-ai-bridge'); ?></h2>
        <p class="omi-seo-ai-bridge-subtitle">
            <?php esc_html_e('Kết nối WordPress của bạn với hệ thống Laravel để đồng bộ nội dung tập trung.', 'omi-seo-ai-bridge'); ?>
        </p>

        <?php if ($show_saved) : ?>
            <div class="omi-seo-ai-bridge-notice" role="status">
                <?php esc_html_e('Đã lưu cài đặt.', 'omi-seo-ai-bridge'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>" class="omi-seo-ai-bridge-form">
            <?php wp_nonce_field('omi_seo_ai_bridge_save_settings'); ?>
            <input type="hidden" name="omi_seo_ai_bridge_save" value="1" />

            <div class="omi-seo-ai-bridge-row omi-seo-ai-bridge-row--status">
                <label><?php esc_html_e('Trạng thái hiện tại', 'omi-seo-ai-bridge'); ?></label>
                <div class="omi-seo-ai-bridge-status-row">
                    <?php if ($is_connected) : ?>
                        <span class="omi-seo-ai-bridge-badge omi-seo-ai-bridge-badge--ok">
                            <span class="omi-seo-ai-bridge-badge__dot" aria-hidden="true"></span>
                            <?php esc_html_e('ĐÃ KẾT NỐI', 'omi-seo-ai-bridge'); ?>
                        </span>
                    <?php else : ?>
                        <span class="omi-seo-ai-bridge-badge omi-seo-ai-bridge-badge--off">
                            <span class="omi-seo-ai-bridge-badge__dot" aria-hidden="true"></span>
                            <?php esc_html_e('CHƯA KẾT NỐI', 'omi-seo-ai-bridge'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

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

            <div class="omi-seo-ai-bridge-submit-wrap">
                <button type="submit" class="button omi-seo-ai-bridge-submit">
                    <?php esc_html_e('Cập nhật lại Api key', 'omi-seo-ai-bridge'); ?>
                </button>
            </div>
        </form>

        <p class="omi-seo-ai-bridge-footnote" style="margin-top: 24px;"><?php esc_html_e('Mã hóa bảo mật 256-bit', 'omi-seo-ai-bridge'); ?></p>
    </div>
</div>
