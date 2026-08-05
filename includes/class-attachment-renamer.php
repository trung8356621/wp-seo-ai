<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Đổi tên file attachment trên Media Library và thay URL cũ trong toàn bộ post_content.
 */
final class Attachment_Renamer
{
    /**
     * @return array{
     *   success: bool,
     *   attachment_id: int,
     *   old_url: string,
     *   filename: string,
     *   wordpress_posts: int,
     *   featured_references: int,
     *   samples: array<int, array{post_id: int, title: string, post_type: string, reference_type: string}>,
     *   supports_redirect: false,
     *   message: string
     * }
     */
    public function scan_usage(int $attachmentId, string $oldUrl = ''): array
    {
        $attachment = $attachmentId > 0 ? get_post($attachmentId) : null;
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return [
                'success'               => false,
                'attachment_id'         => $attachmentId,
                'old_url'               => $oldUrl,
                'filename'              => '',
                'wordpress_posts'       => 0,
                'featured_references'   => 0,
                'samples'               => [],
                'supports_redirect'     => false,
                'message'               => 'Attachment không tồn tại.',
            ];
        }

        $attachmentUrl = (string) wp_get_attachment_url($attachmentId);
        if ($oldUrl === '') {
            $oldUrl = $attachmentUrl;
        }

        $file = get_attached_file($attachmentId);
        $filename = is_string($file) && $file !== ''
            ? wp_basename($file)
            : ($attachmentUrl !== '' ? wp_basename(parse_url($attachmentUrl, PHP_URL_PATH) ?: '') : '');

        $urlsToScan = array_values(array_unique(array_filter([$oldUrl, $attachmentUrl], static fn (string $url): bool => $url !== '')));

        $contentPosts = $this->find_posts_referencing_urls($urlsToScan);
        $featuredPosts = $this->find_posts_with_featured_image($attachmentId);

        $wordpressPosts = count($contentPosts);
        $featuredReferences = count($featuredPosts);

        $samples = [];
        foreach ($contentPosts as $postRow) {
            if (count($samples) >= 20) {
                break;
            }
            $samples[] = [
                'post_id'         => (int) ($postRow['ID'] ?? 0),
                'title'           => (string) ($postRow['post_title'] ?? ''),
                'post_type'       => (string) ($postRow['post_type'] ?? ''),
                'reference_type'  => 'post_content',
            ];
        }

        if (count($samples) < 20) {
            foreach ($featuredPosts as $postRow) {
                if (count($samples) >= 20) {
                    break;
                }
                $postId = (int) ($postRow['ID'] ?? 0);
                $alreadySampled = false;
                foreach ($samples as $sample) {
                    if ((int) ($sample['post_id'] ?? 0) === $postId) {
                        $alreadySampled = true;
                        break;
                    }
                }
                if ($alreadySampled) {
                    continue;
                }
                $samples[] = [
                    'post_id'         => $postId,
                    'title'           => (string) ($postRow['post_title'] ?? ''),
                    'post_type'       => (string) ($postRow['post_type'] ?? ''),
                    'reference_type'  => 'featured_image',
                ];
            }
        }

