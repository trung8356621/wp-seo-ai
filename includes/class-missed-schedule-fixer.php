<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Phát hiện và đăng thẳng bài WP bị "Lịch trình bị bỏ lỡ" (future + post_date đã qua).
 */
final class Missed_Schedule_Fixer
{
    /** @var list<string> */
    private const POST_TYPES = ['post', 'product'];

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'handle_fix_request']);
    }

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     edit_url: string,
     *     view_url: string,
     *     status_label: string,
     *     scheduled_at: string,
     *     post_type: string
     * }>
     */
    public static function list_missed_posts(int $limit = 200): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $nowGmt = current_time('mysql', true);
        $placeholders = implode(', ', array_fill(0, count(self::POST_TYPES), '%s'));

        $sql = $wpdb->prepare(
            "SELECT ID, post_title, post_date, post_type, post_status
             FROM {$wpdb->posts}
             WHERE post_status = 'future'
               AND post_date_gmt <= %s
               AND post_type IN ({$placeholders})
             ORDER BY post_date_gmt ASC
             LIMIT %d",
            array_merge([$nowGmt], self::POST_TYPES, [$limit])
        );

        $rows = $wpdb->get_results($sql);
        if (! is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $postId = (int) ($row->ID ?? 0);
            if ($postId <= 0) {
                continue;
            }

            $title = trim((string) ($row->post_title ?? ''));
            if ($title === '') {
                $title = sprintf('#%d', $postId);
            }

            $scheduledAt = (string) ($row->post_date ?? '');
            $items[] = [
                'id' => $postId,
                'title' => $title,
                'edit_url' => get_edit_post_link($postId, 'raw') ?: admin_url('post.php?post=' . $postId . '&action=edit'),
                'view_url' => get_permalink($postId) ?: '',
                'status_label' => self::status_label_for_missed(),
                'scheduled_at' => $scheduledAt,
                'post_type' => (string) ($row->post_type ?? 'post'),
            ];
        }

        return $items;
    }

    public static function count_missed_posts(): int
    {
        return count(self::list_missed_posts(500));
    }

    /**
     * @return array{success: bool, message: string, post_id?: int}
     */
    public static function publish_post(int $postId): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy bài viết.',
            ];
        }

        if (! in_array($post->post_type, self::POST_TYPES, true)) {
            return [
                'success' => false,
                'message' => 'Loại nội dung không được hỗ trợ.',
            ];
        }

        if ($post->post_status !== 'future') {
            return [
                'success' => false,
                'message' => 'Bài không còn ở trạng thái lên lịch.',
            ];
        }

        if (strtotime((string) $post->post_date_gmt . ' GMT') > time()) {
            return [
                'success' => false,
                'message' => 'Chưa đến giờ đăng theo lịch WordPress.',
            ];
        }

        Laravel_Push_Sync::suppress(true);
        try {
            $result = wp_update_post([
                'ID' => $postId,
                'post_status' => 'publish',
            ], true);
        } finally {
            Laravel_Push_Sync::suppress(false);
        }

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'message' => $result->get_error_message(),
            ];
        }

        clean_post_cache($postId);

        return [
            'success' => true,
            'message' => 'Đã đăng bài.',
            'post_id' => $postId,
        ];
    }

    /**
     * @return array{published: int, failed: int, errors: list<string>}
     */
    public static function publish_all_missed(int $limit = 200): array
    {
        $published = 0;
        $failed = 0;
        $errors = [];

        foreach (self::list_missed_posts($limit) as $item) {
            $result = self::publish_post((int) $item['id']);
            if ($result['success'] ?? false) {
                $published++;
                continue;
            }

            $failed++;
            $errors[] = sprintf(
                '#%d %s: %s',
                (int) $item['id'],
                (string) $item['title'],
                (string) ($result['message'] ?? 'Lỗi không xác định.')
            );
        }

        return [
            'published' => $published,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public static function handle_fix_request(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
        if ($page !== 'omi-seo-ai' || $view !== '') {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (! isset($_POST['_wpnonce'])) {
            return;
        }

        $nonce = sanitize_text_field((string) wp_unslash($_POST['_wpnonce']));
        if (! wp_verify_nonce($nonce, 'omi_seo_fix_missed_schedule')) {
            return;
        }

        $singleId = max(0, (int) ($_POST['omi_fix_post_id'] ?? 0));
        if ($singleId > 0) {
            $result = self::publish_post($singleId);
            wp_safe_redirect(add_query_arg([
                'page' => 'omi-seo-ai',
                'missed_fixed' => ($result['success'] ?? false) ? '1' : '0',
                'missed_msg' => rawurlencode((string) ($result['message'] ?? '')),
            ], admin_url('admin.php')));
            exit;
        }

        if (! isset($_POST['omi_fix_all_missed'])) {
            return;
        }

        $result = self::publish_all_missed();
        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'missed_published' => (string) $result['published'],
            'missed_failed' => (string) $result['failed'],
            'missed_errors' => rawurlencode(implode(' | ', array_slice($result['errors'], 0, 5))),
        ], admin_url('admin.php')));
        exit;
    }

    private static function status_label_for_missed(): string
    {
        return __('Lịch trình bị bỏ lỡ', 'omi-seo-ai-bridge');
    }
}
