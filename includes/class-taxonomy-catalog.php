<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress-owned taxonomy catalog cache (selector/hierarchy source of truth).
 *
 * Not article data. Independent JSON files per supported taxonomy.
 */
final class Taxonomy_Catalog
{
    public const SCHEMA = 'taxonomy_catalog.v1';

    /** @var list<string> */
    public const SUPPORTED = ['category', 'post_tag', 'product_cat', 'product_tag'];

    /** @var list<string> */
    public const HIERARCHICAL = ['category', 'product_cat'];

    /** @var string|null */
    private static ?string $directoryOverride = null;

    /** @var callable|null */
    private static $termsProvider = null;

    /** @var array<string, int> */
    private static array $rebuildCounts = [];

    public static function register(): void
    {
        add_action('created_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('edited_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('delete_term', [self::class, 'on_term_changed'], 20, 3);
    }

    public static function reset_for_tests(): void
    {
        self::$directoryOverride = null;
        self::$termsProvider = null;
        self::$rebuildCounts = [];
    }

    public static function set_directory_override(?string $directory): void
    {
        self::$directoryOverride = $directory;
    }

    public static function set_terms_provider(?callable $provider): void
    {
        self::$termsProvider = $provider;
    }

    public static function rebuild_count(string $taxonomy): int
    {
        return self::$rebuildCounts[$taxonomy] ?? 0;
    }

    public static function is_supported(string $taxonomy): bool
    {
        return in_array($taxonomy, self::SUPPORTED, true);
    }

    public static function is_hierarchical(string $taxonomy): bool
    {
        return in_array($taxonomy, self::HIERARCHICAL, true);
    }

    /**
     * @param  mixed  $termId
     * @param  mixed  $ttId
     */
    public static function on_term_changed($termId, $ttId, $taxonomy = ''): void
    {
        unset($termId, $ttId);
        $taxonomy = sanitize_key((string) $taxonomy);
        if (! self::is_supported($taxonomy)) {
            return;
        }

        self::rebuild($taxonomy);
    }

    /**
     * @return list<array{id: int, name: string, parent: int}>
     */
    public static function read(string $taxonomy): array
    {
        $taxonomy = sanitize_key($taxonomy);
        if (! self::is_supported($taxonomy)) {
            return [];
        }

        $path = self::file_path($taxonomy);
        if (! is_file($path)) {
            self::rebuild($taxonomy);
        }

        $decoded = self::read_file($path);
        if ($decoded === null) {
            self::rebuild($taxonomy);
            $decoded = self::read_file($path);
        }

        return $decoded ?? [];
    }

    public static function rebuild(string $taxonomy): bool
    {
        $taxonomy = sanitize_key($taxonomy);
        if (! self::is_supported($taxonomy)) {
            return false;
        }

        self::$rebuildCounts[$taxonomy] = (self::$rebuildCounts[$taxonomy] ?? 0) + 1;

        $items = self::collect_items($taxonomy);
        $payload = json_encode(
            [
                'schema' => self::SCHEMA,
                'taxonomy' => $taxonomy,
                'items' => $items,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (! is_string($payload)) {
            return false;
        }

        return self::atomic_write(self::file_path($taxonomy), $payload);
    }

    /**
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public static function rest_payload(string $taxonomy): array
    {
        $taxonomy = sanitize_key($taxonomy);
        if (! self::is_supported($taxonomy)) {
            return [
                'ok' => false,
                'status' => 400,
                'body' => [
                    'success' => false,
                    'message' => 'Unsupported taxonomy.',
                    'taxonomy' => $taxonomy,
                ],
            ];
        }

        $items = self::read($taxonomy);

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'schema' => self::SCHEMA,
                'taxonomy' => $taxonomy,
                'items' => $items,
            ],
        ];
    }

    /**
     * @return list<array{id: int, name: string, parent: int}>
     */
    private static function collect_items(string $taxonomy): array
    {
        $raw = self::fetch_terms($taxonomy);
        $hierarchical = self::is_hierarchical($taxonomy);
        $items = [];

        foreach ($raw as $term) {
            $normalized = self::normalize_term($term, $hierarchical);
            if ($normalized === null) {
                continue;
            }
            $items[] = $normalized;
        }

        usort(
            $items,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
        );

        return $items;
    }

    /**
     * @return list<mixed>
     */
    private static function fetch_terms(string $taxonomy): array
    {
        if (is_callable(self::$termsProvider)) {
            $provided = (self::$termsProvider)($taxonomy);

            return is_array($provided) ? array_values($provided) : [];
        }

        if (! function_exists('taxonomy_exists') || ! taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 0,
        ]);

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        return array_values($terms);
    }

    /**
     * @param  mixed  $term
     * @return array{id: int, name: string, parent: int}|null
     */
    private static function normalize_term($term, bool $hierarchical): ?array
    {
        if (is_object($term)) {
            $id = (int) ($term->term_id ?? $term->id ?? 0);
            $name = trim((string) ($term->name ?? ''));
            $parent = (int) ($term->parent ?? 0);
        } elseif (is_array($term)) {
            $id = (int) ($term['term_id'] ?? $term['id'] ?? 0);
            $name = trim((string) ($term['name'] ?? ''));
            $parent = (int) ($term['parent'] ?? 0);
        } else {
            return null;
        }

        if ($id <= 0 || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'parent' => $hierarchical ? max(0, $parent) : 0,
        ];
    }

    /**
     * @return list<array{id: int, name: string, parent: int}>|null
     */
    private static function read_file(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach ($decoded['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = self::normalize_term($row, true);
            if ($normalized === null) {
                continue;
            }
            $items[] = $normalized;
        }

        return $items;
    }

    private static function atomic_write(string $path, string $payload): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! self::ensure_directory($directory)) {
            return false;
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $payload);
        fflush($handle);
        fclose($handle);

        if ($written === false || $written < strlen($payload)) {
            @unlink($tmp);

            return false;
        }

        if (@rename($tmp, $path)) {
            return true;
        }

        if (is_file($path)) {
            @unlink($path);
        }

        $moved = @rename($tmp, $path);
        if (! $moved) {
            @unlink($tmp);
        }

        return $moved;
    }

    private static function ensure_directory(string $directory): bool
    {
        if (function_exists('wp_mkdir_p')) {
            return (bool) wp_mkdir_p($directory);
        }

        return is_dir($directory) || mkdir($directory, 0755, true);
    }

    public static function file_path(string $taxonomy): string
    {
        return self::directory().DIRECTORY_SEPARATOR.$taxonomy.'.json';
    }

    private static function directory(): string
    {
        if (is_string(self::$directoryOverride) && self::$directoryOverride !== '') {
            return rtrim(self::$directoryOverride, '/\\');
        }

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
        if ($base === '') {
            $base = sys_get_temp_dir();
        }

        return rtrim($base, '/\\').DIRECTORY_SEPARATOR.'omi-seo-ai'.DIRECTORY_SEPARATOR.'taxonomy-catalog';
    }
}
