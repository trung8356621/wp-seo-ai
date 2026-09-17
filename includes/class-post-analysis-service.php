<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Local post analysis (links, SEO meta, keyword candidates, stats).
 * Source of truth for HTML-derived metadata — no Laravel network calls.
 */
final class Post_Analysis_Service
{
    public const VERSION = 1;

    public const META_CACHE = '_omi_seo_post_analysis_cache';

    public const CONTEXT_LIMIT = 100;

    public const OPTION_WIKI_TRUST = 'omi_seo_wiki_trust_domains';

    public const SUPPORTED_POST_TYPES = ['post', 'page', 'product'];

    /** @var list<string> */
    public const DEFAULT_WIKI_TRUST_DOMAINS = [
        'wikipedia.org',
        '*.gov',
        '*.edu',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function analyze(int $postId, bool $useCache = true): ?array
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        if (! $this->is_supported_post($post)) {
            return null;
        }

        $contentHash = self::content_hash($post);
        if ($useCache) {
            $cached = $this->read_cache($postId);
            if (is_array($cached)
                && (string) ($cached['content_hash'] ?? '') === $contentHash
                && (int) ($cached['analysis_version'] ?? 0) === self::VERSION
            ) {
                return $cached;
            }
        }

        $payload = $this->build_payload($post, $contentHash);
        $this->write_cache($postId, $payload);

        return $payload;
    }

