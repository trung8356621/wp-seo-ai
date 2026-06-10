<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

final class Redirection_Manager
{
    public const OPTION_ENABLED = 'omi_seo_ai_redirections_enabled';

    private const OPTION_ITEMS = 'omi_seo_ai_redirections';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybe_redirect'], 0);
        add_action('admin_init', [self::class, 'handle_admin_request']);
    }

    public static function enabled(): bool
    {
        return (string) get_option(self::OPTION_ENABLED, '1') === '1';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $items = get_option(self::OPTION_ITEMS, []);
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $source = self::normalize_source((string) ($item['source'] ?? ''));
            $target = self::normalize_target((string) ($item['target'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }

            $normalized[] = [
                'id' => sanitize_key((string) ($item['id'] ?? '')),
                'source' => $source,
                'target' => $target,
                'status_code' => self::normalize_status_code((int) ($item['status_code'] ?? 301)),
                'enabled' => (bool) ($item['enabled'] ?? true),
                'hits' => max(0, (int) ($item['hits'] ?? 0)),
                'post_id' => max(0, (int) ($item['post_id'] ?? 0)),
                'created_at' => sanitize_text_field((string) ($item['created_at'] ?? '')),
                'updated_at' => sanitize_text_field((string) ($item['updated_at'] ?? '')),
            ];
        }

        return array_values($normalized);
    }

    public static function add_auto(string $oldUrl, string $newUrl, int $postId): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $source = self::source_from_url($oldUrl);
        $target = self::normalize_target($newUrl);
        if ($source === '' || $target === '' || self::urls_are_equivalent($oldUrl, $newUrl)) {
            return false;
        }

        self::upsert([
            'source' => $source,
            'target' => $target,
            'status_code' => 301,
            'enabled' => true,
            'post_id' => $postId,
        ]);

        return true;
    }

    public static function maybe_redirect(): void
    {
        if (
            ! self::enabled()
            || is_admin()
            || wp_doing_ajax()
            || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
        ) {
            return;
        }

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '';
        $source = self::normalize_source($requestUri);
        if ($source === '') {
            return;
        }

        $items = self::all();
        foreach ($items as $index => $item) {
            if (! ($item['enabled'] ?? false) || ! hash_equals((string) $item['source'], $source)) {
                continue;
            }

            $target = self::absolute_target((string) $item['target']);
            if ($target === '' || self::urls_are_equivalent(home_url($source), $target)) {
                return;
            }

            $items[$index]['hits'] = (int) ($item['hits'] ?? 0) + 1;
            $items[$index]['updated_at'] = current_time('mysql');
            update_option(self::OPTION_ITEMS, $items, false);

            wp_redirect($target, self::normalize_status_code((int) $item['status_code']), 'TVH SEO AI Bridge');
            exit;
        }
    }

    public static function handle_admin_request(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
        if ($page !== 'omi-seo-ai' || $view !== 'redirections' || ! isset($_POST['omi_redirection_action'])) {
            return;
        }

        check_admin_referer('omi_seo_ai_redirections');

        $action = sanitize_key((string) wp_unslash($_POST['omi_redirection_action']));
        $message = 'saved';

        if ($action === 'save') {
            self::upsert([
                'id' => sanitize_key((string) wp_unslash($_POST['redirect_id'] ?? '')),
                'source' => (string) wp_unslash($_POST['source'] ?? ''),
                'target' => (string) wp_unslash($_POST['target'] ?? ''),
                'status_code' => (int) ($_POST['status_code'] ?? 301),
                'enabled' => isset($_POST['enabled']),
                'post_id' => (int) ($_POST['post_id'] ?? 0),
            ]);
        } elseif ($action === 'delete') {
            self::delete(sanitize_key((string) wp_unslash($_POST['redirect_id'] ?? '')));
            $message = 'deleted';
        } elseif ($action === 'toggle') {
            self::toggle(sanitize_key((string) wp_unslash($_POST['redirect_id'] ?? '')));
            $message = 'toggled';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'omi-seo-ai',
            'view' => 'redirections',
            'message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function upsert(array $data): void
    {
        $source = self::normalize_source((string) ($data['source'] ?? ''));
        $target = self::normalize_target((string) ($data['target'] ?? ''));
        if ($source === '' || $target === '') {
            return;
        }

        $items = self::all();
        $requestedId = sanitize_key((string) ($data['id'] ?? ''));
        $matchIndex = null;

        foreach ($items as $index => $item) {
            if (
                ($requestedId !== '' && hash_equals((string) $item['id'], $requestedId))
                || hash_equals((string) $item['source'], $source)
            ) {
                $matchIndex = $index;
                break;
            }
        }

        $now = current_time('mysql');
        $existing = $matchIndex !== null ? $items[$matchIndex] : [];
        $item = [
            'id' => (string) ($existing['id'] ?? wp_generate_uuid4()),
            'source' => $source,
            'target' => $target,
            'status_code' => self::normalize_status_code((int) ($data['status_code'] ?? 301)),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'hits' => (int) ($existing['hits'] ?? 0),
            'post_id' => max(0, (int) ($data['post_id'] ?? ($existing['post_id'] ?? 0))),
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        if ($matchIndex === null) {
            array_unshift($items, $item);
        } else {
            $items[$matchIndex] = $item;
        }

        update_option(self::OPTION_ITEMS, array_values($items), false);
    }

    private static function delete(string $id): void
    {
        if ($id === '') {
            return;
        }

        update_option(self::OPTION_ITEMS, array_values(array_filter(
            self::all(),
            static fn (array $item): bool => ! hash_equals((string) $item['id'], $id),
        )), false);
    }

    private static function toggle(string $id): void
    {
        if ($id === '') {
            return;
        }

        $items = self::all();
        foreach ($items as &$item) {
            if (hash_equals((string) $item['id'], $id)) {
                $item['enabled'] = ! (bool) $item['enabled'];
                $item['updated_at'] = current_time('mysql');
                break;
            }
        }
        unset($item);

        update_option(self::OPTION_ITEMS, $items, false);
    }

    private static function normalize_source(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $path = wp_parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '';
        }

        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : untrailingslashit($path);
    }

    private static function source_from_url(string $url): string
    {
        return self::normalize_source($url);
    }

    private static function normalize_target(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return '/' . ltrim($value, '/');
        }

        return esc_url_raw($value, ['http', 'https']);
    }

    private static function absolute_target(string $target): string
    {
        return str_starts_with($target, '/') ? home_url($target) : esc_url_raw($target);
    }

    private static function normalize_status_code(int $statusCode): int
    {
        return in_array($statusCode, [301, 302, 307, 308], true) ? $statusCode : 301;
    }

    private static function urls_are_equivalent(string $first, string $second): bool
    {
        return untrailingslashit(strtolower(trim($first))) === untrailingslashit(strtolower(trim($second)));
    }
}
