<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Đăng bình luận (post/page) hoặc review WooCommerce (product) từ payload Laravel.
 */
final class Comment_Review_Publisher
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{created: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    public function publish_batch(int $postId, array $items): array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return [
                'created' => [],
                'errors'  => [
                    [
                        'index'   => -1,
                        'message' => 'Post not found.',
                    ],
                ],
            ];
        }

        $isProduct = $post->post_type === 'product';
        $created   = [];
        $errors    = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                $errors[] = [
                    'index'   => $index,
                    'message' => 'Invalid item payload.',
                ];
                continue;
            }

            $result = $this->insert_one($postId, $isProduct, $item, $index);
            if (isset($result['error'])) {
                $errors[] = $result;
                continue;
            }

            $created[] = $result;
        }

        if ($isProduct && $created !== [] && function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($postId);
        }

        return [
            'created' => $created,
            'errors'  => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function insert_one(int $postId, bool $isProduct, array $item, int $index): array
    {
        $content = trim((string) ($item['content'] ?? $item['comment'] ?? ''));
        $author  = trim((string) ($item['author'] ?? $item['author_name'] ?? 'Khách'));
        $email   = sanitize_email((string) ($item['email'] ?? $item['author_email'] ?? ''));

        if ($content === '') {
            return [
                'index'   => $index,
                'message' => 'Empty comment content.',
                'error'   => true,
            ];
        }

        if ($email === '' || ! is_email($email)) {
            $email = $this->fallback_email($author, $index);
        }

        $rating = null;
        if ($isProduct) {
            $rating = isset($item['rating']) ? (int) $item['rating'] : null;
            if ($rating !== null) {
                $rating = max(1, min(5, $rating));
            }
        }

        $commentData = [
            'comment_post_ID'      => $postId,
            'comment_author'       => $author,
            'comment_author_email' => $email,
            'comment_content'      => $content,
            'comment_type'         => $isProduct ? 'review' : 'comment',
            'comment_parent'       => 0,
            'user_id'              => 0,
            'comment_author_IP'    => '127.0.0.1',
            'comment_agent'        => 'OmiSeoAiBridge/1.0',
            'comment_date'         => current_time('mysql'),
            'comment_approved'     => 1,
        ];

        $commentId = (int) wp_insert_comment(wp_slash($commentData));
        if ($commentId <= 0) {
            return [
                'index'   => $index,
                'message' => 'wp_insert_comment failed.',
                'error'   => true,
            ];
        }

        if ($isProduct && $rating !== null) {
            update_comment_meta($commentId, 'rating', $rating);
        }

        $row = [
            'index'      => $index,
            'comment_id' => $commentId,
            'type'       => $isProduct ? 'review' : 'comment',
        ];

        if ($isProduct && $rating !== null) {
            $row['rating'] = $rating;
        }

        return $row;
    }

    private function fallback_email(string $author, int $index): string
    {
        $slug = sanitize_title($author);
        if ($slug === '') {
            $slug = 'guest';
        }

        return $slug . '+' . ($index + 1) . '@omi-seo-ai.local';
    }
}
