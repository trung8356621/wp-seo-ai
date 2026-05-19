<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class Rest_Controller
{
    public const NAMESPACE = 'omi-seo-ai/v1';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/sync', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync'],
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'is_test' => [
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => static fn ($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                ],
                'limit_per_type' => [
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_post'],
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/comment-reviews', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_publish_comment_reviews'],
            'permission_callback' => [self::class, 'authorize_write'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);
    }

    public static function authorize(WP_REST_Request $request): bool
    {
        $expected = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, '');
        if ($expected === '') {
            return false;
        }

        $token = self::extract_bearer_token($request);
        if ($token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function authorize_write(WP_REST_Request $request): bool
    {
        $expected = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_WRITE, '');
        if ($expected === '') {
            return false;
        }

        $token = self::extract_bearer_token($request);
        if ($token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function handle_publish_comment_reviews(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $items = $body['items'] ?? [];
        if (! is_array($items)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Field "items" must be an array.',
            ], 400);
        }

        $publisher = new Comment_Review_Publisher();
        $result = $publisher->publish_batch($postId, $items);

        $post = get_post($postId);
        $postType = $post instanceof \WP_Post ? (string) $post->post_type : '';

        return new WP_REST_Response([
            'success' => $result['errors'] === [] || $result['created'] !== [],
            'wp_post_id' => $postId,
            'wp_post_type' => $postType,
            'created_count' => count($result['created']),
            'error_count' => count($result['errors']),
            'created' => $result['created'],
            'errors' => $result['errors'],
        ], $result['created'] === [] ? 422 : 200);
    }

    public static function handle_post(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $mapped = (new Sync_Provider())->map_post_by_id($postId);
        if ($mapped === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'post' => [
                'wp_id' => (int) ($mapped['wp_id'] ?? $postId),
                'title' => (string) ($mapped['title'] ?? ''),
                'slug' => (string) ($mapped['slug'] ?? ''),
                'status' => (string) ($mapped['status'] ?? ''),
                'published_at' => $mapped['published_at'] ?? null,
                'post_content' => (string) ($mapped['post_content'] ?? ''),
                'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
                'product_gallery' => is_array($mapped['product_gallery'] ?? null)
                    ? $mapped['product_gallery']
                    : [],
                'permalink' => (string) get_permalink($postId),
            ],
        ], 200);
    }

    public static function handle_sync(WP_REST_Request $request): WP_REST_Response
    {
        $isTest = (bool) $request->get_param('is_test');
        $limitPerType = (int) $request->get_param('limit_per_type');
        if ($limitPerType <= 0 && $isTest) {
            $limitPerType = 2;
        }

        $provider = new Sync_Provider();
        $payload = $provider->collect($limitPerType);

        return new WP_REST_Response([
            'success' => true,
            'message' => $isTest
                ? 'Test sync payload generated.'
                : 'Sync payload generated.',
            'is_test' => $isTest,
            'limit_per_type' => $limitPerType,
            'counts'  => $payload['counts'],
            'items'   => $payload['items'],
        ], 200);
    }

    private static function extract_bearer_token(WP_REST_Request $request): string
    {
        $auth = (string) $request->get_header('authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }

        $alt = (string) $request->get_header('x-omi-read-token');
        if ($alt !== '') {
            return trim($alt);
        }

        $writeAlt = (string) $request->get_header('x-omi-write-token');
        if ($writeAlt !== '') {
            return trim($writeAlt);
        }

        return '';
    }
}
