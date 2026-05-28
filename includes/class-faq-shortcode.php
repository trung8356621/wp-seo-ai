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
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_filter('the_content', [self::class, 'append_faq_when_meta_without_shortcode'], 12);
        add_filter('term_description', [self::class, 'append_term_faq_when_needed'], 12, 2);
    }

    /**
     * Meta _omi_seo_faqs có dữ liệu nhưng post_content thiếu [omi_faq] → vẫn hiển thị FAQ.
     */
    public static function append_faq_when_meta_without_shortcode(string $content): string
    {
        if (! is_singular() || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }

        global $post;
        if (! $post instanceof \WP_Post) {
            return $content;
        }

        if (
            has_shortcode($content, 'omi_faq')
            || str_contains($content, 'class="omi-faq-container"')
            || str_contains($content, "class='omi-faq-container'")
        ) {
            return $content;
        }

        $faqs = self::resolve_faqs_for_post((int) $post->ID);
        if ($faqs === []) {
            return $content;
        }

        return $content . self::render([]);
    }

    /**
     * Taxonomy description (vd product_cat) có thể không được do_shortcode bởi theme.
     * Filter này đảm bảo [omi_faq] luôn render đúng và fallback append theo meta term.
     */
    public static function append_term_faq_when_needed(string $description, $term = null): string
    {
        if (! function_exists('is_tax') || (! is_tax() && ! is_category() && ! is_tag())) {
            return $description;
        }

        $termId = self::resolve_term_id($term);
        if ($termId <= 0) {
            return $description;
        }

        if (
            str_contains($description, 'class="omi-faq-container"')
            || str_contains($description, "class='omi-faq-container'")
        ) {
            return $description;
        }

        // Force chạy shortcode trong term description (nếu theme chưa xử lý).
        if (has_shortcode($description, 'omi_faq')) {
            return do_shortcode($description);
        }

        $faqs = self::resolve_faqs_for_term($termId);
        if ($faqs === []) {
            return $description;
        }

        return $description . self::render_term_faq($termId);
    }

    public static function enqueue_assets(): void
    {
        wp_enqueue_style(
            'omi-seo-faq-accordion',
            OMI_SEO_AI_BRIDGE_URL . 'assets/css/omi-faq-accordion.css',
            [],
            OMI_SEO_AI_BRIDGE_VERSION,
        );
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

        $html = '<div class="omi-faq-container">';
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq) || empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $questionRaw = trim((string) $faq['question']);
            $question = esc_html(self::numbered_question_label($questionRaw, (int) $index));
            $answerHtml = self::format_faq_html_field((string) $faq['answer']);
            $moreHtml = trim((string) ($faq['more'] ?? ''));
            $moreHtml = $moreHtml !== '' ? self::format_faq_html_field($moreHtml) : '';

            $openAttr = $index === 0 ? ' open' : '';
            $html .= '<details class="omi-faq-item"' . $openAttr . '>';
            $html .= '<summary class="omi-faq-item__summary">';
            $html .= '<span class="omi-faq-item__chevron" aria-hidden="true"></span>';
            $html .= '<span class="omi-faq-item__question">' . $question . '</span>';
            $html .= '</summary>';
            $html .= '<div class="omi-faq-item__body">';
            if ($moreHtml !== '') {
                $html .= '<div class="omi-faq-item__more">' . $moreHtml . '</div>';
            }
            $html .= '<div class="omi-faq-item__answer">' . $answerHtml . '</div>';
            $html .= '</div>';
            $html .= '</details>';

            $schema['mainEntity'][] = [
                '@type' => 'Question',
                'name' => wp_strip_all_tags($questionRaw),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags((string) $faq['answer']),
                ],
            ];
        }

        $html .= '</div>';

        if ($schema['mainEntity'] !== []) {
            $html .= '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>';
        }

        return $html;
    }

    private static function numbered_question_label(string $question, int $index): string
    {
        if (preg_match('/^\d+[\.\)]\s/u', $question) === 1) {
            return $question;
        }

        return ($index + 1) . '. ' . $question;
    }

    private static function format_faq_html_field(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/<(p|div|ul|ol|blockquote|br|em|strong|a)\b/i', $raw) === 1) {
            return wp_kses_post($raw);
        }

        return nl2br(esc_html($raw), false);
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
        if (function_exists('is_tax') && (is_tax() || is_category() || is_tag())) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                return self::resolve_faqs_for_term((int) $term->term_id);
            }
        }

        global $post;

        if ($post instanceof \WP_Post) {
            return self::resolve_faqs_for_post((int) $post->ID);
        }

        return [];
    }

    /**
     * @param  mixed  $term
     */
    private static function resolve_term_id($term): int
    {
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }

        if (is_numeric($term)) {
            return (int) $term;
        }

        $queried = get_queried_object();
        if ($queried instanceof \WP_Term) {
            return (int) $queried->term_id;
        }

        return 0;
    }

    /**
     * @param  array<int, mixed>|null  $faqs
     * @return list<array{question: string, answer: string, more: string}>
     */
    public static function normalize_faq_payload(mixed $faqs): array
    {
        return is_array($faqs) ? self::normalize_faq_rows($faqs) : [];
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

    private static function render_term_faq(int $termId): string
    {
        $faqs = self::resolve_faqs_for_term($termId);
        if ($faqs === []) {
            return '';
        }

        $html = '<div class="omi-faq-container">';
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq) || empty($faq['question']) || empty($faq['answer'])) {
                continue;
            }

            $questionRaw = trim((string) $faq['question']);
            $question = esc_html(self::numbered_question_label($questionRaw, (int) $index));
            $answerHtml = self::format_faq_html_field((string) $faq['answer']);
            $moreHtml = trim((string) ($faq['more'] ?? ''));
            $moreHtml = $moreHtml !== '' ? self::format_faq_html_field($moreHtml) : '';

            $openAttr = $index === 0 ? ' open' : '';
            $html .= '<details class="omi-faq-item"' . $openAttr . '>';
            $html .= '<summary class="omi-faq-item__summary">';
            $html .= '<span class="omi-faq-item__chevron" aria-hidden="true"></span>';
            $html .= '<span class="omi-faq-item__question">' . $question . '</span>';
            $html .= '</summary>';
            $html .= '<div class="omi-faq-item__body">';
            if ($moreHtml !== '') {
                $html .= '<div class="omi-faq-item__more">' . $moreHtml . '</div>';
            }
            $html .= '<div class="omi-faq-item__answer">' . $answerHtml . '</div>';
            $html .= '</div>';
            $html .= '</details>';

            $schema['mainEntity'][] = [
                '@type' => 'Question',
                'name' => wp_strip_all_tags($questionRaw),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags((string) $faq['answer']),
                ],
            ];
        }

        $html .= '</div>';

        if ($schema['mainEntity'] !== []) {
            $html .= '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>';
        }

        return $html;
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
            $row = self::normalize_faq_row($faq);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * @return array{question: string, answer: string, more: string}|null
     */
    private static function normalize_faq_row(mixed $faq): ?array
    {
        if (! is_array($faq)) {
            return null;
        }

        $question = self::pick_faq_field($faq, ['question', 'q', 'title', 'name', 'label', 'heading']);
        $answer = self::pick_faq_field($faq, ['answer', 'a', 'content', 'body', 'text', 'response', 'value']);
        $more = self::pick_faq_field($faq, ['more', 'see_more', 'seeMore', 'xem_them', 'intro', 'lead']);

        if ($question === '' && $answer === '') {
            return null;
        }

        if ($question === '') {
            $question = 'FAQ';
        }

        if ($answer === '' && $more !== '') {
            $answer = $more;
            $more = '';
        }

        if ($answer === '') {
            return null;
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'more' => $more,
        ];
    }

    /**
     * @param  array<string, mixed>  $faq
     * @param  list<string>  $keys
     */
    private static function pick_faq_field(array $faq, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $faq)) {
                continue;
            }

            $value = trim((string) $faq[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
