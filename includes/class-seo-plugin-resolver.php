<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Lấy SEO title, description, focus keyword từ Rank Math hoặc Yoast SEO (nếu có).
 */
final class Seo_Plugin_Resolver
{
    /**
     * Thông tin plugin SEO cấp site (gọi một lần trước đồng bộ, không lưu theo từng bài).
     *
     * @return array{
     *   active: string,
     *   rank_math: array{installed: bool, version: string|null},
     *   yoast: array{installed: bool, version: string|null},
     *   meta_keys: array{post: array<string, array<int, string>>, term: array<string, array<int, string>>},
     *   wordpress_version: string,
     *   site_url: string,
     *   bridge_version: string,
     *   permalink: array{
     *     structure: string,
     *     category_base: string,
     *     tag_base: string,
     *     woocommerce?: array{product_base: string, category_base: string, tag_base: string}
     *   }
     * }
     */
    public static function site_info(): array
    {
        $rankMathInstalled = self::is_rank_math_active();
        $yoastInstalled = self::is_yoast_active();

        $active = 'none';
        if ($rankMathInstalled) {
            $active = 'rank_math';
        } elseif ($yoastInstalled) {
            $active = 'yoast';
        }

        return [
            'active'            => $active,
            'rank_math'         => [
                'installed' => $rankMathInstalled,
                'version'   => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : null,
            ],
            'yoast'             => [
                'installed' => $yoastInstalled,
                'version'   => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : null,
            ],
            'meta_keys'         => [
                'post' => [
                    'seo_title'        => ['rank_math_title', '_rank_math_title', '_yoast_wpseo_title'],
                    'meta_description' => ['rank_math_description', '_rank_math_description', '_yoast_wpseo_metadesc'],
                    'focus_keyword'    => ['rank_math_focus_keyword', '_rank_math_focus_keyword', '_yoast_wpseo_focuskw'],
                ],
                'term' => [
                    'seo_title'        => ['rank_math_title', 'rank_math_seo_title', 'wpseo_title'],
                    'meta_description' => ['rank_math_description', 'wpseo_desc'],
                    'focus_keyword'    => ['rank_math_focus_keyword'],
                ],
            ],
            'wordpress_version' => (string) get_bloginfo('version'),
            'site_url'          => (string) home_url('/'),
            'bridge_version'    => defined('OMI_SEO_AI_BRIDGE_VERSION') ? (string) OMI_SEO_AI_BRIDGE_VERSION : '',
            'permalink'         => self::permalink_settings(),
            'polylang'          => Polylang_Sync::site_info(),
        ];
    }

    /**
     * Cấu trúc permalink WordPress (Settings → Permalinks) để Laravel ghép URL khi sync trả ?p=ID.
     *
     * @return array{
     *   structure: string,
     *   category_base: string,
     *   tag_base: string,
     *   woocommerce?: array{product_base: string, category_base: string, tag_base: string}
     * }
     */
    public static function permalink_settings(): array
    {
        $settings = [
            'structure'     => (string) get_option('permalink_structure'),
            'category_base' => (string) get_option('category_base'),
            'tag_base'      => (string) get_option('tag_base'),
            'templates_version' => 1,
            'templates'     => [
                'post' => self::sample_post_permalink_template('post'),
                'category' => self::sample_term_permalink_template('category'),
                'product' => self::sample_post_permalink_template('product'),
                'product_category' => self::sample_term_permalink_template('product_cat'),
            ],
        ];

        if (! class_exists('WooCommerce') && ! function_exists('WC') && ! post_type_exists('product')) {
            return $settings;
        }

        $wc = get_option('woocommerce_permalinks');
        if (! is_array($wc)) {
            return $settings;
        }

        $settings['woocommerce'] = [
            'product_base'  => (string) ($wc['product_base'] ?? ''),
            'category_base' => (string) ($wc['category_base'] ?? ''),
            'tag_base'      => (string) ($wc['tag_base'] ?? ''),
        ];

        return $settings;
    }

