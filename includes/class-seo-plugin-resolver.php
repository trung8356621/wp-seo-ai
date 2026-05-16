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
