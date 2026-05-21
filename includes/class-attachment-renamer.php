<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Đổi tên file attachment trên Media Library và thay URL cũ trong toàn bộ post_content.
 */
final class Attachment_Renamer
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{renamed: array<int, array<string, mixed>>, posts_updated: int, errors: array<int, array<string, mixed>>}
     */
    public function rename_batch(array $items): array
    {
        $renamed = [];
        $errors = [];
        $postsUpdated = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result = $this->rename_one($item);
            if ($result['success'] ?? false) {
                $renamed[] = $result;
                $postsUpdated += (int) ($result['posts_updated'] ?? 0);
            } else {
                $errors[] = $result;
            }
        }

        return [
            'renamed'        => $renamed,
            'posts_updated'  => $postsUpdated,
            'errors'         => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function rename_one(array $item): array
    {
        $attachmentId = (int) ($item['attachment_id'] ?? 0);
        $newSlug = $this->sanitize_slug((string) ($item['new_slug'] ?? ''));

        if ($attachmentId <= 0 || $newSlug === '') {
            return [
                'success' => false,
                'message' => 'Thiếu attachment_id hoặc new_slug.',
            ];
        }

        $attachment = get_post($attachmentId);
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Attachment không tồn tại.',
            ];
        }

        $oldUrl = trim((string) ($item['old_url'] ?? ''));
        if ($oldUrl === '') {
            $oldUrl = (string) wp_get_attachment_url($attachmentId);
        }

        $file = get_attached_file($attachmentId);
        if (! is_string($file) || $file === '' || ! file_exists($file)) {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Không tìm thấy file vật lý trên server.',
            ];
        }

        $pathInfo = pathinfo($file);
        $extension = isset($pathInfo['extension']) && $pathInfo['extension'] !== ''
            ? strtolower((string) $pathInfo['extension'])
            : '';

        $newFilename = $extension !== '' ? $newSlug . '.' . $extension : $newSlug;
        $newFile = trailingslashit((string) ($pathInfo['dirname'] ?? '')) . $newFilename;

        if ($newFile === $file) {
            $newUrl = (string) wp_get_attachment_url($attachmentId);

            return [
                'success'        => true,
                'attachment_id'  => $attachmentId,
                'old_url'        => $oldUrl,
                'new_url'        => $newUrl,
                'new_slug'       => $newSlug,
                'posts_updated'  => 0,
                'message'        => 'Slug không đổi.',
            ];
        }

        if (file_exists($newFile)) {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'File đích đã tồn tại: ' . $newFilename,
            ];
        }

        if (! @rename($file, $newFile)) {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Không đổi tên được file trên đĩa.',
            ];
        }

        $relative = _wp_relative_upload_path($newFile);
        if (is_string($relative) && $relative !== '') {
            update_attached_file($attachmentId, $relative);
        }

        $newUrl = (string) wp_get_attachment_url($attachmentId);

        wp_update_post([
            'ID'         => $attachmentId,
            'post_name'  => $newSlug,
            'guid'       => $newUrl,
        ]);

        clean_attachment_cache($attachmentId);

        $postsUpdated = 0;
        if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
            $postsUpdated = $this->replace_url_in_all_posts($oldUrl, $newUrl);
        }

        return [
            'success'       => true,
            'attachment_id' => $attachmentId,
            'old_url'       => $oldUrl,
            'new_url'       => $newUrl,
            'new_slug'      => $newSlug,
            'posts_updated' => $postsUpdated,
        ];
    }

    private function sanitize_slug(string $slug): string
    {
        $slug = sanitize_file_name($slug);
        $slug = preg_replace('/\.[a-z0-9]{1,8}$/i', '', $slug) ?? $slug;

        return trim((string) $slug, '-_');
    }

    private function replace_url_in_all_posts(string $oldUrl, string $newUrl): int
    {
        global $wpdb;

        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return 0;
        }

        $like = '%' . $wpdb->esc_like($oldUrl) . '%';

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts}
                SET post_content = REPLACE(post_content, %s, %s)
                WHERE post_content LIKE %s
                AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                AND post_status != 'auto-draft'",
                $oldUrl,
                $newUrl,
                $like
            )
        );

        return is_int($updated) ? $updated : 0;
    }
}
