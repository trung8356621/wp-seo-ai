<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

final class Permalink_Resolver
{
    public static function for_post(int|\WP_Post $post): string
    {
        $post = $post instanceof \WP_Post ? $post : get_post($post);
        if (! $post instanceof \WP_Post) {
            return '';
        }

        if (in_array($post->post_status, ['publish', 'private'], true)) {
            $url = get_the_permalink($post);

            return is_string($url) ? $url : '';
        }

        if (! function_exists('get_sample_permalink')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        if (function_exists('get_sample_permalink')) {
            $sample = get_sample_permalink($post->ID, $post->post_title, $post->post_name);
            $template = is_array($sample) ? (string) ($sample[0] ?? '') : '';
            $slug = is_array($sample) ? (string) ($sample[1] ?? $post->post_name) : (string) $post->post_name;
            if ($template !== '') {
                return str_replace(
                    ['%postname%', '%pagename%'],
                    [rawurlencode($slug), rawurlencode($slug)],
                    $template,
                );
            }
        }

        $url = get_the_permalink($post);

        return is_string($url) ? $url : '';
    }

    public static function for_term(int|\WP_Term $term, string $taxonomy = ''): string
    {
        if (! $term instanceof \WP_Term) {
            $term = get_term($term, $taxonomy);
        }
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return '';
        }

        $url = get_term_link($term);

        return is_wp_error($url) ? '' : (string) $url;
    }
}
