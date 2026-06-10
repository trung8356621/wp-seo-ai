<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Sync_Provider
{
    /**
     * Trang tĩnh được chọn làm trang chủ (Cài đặt → Đọc) — không đẩy lên Laravel.
     */
    public static function is_sync_excluded_post(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        if ((string) get_option('show_on_front') !== 'page') {
            return false;
        }

        $frontPageId = (int) get_option('page_on_front');

        return $frontPageId > 0 && $postId === $frontPageId;
    }

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
        $synced = 0;

        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }

            if (self::is_sync_excluded_post((int) $post->ID)) {
                continue;
            }

            $items[] = $this->map_post($post, $seoType, $postType);
            $synced++;
        }

        $counts[$countKey] = $synced;

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

            $items[] = $this->map_term($term, $taxonomy, $seoType);
        }
    }

    public function map_term_by_id(string $taxonomy, int $termId): ?array
    {
        if (! taxonomy_exists($taxonomy)) {
            return null;
        }

        $term = get_term($termId, $taxonomy);
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return null;
        }

        $seoType = $this->seo_type_for_taxonomy($taxonomy);

        return $this->map_term($term, $taxonomy, $seoType);
    }

    public function map_post_by_id(int $postId): ?array
    {
        if (self::is_sync_excluded_post($postId)) {
            return null;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        $seoType = $post->post_type === 'product' ? 'product' : 'article';

        return $this->map_post($post, $seoType, (string) $post->post_type);
    }

    /**
     * @return array<string, mixed>
     */
    private function map_term(\WP_Term $term, string $taxonomy, string $seoType): array
    {
        $termId = (int) $term->term_id;
        $seo = Seo_Plugin_Resolver::for_term($termId, $taxonomy);
        $description = (string) $term->description;

        return [
            'wp_id'              => $termId,
            'type'               => $seoType,
            'wp_post_type'       => $taxonomy,
            'wp_entity'          => 'term',
            'title'              => (string) $term->name,
            'slug'               => (string) $term->slug,
            'permalink'          => $this->resolve_term_permalink($term),
            'post_content'       => $description,
            'faqs'               => Faq_Shortcode::resolve_faqs_for_term($termId),
            'featured_image_url' => $this->resolve_term_featured_image($termId),
            'product_gallery'    => [],
            'post_images'        => (new Post_Images_Extractor())->extract_from_content($description),
            'status'             => 'publish',
            'published_at'       => null,
            'seo'                => $seo,
            'scoring'            => [
                'body'             => $description,
                'slug'             => (string) $term->slug,
                'seo_title'        => $seo['seo_title'],
                'meta_description' => $seo['meta_description'],
                'focus_keyword'    => $seo['focus_keyword'],
            ],
        ];
    }

    private function seo_type_for_taxonomy(string $taxonomy): string
    {
        return $taxonomy === 'product_cat' ? 'product_category' : 'category';
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

        $postImages = (new Post_Images_Extractor())->extract_from_content((string) $post->post_content);

        $postId = (int) $post->ID;
        $woocommerce = $wpPostType === 'product' ? $this->resolve_woocommerce_payload($postId) : [];

        return [
            'wp_id'        => $postId,
            'type'         => $seoType,
            'wp_post_type' => $wpPostType,
            'wp_entity'    => 'post',
            'title'        => (string) get_the_title($post),
            'slug'         => (string) $post->post_name,
            'permalink'    => $this->resolve_post_permalink($post),
            'post_content' => (string) $post->post_content,
            'faqs'         => Faq_Shortcode::resolve_faqs_for_post($postId),
            'virtual_comments' => Virtual_Comments::get_virtual_items($postId),
            'schema_json_ld' => Schema_Ld_Exporter::for_post($postId),
            'woocommerce'    => $woocommerce,
            'featured_image_url' => $featuredImageUrl,
            'product_gallery' => $productGallery,
            'post_images'     => $postImages,
            'status'       => (string) $post->post_status,
            'post_date'    => (string) $post->post_date,
            'post_modified' => (string) $post->post_modified,
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

    private function resolve_post_permalink(\WP_Post $post): string
    {
        return Permalink_Resolver::for_post($post);
    }

    private function resolve_term_permalink(\WP_Term $term): string
    {
        return Permalink_Resolver::for_term($term);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve_woocommerce_payload(int $postId): array
    {
        if (! function_exists('wc_get_product')) {
            return [];
        }

        $product = wc_get_product($postId);
        if (! $product instanceof \WC_Product) {
            return [];
        }

        $currency = function_exists('get_woocommerce_currency')
            ? (string) get_woocommerce_currency()
            : 'VND';

        $payload = [
            'currency'      => $currency,
            'price'         => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'in_stock'      => $product->is_in_stock(),
        ];

        if ($product->is_type('variable')) {
            $prices = $product->get_variation_prices();
            $priceList = is_array($prices['price'] ?? null) ? array_filter(array_map('floatval', $prices['price'])) : [];
            if ($priceList !== []) {
                $payload['min_price'] = (string) min($priceList);
                $payload['max_price'] = (string) max($priceList);
            }
        }

        return $payload;
    }

    /**
     * WooCommerce / WP: term meta thumbnail_id.
     */
    private function resolve_term_featured_image(int $termId): string
    {
        $thumbId = (int) get_term_meta($termId, 'thumbnail_id', true);
        if ($thumbId <= 0) {
            return '';
        }

        $url = (string) wp_get_attachment_image_url($thumbId, 'medium');
        if ($url === '') {
            $url = (string) wp_get_attachment_image_url($thumbId, 'full');
        }

        return $url;
    }
}
