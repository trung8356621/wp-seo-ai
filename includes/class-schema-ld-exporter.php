<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Xuất JSON-LD Product (AggregateOffer + aggregateRating) cho đồng bộ Laravel / preview.
 * Review ảo chỉ từ post meta — không dùng wp_comments.
 */
final class Schema_Ld_Exporter
{
    public static function for_post(int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        $fromRankMath = self::from_rank_math_meta($postId);
        if ($fromRankMath !== '') {
            return $fromRankMath;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post || $post->post_type !== 'product') {
            return '';
        }

        return self::from_woocommerce_product($postId);
    }

    private static function from_rank_math_meta(int $postId): string
    {
        $candidates = [];

        $allMeta = get_post_meta($postId);
        if (is_array($allMeta)) {
            foreach ($allMeta as $key => $values) {
                if (! is_string($key) || ! is_array($values)) {
                    continue;
                }

                if (
                    ! str_starts_with($key, 'rank_math_schema_')
                    && $key !== 'rank_math_schema'
                    && $key !== 'rank_math_rich_snippet'
                ) {
                    continue;
                }

                $raw = $values[0] ?? null;
                $decoded = self::decode_meta_value($raw);
                if ($decoded !== null) {
                    $candidates[] = $decoded;
                }
            }
        }

        if ($candidates === []) {
            return '';
        }

        $graph = [];
        foreach ($candidates as $node) {
            if (isset($node['@graph']) && is_array($node['@graph'])) {
                foreach ($node['@graph'] as $sub) {
                    if (is_array($sub)) {
                        $graph[] = $sub;
                    }
                }
                continue;
            }

            $graph[] = $node;
        }

        if ($graph === []) {
            return '';
        }

        return (string) wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function from_woocommerce_product(int $postId): string
    {
        if (! function_exists('wc_get_product')) {
            return '';
        }

        $product = wc_get_product($postId);
        if (! $product instanceof \WC_Product) {
            return '';
        }

        $currency = function_exists('get_woocommerce_currency')
            ? (string) get_woocommerce_currency()
            : 'VND';

        $offers = self::build_offers_node($product, $currency);
        $node = [
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'url'         => get_permalink($postId),
            'sku'         => $product->get_sku(),
        ];

        if ($offers !== null) {
            $node['offers'] = $offers;
        }

        $stats = Virtual_Comments::get_virtual_reviews_stats($postId);
        if (is_array($stats) && ($stats['count'] ?? 0) > 0) {
            $node['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) ($stats['average'] ?? 5),
                'reviewCount' => (string) ($stats['count'] ?? 0),
            ];
        } elseif ($product->get_review_count() > 0 && wc_review_ratings_enabled()) {
            $node['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $product->get_average_rating(),
                'reviewCount' => (string) $product->get_review_count(),
            ];
        }

        return (string) wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [$node],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function build_offers_node(\WC_Product $product, string $currency): ?array
    {
        if ($product->is_type('variable')) {
            $prices = $product->get_variation_prices();
            $priceList = is_array($prices['price'] ?? null) ? array_filter(array_map('floatval', $prices['price'])) : [];
            if ($priceList === []) {
                return null;
            }

            $low = min($priceList);
            $high = max($priceList);

            return [
                '@type'         => 'AggregateOffer',
                'lowPrice'      => (string) $low,
                'highPrice'     => (string) $high,
                'priceCurrency' => $currency,
                'availability'  => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];
        }

        $price = $product->get_price();
        if ($price === '' || ! is_numeric($price)) {
            return null;
        }

        return [
            '@type'         => 'Offer',
            'price'         => (string) $price,
            'priceCurrency' => $currency,
            'availability'  => $product->is_in_stock()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode_meta_value(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