    private static function sample_post_permalink_template(string $postType): string
    {
        if (! post_type_exists($postType)) {
            return '';
        }

        global $wpdb;
        $postId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY ID DESC LIMIT 1",
            $postType,
        ));
        if ($postId <= 0) {
            return '';
        }

        $slug = trim((string) get_post_field('post_name', $postId));
        $url = Permalink_Resolver::for_post($postId);

        return self::replace_url_slug_with_token($url, $slug);
    }

    private static function sample_term_permalink_template(string $taxonomy): string
    {
        if (! taxonomy_exists($taxonomy)) {
            return '';
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 1,
            'orderby' => 'term_id',
            'order' => 'DESC',
        ]);
        $term = is_array($terms) ? ($terms[0] ?? null) : null;
        if (! $term instanceof \WP_Term) {
            return '';
        }

        $url = Permalink_Resolver::for_term($term);

        return self::replace_url_slug_with_token($url, (string) $term->slug);
    }

    private static function replace_url_slug_with_token(string $url, string $slug): string
    {
        $url = trim($url);
        $slug = trim($slug);
        if ($url === '' || $slug === '') {
            return '';
        }

        $encodedSlug = rawurlencode($slug);
        $position = strrpos($url, '/' . $encodedSlug);
        $matchedSlug = $encodedSlug;
        if ($position === false) {
            $position = strrpos($url, '/' . $slug);
            $matchedSlug = $slug;
        }
        if ($position === false) {
            return '';
        }

        $prefixLength = $position + 1;

        return substr($url, 0, $prefixLength) . '%slug%' . substr($url, $prefixLength + strlen($matchedSlug));
    }

    public static function is_rank_math_active(): bool
    {
        return defined('RANK_MATH_VERSION')
            || class_exists('\RankMath\Helper')
            || function_exists('rank_math');
    }

    public static function is_yoast_active(): bool
    {
        return defined('WPSEO_VERSION')
            || class_exists('\WPSEO_Meta')
            || function_exists('wpseo_init');
    }

    /**
     * @return array{
     *   plugin: string,
     *   seo_title: string,
     *   meta_description: string,
     *   focus_keyword: string
     * }
     */
    public static function for_post(int $postId): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return self::empty_payload();
        }

        if (self::is_rank_math_active()) {
            $payload = self::from_rank_math_post($postId, $post);
            if ($payload['seo_title'] !== '' || $payload['meta_description'] !== '' || $payload['focus_keyword'] !== '') {
                $payload['plugin'] = 'rank_math';

                return $payload;
            }
        }

        if (self::is_yoast_active()) {
            $payload = self::from_yoast_post($postId, $post);
            if ($payload['seo_title'] !== '' || $payload['meta_description'] !== '' || $payload['focus_keyword'] !== '') {
                $payload['plugin'] = 'yoast';

                return $payload;
            }
        }

        return [
            'plugin'           => 'none',
            'seo_title'        => (string) get_the_title($post),
            'meta_description' => self::fallback_excerpt($post),
            'focus_keyword'    => '',
        ];
    }

    /**
     * @return array{
     *   plugin: string,
     *   seo_title: string,
     *   meta_description: string,
     *   focus_keyword: string
     * }
     */
    public static function for_term(int $termId, string $taxonomy): array
    {
        $term = get_term($termId, $taxonomy);
        if (! $term instanceof \WP_Term) {
            return self::empty_payload();
        }

        if (self::is_rank_math_active()) {
            $payload = self::from_rank_math_term($termId, $term);
            if ($payload['seo_title'] !== '' || $payload['meta_description'] !== '' || $payload['focus_keyword'] !== '') {
                $payload['plugin'] = 'rank_math';

                return $payload;
            }
        }

        if (self::is_yoast_active()) {
            $payload = self::from_yoast_term($termId, $term);
            if ($payload['seo_title'] !== '' || $payload['meta_description'] !== '' || $payload['focus_keyword'] !== '') {
                $payload['plugin'] = 'yoast';

                return $payload;
            }
        }

        return [
            'plugin'           => 'none',
            'seo_title'        => (string) $term->name,
            'meta_description' => wp_strip_all_tags((string) $term->description),
            'focus_keyword'    => '',
        ];
    }

    /**
     * @return array{plugin:string,seo_title:string,meta_description:string,focus_keyword:string}
     */
    private static function from_rank_math_post(int $postId, \WP_Post $post): array
    {
        $title = self::meta_value($postId, [
            'rank_math_title',
            '_rank_math_title',
        ]);
        $description = self::meta_value($postId, [
            'rank_math_description',
            '_rank_math_description',
        ]);
        $keyword = self::meta_value($postId, [
            'rank_math_focus_keyword',
            '_rank_math_focus_keyword',
        ]);
        if ($keyword === '' && class_exists('\RankMath\Helper')) {
            $keyword = trim((string) \RankMath\Helper::get_post_meta('focus_keyword', $postId));
        }

        if ($title === '') {
            $title = (string) get_the_title($post);
        }
        if ($description === '') {
            $description = self::fallback_excerpt($post);
        }

        return [
            'plugin'           => 'rank_math',
            'seo_title'        => $title,
            'meta_description' => $description,
            'focus_keyword'    => $keyword,
        ];
    }

    /**
     * @return array{plugin:string,seo_title:string,meta_description:string,focus_keyword:string}
     */
    private static function from_yoast_post(int $postId, \WP_Post $post): array
    {
        $title = self::meta_value($postId, [
            '_yoast_wpseo_title',
        ]);
        $description = self::meta_value($postId, [
            '_yoast_wpseo_metadesc',
        ]);
        $keyword = self::meta_value($postId, [
            '_yoast_wpseo_focuskw',
        ]);

        if ($title === '' && class_exists('\WPSEO_Meta')) {
            $title = (string) \WPSEO_Meta::get_value('title', $postId);
        }
        if ($description === '' && class_exists('\WPSEO_Meta')) {
            $description = (string) \WPSEO_Meta::get_value('metadesc', $postId);
        }
        if ($keyword === '' && class_exists('\WPSEO_Meta')) {
            $keyword = (string) \WPSEO_Meta::get_value('focuskw', $postId);
        }

        if ($title === '') {
            $title = (string) get_the_title($post);
        }
        if ($description === '') {
            $description = self::fallback_excerpt($post);
        }

        return [
            'plugin'           => 'yoast',
            'seo_title'        => $title,
            'meta_description' => $description,
            'focus_keyword'    => $keyword,
        ];
    }

    /**
     * @return array{plugin:string,seo_title:string,meta_description:string,focus_keyword:string}
     */
    private static function from_rank_math_term(int $termId, \WP_Term $term): array
    {
        $title = (string) get_term_meta($termId, 'rank_math_title', true);
        if ($title === '') {
            $title = (string) get_term_meta($termId, 'rank_math_seo_title', true);
        }
        $description = (string) get_term_meta($termId, 'rank_math_description', true);
        $keyword = (string) get_term_meta($termId, 'rank_math_focus_keyword', true);

        if ($title === '') {
            $title = (string) $term->name;
        }

        return [
            'plugin'           => 'rank_math',
            'seo_title'        => $title,
            'meta_description' => $description !== '' ? $description : wp_strip_all_tags((string) $term->description),
            'focus_keyword'    => $keyword,
        ];
    }

    /**
     * @return array{plugin:string,seo_title:string,meta_description:string,focus_keyword:string}
     */
    private static function from_yoast_term(int $termId, \WP_Term $term): array
    {
        $title = (string) get_term_meta($termId, 'wpseo_title', true);
        $description = (string) get_term_meta($termId, 'wpseo_desc', true);
        $keyword = '';

        if ($title === '') {
            $title = (string) $term->name;
        }

        return [
            'plugin'           => 'yoast',
            'seo_title'        => $title,
            'meta_description' => $description !== '' ? $description : wp_strip_all_tags((string) $term->description),
            'focus_keyword'    => $keyword,
        ];
    }

    /**
     * @param array<int, string> $keys
     */
    private static function meta_value(int $postId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = get_post_meta($postId, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private static function fallback_excerpt(\WP_Post $post): string
    {
        $excerpt = (string) get_the_excerpt($post);
        if ($excerpt !== '') {
            return $excerpt;
        }

        return wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40, '...');
    }

    /**
     * Ghi SEO title, meta description, focus keyword lên post (Rank Math hoặc Yoast).
     *
     * @param  array<string, mixed>  $seo
     */
    public static function apply_to_post(int $postId, array $seo): bool
    {
        if ($postId <= 0 || $seo === []) {
            return false;
        }

        $seoTitle = array_key_exists('seo_title', $seo)
            ? trim((string) $seo['seo_title'])
            : null;
        $metaDescription = array_key_exists('meta_description', $seo)
            ? trim((string) $seo['meta_description'])
            : null;
        $focusKeyword = array_key_exists('focus_keyword', $seo)
            ? trim((string) $seo['focus_keyword'])
            : null;

        if ($seoTitle === null && $metaDescription === null && $focusKeyword === null) {
            return false;
        }

        if (self::is_rank_math_active()) {
            return self::apply_rank_math_post($postId, $seoTitle, $metaDescription, $focusKeyword);
        }

        if (self::is_yoast_active()) {
            return self::apply_yoast_post($postId, $seoTitle, $metaDescription, $focusKeyword);
        }

        return false;
    }

    /**
     * Ghi SEO title, meta description, focus keyword lên term taxonomy (Rank Math hoặc Yoast).
     *
     * @param  array<string, mixed>  $seo
     */
    public static function apply_to_term(int $termId, array $seo): bool
    {
        if ($termId <= 0 || $seo === []) {
            return false;
        }

        $seoTitle = array_key_exists('seo_title', $seo)
            ? trim((string) $seo['seo_title'])
            : null;
        $metaDescription = array_key_exists('meta_description', $seo)
            ? trim((string) $seo['meta_description'])
            : null;
        $focusKeyword = array_key_exists('focus_keyword', $seo)
            ? trim((string) $seo['focus_keyword'])
            : null;

        if ($seoTitle === null && $metaDescription === null && $focusKeyword === null) {
            return false;
        }

        if (self::is_rank_math_active()) {
            return self::apply_rank_math_term($termId, $seoTitle, $metaDescription, $focusKeyword);
        }

        if (self::is_yoast_active()) {
            return self::apply_yoast_term($termId, $seoTitle, $metaDescription, $focusKeyword);
        }

        return false;
    }

    private static function apply_rank_math_post(
        int $postId,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $focusKeyword,
    ): bool {
        $applied = false;

        if ($seoTitle !== null) {
            if ($seoTitle === '') {
                delete_post_meta($postId, 'rank_math_title');
                delete_post_meta($postId, '_rank_math_title');
            } else {
                update_post_meta($postId, 'rank_math_title', $seoTitle);
                update_post_meta($postId, '_rank_math_title', $seoTitle);
            }
            $applied = true;
        }

        if ($metaDescription !== null) {
            update_post_meta($postId, 'rank_math_description', $metaDescription);
            update_post_meta($postId, '_rank_math_description', $metaDescription);
            $applied = true;
        }

        if ($focusKeyword !== null) {
            update_post_meta($postId, 'rank_math_focus_keyword', $focusKeyword);
            update_post_meta($postId, '_rank_math_focus_keyword', $focusKeyword);
            if (class_exists('\RankMath\Helper')) {
                \RankMath\Helper::update_post_meta('focus_keyword', $postId, $focusKeyword);
            }
            $applied = true;
        }

        return $applied;
    }

    private static function apply_yoast_post(
        int $postId,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $focusKeyword,
    ): bool {
        $applied = false;

        if ($seoTitle !== null) {
            update_post_meta($postId, '_yoast_wpseo_title', $seoTitle);
            $applied = true;
        }

        if ($metaDescription !== null) {
            update_post_meta($postId, '_yoast_wpseo_metadesc', $metaDescription);
            $applied = true;
        }

        if ($focusKeyword !== null) {
            update_post_meta($postId, '_yoast_wpseo_focuskw', $focusKeyword);
            $applied = true;
        }

        return $applied;
    }

    private static function apply_rank_math_term(
        int $termId,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $focusKeyword,
    ): bool {
        $applied = false;

        if ($seoTitle !== null) {
            if ($seoTitle === '') {
                delete_term_meta($termId, 'rank_math_title');
                delete_term_meta($termId, 'rank_math_seo_title');
            } else {
                update_term_meta($termId, 'rank_math_title', $seoTitle);
                update_term_meta($termId, 'rank_math_seo_title', $seoTitle);
            }
            $applied = true;
        }

        if ($metaDescription !== null) {
            update_term_meta($termId, 'rank_math_description', $metaDescription);
            $applied = true;
        }

        if ($focusKeyword !== null) {
            update_term_meta($termId, 'rank_math_focus_keyword', $focusKeyword);
            $applied = true;
        }

        return $applied;
    }

    private static function apply_yoast_term(
        int $termId,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $focusKeyword,
    ): bool {
        $applied = false;

        if ($seoTitle !== null) {
            update_term_meta($termId, 'wpseo_title', $seoTitle);
            $applied = true;
        }

        if ($metaDescription !== null) {
            update_term_meta($termId, 'wpseo_desc', $metaDescription);
            $applied = true;
        }

        return $applied;
    }

    /**
     * @return array{plugin:string,seo_title:string,meta_description:string,focus_keyword:string}
     */
    private static function empty_payload(): array
    {
        return [
            'plugin'           => 'none',
            'seo_title'        => '',
            'meta_description' => '',
            'focus_keyword'    => '',
        ];
    }
}
