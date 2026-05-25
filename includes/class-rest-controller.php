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

        register_rest_route(self::NAMESPACE, '/site-info', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_site_info'],
            'permission_callback' => [self::class, 'authorize'],
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

        register_rest_route(self::NAMESPACE, '/attachments/(?P<id>\d+)/replace-binary', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_replace_attachment_binary'],
            'permission_callback' => [self::class, 'authorize_write'],
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

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/virtual-comments', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_save_virtual_comments'],
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

        register_rest_route(self::NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)/editor-sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_term_editor_sync'],
            'permission_callback' => [self::class, 'authorize_write'],
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

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/media', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_post_media'],
            'permission_callback' => [self::class, 'authorize_write'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)/media', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_term_media'],
            'permission_callback' => [self::class, 'authorize_write'],
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

    public static function handle_replace_attachment_binary(WP_REST_Request $request): WP_REST_Response
    {
        $attachmentId = (int) $request->get_param('id');
        $resolved = self::resolve_attachment_upload_temp_file($request);

        if ($resolved === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Thiếu file ảnh (multipart field "file" hoặc raw body với Content-Type image/*).',
            ], 400);
        }

        [$tempPath, $mime, $unlinkTemp] = $resolved;

        try {
            $replacer = new Attachment_Binary_Replacer();
            $result = $replacer->replace($attachmentId, $tempPath, $mime);
        } finally {
            if ($unlinkTemp && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $status = ($result['success'] ?? false) ? 200 : 422;

        return new WP_REST_Response($result, $status);
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null [tempPath, mime, unlinkAfterUse]
     */
    private static function resolve_attachment_upload_temp_file(WP_REST_Request $request): ?array
    {
        $files = $request->get_file_params();
        $uploaded = $files['file'] ?? $files['image'] ?? null;

        if (is_array($uploaded) && ! empty($uploaded['tmp_name']) && is_file((string) $uploaded['tmp_name'])) {
            $mime = isset($uploaded['type']) ? (string) $uploaded['type'] : '';

            return [(string) $uploaded['tmp_name'], $mime, false];
        }

        $body = $request->get_body();
        if (! is_string($body) || $body === '') {
            return null;
        }

        $contentType = $request->get_content_type();
        $mime = '';
        if (is_array($contentType)) {
            $mime = trim((string) ($contentType['value'] ?? ''));
        }

        if ($mime === '' || ! str_starts_with(strtolower($mime), 'image/')) {
            $mime = 'image/jpeg';
        }

        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tempPath = wp_tempnam('omi-replace');
        if (! is_string($tempPath) || $tempPath === '') {
            return null;
        }

        if (file_put_contents($tempPath, $body) === false) {
            @unlink($tempPath);

            return null;
        }

        return [$tempPath, $mime, true];
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

        $normalized = Faq_Shortcode::normalize_faq_payload($faqs);

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
            $normalized = Faq_Shortcode::normalize_faq_payload($faqs);
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

        $virtualCount = self::apply_virtual_comments_from_body($postId, $body, $post->post_type === 'product');

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Đã đồng bộ bài viết từ SEO editor.',
            'wp_post_id' => $postId,
            'faq_count' => $faqCount,
            'virtual_count' => $virtualCount,
        ], 200);
    }

    public static function handle_save_virtual_comments(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $count = self::apply_virtual_comments_from_body($postId, $body, $post->post_type === 'product');

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Virtual comments saved.',
            'wp_post_id' => $postId,
            'virtual_count' => $count,
            'count' => $count,
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function apply_virtual_comments_from_body(int $postId, array $body, bool $isProduct): int
    {
        $items = null;

        if (isset($body['virtual_comments']) && is_array($body['virtual_comments'])) {
            $items = $body['virtual_comments'];
        } elseif (isset($body['items']) && is_array($body['items'])) {
            $items = $body['items'];
        } elseif (isset($body['meta_input']) && is_array($body['meta_input'])) {
            $raw = $body['meta_input'][Virtual_Comments::META_KEY] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
        }

        if ($items === null) {
            return count(Virtual_Comments::get_virtual_items($postId));
        }

        $result = Virtual_Comments::save_for_post($postId, $items);

        return (int) ($result['count'] ?? 0);
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

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $result = Virtual_Comments::save_for_post($postId, $items);
        $postType = (string) $post->post_type;
        $count = (int) ($result['count'] ?? 0);

        return new WP_REST_Response([
            'success' => (bool) ($result['success'] ?? false) && $count > 0,
            'wp_post_id' => $postId,
            'wp_post_type' => $postType,
            'created_count' => $count,
            'error_count' => $count > 0 ? 0 : 1,
            'virtual_count' => $count,
            'created' => $count > 0
                ? array_map(
                    static fn (int $index): array => [
                        'index' => $index,
                        'virtual' => true,
                    ],
                    range(0, $count - 1),
                )
                : [],
            'errors' => $count > 0 ? [] : [[
                'index' => -1,
                'message' => (string) ($result['message'] ?? 'No valid virtual comments.'),
            ]],
        ], $count > 0 ? 200 : 422);
    }

    public static function handle_term_editor_sync(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = (string) $request->get_param('taxonomy');
        $termId = (int) $request->get_param('id');

        $term = get_term($termId, $taxonomy);
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Term not found.',
            ], 404);
        }

        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $update = [];
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title !== '') {
            $update['name'] = $title;
        }

        $slug = isset($body['slug']) ? sanitize_title((string) $body['slug']) : '';
        if ($slug !== '') {
            $update['slug'] = $slug;
        }

        $description = null;
        $rawDescription = $body['post_content'] ?? null;
        if (is_string($rawDescription) && $rawDescription !== '') {
            $description = $rawDescription;
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
            Faq_Shortcode::store_faqs_for_term($termId, $normalized);
            $faqCount = count($normalized);

            if (
                $description === null
                && $normalized !== []
                && ! has_shortcode((string) $term->description, 'omi_faq')
            ) {
                $description = rtrim((string) $term->description) . "\n\n[omi_faq]";
            }
        }

        if ($update !== []) {
            update_term_meta($termId, '_omi_seo_ai_skip_push', '1');
            $result = wp_update_term($termId, $taxonomy, $update);
            delete_term_meta($termId, '_omi_seo_ai_skip_push');

            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $result->get_error_message(),
                ], 422);
            }
        }

        if (is_string($description)) {
            $descriptionResult = self::update_term_description_raw($termId, $taxonomy, $description);
            if (is_wp_error($descriptionResult)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $descriptionResult->get_error_message(),
                ], 422);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Đã đồng bộ danh mục từ SEO editor.',
            'wp_post_id' => $termId,
            'taxonomy' => $taxonomy,
            'faq_count' => $faqCount,
        ], 200);
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
                'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
                'product_gallery' => is_array($mapped['product_gallery'] ?? null)
                    ? $mapped['product_gallery']
                    : [],
                'post_images' => is_array($mapped['post_images'] ?? null)
                    ? $mapped['post_images']
                    : [],
                'permalink' => (string) ($mapped['permalink'] ?? ''),
                'faqs' => is_array($mapped['faqs'] ?? null) ? $mapped['faqs'] : [],
            ],
        ], 200);
    }

    public static function handle_post_media(WP_REST_Request $request): WP_REST_Response
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

        if (array_key_exists('featured_attachment_id', $body)) {
            self::apply_post_featured_image($postId, (int) $body['featured_attachment_id']);
        }

        if (array_key_exists('product_gallery_ids', $body) && is_array($body['product_gallery_ids'])) {
            self::apply_post_product_gallery($postId, $body['product_gallery_ids']);
        }

        $mapped = (new Sync_Provider())->map_post_by_id($postId);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Đã cập nhật media.',
            'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
            'product_gallery' => is_array($mapped['product_gallery'] ?? null)
                ? $mapped['product_gallery']
                : [],
        ], 200);
    }

    public static function handle_term_media(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = (string) $request->get_param('taxonomy');
        $termId = (int) $request->get_param('id');

        $term = get_term($termId, $taxonomy);
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Term not found.',
            ], 404);
        }

        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        if (array_key_exists('featured_attachment_id', $body)) {
            self::apply_term_thumbnail($termId, (int) $body['featured_attachment_id']);
        }

        $mapped = (new Sync_Provider())->map_term_by_id($taxonomy, $termId);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Đã cập nhật ảnh danh mục.',
            'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
            'product_gallery' => [],
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

    public static function handle_site_info(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $info = Seo_Plugin_Resolver::site_info();

        return new WP_REST_Response([
            'success'   => true,
            'message'   => 'Site SEO plugin info.',
            'site_info' => $info,
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

    private static function apply_post_featured_image(int $postId, int $attachmentId): void
    {
        if ($attachmentId > 0) {
            set_post_thumbnail($postId, $attachmentId);
        } else {
            delete_post_thumbnail($postId);
        }
    }

    /**
     * @param  array<int, mixed>  $attachmentIds
     */
    private static function apply_post_product_gallery(int $postId, array $attachmentIds): void
    {
        $ids = array_values(array_filter(array_map(static fn ($id): int => max(0, (int) $id), $attachmentIds)));
        update_post_meta($postId, '_product_image_gallery', implode(',', $ids));
    }

    private static function apply_term_thumbnail(int $termId, int $attachmentId): void
    {
        if ($attachmentId > 0) {
            update_term_meta($termId, 'thumbnail_id', $attachmentId);
        } else {
            delete_term_meta($termId, 'thumbnail_id');
        }
    }

    /**
     * Cập nhật raw HTML cho taxonomy description (không qua wp_update_term để tránh bị kses strip).
     *
     * @return true|\WP_Error
     */
    private static function update_term_description_raw(int $termId, string $taxonomy, string $description)
    {
        global $wpdb;

        if (! isset($wpdb->term_taxonomy)) {
            return new \WP_Error('omi_term_update_failed', 'WordPress DB table term_taxonomy not available.');
        }

        $updated = $wpdb->update(
            $wpdb->term_taxonomy,
            ['description' => $description],
            [
                'term_id' => $termId,
                'taxonomy' => $taxonomy,
            ],
            ['%s'],
            ['%d', '%s'],
        );

        if ($updated === false) {
            return new \WP_Error('omi_term_update_failed', 'Failed to update term description.');
        }

        clean_term_cache($termId, $taxonomy);

        return true;
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
