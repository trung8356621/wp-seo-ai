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
     * Danh sách nhẹ wp_id + modified để Laravel so sánh incremental sync.
     *
     * @return array{counts: array<string, int>, entries: array<int, array<string, mixed>>}
     */
    public function collect_manifest(): array
    {
        $entries = [];
        $counts = [];

        $this->collect_post_manifest('post', 'article', $entries, $counts);
        $this->collect_post_manifest('page', 'article', $entries, $counts);
        $this->collect_product_manifest($entries, $counts);
        $this->collect_term_manifest('category', 'category', $entries, $counts);
        $this->collect_term_manifest('product_cat', 'product_category', $entries, $counts);

        return [
            'counts'  => $counts,
            'entries' => $entries,
            'totals'  => [
                'entries'        => count($entries),
                'wp_admin_posts' => $this->count_wp_admin_posts('post'),
                'wp_admin_pages' => $this->count_wp_admin_posts('page'),
                'manifest_posts' => (int) ($counts['article'] ?? 0),
                'manifest_pages' => (int) ($counts['page'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array{items: array<int, array<string, mixed>>}
     */
    public function collect_items(array $refs): array
    {
        $items = [];

        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $wpId = (int) ($ref['wp_id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            $entity = (string) ($ref['wp_entity'] ?? 'post');
            if ($entity === 'term') {
                $taxonomy = (string) ($ref['wp_post_type'] ?? '');
                if ($taxonomy === '') {
                    continue;
                }

                $mapped = $this->map_term_by_id($taxonomy, $wpId);
                if ($mapped !== null) {
                    $items[] = $mapped;
                }

                continue;
            }

            $mapped = $this->map_post_by_id($wpId);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return ['items' => $items];
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

        $queryArgs = array_merge([
            'post_type'      => $postType,
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => $limitPerType > 0 ? $limitPerType : -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ], Polylang_Sync::query_args_for_all_languages());

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
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, int> $counts
     */
    private function collect_post_manifest(string $postType, string $seoType, array &$entries, array &$counts): void
    {
        if (! post_type_exists($postType)) {
            $counts[$postType === 'page' ? 'page' : $seoType] = 0;
            return;
        }

        $query = new \WP_Query(array_merge([
            'post_type'              => $postType,
            'post_status'            => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page'         => -1,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'fields'                 => 'ids',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ], Polylang_Sync::query_args_for_all_languages()));

        $countKey = $postType === 'page' ? 'page' : $seoType;
        $synced = 0;

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0 || self::is_sync_excluded_post($postId)) {
                continue;
            }

            $post = get_post($postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $entries[] = [
                'wp_id'         => $postId,
                'type'          => $seoType,
                'wp_post_type'  => $postType,
                'wp_entity'     => 'post',
                'post_modified' => (string) $post->post_modified,
            ];
            $synced++;
        }

        $counts[$countKey] = $synced;
        wp_reset_postdata();
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, int> $counts
     */
    private function collect_product_manifest(array &$entries, array &$counts): void
    {
        if (! post_type_exists('product')) {
            $counts['product'] = 0;
            return;
        }

        $this->collect_post_manifest('product', 'product', $entries, $counts);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, int> $counts
     */
    private function collect_term_manifest(string $taxonomy, string $seoType, array &$entries, array &$counts): void
    {
        if (! taxonomy_exists($taxonomy)) {
            $counts[$seoType] = 0;
            return;
        }

        $terms = get_terms(array_merge([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ], Polylang_Sync::query_args_for_all_languages()));

        if (is_wp_error($terms) || ! is_array($terms)) {
            $counts[$seoType] = 0;
            return;
        }

        $counts[$seoType] = count($terms);

        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }

            $entries[] = [
                'wp_id'        => (int) $term->term_id,
                'type'         => $seoType,
                'wp_post_type' => $taxonomy,
                'wp_entity'    => 'term',
            ];
        }
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

        $termArgs = array_merge([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ], Polylang_Sync::query_args_for_all_languages());
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
     * Identity + SEO snapshot only — no post_content / scoring.body / image extract.
     *
     * @return array<string, mixed>|null
     */
    public function map_post_index_by_id(int $postId): ?array
    {
        if (self::is_sync_excluded_post($postId)) {
            return null;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        $seoType = $post->post_type === 'product' ? 'product' : 'article';

        return $this->map_post_index($post, $seoType, (string) $post->post_type);
    }

    /**
     * @return array<string, mixed>
     */
    private function map_post_index(\WP_Post $post, string $seoType, string $wpPostType): array
    {
        $seo = Seo_Plugin_Resolver::for_post((int) $post->ID);
        $featuredImageUrl = '';
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $featuredImageUrl = (string) wp_get_attachment_image_url($thumbId, 'medium');
        }

        return [
            'wp_id' => (int) $post->ID,
            'type' => $seoType,
            'wp_post_type' => $wpPostType,
            'wp_entity' => 'post',
            'title' => (string) get_the_title($post),
            'slug' => (string) $post->post_name,
            'permalink' => $this->resolve_post_permalink($post),
            'status' => (string) $post->post_status,
            'post_date' => (string) $post->post_date,
            'post_modified' => (string) $post->post_modified,
            'published_at' => $post->post_status === 'publish'
                ? get_post_time('c', true, $post)
                : null,
            'featured_image_url' => $featuredImageUrl,
            'category_ids' => $this->resolve_post_category_ids($post),
            'seo' => $seo,
            'scoring' => [
                'body' => '',
                'slug' => (string) $post->post_name,
                'seo_title' => (string) ($seo['seo_title'] ?? ''),
                'meta_description' => (string) ($seo['meta_description'] ?? ''),
                'focus_keyword' => (string) ($seo['focus_keyword'] ?? ''),
            ],
            'multilingual' => Polylang_Sync::multilingual_field_for_post((int) $post->ID),
        ];
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
            'parent_id'              => (int) $term->parent,
            'parent_term_id'         => (int) $term->parent,
            'term_id'                => $termId,
            'taxonomy'               => $taxonomy,
            'post_count'             => (int) $term->count,
            'page_type'              => 'taxonomy',
            'wp_id'              => $termId,
            'type'               => $seoType,
            'wp_post_type'       => $taxonomy,
            'wp_entity'          => 'term',
            'title'              => (string) $term->name,
            'name'               => (string) $term->name,
            'slug'               => (string) $term->slug,
            'permalink'          => $this->resolve_term_permalink($term),
            'url'                => $this->resolve_term_permalink($term),
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
            'multilingual'       => Polylang_Sync::multilingual_field_for_term($termId),
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
            'category_ids' => $this->resolve_post_category_ids($post),
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
            'multilingual' => Polylang_Sync::multilingual_field_for_post($postId),
        ];
    }

    /**
     * WP term IDs gán cho post/product (category hoặc product_cat).
     *
     * @return list<int>
     */
    private function resolve_post_category_ids(\WP_Post $post): array
    {
        $taxonomy = (string) $post->post_type === 'product' ? 'product_cat' : 'category';
        $termIds = wp_get_post_terms((int) $post->ID, $taxonomy, ['fields' => 'ids']);

        if (is_wp_error($termIds) || ! is_array($termIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0)));
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

    private function count_wp_admin_posts(string $postType): int
    {
        if (! post_type_exists($postType)) {
            return 0;
        }

        $counts = wp_count_posts($postType);
        if (! is_object($counts)) {
            return 0;
        }

        $total = 0;
        foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
            $total += (int) ($counts->$status ?? 0);
        }

        return $total;
    }
}
