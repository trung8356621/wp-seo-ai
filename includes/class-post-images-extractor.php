<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Quét ảnh trong nội dung bài viết và gắn ID attachment WordPress khi có thể.
 */
final class Post_Images_Extractor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract_from_content(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $items = [];
        $seen = [];

        $this->collect_from_gutenberg_comments($content, $items, $seen);
        $this->collect_from_html($content, $items, $seen);

        return array_values($items);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, true> $seen
     */
    private function collect_from_gutenberg_comments(string $content, array &$items, array &$seen): void
    {
        if (! preg_match_all(
            '/<!--\s*wp:image\s+(\{.*?\})\s*-->/s',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            return;
        }

        foreach ($matches as $match) {
            $json = json_decode((string) ($match[1] ?? ''), true);
            if (! is_array($json)) {
                continue;
            }

            $attachmentId = (int) ($json['id'] ?? 0);
            if ($attachmentId <= 0) {
                continue;
            }

            $this->push_attachment($attachmentId, $items, $seen);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, true> $seen
     */
    private function collect_from_html(string $content, array &$items, array &$seen): void
    {
        $internalErrors = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $content . '</div>';
        if (! @$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);

            return;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//img');

        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }

            $src = trim((string) $img->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            $attachmentId = $this->resolve_attachment_id($img, $src);
            if ($attachmentId > 0) {
                $this->push_attachment($attachmentId, $items, $seen, $img);
                continue;
            }

            $this->push_src_only($src, $img, $items, $seen);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, true> $seen
     */
    private function push_attachment(int $attachmentId, array &$items, array &$seen, ?\DOMElement $img = null): void
    {
        $dedupeKey = 'id:' . $attachmentId;
        if (isset($seen[$dedupeKey])) {
            return;
        }

        $attachment = get_post($attachmentId);
        if (! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            return;
        }

        $src = (string) wp_get_attachment_url($attachmentId);
        if ($src === '' && $img instanceof \DOMElement) {
            $src = trim((string) $img->getAttribute('src'));
        }
        if ($src === '') {
            return;
        }

        $seen[$dedupeKey] = true;
        $seen['src:' . $this->normalize_src_key($src)] = true;

        $figure = $img?->parentNode instanceof \DOMElement && strtolower($img->parentNode->tagName) === 'figure'
            ? $img->parentNode
            : null;

        $items[] = $this->build_item(
            $attachmentId,
            $src,
            (string) $attachment->post_name,
            $img,
            $figure
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, true> $seen
     */
    private function push_src_only(string $src, \DOMElement $img, array &$items, array &$seen): void
    {
        $srcKey = 'src:' . $this->normalize_src_key($src);
        if (isset($seen[$srcKey])) {
            return;
        }

        $attachmentId = (int) attachment_url_to_postid($src);
        if ($attachmentId > 0) {
            $this->push_attachment($attachmentId, $items, $seen, $img);

            return;
        }

        $seen[$srcKey] = true;

        $figure = $img->parentNode instanceof \DOMElement && strtolower($img->parentNode->tagName) === 'figure'
            ? $img->parentNode
            : null;

        $items[] = $this->build_item(0, $src, $this->slug_from_url($src), $img, $figure);
    }

    private function resolve_attachment_id(\DOMElement $img, string $src): int
    {
        $class = (string) $img->getAttribute('class');
        if (preg_match('/\bwp-image-(\d+)\b/', $class, $m)) {
            return (int) $m[1];
        }

        $dataId = (int) $img->getAttribute('data-id');
        if ($dataId > 0) {
            return $dataId;
        }

        $parent = $img->parentNode;
        if ($parent instanceof \DOMElement) {
            $parentClass = (string) $parent->getAttribute('class');
            if (preg_match('/\bwp-image-(\d+)\b/', $parentClass, $m)) {
                return (int) $m[1];
            }

            $parentDataId = (int) $parent->getAttribute('data-id');
            if ($parentDataId > 0) {
                return $parentDataId;
            }
        }

        return (int) attachment_url_to_postid($src);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_item(
        int $attachmentId,
        string $src,
        string $slug,
        ?\DOMElement $img,
        ?\DOMElement $figure
    ): array {
        $alt = $img instanceof \DOMElement ? trim((string) $img->getAttribute('alt')) : '';
        $title = $img instanceof \DOMElement ? trim((string) $img->getAttribute('title')) : '';

        $caption = '';
        if ($figure instanceof \DOMElement) {
            foreach ($figure->getElementsByTagName('figcaption') as $cap) {
                if ($cap instanceof \DOMElement) {
                    $caption = trim((string) $cap->textContent);
                    break;
                }
            }
        }

        if ($attachmentId > 0 && $slug === '') {
            $attachment = get_post($attachmentId);
            if ($attachment instanceof \WP_Post) {
                $slug = (string) $attachment->post_name;
            }
        }

        if ($slug === '') {
            $slug = $this->slug_from_url($src);
        }

        return [
            'wp_attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'src'              => $src,
            'slug'             => $slug,
            'alt'              => $alt,
            'title'            => $title,
            'caption'          => $caption,
        ];
    }

    private function slug_from_url(string $src): string
    {
        $path = (string) wp_parse_url($src, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $filename = basename($path);
        if ($filename === '') {
            return '';
        }

        $slug = pathinfo($filename, PATHINFO_FILENAME);

        return is_string($slug) ? $slug : '';
    }

    private function normalize_src_key(string $src): string
    {
        $path = (string) wp_parse_url($src, PHP_URL_PATH);

        return strtolower(rtrim($path, '/'));
    }
}
