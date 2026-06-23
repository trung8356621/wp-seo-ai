<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Rank_Math_Faq_Schema
{
    public static function register(): void
    {
        add_filter('rank_math/snippet/rich_data', [self::class, 'injectFaqSchema'], 99, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  mixed  $blockInstances
     * @return array<string, mixed>
     */
    public static function injectFaqSchema(array $data, $blockInstances): array
    {
        unset($blockInstances);

        if (! is_singular()) {
            return $data;
        }

        $postId = (int) get_the_ID();
        if ($postId <= 0) {
            return $data;
        }

        $faqsMeta = get_post_meta($postId, Faq_Shortcode::META_FAQS, true);
        if (empty($faqsMeta) || ! is_array($faqsMeta)) {
            return $data;
        }

        $faqSchema = [
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];

        foreach ($faqsMeta as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $cleanAnswer = strip_tags($answer);
            $cleanAnswer = html_entity_decode($cleanAnswer, ENT_QUOTES, 'UTF-8');
            $cleanAnswer = trim((string) preg_replace('/\s+/u', ' ', $cleanAnswer));

            $faqSchema['mainEntity'][] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $cleanAnswer,
                ],
            ];
        }

        if ($faqSchema['mainEntity'] === []) {
            return $data;
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $data['@graph'][] = $faqSchema;

            return $data;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$data, $faqSchema],
        ];
    }
}
