<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Provider
{
    /**
     * @return array{counts: array<string, int>, items: array<int, array<string, mixed>>}
     */
    public function collect(int $limitPerType = 0): array
    {
        $items = [];
        $counts = [];

        $this->collect_posts('post', 'article', $limitPerType, $items, $counts);
        $this->collect_posts('page', 'article', $limitPerType, $items, $counts);
        $this->collect_products($limitPerType, $items, $counts);
        $this->collect_terms('category', 'category', $limitPerType, $items, $counts);
        $this->collect_terms('product_cat', 'product_category', $limitPerType, $items, $counts);

        return [
            'counts' => $counts,
            'items'  => $items,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, int> $counts
     */
    private function collect_posts(string $postType, string $seoType, int $limitPerType, array &$items, array &$counts): void
    {
        if (! post_type_exists($postType)) {
            $counts[$seoType] = 0;
            return;
        }

        $queryArgs = [
            'post_type'      => $postType,
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => $limitPerType > 0 ? $limitPerType : -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ];

        $query = new \WP_Query($queryArgs);
        $countKey = $postType === 'page' ? 'page' : $seoType;
        $counts[$countKey] = is_array($query->posts) ? count($query->posts) : 0;

        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $items[] = $this->map_post($post, $seoType, $postType);
        }

        wp_reset_postdata();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, int> $counts
     */
    private function collect_products(int $limitPerType, array &$items, array &$counts): void
    {
        if (! post_type_exists('product')) {
            $counts['product'] = 0;
            return;
        }

        $this->collect_posts('product', 'product', $limitPerType, $items, $counts);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, int> $counts
     */
    private function collect_terms(string $taxonomy, string $seoType, int $limitPerType, array &$items, array &$counts): void
    {
        if (! taxonomy_exists($taxonomy)) {
            $counts[$seoType] = 0;
            return;
        }

        $termArgs = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ];
        if ($limitPerType > 0) {
            $termArgs['number'] = $limitPerType;
        }

        $terms = get_terms($termArgs);

        if (is_wp_error($terms) || ! is_array($terms)) {
            $counts[$seoType] = 0;
            return;
        }

        $counts[$seoType] = count($terms);

        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }

            $seo = Seo_Plugin_Resolver::for_term((int) $term->term_id, $taxonomy);
            $description = (string) $term->description;

            $items[] = [
                'wp_id'        => (int) $term->term_id,
                'type'         => $seoType,
                'wp_post_type' => $taxonomy,
                'title'        => (string) $term->name,
                'slug'         => (string) $term->slug,
                'status'       => 'publish',
                'published_at' => null,
                'seo'          => $seo,
                'scoring'      => [
                    'body'             => $description,
                    'slug'             => (string) $term->slug,
                    'seo_title'        => $seo['seo_title'],
                    'meta_description' => $seo['meta_description'],
                    'focus_keyword'    => $seo['focus_keyword'],
                ],
            ];
        }
    }

    public function map_post_by_id(int $postId): ?array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        $seoType = $post->post_type === 'product' ? 'product' : 'article';

        return $this->map_post($post, $seoType, (string) $post->post_type);
    }

    private function map_post(\WP_Post $post, string $seoType, string $wpPostType): array
    {
        $seo = Seo_Plugin_Resolver::for_post((int) $post->ID);
        $content = apply_filters('the_content', (string) $post->post_content);
        $featuredImageUrl = '';
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $featuredImageUrl = (string) wp_get_attachment_image_url($thumbId, 'medium');
        }

        $productGallery = $wpPostType === 'product'
            ? $this->resolve_product_gallery((int) $post->ID)
            : [];

        return [
            'wp_id'        => (int) $post->ID,
            'type'         => $seoType,
            'wp_post_type' => $wpPostType,
            'title'        => (string) get_the_title($post),
            'slug'         => (string) $post->post_name,
            'post_content' => (string) $post->post_content,
            'featured_image_url' => $featuredImageUrl,
            'product_gallery' => $productGallery,
            'status'       => (string) $post->post_status,
            'published_at' => $post->post_status === 'publish'
                ? get_post_time('c', true, $post)
                : null,
            'seo'          => $seo,
            'scoring'      => [
                'body'             => (string) $content,
                'slug'             => (string) $post->post_name,
                'seo_title'        => $seo['seo_title'],
                'meta_description' => $seo['meta_description'],
                'focus_keyword'    => $seo['focus_keyword'],
            ],
        ];
    }

    /**
     * WooCommerce product gallery (_product_image_gallery).
     *
     * @return array<int, array{id: int, url: string}>
     */
    private function resolve_product_gallery(int $postId): array
    {
        $raw = get_post_meta($postId, '_product_image_gallery', true);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $gallery = [];
        foreach (array_filter(array_map('intval', explode(',', $raw))) as $attachmentId) {
            if ($attachmentId <= 0) {
                continue;
            }

            $url = (string) wp_get_attachment_image_url($attachmentId, 'woocommerce_thumbnail');
            if ($url === '') {
                $url = (string) wp_get_attachment_image_url($attachmentId, 'thumbnail');
            }
            if ($url === '') {
                continue;
            }

            $gallery[] = [
                'id'  => $attachmentId,
                'url' => $url,
            ];
        }

        return $gallery;
    }
}
