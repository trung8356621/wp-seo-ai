<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export SEO scores tagged by provider — never invent Rank Math numbers.
 */
final class Score_Exporter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for_post(\WP_Post $post): ?array
    {
        $info = Seo_Plugin_Resolver::site_info();
        $active = (string) ($info['active'] ?? 'none');

        if ($active === 'rank_math') {
            $score = get_post_meta($post->ID, 'rank_math_seo_score', true);
            if ($score === '' || $score === null) {
                $score = get_post_meta($post->ID, 'rank_math_internal_links_score', true);
            }
            if ($score === '' || $score === null || ! is_numeric($score)) {
                return [
                    'wordpress_id' => (int) $post->ID,
                    'source' => 'rank_math',
                    'score' => null,
                    'raw' => ['note' => 'assessment_present_score_unavailable'],
                ];
            }

            return [
                'wordpress_id' => (int) $post->ID,
                'source' => 'rank_math',
                'score' => (float) $score,
                'raw' => ['rank_math_seo_score' => $score],
            ];
        }

        if ($active === 'yoast') {
            $score = get_post_meta($post->ID, '_yoast_wpseo_linkdex', true);
            if ($score === '' || $score === null || ! is_numeric($score)) {
                return [
                    'wordpress_id' => (int) $post->ID,
                    'source' => 'yoast',
                    'score' => null,
                    'raw' => ['note' => 'assessment_present_score_unavailable'],
                ];
            }

            return [
                'wordpress_id' => (int) $post->ID,
                'source' => 'yoast',
                'score' => (float) $score,
                'raw' => ['_yoast_wpseo_linkdex' => $score],
            ];
        }

        return null;
    }
}
