<?php
/**
 * Dọn dẹp revision WordPress cũ.
 *
 * @package OmiSeoAiBridge
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$total = \OmiSeoAiBridge\Revision_Manager::count_revisions();
$deleted = isset($_GET['deleted']) ? max(0, (int) $_GET['deleted']) : null;
$remaining = isset($_GET['remaining']) ? max(0, (int) $_GET['remaining']) : null;
$disabled = \OmiSeoAiBridge\Revision_Manager::is_disabled();
?>
<div class="wrap omi-seo-ai-bridge-wrap">
    <div class="omi-seo-ai-bridge-card">
        <div class="omi-seo-ai-bridge-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai')); ?>">
                ← TVH SEO AI
            </a>
        </div>

        <h2>Dọn dẹp Revision cũ</h2>
        <p class="omi-seo-ai-bridge-subtitle">
            Plugin TVH SEO AI đã tắt revision WordPress mặc định để giảm tải database.
            Trang này giúp xóa các bản revision còn sót lại từ trước.
        </p>

        <?php if ($deleted !== null) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        esc_html__('Đã xóa %1$d revision. Còn lại: %2$d.', 'omi-seo-ai-bridge'),
                        $deleted,
                        $remaining ?? $total
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p>
            <strong><?php esc_html_e('Trạng thái revision mới:', 'omi-seo-ai-bridge'); ?></strong>
            <?php if ($disabled) : ?>
                <?php esc_html_e('Đã tắt (không tạo revision mới).', 'omi-seo-ai-bridge'); ?>
            <?php else : ?>
                <?php esc_html_e('Đang bật revision WordPress.', 'omi-seo-ai-bridge'); ?>
            <?php endif; ?>
        </p>

        <p>
            <strong><?php esc_html_e('Revision còn trong database:', 'omi-seo-ai-bridge'); ?></strong>
            <?php echo esc_html((string) $total); ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('omi_seo_revision_cleanup'); ?>
            <p>
                <button type="submit" class="button button-primary" <?php disabled($total <= 0); ?>>
                    Xóa 500 revision cũ nhất
                </button>
            </p>
            <p class="description">
                Thao tác an toàn: chỉ xóa post_type <code>revision</code>, không đụng bài viết/sản phẩm.
                Chạy nhiều lần nếu còn revision.
            </p>
        </form>
    </div>
</div>