        return [
            'success'               => true,
            'attachment_id'         => $attachmentId,
            'old_url'               => $oldUrl,
            'filename'              => $filename,
            'wordpress_posts'       => $wordpressPosts,
            'featured_references'   => $featuredReferences,
            'samples'               => $samples,
            'supports_redirect'     => false,
            'message'               => 'OK',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function rename_one_public(array $item): array
    {
        return $this->rename_one($item);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{renamed: array<int, array<string, mixed>>, posts_updated: int, errors: array<int, array<string, mixed>>}
     */
    public function rename_batch(array $items): array
    {
        $renamed = [];
        $errors = [];
        $postsUpdated = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result = $this->rename_one($item);
            if ($result['success'] ?? false) {
                $renamed[] = $result;
                $postsUpdated += (int) ($result['posts_updated'] ?? 0);
            } else {
                $errors[] = $result;
            }
        }

        return [
            'renamed'        => $renamed,
            'posts_updated'  => $postsUpdated,
            'errors'         => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function rename_one(array $item): array
    {
        $requestedAttachmentId = (int) ($item['attachment_id'] ?? 0);
        $newSlug = $this->sanitize_slug((string) ($item['new_slug'] ?? ''));
        $oldUrl = trim((string) ($item['old_url'] ?? ''));

        if ($newSlug === '') {
            return [
                'success' => false,
                'message' => 'Thiếu attachment_id hoặc new_slug.',
            ];
        }

        // ID stale (ảnh sync/reimport đổi ID) — resolve lại theo old_url / basename file.
        $attachmentId = $this->resolve_attachment_id($requestedAttachmentId, $oldUrl);
        $attachment = $attachmentId > 0 ? get_post($attachmentId) : null;
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return [
                'success'       => false,
                'attachment_id' => $requestedAttachmentId > 0 ? $requestedAttachmentId : $attachmentId,
                'message'       => 'Attachment không tồn tại.',
            ];
        }

        if ($oldUrl === '') {
            $oldUrl = (string) wp_get_attachment_url($attachmentId);
        }

        $file = get_attached_file($attachmentId);
        if (! is_string($file) || $file === '' || ! file_exists($file)) {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Không tìm thấy file vật lý trên server.',
            ];
        }

        $pathInfo = pathinfo($file);
        $extension = isset($pathInfo['extension']) && $pathInfo['extension'] !== ''
            ? strtolower((string) $pathInfo['extension'])
            : '';
        $oldBasename = (string) ($pathInfo['filename'] ?? '');
        $baseDir = (string) ($pathInfo['dirname'] ?? '');
        $oldRelativeMain = _wp_relative_upload_path($file);
        if (! is_string($oldRelativeMain)) {
            $oldRelativeMain = '';
        }

        $requestedSlug = $newSlug;
        $newFilename = $extension !== '' ? $newSlug . '.' . $extension : $newSlug;
        $newFile = trailingslashit($baseDir) . $newFilename;

        $strictCollision = (bool) ($item['strict_collision'] ?? false);

        if ($newFile !== $file && is_file($newFile)) {
            if ($strictCollision) {
                return [
                    'success'       => false,
                    'attachment_id' => $attachmentId,
                    'message'       => 'Filename already exists',
                ];
            }

            $uniqueFilename = wp_unique_filename($baseDir, $newFilename);
            $uniqueFilename = trim((string) $uniqueFilename);

            if ($uniqueFilename === '') {
                return [
                    'success'       => false,
                    'attachment_id' => $attachmentId,
                    'message'       => 'Không tạo được tên file mới khi bị trùng.',
                ];
            }

            $newFilename = $uniqueFilename;
            $newFile = trailingslashit($baseDir) . $newFilename;
            $newSlug = (string) pathinfo($newFilename, PATHINFO_FILENAME);
        }

        if ($newFile === $file) {
            $newUrl = (string) wp_get_attachment_url($attachmentId);

            return [
                'success'        => true,
                'attachment_id'  => $attachmentId,
                'old_url'        => $oldUrl,
                'new_url'        => $newUrl,
                'new_slug'       => $newSlug,
                'posts_updated'  => 0,
                'message'        => 'Slug không đổi.',
            ];
        }

        if (file_exists($newFile)) {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'File đích đã tồn tại: ' . $newFilename,
            ];
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $oldVariantUrls = $this->collect_variant_urls($metadata, $oldRelativeMain);
        $deletedVariantCount = $this->delete_dimension_variants($metadata, $baseDir, $oldBasename);

        if (! @rename($file, $newFile)) {
            $this->regenerate_attachment_metadata($attachmentId, $file);

            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Không đổi tên được file trên đĩa.',
            ];
        }

        $newRelativeMain = _wp_relative_upload_path($newFile);
        if (is_string($newRelativeMain) && $newRelativeMain !== '') {
            update_attached_file($attachmentId, $newRelativeMain);
        } else {
            $newRelativeMain = '';
        }

        $regeneratedMetadata = $this->regenerate_attachment_metadata($attachmentId, $newFile);
        if ($regeneratedMetadata === null) {
            if (@rename($newFile, $file)) {
                update_attached_file($attachmentId, $oldRelativeMain);
                $this->regenerate_attachment_metadata($attachmentId, $file);
            }

            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Renamed the original file but could not regenerate WordPress image sizes.',
            ];
        }

        $newUrl = (string) wp_get_attachment_url($attachmentId);
        $oldMainUrlFromPath = $this->uploads_url_from_relative($oldRelativeMain);
        $newMainUrlFromPath = $this->uploads_url_from_relative($newRelativeMain);
        $oldToNewUrlMap = $this->map_regenerated_variant_urls(
            $oldVariantUrls,
            $regeneratedMetadata,
            $newRelativeMain,
            $newUrl
        );

