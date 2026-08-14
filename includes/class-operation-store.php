<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stable operation_id replay for Laravel → WP mutations.
 */
final class Operation_Store
{
    public const META_KEY = '_omi_publish_operation_key';

    public const OPTION_PREFIX = 'omi_seo_op_';

    /**
     * @return array<string, mixed>|null
     */
    public static function lookup(string $operationId): ?array
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return null;
        }

        $cached = self::lookup_any($operationId);
        if (is_array($cached) && (int) ($cached['wp_post_id'] ?? 0) > 0) {
            return $cached;
        }

        $ids = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
            'meta_key' => self::META_KEY,
            'meta_value' => $operationId,
            'fields' => 'ids',
            'posts_per_page' => 1,
            'suppress_filters' => true,
        ]);
        $postId = (int) ($ids[0] ?? 0);
        if ($postId <= 0) {
            return null;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        return self::result_payload($postId, $post, true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function remember(string $operationId, int $postId, array $result = []): void
    {
        $operationId = trim($operationId);
        if ($operationId === '' || $postId <= 0) {
            return;
        }

        update_post_meta($postId, self::META_KEY, $operationId);

        $post = get_post($postId);
        $payload = $post instanceof \WP_Post
            ? self::result_payload($postId, $post, true)
            : array_merge(['wp_post_id' => $postId, 'already_processed' => true], $result);

        update_option(self::option_key($operationId), $payload, false);
    }

    /**
     * Generic (non-post) replay, e.g. plugin self-update.
     *
     * @return array<string, mixed>|null
     */
    public static function lookup_any(string $operationId): ?array
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return null;
        }

        $cached = get_option(self::option_key($operationId), null);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function remember_any(string $operationId, array $result): void
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return;
        }

        update_option(self::option_key($operationId), $result, false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function replay_response(\WP_Post $post): array
    {
        return self::result_payload((int) $post->ID, $post, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function result_payload(int $postId, \WP_Post $post, bool $alreadyProcessed): array
    {
        return [
            'already_processed' => $alreadyProcessed,
            'wp_post_id' => $postId,
            'post_status' => (string) $post->post_status,
            'slug' => (string) $post->post_name,
            'permalink' => Permalink_Resolver::for_post($postId),
            'status' => (string) $post->post_status,
        ];
    }

    private static function option_key(string $operationId): string
    {
        return self::OPTION_PREFIX.hash('sha256', $operationId);
    }
}
