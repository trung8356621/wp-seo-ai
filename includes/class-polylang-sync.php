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
     *   languages: array<int, array{
     *     slug: string,
     *     name: string,
     *     locale: string,
     *     url_prefix: string,
     *     home_url: string
     *   }>
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

        $hideDefault = true;
        if (function_exists('PLL')) {
            $pll = PLL();
            if (is_object($pll) && isset($pll->options) && is_array($pll->options)) {
                $hideDefault = ! empty($pll->options['hide_default']);
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
                    $homeUrl = '';
                    if (function_exists('pll_home_url')) {
                        $homeUrl = rtrim((string) pll_home_url($slug), '/');
                    }

                    // Explicit WP routing prefix — never invent on Laravel side.
                    // Default language with hide_default → empty prefix (root URLs).
                    $urlPrefix = '';
                    if ($slug === $default && $hideDefault) {
                        $urlPrefix = '';
                    } elseif ($homeUrl !== '') {
                        $siteHome = rtrim((string) home_url('/'), '/');
                        if (str_starts_with($homeUrl, $siteHome)) {
                            $urlPrefix = trim(substr($homeUrl, strlen($siteHome)), '/');
                        }
                    } elseif ($slug !== $default || ! $hideDefault) {
                        // Polylang slug is the rewrite segment when home_url unavailable.
                        $urlPrefix = $slug;
                    }

                    $languages[] = [
                        'slug'       => $slug,
                        'name'       => trim((string) ($names[$index] ?? $slug)),
                        'locale'     => trim((string) ($locales[$index] ?? $slug)),
                        'url_prefix' => $urlPrefix,
                        'home_url'   => $homeUrl !== '' ? $homeUrl . '/' : '',
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
     * WP_Query lang arg for a single Polylang language (canonical or raw slug).
     *
     * @return array<string, string>
     */
    public static function query_args_for_language(string $language): array
    {
        $language = self::normalize_language_slug($language);
        if ($language === '' || ! self::is_active()) {
            return [];
        }

        $slugs = self::term_slugs_for_canonical($language);

        return ['lang' => $slugs[0] ?? $language];
    }

    /**
     * Polylang term slugs that normalize to the given canonical language code.
     *
     * @return list<string>
     */
    public static function term_slugs_for_canonical(string $canonical): array
    {
        $canonical = self::normalize_language_slug($canonical);
        if ($canonical === '') {
            return [];
        }

        if (! self::is_active() || ! function_exists('pll_languages_list')) {
            return [$canonical];
        }

        $raw = pll_languages_list(['fields' => 'slug']);
        if (! is_array($raw)) {
            return [$canonical];
        }

        $matched = [];
        foreach ($raw as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            if (self::normalize_language_slug($slug) === $canonical) {
                $matched[] = $slug;
            }
        }

        return $matched !== [] ? array_values(array_unique($matched)) : [$canonical];
    }

    /**
     * SQL EXISTS fragment filtering posts by Polylang language taxonomy.
     * Empty language or inactive Polylang → no filter (single-language / all-langs behavior).
     *
     * @param  string  $postsIdExpr  e.g. "{$wpdb->posts}.ID" or "p.ID"
     * @return array{sql: string, params: list<string>}
     */
    public static function sql_posts_language_exists_fragment(string $postsIdExpr, string $language): array
    {
        $language = self::normalize_language_slug($language);
        if ($language === '' || ! self::is_active()) {
            return ['sql' => '', 'params' => []];
        }

        global $wpdb;
        $slugs = self::term_slugs_for_canonical($language);
        if ($slugs === []) {
            return ['sql' => '', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));

        return [
            'sql' => " AND EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} omi_pll_tr
                INNER JOIN {$wpdb->term_taxonomy} omi_pll_tt
                    ON omi_pll_tt.term_taxonomy_id = omi_pll_tr.term_taxonomy_id
                    AND omi_pll_tt.taxonomy = 'language'
                INNER JOIN {$wpdb->terms} omi_pll_t
                    ON omi_pll_t.term_id = omi_pll_tt.term_id
                WHERE omi_pll_tr.object_id = {$postsIdExpr}
                AND omi_pll_t.slug IN ({$placeholders})
            )",
            'params' => $slugs,
        ];
    }

    /**
     * Content inventory counts grouped by canonical Polylang language.
     *
     * @param  list<string>  $postTypes
     * @param  list<string>  $statuses
     * @param  array{sql: string, params: list<int>}  $exclude
     * @return array<string, int> canonical_lang => count
     */
    public static function count_content_by_language(
        array $postTypes,
        array $statuses,
        array $exclude
    ): array {
        if (! self::is_active() || $postTypes === [] || $statuses === []) {
            return [];
        }

        global $wpdb;
        $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $excludeSql = (string) ($exclude['sql'] ?? '');
        $excludeParams = is_array($exclude['params'] ?? null) ? $exclude['params'] : [];

        $sql = "SELECT omi_pll_t.slug AS lang_slug, COUNT(DISTINCT {$wpdb->posts}.ID) AS cnt
            FROM {$wpdb->posts}
            INNER JOIN {$wpdb->term_relationships} omi_pll_tr
                ON omi_pll_tr.object_id = {$wpdb->posts}.ID
            INNER JOIN {$wpdb->term_taxonomy} omi_pll_tt
                ON omi_pll_tt.term_taxonomy_id = omi_pll_tr.term_taxonomy_id
                AND omi_pll_tt.taxonomy = 'language'
            INNER JOIN {$wpdb->terms} omi_pll_t
                ON omi_pll_t.term_id = omi_pll_tt.term_id
            WHERE {$wpdb->posts}.post_type IN ({$typePlaceholders})
            AND {$wpdb->posts}.post_status IN ({$statusPlaceholders})
            {$excludeSql}
            GROUP BY omi_pll_t.slug";

        $params = array_merge($postTypes, $statuses, $excludeParams);
        $prepared = $wpdb->prepare($sql, $params);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $canonical = self::normalize_language_slug(trim((string) ($row['lang_slug'] ?? '')));
            $cnt = (int) ($row['cnt'] ?? 0);
            if ($canonical === '' || $cnt <= 0) {
                continue;
            }
            $out[$canonical] = ($out[$canonical] ?? 0) + $cnt;
        }
        ksort($out);

        return $out;
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
