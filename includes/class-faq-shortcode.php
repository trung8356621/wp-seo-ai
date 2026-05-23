<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [omi_faq], Schema FAQPage và bảo vệ shortcode khi lưu bài.
 */
final class Faq_Shortcode
{
    public const META_FAQS = '_omi_seo_faqs';

    private const SKIP_PUSH_META = '_omi_seo_ai_skip_push';

    public static function register(): void
    {
        add_shortcode('omi_faq', [self::class, 'render']);

        add_action('save_post', [self::class, 'ensure_shortcode_on_save'], 20, 3);
    }

    /**
     * @param  array<string, string>|string  $atts
     */
    public static function render($atts): string
    {
        unset($atts);

        $faqs = self::resolve_faqs_for_render_context();
        if ($faqs === []) {
            return '';
        }

        $html = '<div class="omi-faq-container" style="margin-top: 30px;">';

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];

        foreach ($faqs as $faq) {
            if (! is_array($faq) || empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $q = esc_html((string) $faq['question']);
            $a = wp_kses_post((string) $faq['answer']);
            $m = wp_kses_post((string) ($faq['more'] ?? ''));
            $answerHtml = preg_match('/<(p|div|ul|ol|blockquote|br|em|strong)\b/i', $a) === 1
                ? $a
                : nl2br($a, false);
            $moreHtml = '';
            if ($m !== '') {
                $moreHtml = preg_match('/<(p|div|ul|ol|blockquote|br|em|strong|img)\b/i', $m) === 1
                    ? $m
                    : nl2br($m, false);
            }

            $html .= '<details class="omi-faq-item" style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; background: #fff;">';
            $html .= '<summary style="font-weight: bold; cursor: pointer; font-size: 1.1em; color: #1f2937;">' . $q . '</summary>';
            if ($moreHtml !== '') {
                $html .= '<div style="margin-top: 12px; color: #4b5563; line-height: 1.6;">' . $moreHtml . '</div>';
            }
            $html .= '<div style="margin-top: 15px; color: #4b5563; line-height: 1.6;">' . $answerHtml . '</div>';
            $html .= '</details>';

            $schema['mainEntity'][] = [
                '@type' => 'Question',
                'name' => wp_strip_all_tags((string) $faq['question']),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags($a),
                ],
            ];
        }

        $html .= '</div>';

        if ($schema['mainEntity'] !== []) {
            $html .= '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>';
        }

        return $html;
    }

    public static function ensure_shortcode_on_save(int $postId, $post, bool $update): void
    {
        unset($update);

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (! $post instanceof \WP_Post) {
            $post = get_post($postId);
        }

        if (! $post instanceof \WP_Post) {
            return;
        }

        if ((string) get_post_meta($postId, self::SKIP_PUSH_META, true) === '1') {
            return;
        }

        $faqs = get_post_meta($postId, self::META_FAQS, true);
        if (is_string($faqs)) {
            $decoded = json_decode($faqs, true);
            $faqs = is_array($decoded) ? $decoded : [];
        }

        if ($faqs === [] || ! is_array($faqs)) {
            return;
        }

        if (has_shortcode((string) $post->post_content, 'omi_faq')) {
            return;
        }

        remove_action('save_post', [self::class, 'ensure_shortcode_on_save'], 20);

        update_post_meta($postId, self::SKIP_PUSH_META, '1');

        wp_update_post([
            'ID' => $postId,
            'post_content' => rtrim((string) $post->post_content) . "\n\n[omi_faq]",
        ]);

        delete_post_meta($postId, self::SKIP_PUSH_META);

        add_action('save_post', [self::class, 'ensure_shortcode_on_save'], 20, 3);
    }

    /**
     * @return list<array{question: string, answer: string, more: string}>
     */
    private static function resolve_faqs_for_render_context(): array
    {
        global $post;

        if ($post instanceof \WP_Post) {
            return self::resolve_faqs_for_post((int) $post->ID);
        }

        if (function_exists('is_tax') && (is_tax() || is_category() || is_tag())) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                return self::resolve_faqs_for_term((int) $term->term_id);
            }
        }

        return [];
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $faqs
     */
    public static function store_faqs(int $postId, array $faqs): void
    {
        update_post_meta($postId, self::META_FAQS, $faqs);
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $faqs
     */
    public static function store_faqs_for_term(int $termId, array $faqs): void
    {
        update_term_meta($termId, self::META_FAQS, $faqs);
    }

    /**
     * @return list<array{question: string, answer: string, more: string}>
     */
    public static function resolve_faqs_for_term(int $termId): array
    {
        if ($termId <= 0) {
            return [];
        }

        $faqs = get_term_meta($termId, self::META_FAQS, true);
        if (is_string($faqs)) {
            $decoded = json_decode($faqs, true);
            $faqs = is_array($decoded) ? $decoded : [];
        }

        return self::normalize_faq_rows(is_array($faqs) ? $faqs : []);
    }

    /**
     * @return list<array{question: string, answer: string, more: string}>
     */
    public static function resolve_faqs_for_post(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $faqs = get_post_meta($postId, self::META_FAQS, true);
        if (is_string($faqs)) {
            $decoded = json_decode($faqs, true);
            $faqs = is_array($decoded) ? $decoded : [];
        }

        return self::normalize_faq_rows(is_array($faqs) ? $faqs : []);
    }

    /**
     * @param  array<int, mixed>  $faqs
     * @return list<array{question: string, answer: string, more: string}>
     */
    private static function normalize_faq_rows(array $faqs): array
    {
        if ($faqs === []) {
            return [];
        }

        $normalized = [];
        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $normalized[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => trim((string) ($faq['more'] ?? '')),
            ];
        }

        return $normalized;
    }
}
