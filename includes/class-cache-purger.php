<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Purge WordPress + page-cache plugins after a Laravel → WP mutation succeeds.
 *
 * Call once at the end of a successful REST write. Missing cache plugins are skipped.
 * Adapter failures never fail the Laravel sync.
 */
final class Cache_Purger
{
    /** @var array<string, true> */
    private static array $purgedKeys = [];

    /**
     * @var list<array{
     *   id: string,
     *   purge_post?: callable(int, string, list<string>): bool,
     *   purge_url?: callable(string): bool,
     *   purge_all?: callable(): bool
     * }>|null
     */
    private static ?array $adapters = null;

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function after_rest_success(int $status, mixed $payload, int $postId, array $context = []): array
    {
        if (! self::is_successful_write($status, $payload) || $postId <= 0) {
            return [
                'purged'    => false,
                'providers' => [],
            ];
        }

        return self::purge_post($postId, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function after_rest_success_url(int $status, mixed $payload, string $url, array $context = []): array
    {
        if (! self::is_successful_write($status, $payload) || $url === '') {
            return [
                'purged'    => false,
                'providers' => [],
            ];
        }

        return self::purge_url($url, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function after_rest_success_all(int $status, mixed $payload, array $context = []): array
    {
        if (! self::is_successful_write($status, $payload)) {
            return [
                'purged'    => false,
                'providers' => [],
            ];
        }

        return self::purge_all($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function purge_post(int $postId, array $context = []): array
    {
        try {
            return self::purge_post_internal($postId, $context);
        } catch (\Throwable $exception) {
            self::log_event('cache_purge_failed', [
                'post_id' => $postId,
                'error'   => $exception->getMessage(),
            ], true);

            return [
                'purged'    => false,
                'providers' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function purge_url(string $url, array $context = []): array
    {
        try {
            return self::purge_url_internal($url, $context);
        } catch (\Throwable $exception) {
            self::log_event('cache_purge_failed', [
                'url'   => $url,
                'error' => $exception->getMessage(),
            ], true);

            return [
                'purged'    => false,
                'providers' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    public static function purge_all(array $context = []): array
    {
        try {
            return self::purge_all_internal($context);
        } catch (\Throwable $exception) {
            self::log_event('cache_purge_failed', [
                'scope' => 'all',
                'error' => $exception->getMessage(),
            ], true);

            return [
                'purged'    => false,
                'providers' => [],
            ];
        }
    }

    /**
     * @param  list<array{
     *   id: string,
     *   purge_post?: callable(int, string, list<string>): bool,
     *   purge_url?: callable(string): bool,
     *   purge_all?: callable(): bool
     * }>|null  $adapters
     */
    public static function use_adapters(?array $adapters): void
    {
        self::$adapters = $adapters;
    }

    public static function reset(): void
    {
        self::$purgedKeys = [];
        self::$adapters = null;
    }

    public static function is_successful_write(int $status, mixed $payload): bool
    {
        if ($status < 200 || $status >= 300) {
            return false;
        }

        return is_array($payload) && ($payload['success'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    private static function purge_post_internal(int $postId, array $context): array
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return [
                'purged'    => false,
                'providers' => [],
            ];
        }

        $key = 'post:' . $postId;
        if (isset(self::$purgedKeys[$key])) {
            return [
                'purged'    => true,
                'providers' => [],
            ];
        }
        self::$purgedKeys[$key] = true;

        self::fire_action('omi_seo_ai_after_sync', $postId, $context);

        $url = self::resolve_post_url($postId);
        $extraUrls = self::normalize_urls($context['extra_urls'] ?? []);
        $providers = self::run_adapters('purge_post', $postId, $url, $extraUrls);

        self::log_event('cache_purge_after_sync', [
            'post_id'   => $postId,
            'providers' => implode(',', $providers),
            'source'    => (string) ($context['source'] ?? ''),
        ], false);

        self::fire_action('omi_seo_ai_cache_purged', $postId, $providers);

        return [
            'purged'    => true,
            'providers' => $providers,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    private static function purge_url_internal(string $url, array $context): array
    {
        $url = trim($url);
        if ($url === '') {
            return [
                'purged'    => false,
                'providers' => [],
            ];
        }

        $key = 'url:' . $url;
        if (isset(self::$purgedKeys[$key])) {
            return [
                'purged'    => true,
                'providers' => [],
            ];
        }
        self::$purgedKeys[$key] = true;

        $entityId = (int) ($context['term_id'] ?? $context['post_id'] ?? 0);
        self::fire_action('omi_seo_ai_after_sync', $entityId, $context);

        $providers = [];
        $termId = (int) ($context['term_id'] ?? 0);
        if ($termId > 0 && function_exists('clean_term_cache')) {
            $taxonomy = (string) ($context['taxonomy'] ?? '');
            if (function_exists('sanitize_key')) {
                $taxonomy = sanitize_key($taxonomy);
            }
            clean_term_cache($termId, $taxonomy);
            $providers[] = 'wordpress';
        }

        $providers = array_values(array_unique(array_merge(
            $providers,
            self::run_adapters('purge_url', 0, $url, []),
        )));

        self::log_event('cache_purge_after_sync', [
            'url'       => $url,
            'providers' => implode(',', $providers),
            'source'    => (string) ($context['source'] ?? ''),
        ], false);

        self::fire_action('omi_seo_ai_cache_purged', $entityId, $providers);

        return [
            'purged'    => true,
            'providers' => $providers,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{purged: bool, providers: list<string>}
     */
    private static function purge_all_internal(array $context): array
    {
        if (isset(self::$purgedKeys['all'])) {
            return [
                'purged'    => true,
                'providers' => [],
            ];
        }
        self::$purgedKeys['all'] = true;

        self::fire_action('omi_seo_ai_after_sync', 0, $context);

        $providers = self::run_adapters('purge_all', 0, '', []);

        self::log_event('cache_purge_after_sync', [
            'scope'     => 'all',
            'providers' => implode(',', $providers),
            'source'    => (string) ($context['source'] ?? ''),
        ], false);

        self::fire_action('omi_seo_ai_cache_purged', 0, $providers);

        return [
            'purged'    => true,
            'providers' => $providers,
        ];
    }

    /**
     * @param  'purge_post'|'purge_url'|'purge_all'  $method
     * @param  list<string>  $extraUrls
     * @return list<string>
     */
    private static function run_adapters(string $method, int $postId, string $url, array $extraUrls): array
    {
        $purged = [];

        foreach (self::adapters() as $adapter) {
            $id = (string) ($adapter['id'] ?? '');
            if ($id === '' || ! isset($adapter[$method]) || ! is_callable($adapter[$method])) {
                continue;
            }

            try {
                $ok = match ($method) {
                    'purge_post' => (bool) $adapter[$method]($postId, $url, $extraUrls),
                    'purge_url'  => (bool) $adapter[$method]($url),
                    'purge_all'  => (bool) $adapter[$method](),
                };
            } catch (\Throwable $exception) {
                self::log_event('cache_purge_provider_failed', [
                    'provider' => $id,
                    'post_id'  => $postId,
                    'error'    => $exception->getMessage(),
                ], true);
                continue;
            }

            if ($ok) {
                $purged[] = $id;
            }
        }

        return $purged;
    }

    /**
     * @return list<array{
     *   id: string,
     *   purge_post?: callable(int, string, list<string>): bool,
     *   purge_url?: callable(string): bool,
     *   purge_all?: callable(): bool
     * }>
     */
    private static function adapters(): array
    {
        if (self::$adapters !== null) {
            return self::$adapters;
        }

        return [
            [
                'id'         => 'wordpress',
                'purge_post' => [self::class, 'purge_wordpress_post'],
                'purge_url'  => [self::class, 'purge_wordpress_url'],
                'purge_all'  => [self::class, 'purge_wordpress_all'],
            ],
            [
                'id'         => 'wp_rocket',
                'purge_post' => [self::class, 'purge_wp_rocket_post'],
                'purge_url'  => [self::class, 'purge_wp_rocket_url'],
                'purge_all'  => [self::class, 'purge_wp_rocket_all'],
            ],
            [
                'id'         => 'litespeed',
                'purge_post' => [self::class, 'purge_litespeed_post'],
                'purge_url'  => [self::class, 'purge_litespeed_url'],
                'purge_all'  => [self::class, 'purge_litespeed_all'],
            ],
            [
                'id'         => 'w3_total_cache',
                'purge_post' => [self::class, 'purge_w3tc_post'],
                'purge_url'  => [self::class, 'purge_w3tc_url'],
                'purge_all'  => [self::class, 'purge_w3tc_all'],
            ],
            [
                'id'         => 'wp_super_cache',
                'purge_post' => [self::class, 'purge_wp_super_cache_post'],
                'purge_all'  => [self::class, 'purge_wp_super_cache_all'],
            ],
            [
                'id'        => 'autoptimize',
                'purge_all' => [self::class, 'purge_autoptimize_all'],
            ],
            [
                'id'         => 'siteground',
                'purge_post' => [self::class, 'purge_siteground_post'],
                'purge_url'  => [self::class, 'purge_siteground_url'],
                'purge_all'  => [self::class, 'purge_siteground_all'],
            ],
            [
                'id'         => 'flyingpress',
                'purge_post' => [self::class, 'purge_flyingpress_post'],
                'purge_url'  => [self::class, 'purge_flyingpress_url'],
                'purge_all'  => [self::class, 'purge_flyingpress_all'],
            ],
            [
                'id'        => 'breeze',
                'purge_all' => [self::class, 'purge_breeze_all'],
            ],
            [
                'id'         => 'cache_enabler',
                'purge_post' => [self::class, 'purge_cache_enabler_post'],
                'purge_url'  => [self::class, 'purge_cache_enabler_url'],
                'purge_all'  => [self::class, 'purge_cache_enabler_all'],
            ],
        ];
    }

    public static function purge_wordpress_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($url, $extraUrls);

        if (! function_exists('clean_post_cache')) {
            return false;
        }

        clean_post_cache($postId);

        return true;
    }

    public static function purge_wordpress_url(string $url): bool
    {
        unset($url);

        return false;
    }

    public static function purge_wordpress_all(): bool
    {
        return false;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_wp_rocket_post(int $postId, string $url, array $extraUrls): bool
    {
        $did = false;

        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($postId);
            $did = true;
        } elseif ($url !== '' && function_exists('rocket_clean_files')) {
            rocket_clean_files([$url]);
            $did = true;
        }

        if ($extraUrls !== [] && function_exists('rocket_clean_files')) {
            rocket_clean_files($extraUrls);
            $did = true;
        }

        return $did;
    }

    public static function purge_wp_rocket_url(string $url): bool
    {
        if ($url === '' || ! function_exists('rocket_clean_files')) {
            return false;
        }

        rocket_clean_files([$url]);

        return true;
    }

    public static function purge_wp_rocket_all(): bool
    {
        if (! function_exists('rocket_clean_domain')) {
            return false;
        }

        rocket_clean_domain();

        return true;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_litespeed_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($url);

        if (! self::litespeed_available()) {
            return false;
        }

        if (! function_exists('do_action')) {
            return false;
        }

        unset($url);

        do_action('litespeed_purge_post', $postId);
        foreach ($extraUrls as $extraUrl) {
            do_action('litespeed_purge_url', $extraUrl);
        }

        return true;
    }

    public static function purge_litespeed_url(string $url): bool
    {
        if ($url === '' || ! self::litespeed_available() || ! function_exists('do_action')) {
            return false;
        }

        do_action('litespeed_purge_url', $url);

        return true;
    }

    public static function purge_litespeed_all(): bool
    {
        if (! self::litespeed_available() || ! function_exists('do_action')) {
            return false;
        }

        do_action('litespeed_purge_all');

        return true;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_w3tc_post(int $postId, string $url, array $extraUrls): bool
    {
        $did = false;

        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($postId);
            $did = true;
        }

        if (function_exists('w3tc_flush_url')) {
            if ($url !== '') {
                w3tc_flush_url($url);
                $did = true;
            }
            foreach ($extraUrls as $extraUrl) {
                w3tc_flush_url($extraUrl);
                $did = true;
            }
        }

        return $did;
    }

    public static function purge_w3tc_url(string $url): bool
    {
        if ($url === '' || ! function_exists('w3tc_flush_url')) {
            return false;
        }

        w3tc_flush_url($url);

        return true;
    }

    public static function purge_w3tc_all(): bool
    {
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();

            return true;
        }

        if (function_exists('w3tc_flush_posts')) {
            w3tc_flush_posts();

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_wp_super_cache_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($url, $extraUrls);

        if (! function_exists('wp_cache_post_change')) {
            return false;
        }

        wp_cache_post_change($postId);

        return true;
    }

    public static function purge_wp_super_cache_all(): bool
    {
        if (! function_exists('wp_cache_clear_cache')) {
            return false;
        }

        wp_cache_clear_cache();

        return true;
    }

    public static function purge_autoptimize_all(): bool
    {
        if (! class_exists('autoptimizeCache') || ! method_exists('autoptimizeCache', 'clearall')) {
            return false;
        }

        \autoptimizeCache::clearall();

        return true;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_siteground_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($postId);

        $urls = $url !== '' ? array_merge([$url], $extraUrls) : $extraUrls;

        return self::purge_siteground_urls($urls);
    }

    public static function purge_siteground_url(string $url): bool
    {
        return self::purge_siteground_urls([$url]);
    }

    public static function purge_siteground_all(): bool
    {
        if (function_exists('sg_cachepress_purge_everything')) {
            sg_cachepress_purge_everything();

            return true;
        }

        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();

            return true;
        }

        $class = '\\SiteGround_Optimizer\\Supercacher\\Supercacher';
        if (class_exists($class) && method_exists($class, 'purge_cache')) {
            $class::purge_cache();

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_flyingpress_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($postId);

        $urls = $url !== '' ? array_merge([$url], $extraUrls) : $extraUrls;

        return self::purge_flyingpress_urls($urls);
    }

    public static function purge_flyingpress_url(string $url): bool
    {
        return self::purge_flyingpress_urls([$url]);
    }

    public static function purge_flyingpress_all(): bool
    {
        $class = '\\FlyingPress\\Purge';
        if (! class_exists($class)) {
            return false;
        }

        if (method_exists($class, 'purge_pages')) {
            $class::purge_pages();

            return true;
        }

        if (method_exists($class, 'purge_everything')) {
            $class::purge_everything();

            return true;
        }

        return false;
    }

    public static function purge_breeze_all(): bool
    {
        if (function_exists('has_action') && function_exists('do_action') && has_action('breeze_clear_all_cache')) {
            do_action('breeze_clear_all_cache');

            return true;
        }

        if (class_exists('Breeze_PurgeCache') && method_exists('Breeze_PurgeCache', 'breeze_cache_flush')) {
            \Breeze_PurgeCache::breeze_cache_flush();

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $extraUrls
     */
    public static function purge_cache_enabler_post(int $postId, string $url, array $extraUrls): bool
    {
        unset($url, $extraUrls);

        if (! self::cache_enabler_available() || ! function_exists('do_action')) {
            return false;
        }

        if (function_exists('has_action') && has_action('cache_enabler_clear_page_cache_by_post')) {
            do_action('cache_enabler_clear_page_cache_by_post', $postId);
        } else {
            do_action('cache_enabler_clear_page_cache_by_post_id', $postId);
        }

        return true;
    }

    public static function purge_cache_enabler_url(string $url): bool
    {
        if ($url === '' || ! self::cache_enabler_available() || ! function_exists('do_action')) {
            return false;
        }

        do_action('cache_enabler_clear_page_cache_by_url', $url);

        return true;
    }

    public static function purge_cache_enabler_all(): bool
    {
        if (! self::cache_enabler_available() || ! function_exists('do_action')) {
            return false;
        }

        do_action('cache_enabler_clear_complete_cache');

        return true;
    }

    /**
     * @param  list<string>  $urls
     */
    private static function purge_siteground_urls(array $urls): bool
    {
        $urls = self::normalize_urls($urls);
        if ($urls === []) {
            return false;
        }

        $class = '\\SiteGround_Optimizer\\Supercacher\\Supercacher';
        if (class_exists($class) && method_exists($class, 'purge_cache_request')) {
            foreach ($urls as $url) {
                $class::purge_cache_request($url);
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     */
    private static function purge_flyingpress_urls(array $urls): bool
    {
        $urls = self::normalize_urls($urls);
        if ($urls === []) {
            return false;
        }

        $class = '\\FlyingPress\\Purge';
        if (! class_exists($class) || ! method_exists($class, 'purge_urls')) {
            return false;
        }

        $class::purge_urls($urls);

        return true;
    }

    private static function litespeed_available(): bool
    {
        return defined('LSCWP_V')
            || class_exists('\\LiteSpeed\\Purge')
            || (function_exists('has_action') && has_action('litespeed_purge_post'));
    }

    private static function cache_enabler_available(): bool
    {
        return class_exists('Cache_Enabler')
            || (function_exists('has_action') && (
                has_action('cache_enabler_clear_page_cache_by_post')
                || has_action('cache_enabler_clear_page_cache_by_post_id')
                || has_action('cache_enabler_clear_complete_cache')
            ));
    }

    private static function resolve_post_url(int $postId): string
    {
        if (class_exists(Permalink_Resolver::class)) {
            $url = Permalink_Resolver::for_post($postId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (function_exists('get_permalink')) {
            $url = get_permalink($postId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param  mixed  $urls
     * @return list<string>
     */
    private static function normalize_urls(mixed $urls): array
    {
        if (! is_array($urls)) {
            return [];
        }

        $normalized = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  mixed  ...$args
     */
    private static function fire_action(string $hook, mixed ...$args): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        do_action($hook, ...$args);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function log_event(string $event, array $context, bool $always): void
    {
        if (! class_exists(Rest_Debug::class)) {
            return;
        }

        if (! $always && ! Rest_Debug::is_debug_enabled()) {
            return;
        }

        Rest_Debug::log($event, $context);
    }
}
