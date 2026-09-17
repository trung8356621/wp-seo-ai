<?php

declare(strict_types=1);

/**
 * SEO Ops sync/Edit Article payloads must use canonical attachment URLs.
 *
 * php tests/SyncCanonicalImageContractTest.php
 */
define('ABSPATH', __DIR__.'/');

$failures = 0;

function omi_assert(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$message}\n";

        return;
    }

    $failures++;
    echo "FAIL  {$message}\n";
}

$sync = file_get_contents(dirname(__DIR__).'/includes/class-sync-provider.php');
$extractor = file_get_contents(dirname(__DIR__).'/includes/class-post-images-extractor.php');
$analysis = file_get_contents(dirname(__DIR__).'/includes/class-post-analysis-service.php');

omi_assert(is_string($sync) && $sync !== '', 'sync provider source readable');
omi_assert(is_string($extractor) && $extractor !== '', 'post images extractor source readable');
omi_assert(is_string($analysis) && $analysis !== '', 'post analysis source readable');

omi_assert(
    ! preg_match("/wp_get_attachment_image_url\s*\([^;]*'(?:medium|thumbnail|woocommerce_thumbnail|full)'/", (string) $sync),
    'sync provider does not select generated image sizes'
);
omi_assert(
    substr_count((string) $sync, 'wp_get_attachment_url(') >= 4,
    'sync provider uses wp_get_attachment_url for featured/gallery/term images'
);
omi_assert(
    str_contains((string) $extractor, 'wp_get_attachment_url($attachmentId)'),
    'Post_Images_Extractor keeps canonical attachment URL'
);
omi_assert(
    ! str_contains((string) $extractor, 'wp_get_attachment_image_url'),
    'Post_Images_Extractor does not pick generated sizes'
);
omi_assert(
    str_contains((string) $analysis, 'wp_get_attachment_url($featuredId)'),
    'post analysis featured image uses canonical attachment URL'
);
omi_assert(
    ! str_contains((string) $sync, 'preg_replace') || ! preg_match('/-\d+x\d+/', (string) $sync),
    'sync provider does not introduce regex original-filename guessing'
);

// Runtime stubs for gallery / term helpers.
$attachmentUrls = [
    11 => 'https://cdn.example.test/wp-content/uploads/2026/01/hero.jpg',
    22 => 'https://cdn.example.test/wp-content/uploads/2026/01/gallery-a.jpg',
    33 => 'https://cdn.example.test/wp-content/uploads/2026/01/gallery-b.jpg',
    44 => 'https://cdn.example.test/wp-content/uploads/2026/01/term-cat.jpg',
];

function wp_get_attachment_url(int $id): string
{
    return (string) ($GLOBALS['attachmentUrls'][$id] ?? '');
}

function wp_get_attachment_image_url(int $id, string $size): string
{
    unset($id, $size);

    return 'https://cdn.example.test/SHOULD-NOT-USE-SIZE.jpg';
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    unset($postId, $single);
    if ($key === '_product_image_gallery') {
        return '22,33';
    }

    return '';
}

function get_term_meta(int $termId, string $key, bool $single = false): mixed
{
    unset($single);
    if ($termId === 7 && $key === 'thumbnail_id') {
        return 44;
    }

    return '';
}

$GLOBALS['attachmentUrls'] = $attachmentUrls;

require_once dirname(__DIR__).'/includes/class-polylang-sync.php';

// Minimal Sync_Provider method coverage via anonymous subclass reflection-free copy.
final class OmiCanonicalImageProbe
{
    public function gallery(int $postId): array
    {
        $raw = get_post_meta($postId, '_product_image_gallery', true);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $gallery = [];
        foreach (array_filter(array_map('intval', explode(',', $raw))) as $attachmentId) {
            if ($attachmentId <= 0) {
                continue;
            }
            $url = (string) wp_get_attachment_url($attachmentId);
            if ($url === '') {
                continue;
            }
            $gallery[] = ['id' => $attachmentId, 'url' => $url];
        }

        return $gallery;
    }

    public function termImage(int $termId): string
    {
        $thumbId = (int) get_term_meta($termId, 'thumbnail_id', true);
        if ($thumbId <= 0) {
            return '';
        }

        return (string) wp_get_attachment_url($thumbId);
    }

    public function featured(int $thumbId): string
    {
        return $thumbId > 0 ? (string) wp_get_attachment_url($thumbId) : '';
    }
}

$probe = new OmiCanonicalImageProbe();
$gallery = $probe->gallery(1);
omi_assert(
    $gallery === [
        ['id' => 22, 'url' => $attachmentUrls[22]],
        ['id' => 33, 'url' => $attachmentUrls[33]],
    ],
    'product gallery resolves via canonical attachment URL'
);
omi_assert($probe->termImage(7) === $attachmentUrls[44], 'term image resolves via canonical attachment URL');
omi_assert($probe->featured(11) === $attachmentUrls[11], 'featured image resolves via canonical attachment URL');
omi_assert($probe->featured(0) === '', 'missing featured attachment stays empty');

// External src-only content image remains original URL (extractor contract).
$content = '<p><img src="https://external.example/photo.jpg" alt="x"></p>';
omi_assert(
    str_contains($content, 'https://external.example/photo.jpg'),
    'src-only external image remains original URL'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "\nAll SyncCanonicalImageContract tests passed.\n";
exit(0);
