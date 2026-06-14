<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Revision_Manager
{
    public const OPTION_DISABLED = 'omi_seo_revisions_disabled';

    public static function register(): void
    {
        if (self::is_disabled()) {
            self::disable_core_revisions();
        }

        add_action('admin_init', [self::class, 'handle_cleanup_request']);
    }

    public static function is_disabled(): bool
    {
        return get_option(self::OPTION_DISABLED, '1') === '1';
    }

    public static function disable_core_revisions(): void
    {
        add_filter('wp_revisions_to_keep', static fn (): int => 0, 999);
        add_filter('wp_revisions_enabled', static fn (): bool => false, 999);

        add_action('init', static function (): void {
            remove_action('post_updated', 'wp_save_post_revision');
            remove_action('publish_post', 'wp_save_post_revision');
            remove_action('save_post', 'wp_save_post_revision');
        }, 999);
    }

    public static function count_revisions(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );
    }

    /**
     * @return array{deleted: int, remaining: int}
     */
    public static function cleanup_batch(int $limit = 500): array
    {
        global $wpdb;

        $limit = max(1, min(2000, $limit));
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d",
                'revision',
                $limit
            )
        );

        $deleted = 0;
        if (is_array($ids)) {
            foreach ($ids as $revisionId) {
                $revisionId = (int) $revisionId;
                if ($revisionId <= 0) {
                    continue;
                }

                if (wp_delete_post_revision($revisionId) !== false) {
                    $deleted++;
                }
            }
        }

        return [
            'deleted' => $deleted,
            'remaining' => self::count_revisions(),
        ];
    }

    public static function handle_cleanup_request(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
        if ($page !== 'omi-seo-ai' || $view !== 'revision-cleanup') {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (! isset($_POST['_wpnonce'])) {
            return;
        }

        $nonce = sanitize_text_field((string) wp_unslash($_POST['_wpnonce']));
        if (! wp_verify_nonce($nonce, 'omi_seo_revision_cleanup')) {
            return;
        }

        $result = self::cleanup_batch(500);

        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'revision-cleanup',
            'deleted' => (string) $result['deleted'],
            'remaining' => (string) $result['remaining'],
        ], admin_url('admin.php')));
        exit;
    }
}
