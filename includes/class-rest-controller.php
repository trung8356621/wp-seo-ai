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

        register_rest_route(self::NAMESPACE, '/sync/manifest', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync_manifest'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/items', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_sync_items'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/site-info', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_site_info'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/capabilities', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_capabilities'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/heartbeat', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_heartbeat'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugin-update/check', [
            'methods'             => WP_REST_Server::READABLE | WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_plugin_update_check'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugin-update/install', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_plugin_update_install'],
            'permission_callback' => [self::class, 'authorize_write'],
        ]);

        register_rest_route(self::NAMESPACE, '/link-health/batch', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_link_health_batch'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/v2/profile', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync_v2_profile'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/v2/delta', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync_v2_delta'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/v2/batches', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_sync_v2_batches'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/v2/manifest', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync_v2_manifest'],
            'permission_callback' => [self::class, 'authorize'],
        ]);

        register_rest_route(self::NAMESPACE, '/taxonomies/(?P<taxonomy>[a-z0-9_-]+)/terms', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_taxonomy_terms'],
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'taxonomy' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): string => sanitize_key((string) $value),
                ],
                'hide_empty' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
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

        register_rest_route(self::NAMESPACE, '/posts', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_create_post'],
            'permission_callback' => [self::class, 'authorize_write'],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/find-by-article', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_find_post_by_article'],
            'permission_callback' => [self::class, 'authorize_write'],
            'args'                => [
                'article_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
                'sync_key' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => static fn ($value): string => sanitize_text_field((string) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/rename', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_rename_attachments'],
            'permission_callback' => [self::class, 'authorize_write'],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/usage', [
            'methods'             => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
            'callback'            => [self::class, 'handle_attachment_usage'],
            'permission_callback' => static function (WP_REST_Request $request): bool {
                return self::authorize_write($request) || self::authorize($request);
            },
            'args'                => [
                'attachment_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
                'old_url' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => static fn ($value): string => esc_url_raw(trim((string) $value)),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/update-meta', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_update_attachment_meta'],
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

        register_rest_route(self::NAMESPACE, '/attachments/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_import_attachment'],
            'permission_callback' => [self::class, 'authorize_write'],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/delete', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_delete_attachment_by_body'],
            'permission_callback' => [self::class, 'authorize_write'],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/(?P<id>\d+)/delete', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_delete_attachment'],
            'permission_callback' => [self::class, 'authorize_write'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/attachments/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [self::class, 'handle_delete_attachment'],
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

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/comment-reviews', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_comment_reviews'],
            'permission_callback' => [self::class, 'authorize'],
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
            'callback'            => static function (WP_REST_Request $request): WP_REST_Response {
                return Rest_Debug::wrap(
                    'POST /posts/{id}/virtual-comments',
                    [Rest_Controller::class, 'handle_save_virtual_comments'],
                    $request,
                );
            },
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
            'callback'            => static function (WP_REST_Request $request): WP_REST_Response {
                return Rest_Debug::wrap(
                    'POST /posts/{id}/editor-sync',
                    [Rest_Controller::class, 'handle_editor_sync'],
                    $request,
                );
            },
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
        $parentId = 0;
        $attachment = get_post($attachmentId);
        if ($attachment instanceof \WP_Post) {
            $parentId = (int) $attachment->post_parent;
        }

        return self::finish_post_write(
            new WP_REST_Response($result, $status),
            $parentId,
            ['source' => 'replace-binary'],
        );
    }

    public static function handle_import_attachment(WP_REST_Request $request): WP_REST_Response
    {
        $title = trim((string) $request->get_param('title'));
        $slug = sanitize_title((string) $request->get_param('slug'));
        $altText = trim((string) $request->get_param('alt_text'));
        $sourceUrl = trim((string) $request->get_param('source_url'));

        $resolved = self::resolve_attachment_upload_temp_file($request);
        $tempPath = '';
        $mime = '';
        $unlinkTemp = false;

        if ($resolved !== null) {
            [$tempPath, $mime, $unlinkTemp] = $resolved;
        } elseif ($sourceUrl !== '') {
            if (! function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $downloaded = download_url($sourceUrl, 60);
            if (! is_wp_error($downloaded) && is_string($downloaded) && is_file($downloaded)) {
                $tempPath = $downloaded;
                $mime = '';
                $unlinkTemp = true;
            }
        }

        if ($tempPath === '' || ! is_file($tempPath)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Thiếu file ảnh (multipart field "file"/"image") hoặc source_url hợp lệ.',
            ], 400);
        }

        try {
            if (! function_exists('wp_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (! function_exists('wp_insert_attachment')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            if (! function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $extension = pathinfo($tempPath, PATHINFO_EXTENSION);
            if (! is_string($extension) || $extension === '') {
                $extension = 'jpg';
            }
            $filename = ($slug !== '' ? $slug : 'seo-media-' . time()) . '.' . $extension;

            $fileArray = [
                'name' => $filename,
                'tmp_name' => $tempPath,
                'error' => 0,
                'size' => (int) filesize($tempPath),
            ];
            if ($mime !== '') {
                $fileArray['type'] = $mime;
            }

            $sideload = wp_handle_sideload($fileArray, [
                'test_form' => false,
            ]);

            if (! is_array($sideload) || ! empty($sideload['error'])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Không tải được file vào media library: ' . (string) ($sideload['error'] ?? 'unknown'),
                ], 422);
            }

            $attachment = [
                'post_mime_type' => (string) ($sideload['type'] ?? $mime ?: 'image/jpeg'),
                'post_title' => $title !== '' ? $title : pathinfo($filename, PATHINFO_FILENAME),
                'post_status' => 'inherit',
            ];
            if ($slug !== '') {
                $attachment['post_name'] = $slug;
            }

            $attachmentId = wp_insert_attachment($attachment, (string) $sideload['file']);
            if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => is_wp_error($attachmentId)
                        ? $attachmentId->get_error_message()
                        : 'Không tạo được attachment.',
                ], 422);
            }

            $meta = wp_generate_attachment_metadata((int) $attachmentId, (string) $sideload['file']);
            if (is_array($meta)) {
                wp_update_attachment_metadata((int) $attachmentId, $meta);
            }

            if ($altText !== '') {
                update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $altText);
            }

            $url = wp_get_attachment_url((int) $attachmentId);
            $post = get_post((int) $attachmentId);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Đã import ảnh vào WordPress.',
                'attachment_id' => (int) $attachmentId,
                'url' => is_string($url) ? $url : '',
                'slug' => $post instanceof \WP_Post ? (string) $post->post_name : '',
            ], 200);
        } finally {
            if ($unlinkTemp && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public static function handle_delete_attachment_by_body(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $attachmentId = (int) ($body['attachment_id'] ?? $body['id'] ?? 0);
        if ($attachmentId <= 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Attachment ID không hợp lệ.',
            ], 400);
        }

        $request->set_param('id', $attachmentId);

        return self::handle_delete_attachment($request);
    }

    public static function handle_delete_attachment(WP_REST_Request $request): WP_REST_Response
    {
        $attachmentId = (int) $request->get_param('id');
        if ($attachmentId <= 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Attachment ID không hợp lệ.',
            ], 400);
        }

        $post = get_post($attachmentId);
        if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Attachment không tồn tại.',
            ], 404);
        }

        $url = wp_get_attachment_url($attachmentId);
        $slug = (string) $post->post_name;

        $deleted = wp_delete_attachment($attachmentId, true);
        if (! $deleted) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Không xóa được attachment trên WordPress.',
            ], 422);
        }

        $parentId = (int) $post->post_parent;

        return self::finish_post_write(new WP_REST_Response([
            'success' => true,
            'message' => 'Đã xóa attachment trên WordPress.',
            'attachment_id' => $attachmentId,
            'url' => is_string($url) ? $url : '',
            'slug' => $slug,
        ], 200), $parentId, ['source' => 'delete-attachment']);
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

    public static function handle_attachment_usage(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $attachmentId = (int) ($request->get_param('attachment_id') ?: ($body['attachment_id'] ?? 0));
        $oldUrl = trim((string) ($request->get_param('old_url') ?: ($body['old_url'] ?? '')));

        if ($attachmentId <= 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Field "attachment_id" is required.',
            ], 400);
        }

        $renamer = new Attachment_Renamer();
        $result = $renamer->scan_usage($attachmentId, $oldUrl);

        $status = ($result['success'] ?? false) ? 200 : 404;

        return new WP_REST_Response($result, $status);
    }

    public static function handle_rename_attachments(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $mode = trim((string) ($body['mode'] ?? 'bulk'));
        $items = $body['items'] ?? [];
        if (! is_array($items)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Field "items" must be an array.',
            ], 400);
        }

        if ($mode === 'explicit_single') {
            $acknowledgeUrlChange = filter_var($body['acknowledge_url_change'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $confirmationPhrase = trim((string) ($body['confirmation_phrase'] ?? $body['confirmation_token'] ?? ''));

            if (! $acknowledgeUrlChange) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Field "acknowledge_url_change" must be true for explicit_single rename.',
                ], 400);
            }

            if ($confirmationPhrase !== 'RENAME') {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Field "confirmation_phrase" must be "RENAME" for explicit_single rename.',
                ], 400);
            }

            if (count($items) !== 1) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'explicit_single mode allows exactly one item.',
                ], 400);
            }

            if (isset($items[0]) && is_array($items[0])) {
                $items[0]['strict_collision'] = true;
            }
        }

        $renamer = new Attachment_Renamer();

        if ($mode === 'explicit_single' && isset($items[0]) && is_array($items[0])) {
            $singleResult = $renamer->rename_one_public($items[0]);
            $result = [
                'renamed'       => ($singleResult['success'] ?? false) ? [$singleResult] : [],
                'posts_updated' => (int) ($singleResult['posts_updated'] ?? 0),
                'errors'        => ($singleResult['success'] ?? false) ? [] : [$singleResult],
            ];
        } else {
            $result = $renamer->rename_batch($items);
        }

        $renamedCount = count($result['renamed']);
        $errorCount = count($result['errors']);
        $postsUpdated = (int) ($result['posts_updated'] ?? 0);

        $response = new WP_REST_Response([
            'success' => $renamedCount > 0 || ($renamedCount === 0 && $errorCount === 0),
            'mode' => $mode,
            'renamed_count' => $renamedCount,
            'error_count' => $errorCount,
            'posts_updated' => $postsUpdated,
            'renamed' => $result['renamed'],
            'errors' => $result['errors'],
        ], $errorCount > 0 && $renamedCount === 0 ? 422 : 200);

        if ($postsUpdated > 0) {
            return self::finish_global_write($response, ['source' => 'attachments-rename']);
        }

        return $response;
    }

    public static function handle_update_attachment_meta(WP_REST_Request $request): WP_REST_Response
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

        $updated = [];
        $errors = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? 0);
            if ($attachmentId <= 0) {
                continue;
            }

            $post = get_post($attachmentId);
            if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
                $errors[] = [
                    'attachment_id' => $attachmentId,
                    'message' => 'Attachment not found.',
                ];
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $altText = trim((string) ($item['alt_text'] ?? $item['alt'] ?? ''));

            if ($title !== '') {
                wp_update_post([
                    'ID' => $attachmentId,
                    'post_title' => $title,
                ]);
            }

            if ($altText !== '') {
                update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText);
            }

            $updated[] = [
                'attachment_id' => $attachmentId,
                'title' => $title !== '' ? $title : get_the_title($attachmentId),
                'alt_text' => $altText !== '' ? $altText : (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            ];
        }

        $updatedCount = count($updated);
        $errorCount = count($errors);

        return new WP_REST_Response([
            'success' => $updatedCount > 0 || ($updatedCount === 0 && $errorCount === 0),
            'updated_count' => $updatedCount,
            'error_count' => $errorCount,
            'updated' => $updated,
            'errors' => $errors,
        ], $errorCount > 0 && $updatedCount === 0 ? 422 : 200);
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

        return self::finish_post_write(new WP_REST_Response([
            'success' => true,
            'wp_post_id' => $postId,
            'faq_count' => count($normalized),
        ], 200), $postId, ['source' => 'seo-faq']);
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

        $oldPostType = (string) $post->post_type;
        $oldPermalink = Permalink_Resolver::for_post($postId);
        $update = ['ID' => $postId];
        $changed = false;
        $postTypeChanged = false;

        $requestedPostType = isset($body['post_type'])
            ? sanitize_key((string) $body['post_type'])
            : '';
        if ($requestedPostType !== '') {
            if (! in_array($requestedPostType, ['post', 'product'], true) || ! post_type_exists($requestedPostType)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Unsupported WordPress post type.',
                ], 422);
            }

            if ($requestedPostType !== $oldPostType) {
                $update['post_type'] = $requestedPostType;
                $changed = true;
                $postTypeChanged = true;
            }
        }

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
        // Outbound Laravel → WP: chỉ nhận publish (lịch xử lý ở Laravel). draft/future bị bỏ.
        if ($status === 'future') {
            $status = 'publish';
        }
        $forcePublish = $status === 'publish';
        $allowStatusDemote = filter_var($body['allow_status_demote'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $currentStatus = sanitize_key((string) $post->post_status);
        // Không bao giờ hạ publish/private/future → draft/pending qua editor-sync.
        if (
            $status !== ''
            && in_array($status, $allowedStatuses, true)
            && self::is_status_demote($currentStatus, $status)
            && ! $allowStatusDemote
        ) {
            Rest_Debug::log('editor_sync_status_demote_blocked', [
                'post_id' => $postId,
                'from' => $currentStatus,
                'to' => $status,
            ]);
            $status = '';
        }
        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $update['post_status'] = $status;
            $changed = true;
        }

        $postDate = self::normalize_post_date($body['post_date'] ?? null);
        // publish + post_date tương lai → WP tự đổi thành future. Clamp về now.
        if ($forcePublish && $postDate !== '') {
            $postDateTs = strtotime($postDate);
            if ($postDateTs !== false && $postDateTs > time()) {
                $postDate = current_time('mysql');
            }
        }
        if ($postDate !== '') {
            $update['post_date'] = $postDate;
            $update['post_date_gmt'] = get_gmt_from_date($postDate);
            $update['edit_date'] = true;
            $changed = true;
        }

        $postContent = $body['post_content'] ?? null;
        if (is_string($postContent) && $postContent !== '') {
            $update['post_content'] = $postContent;
            $changed = true;
        }

        // Chỉ cập nhật meta FAQ khi payload có key "faqs". faqs:[] + meta đang có
        // mà không clear_faqs → bỏ qua (tránh sync Laravel gửi [] nhầm xóa accordion).
        $faqCount = 0;
        if (array_key_exists('faqs', $body) && is_array($body['faqs'])) {
            $normalized = Faq_Shortcode::normalize_faq_payload($body['faqs']);
            $existingFaqs = Faq_Shortcode::resolve_faqs_for_post($postId);
            $clearFaqs = ! empty($body['clear_faqs']);

            if ($normalized !== [] || $clearFaqs || $existingFaqs === []) {
                Faq_Shortcode::store_faqs($postId, $normalized);
                $faqCount = count($normalized);
            } else {
                $faqCount = count($existingFaqs);
            }

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
            try {
                update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
                Laravel_Push_Sync::suppress(true);
                $result = self::with_write_capabilities(static function () use ($update) {
                    return wp_update_post($update, true);
                });
                Laravel_Push_Sync::suppress(false);
                delete_post_meta($postId, '_omi_seo_ai_skip_push');

                if (is_wp_error($result)) {
                    return new WP_REST_Response([
                        'success' => false,
                        'message' => $result->get_error_message(),
                    ], 422);
                }
            } catch (\Throwable $exception) {
                Laravel_Push_Sync::suppress(false);
                delete_post_meta($postId, '_omi_seo_ai_skip_push');
                Rest_Debug::log('editor_sync_post_update_failed', [
                    'post_id' => $postId,
                    'message' => $exception->getMessage(),
                ]);

                return Rest_Debug::error_response(
                    'wp_update_post failed during editor-sync.',
                    $exception,
                    422,
                );
            }
        }

        // Bearer token không gắn WP user — ép publish lại nếu core/plugin hạ draft/pending.
        if ($forcePublish) {
            $publishOk = self::force_post_status($postId, 'publish', $postDate);
            if (! $publishOk) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'WordPress từ chối chuyển bài sang publish (vẫn còn '
                        . (string) get_post_status($postId) . ').',
                    'status' => (string) get_post_status($postId),
                    'wp_post_id' => $postId,
                ], 422);
            }
        }

        $updatedPost = get_post($postId);
        if (! $updatedPost instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post was updated but could not be reloaded.',
            ], 422);
        }

        $newPermalink = Permalink_Resolver::for_post($postId);
        $redirectCreated = false;
        if ($postTypeChanged) {
            $redirectCreated = Redirection_Manager::add_auto($oldPermalink, $newPermalink, $postId);
        }

        $virtualResult = self::apply_virtual_comments_from_body($postId, $body, $updatedPost->post_type === 'product');
        $virtualCount = (int) ($virtualResult['count'] ?? 0);
        $virtualError = (string) ($virtualResult['error'] ?? '');

        $seoApplied = false;
        $seoError = '';
        if (isset($body['seo']) && is_array($body['seo'])) {
            try {
                $seoApplied = Seo_Plugin_Resolver::apply_to_post($postId, $body['seo']);
            } catch (\Throwable $exception) {
                $seoError = $exception->getMessage();
            }
        }

        $categoryCount = 0;
        if (isset($body['category_ids']) && is_array($body['category_ids'])) {
            $taxonomy = (string) $updatedPost->post_type === 'product' ? 'product_cat' : 'category';
            $termIds = array_values(array_unique(array_filter(
                array_map('intval', $body['category_ids']),
                static fn (int $id): bool => $id > 0,
            )));

            if ($termIds !== [] && taxonomy_exists($taxonomy)) {
                $result = wp_set_object_terms($postId, $termIds, $taxonomy, false);
                if (! is_wp_error($result)) {
                    $categoryCount = count($termIds);
                }
            }
        }

        $message = 'Đã đồng bộ bài viết từ SEO editor.';
        if ($postTypeChanged) {
            $message .= sprintf(
                ' Đã chuyển post type từ %s sang %s.',
                $oldPostType,
                (string) $updatedPost->post_type
            );
            if ($redirectCreated) {
                $message .= ' Đã tạo redirect 301 cho URL cũ.';
            }
        }

        $response = [
            'success' => true,
            'message' => $message,
            'wp_post_id' => $postId,
            'post_type' => (string) $updatedPost->post_type,
            'previous_post_type' => $oldPostType,
            'post_type_changed' => $postTypeChanged,
            'redirect_created' => $redirectCreated,
            'slug' => (string) get_post_field('post_name', $postId),
            'permalink' => $newPermalink,
            'status' => (string) $updatedPost->post_status,
            'faq_count' => $faqCount,
            'category_count' => $categoryCount,
            'virtual_count' => $virtualCount,
            'seo_applied' => $seoApplied,
        ];
        $response['post_date'] = (string) $updatedPost->post_date;
        $response['post_modified'] = (string) $updatedPost->post_modified;

        if ($virtualError !== '') {
            $response['virtual_comments_error'] = $virtualError;
        }

        if ($seoError !== '') {
            $response['seo_error'] = $seoError;
        }

        $context = ['source' => 'editor-sync'];
        if ($postTypeChanged && $oldPermalink !== '' && $oldPermalink !== $newPermalink) {
            $context['extra_urls'] = [$oldPermalink];
        }

        return self::finish_post_write(new WP_REST_Response($response, 200), $postId, $context);
    }

    private static function normalize_post_date($value): string
    {
        $date = is_string($value) ? trim($value) : '';
        if ($date === '') {
            return '';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $date)) {
            return '';
        }

        $date = str_replace('T', ' ', $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date)) {
            $date .= ':00';
        }

        return strtotime($date) !== false ? $date : '';
    }

    /**
     * publish/private/future → draft/pending = demote (cấm mặc định).
     */
    private static function is_status_demote(string $from, string $to): bool
    {
        $protected = ['publish', 'private', 'future'];
        $demoted = ['draft', 'pending', 'auto-draft'];

        return in_array($from, $protected, true) && in_array($to, $demoted, true);
    }

    /**
     * REST write dùng Bearer token — không có WP user. Elevate tạm sang admin
     * để wp_update_post / capability filter không hạ publish → pending/draft.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private static function with_write_capabilities(callable $callback)
    {
        $previousUserId = get_current_user_id();
        $adminIds = get_users([
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
        $adminId = isset($adminIds[0]) ? (int) $adminIds[0] : 0;
        if ($adminId > 0) {
            wp_set_current_user($adminId);
        }

        try {
            return $callback();
        } finally {
            wp_set_current_user($previousUserId);
        }
    }

    /**
     * Ép post_status (ưu tiên publish). Trả true khi status cuối khớp.
     */
    private static function force_post_status(int $postId, string $status, string $postDate = ''): bool
    {
        $status = sanitize_key($status);
        if ($postId <= 0 || $status === '') {
            return false;
        }

        if ((string) get_post_status($postId) === $status) {
            return true;
        }

        update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
        Laravel_Push_Sync::suppress(true);

        try {
            self::with_write_capabilities(static function () use ($postId, $status, $postDate): void {
                $payload = [
                    'ID'          => $postId,
                    'post_status' => $status,
                ];

                if ($status === 'publish' && $postDate !== '') {
                    $postDateTs = strtotime($postDate);
                    if ($postDateTs !== false && $postDateTs > time()) {
                        $postDate = current_time('mysql');
                    }
                }

                if ($postDate !== '') {
                    $payload['post_date'] = $postDate;
                    $payload['post_date_gmt'] = get_gmt_from_date($postDate);
                    $payload['edit_date'] = true;
                } elseif ($status === 'publish') {
                    $existingDate = (string) get_post_field('post_date', $postId);
                    if (
                        $existingDate === ''
                        || $existingDate === '0000-00-00 00:00:00'
                        || strtotime($existingDate) > time()
                    ) {
                        $payload['post_date'] = current_time('mysql');
                        $payload['post_date_gmt'] = current_time('mysql', true);
                        $payload['edit_date'] = true;
                    }
                }

                wp_update_post($payload, true);

                $actual = (string) get_post_status($postId);
                if ($status === 'publish' && $actual !== 'publish') {
                    // future/draft/pending → publish thật.
                    wp_publish_post($postId);
                    $actual = (string) get_post_status($postId);
                }

                if ($actual !== $status) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->posts,
                        [
                            'post_status' => $status,
                            'post_date' => $postDate !== '' ? $postDate : current_time('mysql'),
                            'post_date_gmt' => $postDate !== ''
                                ? get_gmt_from_date($postDate)
                                : current_time('mysql', true),
                        ],
                        ['ID' => $postId],
                        ['%s', '%s', '%s'],
                        ['%d'],
                    );
                    clean_post_cache($postId);
                    if ($status === 'publish') {
                        $reloaded = get_post($postId);
                        if ($reloaded instanceof \WP_Post) {
                            do_action('transition_post_status', 'publish', $actual !== '' ? $actual : 'draft', $reloaded);
                            do_action('publish_post', $postId, $reloaded);
                        }
                    }
                }
            });
        } finally {
            Laravel_Push_Sync::suppress(false);
            delete_post_meta($postId, '_omi_seo_ai_skip_push');
        }

        return (string) get_post_status($postId) === $status;
    }

    public static function handle_create_post(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post title is required.',
            ], 422);
        }

        $operationId = trim((string) ($body['operation_id'] ?? $body['publish_operation_key'] ?? $body['_omi_publish_operation_key'] ?? ''));
        if ($operationId !== '') {
            $replay = Operation_Store::lookup($operationId);
            if (is_array($replay)) {
                return new WP_REST_Response(array_merge([
                    'success' => true,
                    'message' => 'Operation already processed.',
                ], $replay), 200);
            }
        }

        $postType = sanitize_key((string) ($body['post_type'] ?? 'post'));
        if (! in_array($postType, ['post', 'product'], true) || ! post_type_exists($postType)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unsupported WordPress post type.',
            ], 422);
        }

        // Create từ Laravel sync: mặc định publish (không nhận future/draft lịch WP).
        $status = sanitize_key((string) ($body['status'] ?? 'publish'));
        if ($status === 'future') {
            $status = 'publish';
        }
        if (! in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
            $status = 'publish';
        }

        $postData = [
            'post_title' => $title,
            'post_status' => $status,
            'post_type' => $postType,
        ];
        $postDate = self::normalize_post_date($body['post_date'] ?? null);
        if ($status === 'publish' && $postDate !== '') {
            $postDateTs = strtotime($postDate);
            if ($postDateTs !== false && $postDateTs > time()) {
                $postDate = current_time('mysql');
            }
        }
        if ($postDate !== '') {
            $postData['post_date'] = $postDate;
            $postData['post_date_gmt'] = get_gmt_from_date($postDate);
        }

        $requestedSlug = sanitize_title((string) ($body['slug'] ?? ''));
        if ($requestedSlug !== '') {
            $postData['post_name'] = $requestedSlug;
        }

        $initialContent = $body['post_content'] ?? null;
        if (is_string($initialContent) && $initialContent !== '') {
            $postData['post_content'] = $initialContent;
        }

        Laravel_Push_Sync::suppress(true);
        try {
            $postId = self::with_write_capabilities(static function () use ($postData) {
                return wp_insert_post($postData, true);
            });
        } finally {
            Laravel_Push_Sync::suppress(false);
        }

        if (is_wp_error($postId)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $postId->get_error_message(),
            ], 422);
        }

        $postId = (int) $postId;

        $articleIdMeta = isset($body['teamvia_article_id'])
            ? (int) $body['teamvia_article_id']
            : (isset($body['_teamvia_article_id']) ? (int) $body['_teamvia_article_id'] : 0);
        $syncKeyMeta = trim((string) ($body['teamvia_sync_key'] ?? $body['_teamvia_sync_key'] ?? ''));
        $operationKeyMeta = trim((string) ($body['operation_id'] ?? $body['publish_operation_key'] ?? $body['_omi_publish_operation_key'] ?? ''));
        if ($articleIdMeta > 0) {
            update_post_meta($postId, '_teamvia_article_id', $articleIdMeta);
        }
        if ($syncKeyMeta !== '') {
            update_post_meta($postId, '_teamvia_sync_key', $syncKeyMeta);
        }
        if ($operationKeyMeta !== '') {
            Operation_Store::remember($operationKeyMeta, $postId);
        }

        if ($status === 'publish') {
            self::force_post_status($postId, 'publish', $postDate);
        }

        $createdPost = get_post($postId);
        $faqCount = 0;
        $seoApplied = false;

        if ($createdPost instanceof \WP_Post) {
            $supplementary = self::apply_supplementary_sync_fields($postId, $body, $createdPost);
            if ($supplementary instanceof WP_REST_Response) {
                return $supplementary;
            }
            $faqCount = (int) ($supplementary['faq_count'] ?? 0);
            $seoApplied = (bool) ($supplementary['seo_applied'] ?? false);
        }

        $message = 'Đã tạo bài viết mới trên WordPress.';
        if ($faqCount > 0) {
            $message .= sprintf(' Đã gắn %d FAQ.', $faqCount);
        }
        if ($seoApplied) {
            $message .= ' Đã áp SEO meta.';
        }

        return self::finish_post_write(new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'wp_post_id' => $postId,
            'slug' => (string) get_post_field('post_name', $postId),
            'permalink' => Permalink_Resolver::for_post($postId),
            'status' => (string) get_post_status($postId),
            'post_date' => (string) get_post_field('post_date', $postId),
            'post_modified' => (string) get_post_field('post_modified', $postId),
            'faq_count' => $faqCount,
            'seo_applied' => $seoApplied,
        ], 201), $postId, ['source' => 'create-post']);
    }

    public static function handle_find_post_by_article(WP_REST_Request $request): WP_REST_Response
    {
        $articleId = (int) $request->get_param('article_id');
        $syncKey = trim((string) $request->get_param('sync_key'));
        $operationKey = trim((string) $request->get_param('operation_key'));
        if ($articleId <= 0 && $operationKey === '' && $syncKey === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'article_id, sync_key, or operation_key is required.',
            ], 422);
        }

        $ids = [];
        if ($articleId > 0) {
            $ids = get_posts([
                'post_type' => ['post', 'product'],
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => 5,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_teamvia_article_id',
                        'value' => $articleId,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ],
                ],
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
        }

        if ((! is_array($ids) || $ids === []) && $operationKey !== '') {
            $ids = get_posts([
                'post_type' => ['post', 'product'],
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => 5,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_omi_publish_operation_key',
                        'value' => $operationKey,
                        'compare' => '=',
                    ],
                ],
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
        }

        if ((! is_array($ids) || $ids === []) && $syncKey !== '') {
            $ids = get_posts([
                'post_type' => ['post', 'product'],
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => 5,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_teamvia_sync_key',
                        'value' => $syncKey,
                        'compare' => '=',
                    ],
                ],
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
        }

        if (! is_array($ids) || $ids === []) {
            return new WP_REST_Response([
                'success' => true,
                'found' => false,
                'wp_post_id' => null,
                'match_count' => 0,
                'ambiguous' => false,
            ], 200);
        }

        $matchCount = count($ids);
        if ($matchCount > 1) {
            return new WP_REST_Response([
                'success' => true,
                'found' => false,
                'ambiguous' => true,
                'match_count' => $matchCount,
                'wp_post_id' => null,
                'candidate_ids' => array_map('intval', array_values($ids)),
            ], 200);
        }

        $postId = (int) $ids[0];

        return new WP_REST_Response([
            'success' => true,
            'found' => true,
            'ambiguous' => false,
            'match_count' => 1,
            'wp_post_id' => $postId,
            'slug' => (string) get_post_field('post_name', $postId),
            'permalink' => Permalink_Resolver::for_post($postId),
            'status' => (string) get_post_status($postId),
            'operation_key' => (string) get_post_meta($postId, '_omi_publish_operation_key', true),
        ], 200);
    }

    /**
     * FAQ, SEO meta và category sau khi post đã tồn tại (create hoặc editor-sync).
     *
     * @return array{faq_count: int, seo_applied: bool, category_count: int}|WP_REST_Response
     */
    private static function apply_supplementary_sync_fields(int $postId, array $body, \WP_Post $post)
    {
        $update = ['ID' => $postId];
        $changed = false;
        $faqCount = 0;

        $faqCount = 0;
        if (array_key_exists('faqs', $body) && is_array($body['faqs'])) {
            $normalized = Faq_Shortcode::normalize_faq_payload($body['faqs']);
            $existingFaqs = Faq_Shortcode::resolve_faqs_for_post($postId);
            $clearFaqs = ! empty($body['clear_faqs']);

            if ($normalized !== [] || $clearFaqs || $existingFaqs === []) {
                Faq_Shortcode::store_faqs($postId, $normalized);
                $faqCount = count($normalized);
            } else {
                $faqCount = count($existingFaqs);
            }

            if (
                $normalized !== []
                && ! has_shortcode((string) $post->post_content, 'omi_faq')
                && ! isset($update['post_content'])
            ) {
                $update['post_content'] = rtrim((string) $post->post_content) . "\n\n[omi_faq]";
                $changed = true;
            }
        }

        if ($changed) {
            try {
                update_post_meta($postId, '_omi_seo_ai_skip_push', '1');
                $result = wp_update_post($update, true);
                delete_post_meta($postId, '_omi_seo_ai_skip_push');

                if (is_wp_error($result)) {
                    return new WP_REST_Response([
                        'success' => false,
                        'message' => $result->get_error_message(),
                    ], 422);
                }
            } catch (\Throwable $exception) {
                delete_post_meta($postId, '_omi_seo_ai_skip_push');

                return Rest_Debug::error_response(
                    'wp_update_post failed while applying supplementary sync fields.',
                    $exception,
                    422,
                );
            }
        }

        $seoApplied = false;
        if (isset($body['seo']) && is_array($body['seo'])) {
            try {
                $seoApplied = Seo_Plugin_Resolver::apply_to_post($postId, $body['seo']);
            } catch (\Throwable) {
                $seoApplied = false;
            }
        }

        $categoryCount = 0;
        if (isset($body['category_ids']) && is_array($body['category_ids'])) {
            $taxonomy = (string) $post->post_type === 'product' ? 'product_cat' : 'category';
            $termIds = array_values(array_unique(array_filter(
                array_map('intval', $body['category_ids']),
                static fn (int $id): bool => $id > 0,
            )));

            if ($termIds !== [] && taxonomy_exists($taxonomy)) {
                $result = wp_set_object_terms($postId, $termIds, $taxonomy, false);
                if (! is_wp_error($result)) {
                    $categoryCount = count($termIds);
                }
            }
        }

        return [
            'faq_count' => $faqCount,
            'seo_applied' => $seoApplied,
            'category_count' => $categoryCount,
        ];
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

        $itemsPreview = $body['virtual_comments'] ?? $body['items'] ?? [];
        Rest_Debug::log('virtual_comments_payload', [
            'post_id'      => $postId,
            'post_type'    => $post->post_type,
            'items_count'  => is_array($itemsPreview) ? count($itemsPreview) : 0,
            'plugin'       => defined('OMI_SEO_AI_BRIDGE_VERSION') ? OMI_SEO_AI_BRIDGE_VERSION : '',
        ]);

        $virtualResult = self::apply_virtual_comments_from_body($postId, $body, $post->post_type === 'product');
        if (($virtualResult['error'] ?? '') !== '') {
            Rest_Debug::log('virtual_comments_save_failed', [
                'post_id' => $postId,
                'error'   => (string) $virtualResult['error'],
            ]);

            $payload = [
                'success' => false,
                'message' => (string) $virtualResult['error'],
            ];
            if (Rest_Debug::is_debug_enabled()) {
                $payload['debug'] = [
                    'post_id'     => $postId,
                    'post_type'   => $post->post_type,
                    'log_file'    => Rest_Debug::log_path(),
                ];
            }

            return new WP_REST_Response($payload, 422);
        }

        $count = (int) ($virtualResult['count'] ?? 0);

        return self::finish_post_write(new WP_REST_Response([
            'success' => true,
            'message' => 'Virtual comments saved.',
            'wp_post_id' => $postId,
            'virtual_count' => $count,
            'count' => $count,
        ], 200), $postId, ['source' => 'virtual-comments']);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{count: int, error: string}
     */
    private static function apply_virtual_comments_from_body(int $postId, array $body, bool $isProduct): array
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
            return [
                'count' => count(Virtual_Comments::get_virtual_items($postId)),
                'error' => '',
            ];
        }

        try {
            $result = Virtual_Comments::save_for_post($postId, $items);
        } catch (\Throwable $exception) {
            Rest_Debug::log('virtual_comments_exception', [
                'post_id' => $postId,
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
            ]);

            return [
                'count' => 0,
                'error' => $exception->getMessage(),
            ];
        }

        if (! ($result['success'] ?? false)) {
            return [
                'count' => 0,
                'error' => (string) ($result['message'] ?? 'Unable to save virtual comments.'),
            ];
        }

        return [
            'count' => (int) ($result['count'] ?? 0),
            'error' => '',
        ];
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
        $saved = (bool) ($result['success'] ?? false);

        $response = new WP_REST_Response([
            'success' => $saved && $count > 0,
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

        if ($saved) {
            Cache_Purger::purge_post($postId, ['source' => 'comment-reviews']);
        }

        return $response;
    }

    public static function handle_get_comment_reviews(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
                'count' => 0,
                'items' => [],
            ], 404);
        }

        $postType = (string) $post->post_type;
        $isProduct = $postType === 'product';
        $items = self::collect_comment_review_items_for_post($postId, $isProduct);

        return new WP_REST_Response([
            'success' => true,
            'wp_post_id' => $postId,
            'wp_post_type' => $postType,
            'count' => count($items),
            'items' => array_values($items),
        ], 200);
    }

    /**
     * Virtual comments live in post meta; real reviews may exist in wp_comments.
     *
     * @return list<array{author: string, content: string, date: string, rating?: int, virtual?: bool}>
     */
    private static function collect_comment_review_items_for_post(int $postId, bool $isProduct): array
    {
        $items = [];
        $seen = [];

        foreach (Virtual_Comments::get_virtual_items($postId) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $formatted = self::format_comment_review_row($row, $isProduct);
            if ($formatted === null) {
                continue;
            }

            $formatted['virtual'] = true;
            $key = self::comment_review_dedupe_key($formatted);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = $formatted;
        }

        $commentType = $isProduct ? 'review' : 'comment';
        $comments = get_comments([
            'post_id' => $postId,
            'status' => 'approve',
            'type' => $commentType,
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
            'number' => 0,
        ]);

        foreach ($comments as $comment) {
            if (! $comment instanceof \WP_Comment) {
                continue;
            }

            $content = trim((string) $comment->comment_content);
            if ($content === '') {
                continue;
            }

            $row = [
                'author' => sanitize_text_field((string) ($comment->comment_author ?: 'Khách mua hàng')),
                'content' => $content,
                'date' => (string) ($comment->comment_date ?: current_time('mysql')),
            ];

            if ($isProduct) {
                $rating = (int) get_comment_meta((int) $comment->comment_ID, 'rating', true);
                if ($rating > 0) {
                    $row['rating'] = max(1, min(5, $rating));
                }
            }

            $key = self::comment_review_dedupe_key($row);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = $row;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{author: string, content: string, date: string, rating?: int}|null
     */
    private static function format_comment_review_row(array $row, bool $isProduct): ?array
    {
        $content = trim((string) ($row['content'] ?? $row['comment'] ?? ''));
        if ($content === '') {
            return null;
        }

        $formatted = [
            'author' => sanitize_text_field((string) ($row['author'] ?? $row['author_name'] ?? 'Khách mua hàng')),
            'content' => $content,
            'date' => trim((string) ($row['date'] ?? '')) !== ''
                ? (string) $row['date']
                : current_time('mysql'),
        ];

        if ($isProduct) {
            foreach (['rating', 'star_ranking', 'stars', 'star'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $formatted['rating'] = max(1, min(5, (int) $row[$key]));
                    break;
                }
            }
        }

        foreach (['_omi_review_id', '_omi_idempotency_key', '_omi_article_id'] as $omiKey) {
            if (! array_key_exists($omiKey, $row)) {
                continue;
            }
            $value = $row[$omiKey];
            if ($omiKey === '_omi_review_id' || $omiKey === '_omi_article_id') {
                $formatted[$omiKey] = (int) $value;
            } else {
                $formatted[$omiKey] = is_string($value) ? sanitize_text_field($value) : (string) $value;
            }
        }

        return $formatted;
    }

    /**
     * @param  array{author?: string, content?: string}  $row
     */
    private static function comment_review_dedupe_key(array $row): string
    {
        return mb_strtolower(trim((string) ($row['author'] ?? '')))
            . "\0"
            . mb_strtolower(trim((string) ($row['content'] ?? '')));
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

        if (array_key_exists('parent_id', $body)) {
            $update['parent'] = max(0, (int) $body['parent_id']);
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

        $seoApplied = false;
        if (isset($body['seo']) && is_array($body['seo'])) {
            $seoApplied = Seo_Plugin_Resolver::apply_to_term($termId, $body['seo']);
        }

        $permalink = Permalink_Resolver::for_term($term);

        return self::finish_url_write(new WP_REST_Response([
            'success' => true,
            'message' => 'Đã đồng bộ danh mục từ SEO editor.',
            'wp_post_id' => $termId,
            'taxonomy' => $taxonomy,
            'slug' => (string) $term->slug,
            'permalink' => $permalink,
            'faq_count' => $faqCount,
            'seo_applied' => $seoApplied,
        ], 200), $permalink, [
            'source'   => 'term-editor-sync',
            'term_id'  => $termId,
            'taxonomy' => $taxonomy,
        ]);
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
                'parent_id' => (int) ($mapped['parent_id'] ?? 0),
                'parent_term_id' => (int) ($mapped['parent_term_id'] ?? $mapped['parent_id'] ?? 0),
                'term_id' => (int) ($mapped['term_id'] ?? $mapped['wp_id'] ?? $termId),
                'taxonomy' => (string) ($mapped['taxonomy'] ?? $taxonomy),
                'post_count' => (int) ($mapped['post_count'] ?? 0),
                'page_type' => (string) ($mapped['page_type'] ?? 'taxonomy'),
                'name' => (string) ($mapped['name'] ?? $mapped['title'] ?? ''),
                'url' => (string) ($mapped['url'] ?? $mapped['permalink'] ?? ''),
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
                'seo' => is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [],
            ],
        ], 200);
    }

    public static function handle_taxonomy_terms(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = (string) $request->get_param('taxonomy');
        if (! taxonomy_exists($taxonomy)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Taxonomy not found.',
            ], 404);
        }

        $hideEmpty = (bool) $request->get_param('hide_empty');
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => $hideEmpty,
        ]);

        if (is_wp_error($terms) || ! is_array($terms)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unable to list terms.',
            ], 500);
        }

        $provider = new Sync_Provider();
        $items = [];
        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }
            $mapped = $provider->map_term_by_id($taxonomy, (int) $term->term_id);
            if (! is_array($mapped)) {
                continue;
            }
            $items[] = [
                'taxonomy' => $taxonomy,
                'term_id' => (int) ($mapped['term_id'] ?? $mapped['wp_id'] ?? $term->term_id),
                'parent_term_id' => (int) ($mapped['parent_term_id'] ?? $mapped['parent_id'] ?? 0),
                'name' => (string) ($mapped['name'] ?? $mapped['title'] ?? ''),
                'slug' => (string) ($mapped['slug'] ?? ''),
                'url' => (string) ($mapped['url'] ?? $mapped['permalink'] ?? ''),
                'post_count' => (int) ($mapped['post_count'] ?? $term->count),
                'page_type' => 'taxonomy',
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'taxonomy' => $taxonomy,
            'count' => count($items),
            'terms' => $items,
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

        return self::finish_post_write(new WP_REST_Response([
            'success' => true,
            'message' => 'Đã cập nhật media.',
            'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
            'product_gallery' => is_array($mapped['product_gallery'] ?? null)
                ? $mapped['product_gallery']
                : [],
        ], 200), $postId, ['source' => 'post-media']);
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
        $permalink = Permalink_Resolver::for_term($term);

        return self::finish_url_write(new WP_REST_Response([
            'success' => true,
            'message' => 'Đã cập nhật ảnh danh mục.',
            'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
            'product_gallery' => [],
        ], 200), $permalink, [
            'source'   => 'term-media',
            'term_id'  => $termId,
            'taxonomy' => $taxonomy,
        ]);
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
                'post_date' => (string) ($mapped['post_date'] ?? ''),
                'post_modified' => (string) ($mapped['post_modified'] ?? ''),
                'published_at' => $mapped['published_at'] ?? null,
                'post_content' => (string) ($mapped['post_content'] ?? ''),
                'featured_image_url' => (string) ($mapped['featured_image_url'] ?? ''),
                'product_gallery' => is_array($mapped['product_gallery'] ?? null)
                    ? $mapped['product_gallery']
                    : [],
                'post_images' => is_array($mapped['post_images'] ?? null)
                    ? $mapped['post_images']
                    : [],
                'permalink' => (string) ($mapped['permalink'] ?? Permalink_Resolver::for_post($postId)),
                'wp_entity' => 'post',
                'type' => (string) ($mapped['type'] ?? ''),
                'wp_post_type' => (string) ($mapped['wp_post_type'] ?? ''),
                'category_ids' => is_array($mapped['category_ids'] ?? null)
                    ? $mapped['category_ids']
                    : [],
                'faqs' => is_array($mapped['faqs'] ?? null) ? $mapped['faqs'] : [],
                'seo' => is_array($mapped['seo'] ?? null) ? $mapped['seo'] : [],
                'multilingual' => is_array($mapped['multilingual'] ?? null)
                    ? $mapped['multilingual']
                    : Polylang_Sync::multilingual_field_for_post($postId),
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

    public static function handle_capabilities(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $manifest = Capability_Manifest::build();

        return new WP_REST_Response([
            'success'  => true,
            'message'  => 'Capability manifest.',
            'manifest' => $manifest,
        ], 200);
    }

    public static function handle_heartbeat(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $manifest = Capability_Manifest::build();
        $rawCaps = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $flags = [];
        foreach ([
            'content_manifest',
            'metadata_only_articles',
            'link_graph',
            'broken_links_v2',
            'link_health_batch',
            'cache_purge',
            'seo_metadata',
            'heartbeat',
            'operation_idempotency',
            'plugin_update',
            'github_release_update',
            'manual_update',
        ] as $key) {
            $flags[$key] = (bool) ($rawCaps[$key]['available'] ?? false);
        }

        $info = Seo_Plugin_Resolver::site_info();

        return new WP_REST_Response([
            'success' => true,
            'status' => 'ok',
            'plugin_version' => defined('OMI_SEO_AI_BRIDGE_VERSION') ? (string) OMI_SEO_AI_BRIDGE_VERSION : '',
            'wp_version' => (string) ($info['wordpress_version'] ?? get_bloginfo('version')),
            'capabilities' => $flags,
            'plugin_update_source' => 'github_release',
        ], 200);
    }

    public static function handle_plugin_update_check(WP_REST_Request $request): WP_REST_Response
    {
        $force = filter_var($request->get_param('force_refresh') ?? true, FILTER_VALIDATE_BOOLEAN);
        $service = new Bridge_Update_Service();
        $payload = $service->check($force);

        return new WP_REST_Response($payload, 200);
    }

    public static function handle_plugin_update_install(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $operationId = trim((string) ($params['operation_id'] ?? $request->get_param('operation_id') ?? ''));
        $service = new Bridge_Update_Service();
        $payload = $service->install($operationId);

        return new WP_REST_Response($payload, 200);
    }

    public static function handle_link_health_batch(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $cursor = (int) ($params['cursor'] ?? $request->get_param('cursor') ?? 0);
        $limit = (int) ($params['limit'] ?? $request->get_param('limit') ?? Link_Health_Engine::BATCH_SIZE);
        $engine = new Link_Health_Engine();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Link health batch.',
            'batch' => $engine->process_batch($cursor, $limit),
        ], 200);
    }

    public static function handle_sync_v2_profile(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $provider = new Site_Sync_V2_Provider();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Site profile.',
            'profile' => $provider->profile(),
        ], 200);
    }

    public static function handle_sync_v2_delta(WP_REST_Request $request): WP_REST_Response
    {
        $provider = new Site_Sync_V2_Provider();
        $batch = $provider->delta([
            'cursor' => (string) $request->get_param('cursor'),
            'run_token' => (string) $request->get_param('run_token'),
            'mode' => 'delta',
            'fields' => (string) ($request->get_param('fields') ?: 'metadata'),
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Delta batch.',
            'batch' => $batch,
        ], 200);
    }

    public static function handle_sync_v2_batches(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $provider = new Site_Sync_V2_Provider();
            $params = $request->get_json_params();
            if (! is_array($params)) {
                $params = [];
            }
            $cursorParam = $params['cursor'] ?? $request->get_param('cursor');
            $batch = $provider->batches([
                'cursor' => $cursorParam === null ? '' : (string) $cursorParam,
                'run_token' => (string) ($params['run_token'] ?? $request->get_param('run_token') ?? ''),
                'mode' => (string) ($params['mode'] ?? 'snapshot'),
                'include_unchanged' => (bool) ($params['include_unchanged'] ?? false),
                'fields' => (string) ($params['fields'] ?? $request->get_param('fields') ?? 'metadata'),
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Snapshot/delta/force_full batch.',
                'batch' => $batch,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'batches failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function handle_sync_v2_manifest(WP_REST_Request $request): WP_REST_Response
    {
        $provider = new Site_Sync_V2_Provider();
        $summary = filter_var($request->get_param('summary'), FILTER_VALIDATE_BOOLEAN);
        $manifest = $summary
            ? $provider->lightweight_manifest_summary()
            : $provider->lightweight_manifest();

        return new WP_REST_Response([
            'success' => true,
            'message' => $summary ? 'Manifest summary.' : 'Lightweight reconciliation manifest.',
            'manifest' => $manifest,
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

    public static function handle_sync_manifest(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $provider = new Sync_Provider();
        $payload = $provider->collect_manifest();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Sync manifest generated.',
            'counts'  => $payload['counts'],
            'entries' => $payload['entries'],
            'totals'  => $payload['totals'] ?? [],
        ], 200);
    }

    public static function handle_sync_items(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $refs = is_array($body['refs'] ?? null) ? $body['refs'] : [];

        $provider = new Sync_Provider();
        $payload = $provider->collect_items($refs);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Sync items payload generated.',
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

    /**
     * @param  array<string, mixed>  $context
     */
    private static function finish_post_write(WP_REST_Response $response, int $postId, array $context = []): WP_REST_Response
    {
        Cache_Purger::after_rest_success(
            $response->get_status(),
            $response->get_data(),
            $postId,
            $context,
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function finish_url_write(WP_REST_Response $response, string $url, array $context = []): WP_REST_Response
    {
        Cache_Purger::after_rest_success_url(
            $response->get_status(),
            $response->get_data(),
            $url,
            $context,
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function finish_global_write(WP_REST_Response $response, array $context = []): WP_REST_Response
    {
        Cache_Purger::after_rest_success_all(
            $response->get_status(),
            $response->get_data(),
            $context,
        );

        return $response;
    }
}
