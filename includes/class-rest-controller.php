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

        register_rest_route(self::NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_term'],
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'taxonomy' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): string => sanitize_key((string) $value),
                ],
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
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

        register_rest_route(self::NAMESPACE, '/attachments/rename', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_rename_attachments'],
            'permission_callback' => [self::class, 'authorize_write'],
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

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/seo-faq', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_sync_seo_faq'],
            'permission_callback' => [self::class, 'authorize_write'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/editor-sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_editor_sync'],
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

    public static function handle_rename_attachments(WP_REST_Request $request): WP_REST_Response
    {
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

        $renamer = new Attachment_Renamer();
        $result = $renamer->rename_batch($items);

        $renamedCount = count($result['renamed']);
        $errorCount = count($result['errors']);

        return new WP_REST_Response([
            'success' => $renamedCount > 0 || ($renamedCount === 0 && $errorCount === 0),
            'renamed_count' => $renamedCount,
            'error_count' => $errorCount,
            'posts_updated' => (int) ($result['posts_updated'] ?? 0),
            'renamed' => $result['renamed'],
            'errors' => $result['errors'],
        ], $errorCount > 0 && $renamedCount === 0 ? 422 : 200);
    }

    public static function handle_sync_seo_faq(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $faqs = $body['faqs'] ?? [];
        if (! is_array($faqs)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Field "faqs" must be an array.',
            ], 400);
        }

        $normalized = [];
        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }
            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            $more = trim((string) ($faq['more'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $normalized[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => $more,
            ];
        }

        Faq_Shortcode::store_faqs($postId, $normalized);

        $postContent = $body['post_content'] ?? null;
        if (is_string($postContent) && $postContent !== '') {
            update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
            wp_update_post([
                'ID' => $postId,
                'post_content' => $postContent,
            ]);
            delete_post_meta($postId, '_omi_seo_ai_skip_push');
        } elseif ($normalized !== [] && ! has_shortcode((string) $post->post_content, 'omi_faq')) {
            update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
            wp_update_post([
                'ID' => $postId,
                'post_content' => rtrim((string) $post->post_content) . "\n\n[omi_faq]",
            ]);
            delete_post_meta($postId, '_omi_seo_ai_skip_push');
        }

        return new WP_REST_Response([
            'success' => true,
            'wp_post_id' => $postId,
            'faq_count' => count($normalized),
        ], 200);
    }

    public static function handle_editor_sync(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $update = ['ID' => $postId];
        $changed = false;

        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title !== '') {
            $update['post_title'] = $title;
            $changed = true;
        }

        $slug = isset($body['slug']) ? sanitize_title((string) $body['slug']) : '';
        if ($slug !== '') {
            $update['post_name'] = $slug;
            $changed = true;
        }

        $status = isset($body['status']) ? sanitize_key((string) $body['status']) : '';
        $allowedStatuses = ['publish', 'draft', 'pending', 'future', 'private'];
        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $update['post_status'] = $status;
            $changed = true;
        }

        $postContent = $body['post_content'] ?? null;
        if (is_string($postContent) && $postContent !== '') {
            $update['post_content'] = $postContent;
            $changed = true;
        }

        $faqs = $body['faqs'] ?? [];
        $faqCount = 0;
        if (is_array($faqs)) {
            $normalized = [];
            foreach ($faqs as $faq) {
                if (! is_array($faq)) {
                    continue;
                }
                $question = trim((string) ($faq['question'] ?? ''));
                $answer = trim((string) ($faq['answer'] ?? ''));
                $more = trim((string) ($faq['more'] ?? ''));
                if ($question === '' || $answer === '') {
                    continue;
                }
                $normalized[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'more' => $more,
                ];
            }
            Faq_Shortcode::store_faqs($postId, $normalized);
            $faqCount = count($normalized);

            if (
                ! isset($update['post_content'])
                && $normalized !== []
                && ! has_shortcode((string) $post->post_content, 'omi_faq')
            ) {
                $update['post_content'] = rtrim((string) $post->post_content) . "\n\n[omi_faq]";
                $changed = true;
            }
        }

        if ($changed) {
            update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
            $result = wp_update_post($update, true);
            delete_post_meta($postId, '_omi_seo_ai_skip_push');

            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $result->get_error_message(),
                ], 422);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Đã đồng bộ bài viết từ SEO editor.',
            'wp_post_id' => $postId,
            'faq_count' => $faqCount,
        ], 200);
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

    public static function handle_term(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = (string) $request->get_param('taxonomy');
        $termId = (int) $request->get_param('id');

        $mapped = (new Sync_Provider())->map_term_by_id($taxonomy, $termId);
        if ($mapped === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Term not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'post' => [
                'wp_id' => (int) ($mapped['wp_id'] ?? $termId),
                'type' => (string) ($mapped['type'] ?? ''),
                'wp_post_type' => (string) ($mapped['wp_post_type'] ?? $taxonomy),
                'wp_entity' => 'term',
                'title' => (string) ($mapped['title'] ?? ''),
                'slug' => (string) ($mapped['slug'] ?? ''),
                'status' => (string) ($mapped['status'] ?? 'publish'),
                'published_at' => $mapped['published_at'] ?? null,
                'post_content' => (string) ($mapped['post_content'] ?? ''),
                'featured_image_url' => '',
                'product_gallery' => [],
                'post_images' => is_array($mapped['post_images'] ?? null)
                    ? $mapped['post_images']
                    : [],
                'permalink' => (string) ($mapped['permalink'] ?? ''),
            ],
        ], 200);
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
                'post_images' => is_array($mapped['post_images'] ?? null)
                    ? $mapped['post_images']
                    : [],
                'permalink' => (string) ($mapped['permalink'] ?? get_permalink($postId)),
                'wp_entity' => 'post',
                'type' => (string) ($mapped['type'] ?? ''),
                'wp_post_type' => (string) ($mapped['wp_post_type'] ?? ''),
                'faqs' => is_array($mapped['faqs'] ?? null) ? $mapped['faqs'] : [],
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
