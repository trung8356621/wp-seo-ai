<?php
/**
 * Màn hình Welcome – SEO AI Bridge
 *
 * @package OmiSeoAiBridge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_connected = function_exists('omi_seo_ai_bridge_is_connected') && omi_seo_ai_bridge_is_connected();
$settings_url = admin_url('admin.php?page=omi-seo-ai&view=settings');
?>
<div class="wrap omi-seo-ai-bridge-wrap">
    <div class="omi-seo-ai-bridge-card">
        <div class="omi-seo-ai-bridge-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=redirections')); ?>">
                Chuyển hướng
            </a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=repair-images')); ?>">
                Sửa ảnh phụ sai tên
            </a>
        </div>
        <div class="omi-seo-ai-bridge-card__corner">
            <a href="<?php echo esc_url($settings_url); ?>" title="<?php esc_attr_e('Cài đặt', 'omi-seo-ai-bridge'); ?>" aria-label="<?php esc_attr_e('Cài đặt', 'omi-seo-ai-bridge'); ?>">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
            </a>
        </div>

        <div class="omi-seo-ai-bridge-icon-wrap">
            <span class="dashicons dashicons-networking" aria-hidden="true"></span>
        </div>

        <h2><?php esc_html_e('Trạng thái kết nối', 'omi-seo-ai-bridge'); ?></h2>
        <p class="omi-seo-ai-bridge-subtitle">
            <?php esc_html_e('Kết nối WordPress của bạn với hệ thống Omnichannel để quản lý nội dung tập trung và đồng bộ dữ liệu.', 'omi-seo-ai-bridge'); ?>
        </p>

        <div class="omi-seo-ai-bridge-status-row">
            <span class="omi-seo-ai-bridge-status-label"><?php esc_html_e('Trạng thái hiện tại:', 'omi-seo-ai-bridge'); ?></span>
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

        <p class="omi-seo-ai-bridge-footnote"><?php esc_html_e('Mã hóa bảo mật 256-bit', 'omi-seo-ai-bridge'); ?></p>
    </div>
</div>
