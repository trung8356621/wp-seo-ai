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
        $attachmentId = (int) ($item['attachment_id'] ?? 0);
        $newSlug = $this->sanitize_slug((string) ($item['new_slug'] ?? ''));

        if ($attachmentId <= 0 || $newSlug === '') {
            return [
                'success' => false,
                'message' => 'Thiếu attachment_id hoặc new_slug.',
            ];
        }

        $attachment = get_post($attachmentId);
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return [
                'success'       => false,
                'attachment_id' => $attachmentId,
                'message'       => 'Attachment không tồn tại.',
            ];
        }

        $oldUrl = trim((string) ($item['old_url'] ?? ''));
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

        if ($newFile !== $file && is_file($newFile)) {
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

        if (! @rename($file, $newFile)) {
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

        $oldToNewUrlMap = [];
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $variantRenamedCount = 0;
        if ($oldBasename !== '' && $baseDir !== '') {
            [
                $metadata,
                $variantUrlMap,
                $variantRenamedCount,
            ] = $this->rename_attachment_variants(
                $metadata,
                $baseDir,
                $oldBasename,
                $newSlug,
                $oldRelativeMain,
                $newRelativeMain
            );

            if ($variantUrlMap !== []) {
                $oldToNewUrlMap = array_merge($oldToNewUrlMap, $variantUrlMap);
            }
        }

        if ($newRelativeMain !== '') {
            $metadata['file'] = $newRelativeMain;
        }
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        $newUrl = (string) wp_get_attachment_url($attachmentId);
        $oldMainUrlFromPath = $this->uploads_url_from_relative($oldRelativeMain);
        $newMainUrlFromPath = $this->uploads_url_from_relative($newRelativeMain);

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
            'variant_renamed_count' => $variantRenamedCount,
            'posts_updated' => $postsUpdated,
        ];
    }

    private function sanitize_slug(string $slug): string
    {
        $slug = sanitize_file_name($slug);
        $slug = preg_replace('/\.[a-z0-9]{1,8}$/i', '', $slug) ?? $slug;

        return trim((string) $slug, '-_');
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
