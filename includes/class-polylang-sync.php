<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Polylang metadata vectors for Laravel Omnichannel sync.
 */
final class Polylang_Sync
{
    public static function is_active(): bool
    {
        return function_exists('pll_get_post_language');
    }

    /**
     * @return array{
     *   active: bool,
     *   default: string,
     *   languages: array<int, array{slug: string, name: string, locale: string}>
     * }
     */
    public static function site_info(): array
    {
        if (! self::is_active()) {
            return [
                'active'     => false,
                'default'    => 'vi',
                'languages'  => [],
            ];
        }

        $default = 'vi';
        if (function_exists('pll_default_language')) {
            $resolved = trim((string) pll_default_language('slug'));
            if ($resolved !== '') {
                $default = $resolved;
            }
        }

        $languages = [];
        if (function_exists('pll_languages_list')) {
            $slugs = pll_languages_list(['fields' => 'slug']);
            $names = pll_languages_list(['fields' => 'name']);
            $locales = pll_languages_list(['fields' => 'locale']);

            if (is_array($slugs)) {
                foreach ($slugs as $index => $slug) {
                    $slug = trim((string) $slug);
                    if ($slug === '') {
                        continue;
                    }

                    $languages[] = [
                        'slug'   => $slug,
                        'name'   => trim((string) ($names[$index] ?? $slug)),
                        'locale' => trim((string) ($locales[$index] ?? $slug)),
                    ];
                }
            }
        }

        return [
            'active'    => true,
            'default'   => $default,
            'languages' => $languages,
        ];
    }

    /**
     * @return array{current_lang: string, translations: array<string, int>}|null
     */
    public static function payload_for_post(int $postId): ?array
    {
        if (! self::is_active() || $postId <= 0) {
            return null;
        }

        $currentLang = trim((string) pll_get_post_language($postId, 'slug'));
        if ($currentLang === '') {
            return null;
        }

        return [
            'current_lang'  => $currentLang,
            'translations'  => self::normalize_translation_map(
                pll_get_post_translations($postId),
            ),
        ];
    }

    /**
     * @return array{current_lang: string, translations: array<string, int>}|null
     */
    public static function payload_for_term(int $termId): ?array
    {
        if (! function_exists('pll_get_term_language') || $termId <= 0) {
            return null;
        }

        $currentLang = trim((string) pll_get_term_language($termId, 'slug'));
        if ($currentLang === '') {
            return null;
        }

        $raw = function_exists('pll_get_term_translations')
            ? pll_get_term_translations($termId)
            : [];

        return [
            'current_lang'  => $currentLang,
            'translations'  => self::normalize_translation_map($raw),
        ];
    }

    /**
     * Mandatory multilingual object for sync payloads (fallback when Polylang inactive).
     *
     * @return array{current_lang: string, translations: array<string, int>}
     */
    public static function multilingual_field_for_post(int $postId): array
    {
        $payload = self::payload_for_post($postId);
        if ($payload !== null) {
            return $payload;
        }

        return [
            'current_lang'  => 'vi',
            'translations'  => $postId > 0 ? ['vi' => $postId] : [],
        ];
    }

    /**
     * @return array{current_lang: string, translations: array<string, int>}
     */
    public static function multilingual_field_for_term(int $termId): array
    {
        $payload = self::payload_for_term($termId);
        if ($payload !== null) {
            return $payload;
        }

        return [
            'current_lang'  => 'vi',
            'translations'  => $termId > 0 ? ['vi' => $termId] : [],
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array<string, int>
     */
    private static function normalize_translation_map($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $lang => $entityId) {
            $lang = trim((string) $lang);
            $entityId = (int) $entityId;
            if ($lang === '' || $entityId <= 0) {
                continue;
            }

            $normalized[$lang] = $entityId;
        }

        return $normalized;
    }
}
