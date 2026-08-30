<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ánh xạ kiểu nội dung native của WordPress (post type / taxonomy) sang
 * content_type chuẩn của SEO Ops: chỉ post | page | product.
 */
final class Content_Type_Map
{
    public const OPTION = 'omi_seo_content_type_map';

    /** Không được phát sinh giá trị thứ 4. */
    public const TARGETS = ['post', 'page', 'product'];

    public const FALLBACK = 'post';

    /**
     * Mặc định an toàn cho các native đã biết.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'post' => 'post',
        'page' => 'page',
        'product' => 'product',
        'category' => 'post',
        'post_tag' => 'post',
        'product_cat' => 'product',
        'product_tag' => 'product',
        'portfolio' => 'post',
        'landing_page' => 'page',
        'machine' => 'product',
    ];

    /**
     * Taxonomy hệ thống / kỹ thuật — không đưa vào bảng ánh xạ.
     *
     * @var list<string>
     */
    private const EXCLUDED_TAXONOMIES = [
        'post_format',
        'nav_menu',
        'link_category',
        'wp_theme',
        'wp_template_part_area',
        'wp_pattern_category',
        'product_visibility',
        'product_shipping_class',
        'product_type',
        'translation_priority',
    ];

    /**
     * Taxonomy luôn hiển thị nếu tồn tại (kể cả khi không public).
     *
     * @var list<string>
     */
    private const ALWAYS_TAXONOMIES = ['category', 'post_tag', 'product_cat', 'product_tag'];

    /** @var array<string, string>|null */
    private static ?array $savedCache = null;

    /**
     * Ánh xạ hiệu lực đầy đủ: mọi native phát hiện được => post|page|product.
     * Đây là map gửi lên Laravel trong Site Sync profile.
     *
     * @return array<string, string>
     */
    public static function get_content_type_map(): array
    {
        $map = [];

        foreach (self::discover_natives() as $native) {
            $slug = (string) $native['name'];
            $map[$slug] = self::resolve_content_type($slug, $native['kind'] === 'taxonomy');
        }

        // Override đã lưu cho native chưa/không còn discover được vẫn phải xuất hiện.
        foreach (self::saved_map() as $slug => $target) {
            $map[$slug] = $target;
        }

        ksort($map);

        return $map;
    }

    /**
     * Native slug => content_type chuẩn (post|page|product).
     */
    public static function resolve_content_type(string $native, bool $isTerm = false): string
    {
        $slug = strtolower(trim($native));
        if ($slug === '') {
            return self::FALLBACK;
        }

        $saved = self::saved_map();
        if (isset($saved[$slug])) {
            return $saved[$slug];
        }

        if (isset(self::DEFAULTS[$slug])) {
            return self::DEFAULTS[$slug];
        }

        $inherited = self::inherit_from_taxonomy_objects($slug, $isTerm);
        if ($inherited !== null) {
            return $inherited;
        }

        return self::FALLBACK;
    }

    /**
     * Native types có thể ánh xạ (post type public + taxonomy nội dung).
     *
     * @return list<array{name: string, label: string, kind: string, builtin: bool}>
     */
    public static function discover_natives(): array
    {
        $natives = [];

        foreach (Site_Sync_V2_Provider::public_content_post_types() as $postType) {
            $slug = (string) ($postType['name'] ?? '');
            if ($slug === '') {
                continue;
            }
            $natives[$slug] = [
                'name' => $slug,
                'label' => (string) ($postType['label'] ?? $slug),
                'kind' => 'post_type',
                'builtin' => (bool) ($postType['builtin'] ?? false),
            ];
        }

        foreach (self::discover_taxonomies() as $slug => $label) {
            if (isset($natives[$slug])) {
                continue;
            }
            $natives[$slug] = [
                'name' => $slug,
                'label' => $label,
                'kind' => 'taxonomy',
                'builtin' => in_array($slug, ['category', 'post_tag'], true),
            ];
        }

        return array_values($natives);
    }

    /**
     * Override do người dùng lưu (đã chuẩn hóa).
     *
     * @return array<string, string>
     */
    public static function saved_map(): array
    {
        if (self::$savedCache !== null) {
            return self::$savedCache;
        }

        $raw = get_option(self::OPTION, []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        self::$savedCache = is_array($raw) ? self::sanitize_map($raw) : [];

        return self::$savedCache;
    }

    /**
     * @param  array<string|int, mixed>  $raw
     */
    public static function save(array $raw): void
    {
        $sanitized = self::sanitize_map($raw);
        self::$savedCache = $sanitized;

        update_option(self::OPTION, $sanitized, false);
    }

    /**
     * @param  array<string|int, mixed>  $raw
     * @return array<string, string>
     */
    public static function sanitize_map(array $raw): array
    {
        $map = [];

        foreach ($raw as $native => $target) {
            $slug = strtolower(trim((string) $native));
            $value = strtolower(trim((string) $target));
            if ($slug === '' || ! in_array($value, self::TARGETS, true)) {
                continue;
            }
            $map[sanitize_key($slug)] = $value;
        }

        ksort($map);

        return $map;
    }

    /**
     * Taxonomy chưa có mặc định: kế thừa từ post type mà nó gắn vào,
     * chỉ khi mọi object_type quy về cùng một content_type.
     */
    private static function inherit_from_taxonomy_objects(string $slug, bool $isTerm): ?string
    {
        if (! $isTerm && ! taxonomy_exists($slug)) {
            return null;
        }

        $taxonomy = get_taxonomy($slug);
        if (! $taxonomy instanceof \WP_Taxonomy || ! is_array($taxonomy->object_type)) {
            return null;
        }

        $saved = self::saved_map();
        $targets = [];
        foreach ($taxonomy->object_type as $objectType) {
            $objectSlug = strtolower(trim((string) $objectType));
            $target = $saved[$objectSlug] ?? self::DEFAULTS[$objectSlug] ?? null;
            if ($target !== null) {
                $targets[$target] = true;
            }
        }

        return count($targets) === 1 ? (string) array_key_first($targets) : null;
    }

    /**
     * @return array<string, string> slug => label
     */
    private static function discover_taxonomies(): array
    {
        $found = [];

        if (did_action('init')) {
            $taxonomies = get_taxonomies(['public' => true], 'objects');
            foreach ($taxonomies as $taxonomy) {
                $slug = (string) $taxonomy->name;
                if ($slug === '' || in_array($slug, self::EXCLUDED_TAXONOMIES, true)) {
                    continue;
                }
                $found[$slug] = (string) ($taxonomy->label ?: $slug);
            }
        }

        foreach (self::ALWAYS_TAXONOMIES as $slug) {
            if (isset($found[$slug]) || ! taxonomy_exists($slug)) {
                continue;
            }
            $taxonomy = get_taxonomy($slug);
            $found[$slug] = $taxonomy instanceof \WP_Taxonomy ? (string) ($taxonomy->label ?: $slug) : $slug;
        }

        return $found;
    }
}
