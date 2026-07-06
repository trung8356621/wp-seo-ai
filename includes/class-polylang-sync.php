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
            $resolved = self::normalize_language_slug(
                trim((string) pll_default_language('slug')),
            );
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
                $seenSlugs = [];
                foreach ($slugs as $index => $slug) {
                    $slug = self::normalize_language_slug(trim((string) $slug));
                    if ($slug === '' || isset($seenSlugs[$slug])) {
                        continue;
                    }

                    $seenSlugs[$slug] = true;
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
     * WP_Query / get_terms args để lấy nội dung mọi ngôn ngữ Polylang (không lọc theo ngôn ngữ hiện tại).
     *
     * @return array<string, string>
     */
    public static function query_args_for_all_languages(): array
    {
        if (! self::is_active()) {
            return [];
        }

        return ['lang' => ''];
    }

    /**
     * @return array{current_lang: string, translations: array<string, int>}|null
     */
    public static function payload_for_post(int $postId): ?array
    {
        if (! self::is_active() || $postId <= 0) {
            return null;
        }

        $currentLang = self::normalize_language_slug(
            trim((string) pll_get_post_language($postId, 'slug')),
        );
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

        $currentLang = self::normalize_language_slug(
            trim((string) pll_get_term_language($termId, 'slug')),
        );
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
            $lang = self::normalize_language_slug(trim((string) $lang));
            $entityId = (int) $entityId;
            if ($lang === '' || $entityId <= 0) {
                continue;
            }

            $normalized[$lang] = $entityId;
        }

        return $normalized;
    }

    /**
     * Chuẩn hóa slug Polylang về mã ISO 639-1 canonical để Laravel filter/match đúng.
     * Polylang hay cấu hình sai: slug `vn`, locale `vi_VI`/`vi_VN`/`vi` — đều là tiếng Việt.
     */
    public static function normalize_language_slug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $key = strtolower(str_replace('-', '_', $slug));

        if ($key === 'vn' || $key === 'vi' || str_starts_with($key, 'vi_')) {
            return 'vi';
        }

        return strtolower($slug);
    }
}
