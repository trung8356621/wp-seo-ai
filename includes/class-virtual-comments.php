<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bình luận/review ảo lưu trong post meta _omi_seo_virtual_comments — render qua filter WordPress/WooCommerce.
 */
final class Virtual_Comments
{
    public const META_KEY = '_omi_seo_virtual_comments';

    /** @var array<int, int> */
    private static array $ratings = [];

    public static function register(): void
    {
        add_filter('comments_array', [self::class, 'inject_virtual_comments'], 10, 2);
        add_filter('get_comment_metadata', [self::class, 'inject_virtual_comment_rating'], 10, 4);
        add_filter('get_comments_number', [self::class, 'adjust_comments_number'], 10, 2);

        add_filter('woocommerce_product_get_review_count', [self::class, 'adjust_woocommerce_review_count'], 10, 2);
        add_filter('woocommerce_product_get_rating_count', [self::class, 'adjust_woocommerce_rating_count'], 10, 2);
        add_filter('woocommerce_product_get_average_rating', [self::class, 'adjust_woocommerce_average_rating'], 10, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, count: int, message: string}
     */
    public static function save_for_post(int $postId, array $items): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'Post not found.',
            ];
        }

        $normalized = self::normalize_items($items, $post->post_type === 'product', $postId);
        update_post_meta($postId, self::META_KEY, wp_json_encode($normalized, JSON_UNESCAPED_UNICODE));

        if ($post->post_type === 'product' && function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($postId);
        }

        return [
            'success' => true,
            'count'   => count($normalized),
            'message' => 'Virtual comments saved.',
        ];
    }

    /**
     * @param  array<int, \WP_Comment>  $comments
     * @return array<int, \WP_Comment>
     */
    public static function inject_virtual_comments(array $comments, int $postId): array
    {
        $virtualComments = self::get_virtual_items($postId);
        if ($virtualComments === []) {
            return $comments;
        }

        $postType = get_post_type($postId);
        $index = 1;

        foreach ($virtualComments as $vc) {
            if (! is_array($vc)) {
                continue;
            }

            $commentId = -($postId * 1000 + $index);
            $commentDate = isset($vc['date']) && is_string($vc['date']) && $vc['date'] !== ''
                ? $vc['date']
                : current_time('mysql');
            $rating = isset($vc['rating']) ? (int) $vc['rating'] : 5;
            $rating = max(1, min(5, $rating));

            self::$ratings[$commentId] = $rating;

            $commentData = [
                'comment_ID'           => $commentId,
                'comment_post_ID'      => $postId,
                'comment_author'       => sanitize_text_field((string) ($vc['author'] ?? 'Khách mua hàng')),
                'comment_author_email' => 'customer.' . abs($commentId) . '@example.com',
                'comment_author_url'   => '',
                'comment_author_IP'    => '127.0.0.1',
                'comment_date'         => $commentDate,
                'comment_date_gmt'     => get_gmt_from_date($commentDate),
                'comment_content'      => wp_kses_post((string) ($vc['content'] ?? '')),
                'comment_karma'        => 0,
                'comment_approved'     => '1',
                'comment_agent'        => 'OMI SEO AI Engine',
                'comment_type'         => $postType === 'product' ? 'review' : 'comment',
                'comment_parent'       => 0,
                'user_id'              => 0,
            ];

            $comments[] = new \WP_Comment((object) $commentData);
            $index++;
        }

        return $comments;
    }

  /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function inject_virtual_comment_rating($value, int $commentId, string $metaKey, bool $single)
    {
        if ($commentId >= 0 || $metaKey !== 'rating') {
            return $value;
        }

        if (! isset(self::$ratings[$commentId])) {
            return $value;
        }

        $ratingVal = self::$ratings[$commentId];

        return $single ? $ratingVal : [$ratingVal];
    }

    public static function adjust_comments_number(int $count, int $postId): int
    {
        $stats = self::get_virtual_reviews_stats($postId);
        if ($stats === false) {
            return $count;
        }

        return $count + (int) $stats['count'];
    }

    /**
     * @param  int|\WC_Product  $product
     */
    public static function adjust_woocommerce_review_count(int $count, $product): int
    {
        $productId = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
        $stats = self::get_virtual_reviews_stats($productId);
        if ($stats === false) {
            return $count;
        }

        return $count + (int) $stats['count'];
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    public static function adjust_woocommerce_rating_count(array $counts, $product): array
    {
        $productId = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
        $stats = self::get_virtual_reviews_stats($productId);
        if ($stats === false) {
            return $counts;
        }

        foreach ($stats['ratings'] as $rating => $num) {
            if (! isset($counts[$rating])) {
                $counts[$rating] = 0;
            }
            $counts[$rating] += (int) $num;
        }

        return $counts;
    }

    /**
     * @param  float|string  $average
     * @return float|string
     */
    public static function adjust_woocommerce_average_rating($average, $product)
    {
        $productId = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
        $stats = self::get_virtual_reviews_stats($productId);
        if ($stats === false) {
            return $average;
        }

        $realCount = (int) get_comments([
            'post_id' => $productId,
            'status'  => 'approve',
            'type'    => 'review',
            'count'   => true,
        ]);

        $realAverage = (float) $average;
        $realSum = $realCount > 0 ? $realAverage * $realCount : 0.0;
        $totalCount = $realCount + (int) $stats['count'];

        if ($totalCount <= 0) {
            return $average;
        }

        $totalSum = $realSum + ((float) $stats['average'] * (int) $stats['count']);

        return round($totalSum / $totalCount, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{author: string, content: string, rating?: int, date: string}>
     */
    public static function normalize_items(array $items, bool $isProduct): array
    {
        $normalized = [];
        $index = 0;

        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['comment'] ?? ''));
            if ($content === '') {
                continue;
            }

            $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
            if ($author === '') {
                $author = 'Khách mua hàng';
            }

            $row = [
                'author'  => sanitize_text_field($author),
                'content' => $content,
                'date'    => self::normalize_date($item, $index),
            ];

            if ($isProduct) {
                $rating = isset($item['rating']) ? (int) $item['rating'] : 5;
                $row['rating'] = max(1, min(5, $rating));
            }

            $normalized[] = $row;
            $index++;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_virtual_items(int $postId): array
    {
        $raw = get_post_meta($postId, self::META_KEY, true);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{count: int, average: float, ratings: array<int, int>}|false
     */
    public static function get_virtual_reviews_stats(int $postId)
    {
        $virtualComments = self::get_virtual_items($postId);
        if ($virtualComments === []) {
            return false;
        }

        $total = count($virtualComments);
        $sum = 0;
        $ratings = [];

        foreach ($virtualComments as $vc) {
            if (! is_array($vc)) {
                continue;
            }
            $rating = isset($vc['rating']) ? (int) $vc['rating'] : 5;
            $rating = max(1, min(5, $rating));
            $sum += $rating;
            if (! isset($ratings[$rating])) {
                $ratings[$rating] = 0;
            }
            $ratings[$rating]++;
        }

        if ($total <= 0) {
            return false;
        }

        return [
            'count'   => $total,
            'average' => round($sum / $total, 2),
            'ratings' => $ratings,
        ];
    }

    private static function resolve_post_published_timestamp(int $postId): int
    {
        if ($postId <= 0) {
            return (int) current_time('timestamp');
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return (int) current_time('timestamp');
        }

        $timestamp = strtotime((string) $post->post_date);
        if ($timestamp === false) {
            return (int) current_time('timestamp');
        }

        return $timestamp;
    }

    /**
     * @return list<string>
     */
    private static function build_staggered_comment_dates(int $count, int $publishedTimestamp): array
    {
        if ($count <= 0) {
            return [];
        }

        $pool = [2, 3, 4, 5, 6];
        shuffle($pool);

        $dates = [];

        for ($i = 0; $i < $count; $i++) {
            $days = $i < count($pool) ? $pool[$i] : random_int(2, 6);
            $hour = random_int(8, 21);
            $minute = random_int(0, 59);

            $dates[] = wp_date(
                'Y-m-d H:i:s',
                $publishedTimestamp + ($days * DAY_IN_SECONDS) + ($hour * HOUR_IN_SECONDS) + ($minute * MINUTE_IN_SECONDS),
            );
        }

        sort($dates);

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function normalize_date(array $item, string $fallbackDate): string
    {
        $raw = trim((string) ($item['date'] ?? $item['comment_date'] ?? ''));
        if ($raw !== '') {
            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                return wp_date('Y-m-d H:i:s', $timestamp);
            }
        }

        return $fallbackDate;
    }
}