    /**
     * Analyze from HTML string (tests / offline). Pass optional post stub fields.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function analyze_html(string $html, array $context = []): array
    {
        $postId = (int) ($context['post_id'] ?? 0);
        $title = (string) ($context['title'] ?? '');
        $hash = hash('sha256', $html.'|'.$title);
        $siteHost = (string) ($context['site_host'] ?? (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? ''));

        $extractOptions = [
            'resolve_targets' => (bool) ($context['resolve_targets'] ?? true),
        ];
        if (is_callable($context['target_resolver'] ?? null)) {
            $extractOptions['target_resolver'] = $context['target_resolver'];
        }

        $links = $this->extract_links_from_html($html, $postId, $hash, $siteHost, $extractOptions);
        $seo = is_array($context['seo'] ?? null) ? $context['seo'] : [
            'plugin' => 'none',
            'meta_title' => $title,
            'meta_description' => '',
            'focus_keywords' => [],
        ];
        $keywordCandidates = $this->build_keyword_candidates($links, $seo);
        $stats = $this->build_stats($html, $links, $keywordCandidates);

        return [
            'analysis_version' => self::VERSION,
            'post_id' => $postId,
            'post_type' => (string) ($context['post_type'] ?? 'post'),
            'status' => (string) ($context['status'] ?? 'publish'),
            'title' => $title,
            'slug' => (string) ($context['slug'] ?? ''),
            'permalink' => (string) ($context['permalink'] ?? ''),
            'modified_gmt' => (string) ($context['modified_gmt'] ?? ''),
            'content_hash' => $hash,
            'content_chars' => self::mb_strlen_safe($html),
            'featured_media' => [
                'id' => (int) ($context['featured_media_id'] ?? 0) ?: null,
                'url' => (string) ($context['featured_media_url'] ?? ''),
            ],
            'seo' => $seo,
            'links' => $links,
            'keyword_candidates' => $keywordCandidates,
            'stats' => $stats,
            'analyzed_at' => gmdate('c'),
            'source' => (string) ($context['source'] ?? 'html'),
        ];
    }

    public static function content_hash(\WP_Post $post): string
    {
        return hash('sha256', (string) $post->post_content.'|'.(string) $post->post_title);
    }

    public static function register(): void
    {
        add_action('save_post', [self::class, 'on_save_post'], 25, 2);
        add_action('omi_seo_analyze_post', [self::class, 'run_scheduled_analysis'], 10, 1);
    }

    public static function on_save_post(int $postId, mixed $post = null): void
    {
        if ($postId <= 0 || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (! $post instanceof \WP_Post) {
            $post = get_post($postId);
        }
        if (! $post instanceof \WP_Post) {
            return;
        }

        if (! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        if (in_array($post->post_status, ['auto-draft', 'inherit', 'trash'], true)) {
            return;
        }

        // Invalidate stale cache immediately (cheap). Heavy parse deferred.
        delete_post_meta($postId, self::META_CACHE);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('omi_seo_analyze_post', [$postId], 'omi-seo-ai');

            return;
        }

        if (! wp_next_scheduled('omi_seo_analyze_post', [$postId])) {
            wp_schedule_single_event(time() + 5, 'omi_seo_analyze_post', [$postId]);
        }
    }

    public static function run_scheduled_analysis(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }
        (new self())->analyze($postId, false);
    }

    public function is_supported_post(\WP_Post $post): bool
    {
        if (! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return false;
        }

        return ! in_array($post->post_status, ['auto-draft', 'inherit'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_payload(\WP_Post $post, string $contentHash): array
    {
        $postId = (int) $post->ID;
        $html = (string) $post->post_content;
        $siteHost = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');
        $links = $this->extract_links_from_html($html, $postId, $contentHash, $siteHost, [
            'resolve_targets' => true,
        ]);

        $seoRaw = Seo_Plugin_Resolver::for_post($postId);
        $focusRaw = trim((string) ($seoRaw['focus_keyword'] ?? ''));
        $focusKeywords = $this->split_provider_keywords($focusRaw);

        $seo = [
            'plugin' => (string) ($seoRaw['plugin'] ?? 'none'),
            'meta_title' => (string) ($seoRaw['seo_title'] ?? ''),
            'meta_description' => (string) ($seoRaw['meta_description'] ?? ''),
            'focus_keywords' => $focusKeywords,
            'canonical' => (string) ($seoRaw['canonical'] ?? ''),
            'robots' => is_array($seoRaw['robots'] ?? null) ? $seoRaw['robots'] : ['index' => true, 'follow' => true],
        ];

        $keywordCandidates = $this->build_keyword_candidates($links, $seo);
        $stats = $this->build_stats($html, $links, $keywordCandidates);

        $featuredId = (int) get_post_thumbnail_id($postId);
        $featuredUrl = '';
        if ($featuredId > 0) {
            $src = wp_get_attachment_url($featuredId);
            $featuredUrl = is_string($src) ? $src : '';
        }

        $taxonomies = $this->light_taxonomies($postId);

        return [
            'analysis_version' => self::VERSION,
            'post_id' => $postId,
            'post_type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'permalink' => Permalink_Resolver::for_post($postId),
            'modified_gmt' => (string) $post->post_modified_gmt,
            'content_hash' => $contentHash,
            'content_chars' => self::mb_strlen_safe($html),
            'featured_media' => [
                'id' => $featuredId > 0 ? $featuredId : null,
                'url' => $featuredUrl,
            ],
            'taxonomies' => $taxonomies,
            'seo' => $seo,
            'links' => $links,
            'keyword_candidates' => $keywordCandidates,
            'stats' => $stats,
            'analyzed_at' => gmdate('c'),
            'source' => 'post_content',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function extract_links_from_html(
        string $html,
        int $sourcePostId,
        string $contentHash,
        string $siteHost,
        array $options = [],
    ): array {
        if (trim($html) === '') {
            return [];
        }

        $resolveTargets = (bool) ($options['resolve_targets'] ?? true);
        $targetResolver = is_callable($options['target_resolver'] ?? null)
            ? $options['target_resolver']
            : null;

        if (! class_exists(\DOMDocument::class)) {
            return $this->regex_fallback_links($html, $sourcePostId, $contentHash, $siteHost, $resolveTargets, $targetResolver);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<?xml encoding="utf-8" ?><div id="omi-post-analysis-root">'.$html.'</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="omi-post-analysis-root"]//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $links = [];
        foreach ($nodes as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $hrefRaw = trim(html_entity_decode($a->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($hrefRaw === '' || self::is_skippable_href($hrefRaw)) {
                continue;
            }

            $absolute = self::absolutize($hrefRaw);
            $anchor = self::normalize_whitespace(wp_strip_all_tags((string) ($a->textContent ?? '')));
            $rel = trim((string) $a->getAttribute('rel'));
            $host = (string) (wp_parse_url($absolute, PHP_URL_HOST) ?? '');
            $sameDomain = $siteHost !== '' && $host !== '' && strcasecmp(self::normalize_host($host), self::normalize_host($siteHost)) === 0;

            $targetPostId = null;
            $targetTermId = null;
            $targetTaxonomy = null;
            $unresolvedReason = null;
            $linkType = 'external';
            $status = 'ok';
            $targetExternalUrl = null;

            if ($sameDomain) {
                $resolved = 0;
                if ($resolveTargets) {
                    $resolved = $targetResolver !== null
                        ? (int) $targetResolver($absolute, $hrefRaw)
                        : self::resolve_internal_post_id($absolute, $hrefRaw);
                }
                if ($resolved > 0) {
                    $linkType = 'internal';
                    $targetPostId = $resolved;
                    $status = 'ok';
                } else {
                    $linkType = 'internal_unresolved';
                    $status = 'unresolved';
                    $path = (string) (wp_parse_url($absolute, PHP_URL_PATH) ?? '');
                    if ($path === '/' || $path === '') {
                        $unresolvedReason = 'front_page';
                    } elseif (str_contains($path, '/wp-content/uploads/')) {
                        $unresolvedReason = 'media_file';
                    } else {
                        $termHit = ($targetResolver === null && function_exists('get_term_by'))
                            ? self::resolve_internal_term($absolute)
                            : null;
                        if (is_array($termHit)) {
                            $unresolvedReason = 'taxonomy_archive';
                            $targetTermId = (int) $termHit['term_id'];
                            $targetTaxonomy = (string) $termHit['taxonomy'];
                        } else {
                            $unresolvedReason = 'unknown';
                        }
                    }
                }
            } elseif (self::is_wiki_trust_host($host)) {
                $linkType = 'wiki_trust';
                $targetExternalUrl = $absolute;
                $status = 'ok';
            } else {
                $linkType = 'external';
                $targetExternalUrl = $absolute;
                $status = 'ok';
            }

            $links[] = [
                'href_raw' => $hrefRaw,
                'url' => $absolute,
                'anchor_text' => $anchor,
                'link_type' => $linkType,
                'target_post_id' => $targetPostId,
                'target_term_id' => $targetTermId,
                'target_taxonomy' => $targetTaxonomy,
                'target_external_url' => $targetExternalUrl,
                'unresolved_reason' => $unresolvedReason,
                'context_before' => $this->collect_adjacent_text($a, true, self::CONTEXT_LIMIT),
                'context_after' => $this->collect_adjacent_text($a, false, self::CONTEXT_LIMIT),
                'rel' => $rel !== '' ? $rel : null,
                'status' => $status,
                'content_hash' => $contentHash,
                'source_post_id' => $sourcePostId > 0 ? $sourcePostId : null,
            ];
        }

        return $links;
    }

    /**
     * Catalog shape used by legacy Link_Catalog_Extractor consumers.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog_links_for_post(\WP_Post $post): array
    {
        $analysis = $this->analyze((int) $post->ID, true);
        $links = is_array($analysis['links'] ?? null) ? $analysis['links'] : [];
        $out = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $type = (string) ($link['link_type'] ?? '');
            $catalogType = match ($type) {
                'internal', 'internal_unresolved' => 'internal',
                'wiki_trust', 'external' => 'external',
                default => 'external',
            };
            $out[] = [
                'wordpress_id' => (int) $post->ID,
                'url' => (string) ($link['url'] ?? ''),
                'canonical' => (string) ($link['url'] ?? ''),
                'slug' => (string) $post->post_name,
                'title' => (string) ($link['anchor_text'] ?? ''),
                'anchor' => (string) ($link['anchor_text'] ?? ''),
                'status' => (string) $post->post_status,
                'type' => $catalogType,
                'link_type' => $type,
                'target_post_id' => $link['target_post_id'] ?? null,
                'content_hash' => (string) ($link['content_hash'] ?? ''),
                'updated_at' => gmdate('c', strtotime((string) $post->post_modified_gmt) ?: time()),
                'meta' => [
                    'anchor_text' => (string) ($link['anchor_text'] ?? ''),
                    'context_before' => $link['context_before'] ?? null,
                    'context_after' => $link['context_after'] ?? null,
                    'source_post_type' => (string) $post->post_type,
                    'href_raw' => (string) ($link['href_raw'] ?? ''),
                ],
            ];
        }

        return $out;
    }

    public static function resolve_internal_post_id(string $absoluteUrl, string $hrefRaw = ''): int
    {
        $candidates = self::url_resolution_candidates($absoluteUrl, $hrefRaw);
        foreach ($candidates as $candidate) {
            if ($candidate === '' || ! function_exists('url_to_postid')) {
                continue;
            }
            $id = (int) url_to_postid($candidate);
            if ($id > 0) {
                return $id;
            }
        }

        if (function_exists('attachment_url_to_postid')) {
            foreach ($candidates as $candidate) {
                $path = (string) (wp_parse_url($candidate, PHP_URL_PATH) ?? '');
                if ($path !== '' && str_contains($path, '/wp-content/uploads/')) {
                    $attId = (int) attachment_url_to_postid($candidate);
                    if ($attId > 0) {
                        return $attId;
                    }
                }
            }
        }

        // Path /?p=ID or /index.php?p=ID
        foreach ($candidates as $candidate) {
            $query = (string) (wp_parse_url($candidate, PHP_URL_QUERY) ?? '');
            if ($query === '') {
                continue;
            }
            parse_str($query, $params);
            $p = (int) ($params['p'] ?? $params['page_id'] ?? 0);
            if ($p > 0 && get_post($p) instanceof \WP_Post) {
                return $p;
            }
        }

        return 0;
    }

    /**
     * Best-effort taxonomy archive detection when url_to_postid fails.
     *
     * @return array{term_id:int,taxonomy:string}|null
     */
    public static function resolve_internal_term(string $absoluteUrl): ?array
    {
        $path = trim((string) (wp_parse_url($absoluteUrl, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return null;
        }
        $slug = rawurldecode(basename($path));
        if ($slug === '' || ! function_exists('get_terms')) {
            return null;
        }

        $taxonomies = function_exists('get_taxonomies')
            ? get_taxonomies(['public' => true], 'names')
            : ['category', 'post_tag', 'product_cat', 'product_tag'];
        if (! is_array($taxonomies) || $taxonomies === []) {
            return null;
        }

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term instanceof \WP_Term) {
                return [
                    'term_id' => (int) $term->term_id,
                    'taxonomy' => $taxonomy,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function url_resolution_candidates(string $absoluteUrl, string $hrefRaw = ''): array
    {
        $out = [];
        $push = static function (string $url) use (&$out): void {
            $url = trim($url);
            if ($url === '' || in_array($url, $out, true)) {
                return;
            }
            $out[] = $url;
        };

        $push($absoluteUrl);
        if ($hrefRaw !== '') {
            $push(self::absolutize($hrefRaw));
        }

        $decoded = rawurldecode($absoluteUrl);
        $push($decoded);

        $parts = wp_parse_url($absoluteUrl);
        if (! is_array($parts)) {
            return $out;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';

        if ($host === '') {
            return $out;
        }

        $pathDecoded = rawurldecode($path);
        $pathVariants = array_unique([
            $path,
            $pathDecoded,
            rtrim($path, '/') ?: '/',
            rtrim($pathDecoded, '/') ?: '/',
            ($path === '' || str_ends_with($path, '/')) ? rtrim($path, '/').'/' : $path.'/',
            ($pathDecoded === '' || str_ends_with($pathDecoded, '/')) ? rtrim($pathDecoded, '/').'/' : $pathDecoded.'/',
        ]);

        foreach ($pathVariants as $pathVariant) {
            $push($scheme.'://'.$host.$pathVariant.$query);
            if (str_starts_with(strtolower($host), 'www.')) {
                $push($scheme.'://'.substr($host, 4).$pathVariant.$query);
            } else {
                $push($scheme.'://www.'.$host.$pathVariant.$query);
            }
            $push($pathVariant.$query);
        }

        // Fragment-only navigation still points at the path post.
        return $out;
    }

    public static function is_wiki_trust_host(string $host): bool
    {
        $host = self::normalize_host($host);
        if ($host === '') {
            return false;
        }

        foreach (self::wiki_trust_domains() as $pattern) {
            if (self::host_matches_pattern($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function wiki_trust_domains(): array
    {
        $stored = get_option(self::OPTION_WIKI_TRUST, null);
        if (is_array($stored) && $stored !== []) {
            $domains = [];
            foreach ($stored as $item) {
                $item = strtolower(trim((string) $item));
                if ($item !== '') {
                    $domains[] = $item;
                }
            }

            return $domains !== [] ? array_values(array_unique($domains)) : self::DEFAULT_WIKI_TRUST_DOMAINS;
        }

        return self::DEFAULT_WIKI_TRUST_DOMAINS;
    }

    public static function is_skippable_href(string $href): bool
    {
        $lower = strtolower(trim($href));
        if ($lower === '' || str_starts_with($lower, '#')) {
            return true;
        }

        foreach (['javascript:', 'mailto:', 'tel:', 'sms:', 'whatsapp:', 'viber:', 'data:', 'cid:'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        $scheme = (string) (wp_parse_url($href, PHP_URL_SCHEME) ?? '');
        if ($scheme !== '') {
            $scheme = strtolower($scheme);

            return in_array($scheme, [
                'javascript',
                'mailto',
                'tel',
                'sms',
                'whatsapp',
                'viber',
                'data',
                'cid',
            ], true);
        }

        return false;
    }

    public static function absolutize(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (function_exists('is_ssl') && is_ssl() ? 'https:' : 'https:').$href;
        }
        if (str_starts_with($href, '/')) {
            return home_url($href);
        }

        return home_url('/'.ltrim($href, '/'));
    }

    public static function normalize_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = rtrim($host, '/');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function host_matches_pattern(string $host, string $pattern): bool
    {
        $host = self::normalize_host($host);
        $pattern = self::normalize_host($pattern);
        if ($host === '' || $pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1);

            return $host === substr($pattern, 2) || str_ends_with($host, $suffix);
        }

        if (str_contains($pattern, '*')) {
            $escaped = preg_quote($pattern, '#');
            $escaped = str_replace('\*', '.*', $escaped);

            return preg_match('#^'.$escaped.'$#i', $host) === 1;
        }

        return $host === $pattern || str_ends_with($host, '.'.$pattern);
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @param  array<string, mixed>  $seo
     * @return list<array<string, mixed>>
     */
    private function build_keyword_candidates(array $links, array $seo): array
    {
        $anchorCounts = [];
        foreach ($links as $link) {
            $phrase = self::normalize_whitespace((string) ($link['anchor_text'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $key = self::normalize_phrase_key($phrase);
            if ($key === '') {
                continue;
            }
            if (! isset($anchorCounts[$key])) {
                $anchorCounts[$key] = [
                    'phrase' => $phrase,
                    'source' => 'anchor_text',
                    'occurrences' => 0,
                ];
            }
            $anchorCounts[$key]['occurrences']++;
        }

        $candidates = array_values($anchorCounts);

        $provider = is_array($seo['focus_keywords'] ?? null) ? $seo['focus_keywords'] : [];
        foreach ($provider as $phrase) {
            $phrase = self::normalize_whitespace((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $candidates[] = [
                'phrase' => $phrase,
                'source' => 'provider',
                'occurrences' => 1,
            ];
        }

        return $candidates;
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $keywordCandidates
     * @return array<string, int>
     */
    private function build_stats(string $html, array $links, array $keywordCandidates): array
    {
        $internal = 0;
        $external = 0;
        $wiki = 0;
        $unresolved = 0;
        foreach ($links as $link) {
            $type = (string) ($link['link_type'] ?? '');
            match ($type) {
                'internal' => $internal++,
                'external' => $external++,
                'wiki_trust' => $wiki++,
                'internal_unresolved' => $unresolved++,
                default => null,
            };
        }

        $anchorUnique = 0;
        $providerKeywords = 0;
        foreach ($keywordCandidates as $row) {
            if (($row['source'] ?? '') === 'anchor_text') {
                $anchorUnique++;
            } elseif (($row['source'] ?? '') === 'provider') {
                $providerKeywords++;
            }
        }

        return [
            'content_chars' => self::mb_strlen_safe($html),
            'links_total' => count($links),
            'internal_links' => $internal,
            'external_links' => $external,
            'wiki_trust_links' => $wiki,
            'internal_unresolved_links' => $unresolved,
            'unique_anchor_keywords' => $anchorUnique,
            'provider_keywords' => $providerKeywords,
        ];
    }

    /**
     * @return list<string>
     */
    private function split_provider_keywords(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = self::normalize_whitespace((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, list<array{id:int,name:string,slug:string}>>
     */
    private function light_taxonomies(int $postId): array
    {
        $taxonomies = get_object_taxonomies(get_post_type($postId) ?: 'post', 'names');
        if (! is_array($taxonomies)) {
            return [];
        }

        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            if (in_array($taxonomy, ['post_format'], true)) {
                continue;
            }
            $terms = get_the_terms($postId, $taxonomy);
            if (! is_array($terms) || $terms === []) {
                continue;
            }
            $rows = [];
            foreach ($terms as $term) {
                if (! $term instanceof \WP_Term) {
                    continue;
                }
                $rows[] = [
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                ];
            }
            if ($rows !== []) {
                $out[$taxonomy] = $rows;
            }
        }

        return $out;
    }

    private function collect_adjacent_text(\DOMElement $node, bool $before, int $limit): ?string
    {
        $chunks = [];
        $length = 0;
        $cursor = $before ? $node->previousSibling : $node->nextSibling;

        while ($cursor !== null && $length < $limit) {
            $text = self::normalize_whitespace($this->node_text_content($cursor));
            if ($text !== '') {
                if ($before) {
                    array_unshift($chunks, $text);
                } else {
                    $chunks[] = $text;
                }
                $length += self::mb_strlen_safe($text);
            }
            $cursor = $before ? $cursor->previousSibling : $cursor->nextSibling;
        }

        if ($chunks === []) {
            return null;
        }

        $combined = implode(' ', $chunks);
        if ($before) {
            if (self::mb_strlen_safe($combined) <= $limit) {
                return $combined !== '' ? $combined : null;
            }
            $slice = mb_substr($combined, -1 * $limit);

            return self::trim_word_start($slice);
        }

        $slice = mb_substr($combined, 0, $limit);

        return self::trim_word_end($slice);
    }

    private function node_text_content(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->textContent;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        return (string) $node->textContent;
    }

    /**
     * @param  callable|null  $targetResolver
     * @return list<array<string, mixed>>
     */
    private function regex_fallback_links(
        string $html,
        int $sourcePostId,
        string $contentHash,
        string $siteHost,
        bool $resolveTargets,
        $targetResolver,
    ): array {
        $links = [];
        if (! preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $hrefRaw = trim(html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($hrefRaw === '' || self::is_skippable_href($hrefRaw)) {
                continue;
            }
            $absolute = self::absolutize($hrefRaw);
            $anchor = self::normalize_whitespace(wp_strip_all_tags((string) ($match[2] ?? '')));
            $host = (string) (wp_parse_url($absolute, PHP_URL_HOST) ?? '');
            $sameDomain = $siteHost !== '' && $host !== '' && strcasecmp(self::normalize_host($host), self::normalize_host($siteHost)) === 0;

            $targetPostId = null;
            $targetTermId = null;
            $targetTaxonomy = null;
            $unresolvedReason = null;
            $linkType = 'external';
            $status = 'ok';
            $targetExternalUrl = null;

            if ($sameDomain) {
                $resolved = 0;
                if ($resolveTargets) {
                    $resolved = $targetResolver !== null
                        ? (int) $targetResolver($absolute, $hrefRaw)
                        : self::resolve_internal_post_id($absolute, $hrefRaw);
                }
                $linkType = $resolved > 0 ? 'internal' : 'internal_unresolved';
                $targetPostId = $resolved > 0 ? $resolved : null;
                $status = $resolved > 0 ? 'ok' : 'unresolved';
                if ($resolved <= 0) {
                    $path = (string) (wp_parse_url($absolute, PHP_URL_PATH) ?? '');
                    if ($path === '/' || $path === '') {
                        $unresolvedReason = 'front_page';
                    } elseif (str_contains($path, '/wp-content/uploads/')) {
                        $unresolvedReason = 'media_file';
                    } else {
                        $unresolvedReason = 'unknown';
                    }
                }
            } elseif (self::is_wiki_trust_host($host)) {
                $linkType = 'wiki_trust';
                $targetExternalUrl = $absolute;
            } else {
                $targetExternalUrl = $absolute;
            }

            $links[] = [
                'href_raw' => $hrefRaw,
                'url' => $absolute,
                'anchor_text' => $anchor,
                'link_type' => $linkType,
                'target_post_id' => $targetPostId,
                'target_term_id' => $targetTermId,
                'target_taxonomy' => $targetTaxonomy,
                'target_external_url' => $targetExternalUrl,
                'unresolved_reason' => $unresolvedReason,
                'context_before' => null,
                'context_after' => null,
                'rel' => null,
                'status' => $status,
                'content_hash' => $contentHash,
                'source_post_id' => $sourcePostId > 0 ? $sourcePostId : null,
            ];
        }

        return $links;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read_cache(int $postId): ?array
    {
        $raw = get_post_meta($postId, self::META_CACHE, true);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write_cache(int $postId, array $payload): void
    {
        // Derived metadata only — never store post_content.
        unset($payload['post_content'], $payload['body'], $payload['html']);
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }
        update_post_meta($postId, self::META_CACHE, $json);
    }

    public static function normalize_whitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function normalize_phrase_key(string $phrase): string
    {
        return mb_strtolower(self::normalize_whitespace($phrase));
    }

    private static function trim_word_start(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\s/u', $text) === 1) {
            $text = preg_replace('/^\S*\s+/u', '', $text) ?? $text;
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private static function trim_word_end(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\s/u', $text) === 1) {
            $text = preg_replace('/\s+\S*$/u', '', $text) ?? $text;
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private static function mb_strlen_safe(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
