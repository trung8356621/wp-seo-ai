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

    private string $update_url;

    private function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $slugDir = dirname($this->plugin_basename);
        $this->plugin_slug = ($slugDir === '.' || $slugDir === '\\')
            ? basename($this->plugin_basename, '.php')
            : $slugDir;
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
        add_action('upgrader_process_complete', [$updater, 'clear_update_cache_after_upgrade'], 10, 2);
    }

    /**
     * @param  object|false  $transient
     * @return object|false
     */
    public function check_for_update($transient)
    {
        if (! is_object($transient) || empty($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        if (! array_key_exists($this->plugin_basename, $transient->checked)) {
            return $transient;
        }

        $remote = $this->fetch_remote_metadata();
        if ($remote === null) {
            return $transient;
        }

        $new_version = $this->normalize_version((string) ($remote['version'] ?? ''));
        $installed_version = $this->resolve_installed_version_for_check($transient);

        if ($new_version === '' || version_compare($installed_version, $new_version, '>=')) {
            unset($transient->response[$this->plugin_basename]);
            $this->mark_no_update($transient, $remote, $installed_version, $new_version);

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
        unset($transient->no_update[$this->plugin_basename]);

        return $transient;
    }

    /**
     * @param  object  $transient
     * @param  array<string, mixed>  $remote
     */
    private function mark_no_update(object $transient, array $remote, string $installed_version, string $new_version): void
    {
        if (! isset($transient->no_update) || ! is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $item = new \stdClass();
        $item->slug = $this->plugin_slug;
        $item->plugin = $this->plugin_basename;
        $item->new_version = $new_version !== '' ? $new_version : $installed_version;
        $item->url = (string) ($remote['author_profile'] ?? '');
        $item->package = (string) ($remote['download_url'] ?? '');
        $item->id = $this->plugin_basename;
        $item->tested = (string) ($remote['tested'] ?? '');
        $item->requires = (string) ($remote['requires'] ?? '');
        $item->requires_php = (string) ($remote['requires_php'] ?? '');

        $transient->no_update[$this->plugin_basename] = $item;
    }

    /**
     * @param  object  $transient
     */
    private function resolve_installed_version_for_check(object $transient): string
    {
        $checked = $this->normalize_version((string) ($transient->checked[$this->plugin_basename] ?? ''));
        $header = $this->resolve_installed_version();

        if ($checked === '') {
            return $header;
        }

        if ($header === '') {
            return $checked;
        }

        return version_compare($checked, $header, '>') ? $checked : $header;
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
        $info->version = (string) ($remote['version'] ?? $this->resolve_installed_version());
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
     * @param  mixed  $upgrader
     * @param  array<string, mixed>  $hook_extra
     */
    public function clear_update_cache_after_upgrade($upgrader, array $hook_extra): void
    {
        unset($upgrader);

        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? null;
        if (is_array($plugins) && in_array($this->plugin_basename, $plugins, true)) {
            wp_clean_plugins_cache(true);
            delete_site_transient('update_plugins');

            return;
        }

        $plugin = $hook_extra['plugin'] ?? null;
        if (is_string($plugin) && $plugin === $this->plugin_basename) {
            wp_clean_plugins_cache(true);
            delete_site_transient('update_plugins');
        }
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
        $headerVersion = $this->normalize_version((string) ($data['Version'] ?? ''));

        if ($headerVersion !== '') {
            return $headerVersion;
        }

        return defined('OMI_SEO_AI_BRIDGE_VERSION')
            ? $this->normalize_version((string) OMI_SEO_AI_BRIDGE_VERSION)
            : '0.0.0';
    }

    private function normalize_version(string $version): string
    {
        return trim($version);
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
