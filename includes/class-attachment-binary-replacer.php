<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ghi đè file ảnh attachment (giữ ID, slug, URL) — không lưu backup trên WordPress.
 */
final class Attachment_Binary_Replacer
{
    /**
     * @return array{success: bool, attachment_id?: int, url?: string, message: string}
     */
    public function replace(int $attachmentId, string $tempFilePath, string $mimeType = ''): array
    {
        if ($attachmentId <= 0 || ! is_file($tempFilePath)) {
            return [
                'success' => false,
                'message' => 'Attachment ID hoặc file tạm không hợp lệ.',
            ];
        }

        $attachment = get_post($attachmentId);
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return [
                'success' => false,
                'message' => 'Attachment không tồn tại.',
            ];
        }

        $target = get_attached_file($attachmentId);
        if (! is_string($target) || $target === '') {
            return [
                'success' => false,
                'message' => 'Không tìm thấy file vật lý trên WordPress.',
            ];
        }

        $dir = dirname($target);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return [
                'success' => false,
                'message' => 'Thư mục upload không ghi được.',
            ];
        }

        if (! @copy($tempFilePath, $target)) {
            return [
                'success' => false,
                'message' => 'Không ghi đè được file ảnh trên WordPress.',
            ];
        }

        if ($mimeType !== '') {
            wp_update_post([
                'ID' => $attachmentId,
                'post_mime_type' => $mimeType,
            ]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = wp_generate_attachment_metadata($attachmentId, $target);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        clean_attachment_cache($attachmentId);

        return [
            'success' => true,
            'attachment_id' => $attachmentId,
            'url' => (string) wp_get_attachment_url($attachmentId),
            'message' => 'Đã cập nhật file ảnh trên WordPress.',
        ];
    }
}
