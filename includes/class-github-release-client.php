<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Public GitHub Releases client. No PAT. Successful responses cached 8h unless force_refresh.
 * Failed / invalid package responses are never long-cached.
 */
final class GitHub_Release_Client
{
    public const API_LATEST = 'https://api.github.com/repos/trung8356621/wp-seo-ai/releases/latest';

    public const RELEASES_HTML = 'https://github.com/trung8356621/wp-seo-ai/releases';

    public const ASSET_SLUG = 'wp-seo-ai';

    public const LEGACY_ASSET_SLUG = 'omi-seo-ai-bridge';

    public const PLUGIN_FOLDER = 'wp-seo-ai';

    public const MAIN_PLUGIN_FILE = 'omi-seo-ai-bridge.php';

    public const TRANSIENT_KEY = 'omi_seo_github_latest_release';

    public const CACHE_TTL = 28800;

    /** @var callable(string): array{ok:bool,status:int,body:string,error?:string} */
    private $http;

    /** @var callable(string): mixed */
    private $cacheGet;

    /** @var callable(string, mixed, int): void */
    private $cacheSet;

    /** @var callable(string): void */
    private $cacheDelete;

    /**
     * @param  callable(string): array{ok:bool,status:int,body:string,error?:string}|null  $http
     * @param  callable(string): mixed|null  $cacheGet
     * @param  callable(string, mixed, int): void|null  $cacheSet
     * @param  callable(string): void|null  $cacheDelete
     */
    public function __construct(
        ?callable $http = null,
        ?callable $cacheGet = null,
        ?callable $cacheSet = null,
        ?callable $cacheDelete = null,
    ) {
        $this->http = $http ?? [$this, 'wp_http_get'];
        $this->cacheGet = $cacheGet ?? static function (string $key): mixed {
            return function_exists('get_transient') ? get_transient($key) : false;
        };
        $this->cacheSet = $cacheSet ?? static function (string $key, mixed $value, int $ttl): void {
            if (function_exists('set_transient')) {
                set_transient($key, $value, $ttl);
            }
        };
        $this->cacheDelete = $cacheDelete ?? static function (string $key): void {
            if (function_exists('delete_transient')) {
                delete_transient($key);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch_latest(bool $force_refresh = false): array
    {
        if ($force_refresh) {
            ($this->cacheDelete)(self::TRANSIENT_KEY);
        } else {
            $cached = ($this->cacheGet)(self::TRANSIENT_KEY);
            // Only reuse successful package resolutions — never cache invalid assets for 8h.
            if (is_array($cached) && ($cached['ok'] ?? false) === true && trim((string) ($cached['package_url'] ?? '')) !== '') {
                $cached['from_cache'] = true;

                return $cached;
            }
            if (is_array($cached) && ($cached['ok'] ?? false) !== true) {
                ($this->cacheDelete)(self::TRANSIENT_KEY);
            }
        }

        $result = $this->request_latest();
        if (($result['ok'] ?? false) === true && trim((string) ($result['package_url'] ?? '')) !== '') {
            ($this->cacheSet)(self::TRANSIENT_KEY, $result, self::CACHE_TTL);
        }

        $result['from_cache'] = false;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function request_latest(): array
    {
        try {
            $response = ($this->http)(self::API_LATEST);
        } catch (\Throwable $e) {
            unset($e);

            return $this->fail('github_release_unavailable', 'Không thể kiểm tra phiên bản mới.');
        }

        if (! is_array($response)) {
            return $this->fail('github_release_unavailable', 'Không thể kiểm tra phiên bản mới.');
        }

        if (($response['ok'] ?? false) !== true) {
            $status = (int) ($response['status'] ?? 0);
            if ($status === 403 || $status === 429) {
                return $this->fail('github_rate_limited', 'Không thể kiểm tra phiên bản mới.');
            }

            return $this->fail('github_release_unavailable', 'Không thể kiểm tra phiên bản mới.');
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status === 403 || $status === 429) {
            return $this->fail('github_rate_limited', 'Không thể kiểm tra phiên bản mới.');
        }
        if ($status < 200 || $status >= 300) {
            return $this->fail('github_release_unavailable', 'Không thể kiểm tra phiên bản mới.');
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (! is_array($decoded)) {
            return $this->fail('github_release_unavailable', 'Không thể kiểm tra phiên bản mới.');
        }

        return $this->normalize_release($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize_release(array $payload): array
    {
        $tag = trim((string) ($payload['tag_name'] ?? ''));
        $version = $this->parse_version($tag);
        if ($version === null) {
            return $this->fail(
                'github_invalid_tag',
                'Bản phát hành GitHub không có phiên bản hợp lệ.',
                [
                    'release_version' => null,
                    'tag' => $tag !== '' ? $tag : null,
                    'expected_asset' => null,
                    'found_assets' => self::asset_names_from_payload($payload),
                ],
            );
        }

        $expected = self::expected_asset_name($version);
        $foundAssets = self::asset_names_from_payload($payload);
        $asset = $this->find_package_asset($payload, $version);
        if ($asset === null) {
            return $this->fail(
                'github_asset_missing',
                'Bản phát hành '.$version.' không có gói cài đặt hợp lệ.',
                [
                    'release_version' => $version,
                    'tag' => $tag !== '' ? $tag : 'v'.$version,
                    'expected_asset' => $expected,
                    'found_assets' => $foundAssets,
                ],
            );
        }

        $packageUrl = trim((string) ($asset['browser_download_url'] ?? ''));
        if ($packageUrl === '' || $this->is_source_archive_url($packageUrl)) {
            return $this->fail(
                'github_asset_missing',
                'Bản phát hành '.$version.' không có gói cài đặt hợp lệ.',
                [
                    'release_version' => $version,
                    'tag' => $tag !== '' ? $tag : 'v'.$version,
                    'expected_asset' => $expected,
                    'found_assets' => $foundAssets,
                    'rejected_url' => $packageUrl !== '' ? $packageUrl : null,
                ],
            );
        }

        $size = (int) ($asset['size'] ?? 0);
        if ($size <= 0) {
            return $this->fail(
                'github_asset_missing',
                'Bản phát hành '.$version.' không có gói cài đặt hợp lệ.',
                [
                    'release_version' => $version,
                    'tag' => $tag !== '' ? $tag : 'v'.$version,
                    'expected_asset' => $expected,
                    'found_assets' => $foundAssets,
                    'asset_size' => $size,
                ],
            );
        }

        return [
            'ok' => true,
            'code' => null,
            'message' => '',
            'version' => $version,
            'release_version' => $version,
            'tag' => $tag !== '' ? $tag : 'v'.$version,
            'release_url' => (string) ($payload['html_url'] ?? self::RELEASES_HTML),
            'package_url' => $packageUrl,
            'asset_name' => (string) ($asset['name'] ?? $expected),
            'expected_asset' => $expected,
            'found_assets' => $foundAssets,
            'changelog' => trim((string) ($payload['body'] ?? '')),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'checked_at' => gmdate('c'),
        ];
    }

    public static function expected_asset_name(string $version): string
    {
        return self::ASSET_SLUG.'-'.$version.'.zip';
    }

    public static function legacy_asset_name(string $version): string
    {
        return self::LEGACY_ASSET_SLUG.'-'.$version.'.zip';
    }

    /**
     * @return list<string>
     */
    public static function accepted_asset_names(string $version): array
    {
        return [
            self::expected_asset_name($version),
            self::legacy_asset_name($version),
        ];
    }

    public function parse_version(string $tag): ?string
    {
        $version = ltrim(trim($tag), 'vV');
        if ($version === '' || preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            return null;
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function asset_names_from_payload(array $payload): array
    {
        $assets = $payload['assets'] ?? [];
        if (! is_array($assets)) {
            return [];
        }

        $names = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = trim((string) ($asset['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function find_package_asset(array $payload, string $version): ?array
    {
        $assets = $payload['assets'] ?? [];
        if (! is_array($assets)) {
            return null;
        }

        $wanted = array_map('strtolower', self::accepted_asset_names($version));
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = strtolower(trim((string) ($asset['name'] ?? '')));
            // Reject unversioned wp-seo-ai.zip and other non-matching names.
            if (in_array($name, $wanted, true)) {
                return $asset;
            }
        }

        return null;
    }

    private function is_source_archive_url(string $url): bool
    {
        return str_contains($url, '/archive/') || str_contains($url, '/zipball/') || str_contains($url, '/tarball/');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fail(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'version' => $extra['release_version'] ?? null,
            'release_version' => $extra['release_version'] ?? null,
            'package_url' => null,
            'expected_asset' => $extra['expected_asset'] ?? null,
            'found_assets' => $extra['found_assets'] ?? [],
            'release_url' => self::RELEASES_HTML,
            'checked_at' => gmdate('c'),
        ], $extra);
    }

    /**
     * @return array{ok:bool,status:int,body:string,error?:string}
     */
    private function wp_http_get(string $url): array
    {
        if (! function_exists('wp_remote_get')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'http_unavailable'];
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'wp-seo-ai',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'timeout',
            ];
        }

        return [
            'ok' => true,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
