<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap omi-seo-ai-bridge-wrap omi-seo-ai-repair-wrap">
    <div class="omi-seo-ai-bridge-card">
        <div class="omi-seo-ai-bridge-actions">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai')); ?>">Tổng quan</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>">Cài đặt</a>
        </div>

        <h2>Sửa toàn bộ ảnh phụ sai tên</h2>
        <p class="description">
            Quét Media Library để tìm ảnh phụ có basename khác file full. Khi sửa, plugin xóa các image size cũ,
            tạo lại bằng WordPress và cập nhật URL cũ trong nội dung bài viết.
        </p>

        <div class="omi-seo-ai-repair-actions">
            <button type="button" class="button button-primary" id="omi-repair-scan">Quét Media Library</button>
            <button type="button" class="button" id="omi-repair-run" disabled>Sửa tất cả ảnh lỗi</button>
        </div>

        <div id="omi-repair-status" class="omi-seo-ai-bridge-notice omi-seo-ai-bridge-notice--info" hidden></div>
        <progress id="omi-repair-progress" value="0" max="100" hidden></progress>

        <table class="widefat striped omi-seo-ai-repair-table" id="omi-repair-table" hidden>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Attachment</th>
                    <th>File full</th>
                    <th>Ảnh phụ sai tên</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script>
window.OMI_IMAGE_REPAIR = <?php echo wp_json_encode([
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('omi_repair_image_variants'),
]); ?>;
</script>
