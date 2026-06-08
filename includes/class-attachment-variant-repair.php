<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Attachment_Variant_Repair
{
    /**
     * @return array{ids: list<int>, rows: list<array<string, mixed>>, scanned: int, done: bool}
     */
    public function scan_page(int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        $rows = [];
        $ids = [];
        foreach ((array) $query->posts as $attachmentId) {
            $issue = $this->inspect((int) $attachmentId);
            if ($issue === null) {
                continue;
            }

            $ids[] = (int) $attachmentId;
            $rows[] = $issue;
        }

        return [
            'ids' => $ids,
            'rows' => $rows,
            'scanned' => count((array) $query->posts),
            'done' => $page >= max(1, (int) $query->max_num_pages),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inspect(int $attachmentId): ?array
    {
        $file = get_attached_file($attachmentId);
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (! is_string($file) || $file === '' || ! is_file($file) || ! is_array($metadata)) {
            return null;
        }

        $baseName = (string) pathinfo($file, PATHINFO_FILENAME);
        $mismatched = [];
        foreach ((array) ($metadata['sizes'] ?? []) as $sizeKey => $sizeMeta) {
            if (! is_array($sizeMeta)) {
                continue;
            }

            $variantFile = trim((string) ($sizeMeta['file'] ?? ''));
            if ($variantFile === '' || $this->belongs_to_base($variantFile, $baseName)) {
                continue;
            }

            $mismatched[] = [
                'size' => (string) $sizeKey,
                'file' => $variantFile,
                'url' => $this->variant_url($file, $variantFile),
            ];
        }

        if ($mismatched === []) {
            return null;
        }

        return [
            'attachment_id' => $attachmentId,
            'title' => (string) get_the_title($attachmentId),
            'full_url' => (string) wp_get_attachment_url($attachmentId),
            'full_file' => basename($file),
            'mismatched' => $mismatched,
        ];
    }

    /**
     * @return array{success: bool, attachment_id: int, deleted: int, regenerated: int, posts_updated: int, message: string}
     */
    public function repair(int $attachmentId): array
    {
        $issue = $this->inspect($attachmentId);
        if ($issue === null) {
            return [
                'success' => true,
                'attachment_id' => $attachmentId,
                'deleted' => 0,
                'regenerated' => 0,
                'posts_updated' => 0,
                'message' => 'Attachment không còn ảnh phụ lệch tên.',
            ];
        }

        $file = (string) get_attached_file($attachmentId);
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $oldUrls = [];
        $variantFiles = [];
        foreach ((array) ($metadata['sizes'] ?? []) as $sizeKey => $sizeMeta) {
            if (! is_array($sizeMeta)) {
                continue;
            }

            $variantFile = trim((string) ($sizeMeta['file'] ?? ''));
            if ($variantFile === '') {
                continue;
            }

            $variantFiles[] = trailingslashit(dirname($file)).basename($variantFile);
            $oldUrls[(string) $sizeKey] = $this->variant_url($file, $variantFile);
        }

        $baseName = (string) pathinfo($file, PATHINFO_FILENAME);
        foreach ((array) glob(trailingslashit(dirname($file)).$baseName.'-[0-9]*x[0-9]*.*', GLOB_NOSORT) as $matched) {
            $variantFiles[] = (string) $matched;
        }

        $deleted = 0;
        foreach (array_unique($variantFiles) as $variantFile) {
            if (is_file($variantFile) && @unlink($variantFile)) {
                $deleted++;
            }
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $newMetadata = wp_generate_attachment_metadata($attachmentId, $file);
        if (! is_array($newMetadata)) {
            return [
                'success' => false,
                'attachment_id' => $attachmentId,
                'deleted' => $deleted,
                'regenerated' => 0,
                'posts_updated' => 0,
                'message' => 'Không tạo lại được metadata ảnh WordPress.',
            ];
        }

        wp_update_attachment_metadata($attachmentId, $newMetadata);
        clean_attachment_cache($attachmentId);

        $urlMap = [];
        foreach ($oldUrls as $sizeKey => $oldUrl) {
            $newSize = $newMetadata['sizes'][$sizeKey] ?? null;
            $newFile = is_array($newSize) ? trim((string) ($newSize['file'] ?? '')) : '';
            $newUrl = $newFile !== ''
                ? $this->variant_url($file, $newFile)
                : (string) wp_get_attachment_url($attachmentId);
            if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
                $urlMap[$oldUrl] = $newUrl;
            }
        }

        return [
            'success' => true,
            'attachment_id' => $attachmentId,
            'deleted' => $deleted,
            'regenerated' => count((array) ($newMetadata['sizes'] ?? [])),
            'posts_updated' => $this->replace_urls($urlMap),
            'message' => 'Đã xóa ảnh phụ cũ và tạo lại image sizes.',
        ];
    }

    private function belongs_to_base(string $variantFile, string $baseName): bool
    {
        $variantName = (string) pathinfo($variantFile, PATHINFO_FILENAME);

        return (bool) preg_match('/^'.preg_quote($baseName, '/').'-\d+x\d+$/', $variantName);
    }

    private function variant_url(string $fullFile, string $variantFile): string
    {
        $relativeFull = _wp_relative_upload_path($fullFile);
        if (! is_string($relativeFull) || $relativeFull === '') {
            return '';
        }

        $relativeDir = dirname(str_replace('\\', '/', $relativeFull));
        $relative = ($relativeDir === '.' ? '' : trim($relativeDir, '/').'/').basename($variantFile);
        $uploads = wp_get_upload_dir();
        $baseUrl = trim((string) ($uploads['baseurl'] ?? ''));

        return $baseUrl !== '' ? trailingslashit($baseUrl).$relative : '';
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    private function replace_urls(array $urlMap): int
    {
        global $wpdb;

        $updated = 0;
        foreach ($urlMap as $oldUrl => $newUrl) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts}
                SET post_content = REPLACE(post_content, %s, %s)
                WHERE post_content LIKE %s
                AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                AND post_status != 'auto-draft'",
                $oldUrl,
                $newUrl,
                '%'.$wpdb->esc_like($oldUrl).'%'
            ));
            $updated += is_int($result) ? $result : 0;
        }

        return $updated;
    }
}
