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

    /** @var list<int> */
    private static array $pendingWcTransientFlush = [];

    /** @var array<int, \WP_Comment> */
    private static array $virtualCommentCache = [];

    public static function register(): void
    {
        add_action('shutdown', [self::class, 'flush_pending_wc_transients'], 99);

        add_action('woocommerce_before_single_product_reviews', [self::class, 'prime_product_reviews_query'], 1);
        add_filter('woocommerce_locate_template', [self::class, 'locate_virtual_product_reviews_template'], 20, 3);
        add_filter('wc_get_template', [self::class, 'filter_wc_get_template'], 20, 3);
        // Priority cao hơn CusRev / theme — chúng thường replace comments_template.
        add_filter('comments_template', [self::class, 'filter_comments_template'], 9999);

        add_filter('comments_array', [self::class, 'inject_virtual_comments'], 10, 2);
        add_filter('get_comment', [self::class, 'filter_get_comment'], 10, 2);
        add_filter('get_comment_metadata', [self::class, 'inject_virtual_comment_rating'], 10, 4);
        add_action('woocommerce_review_before_comment_meta', [self::class, 'display_virtual_review_rating'], 9);
        // CusRev (cr-reviews-ajax-*) chiếm tab reviews — ép callback về template ảo khi có meta.
        add_filter('woocommerce_product_tabs', [self::class, 'filter_product_review_tab'], 999);
        add_filter('woocommerce_product_review_list_args', [self::class, 'filter_woocommerce_review_list_args'], 50, 1);
        add_filter('get_comments_number', [self::class, 'adjust_comments_number'], 10, 2);

        add_filter('woocommerce_product_get_review_count', [self::class, 'adjust_woocommerce_review_count'], 10, 2);
        add_filter('woocommerce_product_get_rating_count', [self::class, 'adjust_woocommerce_rating_count'], 10, 2);
        add_filter('woocommerce_product_get_average_rating', [self::class, 'adjust_woocommerce_average_rating'], 10, 2);
    }

    /**
     * @param  string  $template
     * @param  string  $template_name
     * @param  string  $template_path
     */
    public static function locate_virtual_product_reviews_template($template, $template_name, $template_path)
    {
        unset($template_path);

        return self::maybe_reviews_template_override($template, (string) $template_name);
    }

    /**
     * @param  string  $template
     * @param  string  $template_name
     * @param  mixed  $args
     */
    public static function filter_wc_get_template($template, $template_name, $args)
    {
        unset($args);

        return self::maybe_reviews_template_override($template, (string) $template_name);
    }

    public static function filter_comments_template($template): string
    {
        $template = is_string($template) ? $template : '';
        $postId = self::resolve_product_post_id();
        if ($postId <= 0 || self::count_displayable_virtual_items($postId) <= 0) {
            return $template;
        }

        $custom = OMI_SEO_AI_BRIDGE_PATH . 'templates/woocommerce/single-product-reviews-virtual.php';

        return is_readable($custom) ? $custom : $template;
    }

    public static function prime_product_reviews_query(): void
    {
        $postId = self::resolve_product_post_id();
        if ($postId <= 0) {
            return;
        }

        $virtualComments = self::build_virtual_comment_objects_for_post($postId);
        if ($virtualComments === []) {
            return;
        }

        global $wp_query;
        if (! isset($wp_query) || ! is_object($wp_query)) {
            return;
        }

        $wp_query->comments = $virtualComments;
        $wp_query->comment_count = count($virtualComments);
    }

    private static function maybe_reviews_template_override(string $template, string $template_name): string
    {
        if ($template_name !== 'single-product-reviews.php') {
            return $template;
        }

        $postId = self::resolve_product_post_id();
        if ($postId <= 0 || self::count_displayable_virtual_items($postId) <= 0) {
            return $template;
        }

        $custom = OMI_SEO_AI_BRIDGE_PATH . 'templates/woocommerce/single-product-reviews-virtual.php';

        return is_readable($custom) ? $custom : $template;
    }

    public static function resolve_product_post_id(): int
    {
        global $product;

        if ($product instanceof \WC_Product) {
            return (int) $product->get_id();
        }

        $queriedId = (int) get_queried_object_id();
        if ($queriedId > 0 && get_post_type($queriedId) === 'product') {
            return $queriedId;
        }

        $postId = (int) get_the_ID();
        if ($postId > 0 && get_post_type($postId) === 'product') {
            return $postId;
        }

        global $post;
        if ($post instanceof \WP_Post && $post->post_type === 'product') {
            return (int) $post->ID;
        }

        return 0;
    }

    /**
     * Render reviews tab — bypass CusRev AJAX empty state khi có virtual meta.
     */
    public static function render_virtual_reviews_tab(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            $postId = self::resolve_product_post_id();
            if ($postId > 0 && function_exists('wc_get_product')) {
                $resolved = wc_get_product($postId);
                if ($resolved instanceof \WC_Product) {
                    $product = $resolved;
                }
            }
        }

        $custom = OMI_SEO_AI_BRIDGE_PATH . 'templates/woocommerce/single-product-reviews-virtual.php';
        if (! is_readable($custom)) {
            comments_template();

            return;
        }

        include $custom;
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

        $encoded = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'JSON encode failed for virtual comments.',
            ];
        }

        update_post_meta($postId, self::META_KEY, $encoded);
        clean_post_cache($postId);

        if ($post->post_type === 'product' && function_exists('wc_delete_product_transients')) {
            self::$pendingWcTransientFlush[] = $postId;
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
    /**
     * @param  array<int, \WP_Comment>  $comments
     * @param  mixed  $postId
     * @return array<int, \WP_Comment>
     */
    public static function inject_virtual_comments(array $comments, $postId): array
    {
        $postId = (int) $postId;

        $comments = array_values(array_filter(
            $comments,
            static fn ($comment): bool => $comment instanceof \WP_Comment,
        ));

        $virtualComments = self::build_virtual_comment_objects_for_post($postId);
        if ($virtualComments === []) {
            return $comments;
        }

        return array_merge($comments, $virtualComments);
    }

    /**
     * Ép review meta thành WP_Comment (type review) cho wp_list_comments / WooCommerce.
     *
     * @return list<\WP_Comment>
     */
    public static function build_virtual_comment_objects_for_post(int $postId): array
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return [];
        }

        $virtualComments = self::get_virtual_items($postId);
        if ($virtualComments === []) {
            return [];
        }

        $postType = get_post_type($postId) ?: 'post';
        $objects = [];
        $index = 1;

        foreach ($virtualComments as $vc) {
            if (! is_array($vc)) {
                continue;
            }

            $content = trim((string) ($vc['content'] ?? $vc['comment'] ?? $vc['review'] ?? ''));
            if ($content === '') {
                continue;
            }

            $commentId = -($postId * 1000 + $index);
            $commentDate = isset($vc['date']) && is_string($vc['date']) && $vc['date'] !== ''
                ? $vc['date']
                : current_time('mysql');
            $rating = self::resolve_item_rating($vc, $index - 1);

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
                'comment_content'      => wp_kses_post($content),
                'comment_karma'        => 0,
                'comment_approved'     => '1',
                'comment_agent'        => 'OMI SEO AI Engine',
                'comment_type'         => $postType === 'product' ? 'review' : 'comment',
                'comment_parent'       => 0,
                'user_id'              => 0,
            ];

            $commentObject = new \WP_Comment((object) $commentData);
            self::$virtualCommentCache[$commentId] = $commentObject;
            $objects[] = $commentObject;
            $index++;
        }

        return $objects;
    }

    /**
     * @param  \WP_Comment|false|null  $comment
     * @param  mixed  $comment_id
     * @return \WP_Comment|false|null
     */
    public static function filter_get_comment($comment, $comment_id = 0)
    {
        if ($comment instanceof \WP_Comment) {
            return $comment;
        }

        $resolved = self::build_virtual_comment_object((int) $comment_id);

        return $resolved ?? $comment;
    }

    /**
     * @param  mixed  $value
     * @param  mixed  $commentId
     * @param  mixed  $metaKey
     * @param  mixed  $single
     * @return mixed
     */
    public static function inject_virtual_comment_rating($value, $commentId, $metaKey, $single)
    {
        $commentId = (int) $commentId;
        $metaKey = (string) $metaKey;
        $single = (bool) $single;

        if ($commentId >= 0 || $metaKey !== 'rating') {
            return $value;
        }

        if (! isset(self::$ratings[$commentId])) {
            $resolved = self::resolve_rating_for_comment_id($commentId);
            if ($resolved !== null) {
                self::$ratings[$commentId] = $resolved;
            }
        }

        if (! isset(self::$ratings[$commentId])) {
            return $value;
        }

        $ratingVal = self::$ratings[$commentId];

        return $single ? $ratingVal : [$ratingVal];
    }

    /**
     * @param  array<string, mixed>  $tabs
     * @return array<string, mixed>
     */
    public static function filter_product_review_tab(array $tabs): array
    {
        if (! isset($tabs['reviews'])) {
            return $tabs;
        }

        $postId = self::resolve_product_post_id();
        if ($postId <= 0) {
            return $tabs;
        }

        $virtualCount = self::count_displayable_virtual_items($postId);
        if ($virtualCount <= 0) {
            return $tabs;
        }

        $realCount = (int) get_comments([
            'post_id' => $postId,
            'status'  => 'approve',
            'type'    => 'review',
            'count'   => true,
        ]);

        $total = $realCount + $virtualCount;
        $tabs['reviews']['title'] = sprintf(
            /* translators: %s: reviews count */
            __('Reviews (%s)', 'woocommerce'),
            (string) $total,
        );

        // CusRev thay callback → AJAX list rỗng dù meta đã có. Ép về template ảo.
        $tabs['reviews']['callback'] = [self::class, 'render_virtual_reviews_tab'];

        return $tabs;
    }

    /**
     * Ensure Woo review list does not truncate virtual comments
     * when site-level comment pagination is enabled.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function filter_woocommerce_review_list_args(array $args): array
    {
        $postId = self::resolve_product_post_id();
        if ($postId <= 0 || self::count_displayable_virtual_items($postId) <= 0) {
            return $args;
        }

        $args['per_page'] = 0;
        $args['reverse_top_level'] = false;
        $args['page'] = 1;

        return $args;
    }

    public static function count_displayable_virtual_items(int $postId): int
    {
        $count = 0;

        foreach (self::get_virtual_items($postId) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['comment'] ?? $item['review'] ?? ''));
            if ($content !== '') {
                $count++;
            }
        }

        return $count;
    }

    public static function display_virtual_review_rating($comment): void
    {
        if (! $comment instanceof \WP_Comment) {
            return;
        }

        $commentId = (int) $comment->comment_ID;
        if ($commentId >= 0) {
            return;
        }

        if (! function_exists('wc_get_rating_html') || ! wc_review_ratings_enabled()) {
            return;
        }

        if ((int) get_comment_meta($commentId, 'rating', true) > 0) {
            return;
        }

        $rating = self::$ratings[$commentId] ?? self::resolve_rating_for_comment_id($commentId);
        if ($rating === null || $rating <= 0) {
            return;
        }

        echo wc_get_rating_html($rating); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param  mixed  $count
     * @param  mixed  $postId
     */
    public static function adjust_comments_number($count, $postId): int
    {
        $count = (int) $count;
        $postId = (int) $postId;
        $stats = self::get_virtual_reviews_stats($postId);
        if ($stats === false) {
            return $count;
        }

        return $count + (int) $stats['count'];
    }

    /**
     * @param  int|\WC_Product  $product
     */
    /**
     * @param  mixed  $count
     * @param  mixed  $product
     */
    public static function adjust_woocommerce_review_count($count, $product): int
    {
        $count = (int) $count;
        $productId = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
        $virtualCount = self::count_displayable_virtual_items((int) $productId);
        if ($virtualCount <= 0) {
            return $count;
        }

        return $count + $virtualCount;
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    /**
     * @param  mixed  $counts
     * @param  mixed  $product
     * @return array<int, int>
     */
    public static function adjust_woocommerce_rating_count($counts, $product): array
    {
        if (! is_array($counts)) {
            $counts = [];
        }

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
    public static function normalize_items(array $items, bool $isProduct, int $postId = 0): array
    {
        $validItems = [];
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['comment'] ?? $item['review'] ?? ''));
            if ($content === '') {
                continue;
            }

            $validItems[] = $item;
        }

        $staggeredDates = self::build_staggered_comment_dates(
            count($validItems),
            self::resolve_post_published_timestamp($postId),
        );

        $normalized = [];
        $index = 0;

        foreach ($validItems as $item) {
            $author = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách mua hàng'));
            if ($author === '') {
                $author = 'Khách mua hàng';
            }

            $row = [
                'author'  => sanitize_text_field($author),
                'content' => trim((string) ($item['content'] ?? $item['comment'] ?? '')),
                'date'    => self::normalize_date(
                    $item,
                    $staggeredDates[$index] ?? $staggeredDates[0] ?? self::format_datetime((int) current_time('timestamp')),
                ),
            ];

            if ($isProduct) {
                $row['rating'] = self::resolve_item_rating($item, $index);
            }

            foreach (['_omi_review_id', '_omi_idempotency_key', '_omi_article_id'] as $omiKey) {
                if (! array_key_exists($omiKey, $item)) {
                    continue;
                }
                $value = $item[$omiKey];
                if ($omiKey === '_omi_review_id' || $omiKey === '_omi_article_id') {
                    $row[$omiKey] = (int) $value;
                } else {
                    $row[$omiKey] = is_string($value) ? sanitize_text_field($value) : (string) $value;
                }
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
        foreach ([self::META_KEY, 'virtual_comments'] as $metaKey) {
            $decoded = self::decode_virtual_meta_raw(get_post_meta($postId, $metaKey, true));
            if ($decoded === null || $decoded === []) {
                continue;
            }

            return self::enrich_virtual_items_with_ratings($decoded, $postId);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function decode_virtual_meta_raw(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = json_decode(stripslashes($raw), true);
        }

        // Double-encoded JSON string: "\"[{...}]\"" or "[{...}]" stored as quoted string.
        if (is_string($decoded)) {
            $decoded = json_decode(trim($decoded), true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        return array_values($decoded);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function enrich_virtual_items_with_ratings(array $items, int $postId): array
    {
        $isProduct = get_post_type($postId) === 'product';
        $enriched = [];
        $index = 0;

        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($isProduct) {
                $item['rating'] = self::resolve_item_rating($item, $index);
            } elseif (! isset($item['rating'])) {
                $explicit = self::resolve_explicit_rating_from_item($item);
                if ($explicit !== null) {
                    $item['rating'] = $explicit;
                }
            }

            $enriched[] = $item;
            $index++;
        }

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function resolve_explicit_rating_from_item(array $item): ?int
    {
        foreach (['rating', 'star_ranking', 'stars', 'star'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(1, min(5, (int) $item[$key]));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function resolve_item_rating(array $item, int $index = 0): int
    {
        $explicit = self::resolve_explicit_rating_from_item($item);
        if ($explicit !== null) {
            return $explicit;
        }

        $cycle = [5, 5, 4];

        return $cycle[$index % count($cycle)];
    }

    private static function resolve_rating_for_comment_id(int $commentId): ?int
    {
        if ($commentId >= 0) {
            return null;
        }

        $decoded = self::decode_virtual_comment_id($commentId);
        if ($decoded === null) {
            return null;
        }

        $postId = (int) $decoded['post_id'];
        $decodedItems = null;

        foreach ([self::META_KEY, 'virtual_comments'] as $metaKey) {
            $decodedItems = self::decode_virtual_meta_raw(get_post_meta($postId, $metaKey, true));
            if ($decodedItems !== null && $decodedItems !== []) {
                break;
            }
        }

        if (! is_array($decodedItems)) {
            return null;
        }

        $item = $decodedItems[((int) $decoded['index']) - 1] ?? null;
        if (! is_array($item)) {
            return null;
        }

        return self::resolve_item_rating($item, ((int) $decoded['index']) - 1);
    }

    /**
     * @return array{count: int, average: float, ratings: array<int, int>}|false
     */
    public static function get_virtual_reviews_stats(int $postId)
    {
        $objects = self::build_virtual_comment_objects_for_post($postId);
        if ($objects === []) {
            return false;
        }

        $sum = 0;
        $ratings = [];

        foreach ($objects as $comment) {
            if (! $comment instanceof \WP_Comment) {
                continue;
            }

            $commentId = (int) $comment->comment_ID;
            $rating = self::$ratings[$commentId] ?? 5;
            $rating = max(1, min(5, (int) $rating));
            $sum += $rating;
            if (! isset($ratings[$rating])) {
                $ratings[$rating] = 0;
            }
            $ratings[$rating]++;
        }

        $total = count($objects);
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

            $dates[] = self::format_datetime(
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
                return self::format_datetime($timestamp);
            }
        }

        return $fallbackDate;
    }

    private static function format_datetime(int $timestamp): string
    {
        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp);
        }

        return date_i18n('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Ngày hiển thị theo Cài đặt WordPress (date_format / time_format), giống review WC.
     */
    public static function format_review_date_for_display(string $dateRaw): string
    {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            return '';
        }

        $timestamp = strtotime($dateRaw);
        if ($timestamp === false) {
            return $dateRaw;
        }

        if (function_exists('wc_date_format')) {
            $format = wc_date_format();
        } else {
            $format = trim(
                (string) get_option('date_format', 'F j, Y')
                . ' '
                . (string) get_option('time_format', 'g:i a'),
            );
        }

        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp);
        }

        return date_i18n($format, $timestamp);
    }

    public static function format_review_date_for_datetime_attr(string $dateRaw): string
    {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            return '';
        }

        $timestamp = strtotime($dateRaw);
        if ($timestamp === false) {
            return '';
        }

        if (function_exists('wp_date')) {
            return wp_date('c', $timestamp);
        }

        return gmdate('c', $timestamp);
    }

    public static function build_virtual_comment_object(int $commentId): ?\WP_Comment
    {
        if ($commentId >= 0) {
            return null;
        }

        if (isset(self::$virtualCommentCache[$commentId])) {
            return self::$virtualCommentCache[$commentId];
        }

        $decoded = self::decode_virtual_comment_id($commentId);
        if ($decoded === null) {
            return null;
        }

        $postId = (int) $decoded['post_id'];
        $index = (int) $decoded['index'];
        $items = self::get_virtual_items($postId);
        $item = $items[$index - 1] ?? null;
        if (! is_array($item)) {
            return null;
        }

        $postType = get_post_type($postId) ?: 'post';
        $commentDate = isset($item['date']) && is_string($item['date']) && $item['date'] !== ''
            ? $item['date']
            : current_time('mysql');
        $rating = isset($item['rating']) ? max(1, min(5, (int) $item['rating'])) : 5;
        self::$ratings[$commentId] = $rating;

        $commentData = [
            'comment_ID'           => $commentId,
            'comment_post_ID'      => $postId,
            'comment_author'       => sanitize_text_field((string) ($item['author'] ?? 'Khách mua hàng')),
            'comment_author_email' => 'customer.' . abs($commentId) . '@example.com',
            'comment_author_url'   => '',
            'comment_author_IP'    => '127.0.0.1',
            'comment_date'         => $commentDate,
            'comment_date_gmt'     => get_gmt_from_date($commentDate),
            'comment_content'      => wp_kses_post((string) ($item['content'] ?? $item['comment'] ?? $item['review'] ?? '')),
            'comment_karma'        => 0,
            'comment_approved'     => '1',
            'comment_agent'        => 'OMI SEO AI Engine',
            'comment_type'         => $postType === 'product' ? 'review' : 'comment',
            'comment_parent'       => 0,
            'user_id'              => 0,
        ];

        $commentObject = new \WP_Comment((object) $commentData);
        self::$virtualCommentCache[$commentId] = $commentObject;

        return $commentObject;
    }

    /**
     * @return array{post_id: int, index: int}|null
     */
    private static function decode_virtual_comment_id(int $commentId): ?array
    {
        if ($commentId >= 0) {
            return null;
        }

        $abs = abs($commentId);
        $postId = intdiv($abs, 1000);
        $index = $abs % 1000;

        if ($postId <= 0 || $index <= 0) {
            return null;
        }

        return [
            'post_id' => $postId,
            'index'   => $index,
        ];
    }

    public static function flush_pending_wc_transients(): void
    {
        if (! function_exists('wc_delete_product_transients')) {
            self::$pendingWcTransientFlush = [];

            return;
        }

        foreach (array_values(array_unique(self::$pendingWcTransientFlush)) as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            try {
                wc_delete_product_transients($postId);
            } catch (\Throwable $exception) {
                if (class_exists(Rest_Debug::class)) {
                    Rest_Debug::log('wc_transient_flush_failed', [
                        'post_id' => $postId,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        self::$pendingWcTransientFlush = [];
    }

}
