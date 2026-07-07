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

use OmiSeoAiBridge\Missed_Schedule_Fixer;
use OmiSeoAiBridge\Wp_Cron_Disabler;

$is_connected = function_exists('omi_seo_ai_bridge_is_connected') && omi_seo_ai_bridge_is_connected();
$settings_url = admin_url('admin.php?page=omi-seo-ai&view=settings');
$missed_posts = Missed_Schedule_Fixer::list_missed_posts();
$missed_count = count($missed_posts);

$missed_published = isset($_GET['missed_published']) ? (int) $_GET['missed_published'] : null;
$missed_failed = isset($_GET['missed_failed']) ? (int) $_GET['missed_failed'] : null;
$missed_errors = isset($_GET['missed_errors']) ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['missed_errors']))) : '';
$missed_fixed = isset($_GET['missed_fixed']) ? sanitize_key((string) wp_unslash($_GET['missed_fixed'])) : '';
$missed_msg = isset($_GET['missed_msg']) ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['missed_msg']))) : '';
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
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=revision-cleanup')); ?>">
                Dọn dẹp Revision
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

        <?php if (Wp_Cron_Disabler::is_disabled()) : ?>
            <p class="omi-seo-ai-bridge-footnote">
                <?php esc_html_e('WP-Cron đã tắt bởi plugin — lịch đăng bài do Laravel xử lý.', 'omi-seo-ai-bridge'); ?>
            </p>
        <?php else : ?>
            <p class="omi-seo-ai-bridge-footnote"><?php esc_html_e('Mã hóa bảo mật 256-bit', 'omi-seo-ai-bridge'); ?></p>
        <?php endif; ?>
    </div>

    <div class="omi-seo-ai-bridge-card omi-seo-ai-missed-schedule-card">
        <h2><?php esc_html_e('Lịch trình bị bỏ lỡ', 'omi-seo-ai-bridge'); ?></h2>
        <p class="omi-seo-ai-bridge-subtitle">
            <?php esc_html_e('Các bài đã quá giờ đăng nhưng WordPress vẫn giữ trạng thái future. Đăng thẳng publish tại đây (không dùng WP-Cron).', 'omi-seo-ai-bridge'); ?>
        </p>

        <?php if ($missed_published !== null) : ?>
            <div class="omi-seo-ai-bridge-notice<?php echo ($missed_failed ?? 0) > 0 ? ' omi-seo-ai-bridge-notice--warn' : ''; ?>" role="status">
                <?php
                printf(
                    esc_html__('Đã đăng %1$d bài. Thất bại: %2$d.', 'omi-seo-ai-bridge'),
                    (int) $missed_published,
                    (int) ($missed_failed ?? 0)
                );
                if ($missed_errors !== '') {
                    echo ' ' . esc_html($missed_errors);
                }
                ?>
            </div>
        <?php elseif ($missed_fixed === '1' || $missed_fixed === '0') : ?>
            <div class="omi-seo-ai-bridge-notice<?php echo $missed_fixed === '0' ? ' omi-seo-ai-bridge-notice--warn' : ''; ?>" role="status">
                <?php echo esc_html($missed_msg !== '' ? $missed_msg : __('Đã xử lý bài viết.', 'omi-seo-ai-bridge')); ?>
            </div>
        <?php endif; ?>

        <?php if ($missed_count === 0) : ?>
            <div class="omi-seo-ai-bridge-notice omi-seo-ai-bridge-notice--info" role="status">
                <?php esc_html_e('Không có bài viết / sản phẩm nào bị bỏ lỡ lịch.', 'omi-seo-ai-bridge'); ?>
            </div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai')); ?>" class="omi-seo-ai-missed-schedule-actions">
                <?php wp_nonce_field('omi_seo_fix_missed_schedule'); ?>
                <button type="submit" name="omi_fix_all_missed" value="1" class="button button-primary">
                    <?php
                    printf(
                        esc_html__('Đăng tất cả (%d)', 'omi-seo-ai-bridge'),
                        $missed_count
                    );
                    ?>
                </button>
            </form>

            <table class="widefat striped omi-seo-ai-repair-table omi-seo-ai-missed-schedule-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Bài viết', 'omi-seo-ai-bridge'); ?></th>
                        <th><?php esc_html_e('Trạng thái', 'omi-seo-ai-bridge'); ?></th>
                        <th><?php esc_html_e('Giờ lên lịch', 'omi-seo-ai-bridge'); ?></th>
                        <th><?php esc_html_e('Thao tác', 'omi-seo-ai-bridge'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missed_posts as $item) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url((string) $item['edit_url']); ?>">
                                    <?php echo esc_html((string) $item['title']); ?>
                                </a>
                                <?php if (($item['post_type'] ?? '') === 'product') : ?>
                                    <span class="description">(<?php esc_html_e('Sản phẩm', 'omi-seo-ai-bridge'); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="omi-seo-ai-missed-status"><?php echo esc_html((string) $item['status_label']); ?></span>
                            </td>
                            <td><?php echo esc_html((string) $item['scheduled_at']); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('omi_seo_fix_missed_schedule'); ?>
                                    <input type="hidden" name="omi_fix_post_id" value="<?php echo esc_attr((string) $item['id']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e('Đăng ngay', 'omi-seo-ai-bridge'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