        wp_update_post([
            'ID'         => $attachmentId,
            'post_name'  => $newSlug,
            'guid'       => $newUrl,
        ]);

        clean_attachment_cache($attachmentId);

        if ($oldMainUrlFromPath !== '' && $newMainUrlFromPath !== '' && $oldMainUrlFromPath !== $newMainUrlFromPath) {
            $oldToNewUrlMap[$oldMainUrlFromPath] = $newMainUrlFromPath;
        }
        if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
            $oldToNewUrlMap[$oldUrl] = $newUrl;
        }

        $postsUpdated = 0;
        if ($oldToNewUrlMap !== []) {
            $postsUpdated = $this->replace_urls_in_all_posts($oldToNewUrlMap);
        }

        return [
            'success'       => true,
            'attachment_id' => $attachmentId,
            'old_url'       => $oldUrl,
            'new_url'       => $newUrl,
            'new_slug'      => $newSlug,
            'requested_slug' => $requestedSlug,
            'variant_deleted_count' => $deletedVariantCount,
            'variant_regenerated_count' => count((array) ($regeneratedMetadata['sizes'] ?? [])),
            'posts_updated' => $postsUpdated,
        ];
    }

    /**
     * ID gửi lên có thể stale sau reimport/sync — ưu tiên ID còn sống, không thì tìm theo URL/basename.
     */
    private function resolve_attachment_id(int $requestedId, string $oldUrl): int
    {
        if ($requestedId > 0) {
            $attachment = get_post($requestedId);
            if ($attachment instanceof \WP_Post && $attachment->post_type === 'attachment') {
                return $requestedId;
            }
        }

        $cleanUrl = preg_replace('/[?#].*$/', '', trim($oldUrl));
        if (! is_string($cleanUrl) || $cleanUrl === '') {
            return 0;
        }

        $resolved = (int) attachment_url_to_postid($cleanUrl);
        if ($resolved > 0) {
            return $resolved;
        }

        $fullUrl = preg_replace('/-\d+x\d+(?=\.(jpe?g|png|gif|webp)$)/i', '', $cleanUrl);
        if (is_string($fullUrl) && $fullUrl !== '' && $fullUrl !== $cleanUrl) {
            $resolved = (int) attachment_url_to_postid($fullUrl);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        $path = parse_url($cleanUrl, PHP_URL_PATH);
        $basename = is_string($path) ? wp_basename($path) : '';
        if ($basename === '') {
            return 0;
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($basename);
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
             ORDER BY post_id DESC LIMIT 1",
            $like
        ));

        if ($found <= 0) {
            return 0;
        }

        $attachment = get_post($found);

        return ($attachment instanceof \WP_Post && $attachment->post_type === 'attachment')
            ? $found
            : 0;
    }

    private function sanitize_slug(string $slug): string
    {
        $slug = sanitize_file_name($slug);
        $slug = preg_replace('/\.[a-z0-9]{1,8}$/i', '', $slug) ?? $slug;

        return trim((string) $slug, '-_');
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, array{ID: int|string, post_title: string, post_type: string}>
     */
    private function find_posts_referencing_urls(array $urls): array
    {
        global $wpdb;

        $urls = array_values(array_unique(array_filter(array_map(
            static fn ($url): string => trim((string) $url),
            $urls
        ), static fn (string $url): bool => $url !== '')));

        if ($urls === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($urls as $url) {
            $conditions[] = 'post_content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($url) . '%';
        }

        $sql = "SELECT ID, post_title, post_type
                FROM {$wpdb->posts}
                WHERE (" . implode(' OR ', $conditions) . ")
                AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                AND post_status != 'auto-draft'
                ORDER BY ID DESC";

        $prepared = $wpdb->prepare($sql, ...$params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{ID: int|string, post_title: string, post_type: string}>
     */
    private function find_posts_with_featured_image(int $attachmentId): array
    {
        global $wpdb;

        if ($attachmentId <= 0) {
            return [];
        }

        $sql = "SELECT p.ID, p.post_title, p.post_type
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_thumbnail_id'
                AND pm.meta_value = %s
                AND p.post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                AND p.post_status != 'auto-draft'
                ORDER BY p.ID DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, (string) $attachmentId), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function replace_url_in_all_posts(string $oldUrl, string $newUrl): int
    {
        global $wpdb;

        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return 0;
        }

        $like = '%' . $wpdb->esc_like($oldUrl) . '%';

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts}
                SET post_content = REPLACE(post_content, %s, %s)
                WHERE post_content LIKE %s
                AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                AND post_status != 'auto-draft'",
                $oldUrl,
                $newUrl,
                $like
            )
        );

        return is_int($updated) ? $updated : 0;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: int}
     */
    private function rename_attachment_variants(
        array $metadata,
        string $baseDir,
        string $oldBaseName,
        string $newBaseName,
        string $oldRelativeMain,
        string $newRelativeMain
    ): array {
        $urlMap = [];
        $renamedCount = 0;

        $relativeDir = $this->extract_relative_dir($oldRelativeMain !== '' ? $oldRelativeMain : $newRelativeMain);

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeKey => $sizeMeta) {
                if (! is_array($sizeMeta)) {
                    continue;
                }

                $oldVariantFile = trim((string) ($sizeMeta['file'] ?? ''));
                if ($oldVariantFile === '') {
                    continue;
                }

                $newVariantFile = $this->build_variant_filename($oldVariantFile, $oldBaseName, $newBaseName);
                if ($newVariantFile === '' || $newVariantFile === $oldVariantFile) {
                    continue;
                }

                $oldVariantAbs = trailingslashit($baseDir) . ltrim(str_replace('\\', '/', $oldVariantFile), '/');
                $newVariantAbs = trailingslashit($baseDir) . ltrim(str_replace('\\', '/', $newVariantFile), '/');

                $renamed = false;
                if (is_file($oldVariantAbs)) {
                    if (! is_file($newVariantAbs)) {
                        $renamed = @rename($oldVariantAbs, $newVariantAbs);
                    } else {
                        // File đích đã có sẵn (thường do chạy rename trước đó), cập nhật metadata theo tên mới.
                        $renamed = true;
                    }
                } elseif (is_file($newVariantAbs)) {
                    // Trường hợp metadata cũ nhưng file đã được đổi tên trước đó.
                    $renamed = true;
                }

                if (! $renamed) {
                    continue;
                }

                $metadata['sizes'][$sizeKey]['file'] = $newVariantFile;
                $renamedCount += 1;

                $oldVariantRelative = $this->join_relative_path($relativeDir, $oldVariantFile);
                $newVariantRelative = $this->join_relative_path($relativeDir, $newVariantFile);
                $oldVariantUrl = $this->uploads_url_from_relative($oldVariantRelative);
                $newVariantUrl = $this->uploads_url_from_relative($newVariantRelative);
                if ($oldVariantUrl !== '' && $newVariantUrl !== '' && $oldVariantUrl !== $newVariantUrl) {
                    $urlMap[$oldVariantUrl] = $newVariantUrl;
                }
            }
        }

        $originalImage = trim((string) ($metadata['original_image'] ?? ''));
        if ($originalImage !== '') {
            $newOriginalImage = $this->build_variant_filename($originalImage, $oldBaseName, $newBaseName);
            if ($newOriginalImage !== '' && $newOriginalImage !== $originalImage) {
                $oldOriginalAbs = trailingslashit($baseDir) . ltrim(str_replace('\\', '/', $originalImage), '/');
                $newOriginalAbs = trailingslashit($baseDir) . ltrim(str_replace('\\', '/', $newOriginalImage), '/');

                $renamedOriginal = false;
                if (is_file($oldOriginalAbs)) {
                    if (! is_file($newOriginalAbs)) {
                        $renamedOriginal = @rename($oldOriginalAbs, $newOriginalAbs);
                    } else {
                        $renamedOriginal = true;
                    }
                } elseif (is_file($newOriginalAbs)) {
                    $renamedOriginal = true;
                }

                if ($renamedOriginal) {
                    $metadata['original_image'] = $newOriginalImage;
                    $renamedCount += 1;

                    $oldOriginalRelative = $this->join_relative_path($relativeDir, $originalImage);
                    $newOriginalRelative = $this->join_relative_path($relativeDir, $newOriginalImage);
                    $oldOriginalUrl = $this->uploads_url_from_relative($oldOriginalRelative);
                    $newOriginalUrl = $this->uploads_url_from_relative($newOriginalRelative);
                    if ($oldOriginalUrl !== '' && $newOriginalUrl !== '' && $oldOriginalUrl !== $newOriginalUrl) {
                        $urlMap[$oldOriginalUrl] = $newOriginalUrl;
                    }
                }
            }
        }

        return [$metadata, $urlMap, $renamedCount];
    }

    private function build_variant_filename(string $oldFile, string $oldBaseName, string $newBaseName): string
    {
        $oldFile = trim($oldFile);
        if ($oldFile === '' || $oldBaseName === '' || $newBaseName === '') {
            return '';
        }

        $name = (string) pathinfo($oldFile, PATHINFO_FILENAME);
        $extension = (string) pathinfo($oldFile, PATHINFO_EXTENSION);

        if ($name === '') {
            return '';
        }

        if ($name === $oldBaseName) {
            $nextName = $newBaseName;
        } elseif (str_starts_with($name, $oldBaseName . '-')) {
            $nextName = $newBaseName . substr($name, strlen($oldBaseName));
        } else {
            return '';
        }

        if ($extension === '') {
            return $nextName;
        }

        return $nextName . '.' . $extension;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function collect_variant_urls(array $metadata, string $relativeMain): array
    {
        $urls = [];
        $relativeDir = $this->extract_relative_dir($relativeMain);

        foreach ((array) ($metadata['sizes'] ?? []) as $sizeKey => $sizeMeta) {
            if (! is_array($sizeMeta)) {
                continue;
            }

            $file = trim((string) ($sizeMeta['file'] ?? ''));
            $url = $this->uploads_url_from_relative($this->join_relative_path($relativeDir, $file));
            if ($file !== '' && $url !== '') {
                $urls[(string) $sizeKey] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function delete_dimension_variants(array $metadata, string $baseDir, string $baseName): int
    {
        $files = [];

        foreach ((array) ($metadata['sizes'] ?? []) as $sizeMeta) {
            if (is_array($sizeMeta)) {
                $file = trim((string) ($sizeMeta['file'] ?? ''));
                if ($file !== '') {
                    $files[] = trailingslashit($baseDir).basename($file);
                }
            }
        }

        $pattern = trailingslashit($baseDir).$baseName.'-[0-9]*x[0-9]*.*';
        foreach ((array) glob($pattern, GLOB_NOSORT) as $matchedFile) {
            $files[] = (string) $matchedFile;
        }

        $deleted = 0;
        foreach (array_unique($files) as $variantFile) {
            if (is_file($variantFile) && @unlink($variantFile)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function regenerate_attachment_metadata(int $attachmentId, string $file): ?array
    {
        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $file);
        if (! is_array($metadata)) {
            return null;
        }

        wp_update_attachment_metadata($attachmentId, $metadata);

        return $metadata;
    }

    /**
     * @param  array<string, string>  $oldUrls
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function map_regenerated_variant_urls(
        array $oldUrls,
        array $metadata,
        string $relativeMain,
        string $mainUrl
    ): array {
        $urlMap = [];
        $relativeDir = $this->extract_relative_dir($relativeMain);
        $sizes = (array) ($metadata['sizes'] ?? []);

        foreach ($oldUrls as $sizeKey => $oldUrl) {
            $newFile = is_array($sizes[$sizeKey] ?? null)
                ? trim((string) ($sizes[$sizeKey]['file'] ?? ''))
                : '';
            $newUrl = $newFile !== ''
                ? $this->uploads_url_from_relative($this->join_relative_path($relativeDir, $newFile))
                : $mainUrl;

            if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
                $urlMap[$oldUrl] = $newUrl;
            }
        }

        return $urlMap;
    }

    private function uploads_url_from_relative(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '') {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $baseUrl = trim((string) ($uploads['baseurl'] ?? ''));
        if ($baseUrl === '') {
            return '';
        }

        return trailingslashit($baseUrl) . $relativePath;
    }

    private function extract_relative_dir(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '') {
            return '';
        }

        $dir = dirname($relativePath);

        return ($dir === '.' || $dir === DIRECTORY_SEPARATOR) ? '' : trim((string) $dir, '/');
    }

    private function join_relative_path(string $relativeDir, string $filename): string
    {
        $filename = ltrim(str_replace('\\', '/', trim($filename)), '/');
        if ($filename === '') {
            return '';
        }

        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '') {
            return $filename;
        }

        return $relativeDir . '/' . $filename;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    private function replace_urls_in_all_posts(array $urlMap): int
    {
        $totalUpdated = 0;
        foreach ($urlMap as $oldUrl => $newUrl) {
            $oldUrl = trim((string) $oldUrl);
            $newUrl = trim((string) $newUrl);
            if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
                continue;
            }
            $totalUpdated += $this->replace_url_in_all_posts($oldUrl, $newUrl);
        }

        return $totalUpdated;
    }
}
