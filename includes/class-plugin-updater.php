<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kiểm tra và cài đặt bản cập nhật từ Laravel Update Server.
 */
final class Plugin_Updater
{
    private const UPDATE_PATH = '/api/seo/plugin/update-check';

    private string $plugin_file;

    private string $plugin_basename;

    private string $plugin_slug;

    private string $current_version;

    private string $update_url;

    private function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $slugDir = dirname($this->plugin_basename);
        $this->plugin_slug = ($slugDir === '.' || $slugDir === '\\')
            ? basename($this->plugin_basename, '.php')
            : $slugDir;
        $this->current_version = $this->resolve_installed_version();
        $this->update_url = $this->resolve_update_check_url();
    }

    public static function boot(string $plugin_file): void
    {
        $updater = new self($plugin_file);
        if ($updater->update_url === '') {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$updater, 'check_for_update']);
        add_filter('plugins_api', [$updater, 'get_plugin_info'], 20, 3);
    }

    /**
     * @param  object|false  $transient
     * @return object|false
     */
    public function check_for_update($transient)
    {
        if (! is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->fetch_remote_metadata();
        if ($remote === null) {
            return $transient;
        }

        $new_version = (string) ($remote['version'] ?? '');
        if ($new_version === '' || version_compare($this->current_version, $new_version, '>=')) {
            return $transient;
        }

        $package = (string) ($remote['download_url'] ?? '');
        if ($package === '') {
            return $transient;
        }

        $update = new \stdClass();
        $update->slug = $this->plugin_slug;
        $update->plugin = $this->plugin_basename;
        $update->new_version = $new_version;
        $update->url = (string) ($remote['author_profile'] ?? '');
        $update->package = $package;
        $update->tested = (string) ($remote['tested'] ?? '');
        $update->requires = (string) ($remote['requires'] ?? '');
        $update->requires_php = (string) ($remote['requires_php'] ?? '');

        $transient->response[$this->plugin_basename] = $update;

        return $transient;
    }

    /**
     * @param  false|object|array  $res
     * @param  string  $action
     * @param  object  $args
     * @return false|object|array
     */
    public function get_plugin_info($res, string $action, $args)
    {
        if ($action !== 'plugin_information' || ! is_object($args)) {
            return $res;
        }

        $slug = isset($args->slug) ? (string) $args->slug : '';
        if ($slug !== $this->plugin_slug) {
            return $res;
        }

        $remote = $this->fetch_remote_metadata();
        if ($remote === null) {
            return $res;
        }

        $info = new \stdClass();
        $info->name = (string) ($remote['name'] ?? $this->plugin_slug);
        $info->slug = $this->plugin_slug;
        $info->version = (string) ($remote['version'] ?? $this->current_version);
        $info->author = (string) ($remote['author'] ?? '');
        $info->author_profile = (string) ($remote['author_profile'] ?? '');
        $info->requires = (string) ($remote['requires'] ?? '');
        $info->tested = (string) ($remote['tested'] ?? '');
        $info->requires_php = (string) ($remote['requires_php'] ?? '');
        $info->last_updated = (string) ($remote['last_updated'] ?? '');
        $info->download_link = (string) ($remote['download_url'] ?? '');
        $info->homepage = $info->author_profile;

        $sections = is_array($remote['sections'] ?? null) ? $remote['sections'] : [];
        $info->sections = [
            'description' => (string) ($sections['description'] ?? ''),
            'installation' => (string) ($sections['installation'] ?? ''),
            'changelog' => (string) ($sections['changelog'] ?? ''),
        ];

        $banners = is_array($remote['banners'] ?? null) ? $remote['banners'] : [];
        if ($banners !== []) {
            $info->banners = [
                'low' => (string) ($banners['low'] ?? ''),
                'high' => (string) ($banners['high'] ?? ''),
            ];
        }

        return $info;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch_remote_metadata(): ?array
    {
        $response = wp_remote_get($this->update_url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    private function resolve_installed_version(): string
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data($this->plugin_file, false, false);
        $headerVersion = trim((string) ($data['Version'] ?? ''));

        if ($headerVersion !== '') {
            return $headerVersion;
        }

        return defined('OMI_SEO_AI_BRIDGE_VERSION')
            ? (string) OMI_SEO_AI_BRIDGE_VERSION
            : '0.0.0';
    }

    private function resolve_update_check_url(): string
    {
        $base = '';
        if (function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            $base = omi_seo_ai_bridge_laravel_api_url();
        }

        if ($base === '') {
            $base = (string) apply_filters('omi_seo_ai_bridge_update_api_base', '');
            $base = rtrim($base, '/');
        }

        if ($base === '') {
            return '';
        }

        $url = $base . self::UPDATE_PATH;

        return (string) apply_filters('omi_seo_ai_bridge_update_check_url', $url);
    }
}
