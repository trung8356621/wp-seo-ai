<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Compare installed vs GitHub latest; run native Plugin_Upgrader for remote install.
 */
final class Bridge_Update_Service
{
    public const OPERATION_KIND = 'plugin_update';

    private GitHub_Release_Client $github;

    /** @var callable(string, string): array<string, mixed>|null */
    private $upgrader;

    public function __construct(?GitHub_Release_Client $github = null, ?callable $upgrader = null)
    {
        $this->github = $github ?? new GitHub_Release_Client();
        $this->upgrader = $upgrader;
    }

    /**
     * @return array<string, mixed>
     */
    public function check(bool $force_refresh = false): array
    {
        $installed = $this->installed_version();
        $remote = $this->github->fetch_latest($force_refresh);
        if (($remote['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'code' => (string) ($remote['code'] ?? 'github_release_unavailable'),
                'message' => $this->human_check_error((string) ($remote['code'] ?? ''), $installed, (string) ($remote['message'] ?? '')),
                'installed_version' => $installed,
                'latest_version' => null,
                'update_available' => false,
                'release_url' => $remote['release_url'] ?? GitHub_Release_Client::RELEASES_HTML,
                'package_url' => null,
                'changelog' => '',
                'checked_at' => (string) ($remote['checked_at'] ?? gmdate('c')),
                'from_cache' => (bool) ($remote['from_cache'] ?? false),
            ];
        }

        $latest = (string) ($remote['version'] ?? '');
        $updateAvailable = $latest !== '' && version_compare($installed, $latest, '<');

        return [
            'ok' => true,
            'code' => null,
            'message' => '',
            'installed_version' => $installed,
            'latest_version' => $latest,
            'update_available' => $updateAvailable,
            'release_url' => (string) ($remote['release_url'] ?? ''),
            'package_url' => (string) ($remote['package_url'] ?? ''),
            'asset_name' => (string) ($remote['asset_name'] ?? ''),
            'changelog' => (string) ($remote['changelog'] ?? ''),
            'checked_at' => (string) ($remote['checked_at'] ?? gmdate('c')),
            'from_cache' => (bool) ($remote['from_cache'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function install(string $operationId): array
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return [
                'ok' => false,
                'code' => 'missing_operation_id',
                'message' => 'Thiếu operation_id.',
                'updated' => false,
            ];
        }

        $replay = Operation_Store::lookup_any($operationId);
        if (is_array($replay) && ($replay['kind'] ?? '') === self::OPERATION_KIND && is_array($replay['response'] ?? null)) {
            $response = $replay['response'];
            $response['replayed'] = true;

            return $response;
        }

        $previous = $this->installed_version();
        $check = $this->check(true);
        if (($check['ok'] ?? false) !== true) {
            return array_merge($check, [
                'previous_version' => $previous,
                'updated' => false,
                'replayed' => false,
            ]);
        }

        if (($check['update_available'] ?? false) !== true) {
            $response = [
                'ok' => true,
                'previous_version' => $previous,
                'installed_version' => (string) ($check['installed_version'] ?? $previous),
                'latest_version' => (string) ($check['latest_version'] ?? $previous),
                'updated' => false,
                'replayed' => false,
                'message' => '',
            ];
            Operation_Store::remember_any($operationId, [
                'kind' => self::OPERATION_KIND,
                'response' => $response,
            ]);

            return $response;
        }

        $packageUrl = trim((string) ($check['package_url'] ?? ''));
        $latest = (string) ($check['latest_version'] ?? '');
        if ($packageUrl === '' || $latest === '') {
            return [
                'ok' => false,
                'code' => 'github_asset_missing',
                'message' => 'Bản phát hành '.$latest.' không có gói cài đặt hợp lệ.',
                'previous_version' => $previous,
                'installed_version' => $previous,
                'updated' => false,
            ];
        }

        $upgrade = $this->run_upgrader($packageUrl, $latest);
        $installed = $this->installed_version();
        $active = $this->plugin_is_active();

        if (($upgrade['ok'] ?? false) !== true) {
            $response = [
                'ok' => false,
                'code' => (string) ($upgrade['code'] ?? 'upgrade_failed'),
                'message' => (string) ($upgrade['message'] ?? 'Cập nhật plugin thất bại.'),
                'previous_version' => $previous,
                'installed_version' => $installed,
                'updated' => false,
                'plugin_active' => $active,
                'replayed' => false,
            ];
            Operation_Store::remember_any($operationId, [
                'kind' => self::OPERATION_KIND,
                'response' => $response,
            ]);

            return $response;
        }

        if (! $active) {
            $this->reactivate_plugin();
            $active = $this->plugin_is_active();
        }

        if (version_compare($installed, $latest, '<')) {
            $installed = $latest;
        }
        $updated = version_compare($installed, $previous, '>');
        $response = [
            'ok' => $active,
            'code' => $active ? null : 'plugin_inactive',
            'message' => $active ? '' : 'Plugin không còn active sau khi cập nhật.',
            'previous_version' => $previous,
            'installed_version' => $installed,
            'latest_version' => $latest,
            'updated' => $updated,
            'plugin_active' => $active,
            'replayed' => false,
        ];
        Operation_Store::remember_any($operationId, [
            'kind' => self::OPERATION_KIND,
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Shape for WP plugins_api / update_plugins transient.
     *
     * @return array<string, mixed>|null
     */
    public function wordpress_update_metadata(): ?array
    {
        $check = $this->check(false);
        if (($check['ok'] ?? false) !== true) {
            return null;
        }

        $latest = (string) ($check['latest_version'] ?? '');
        $changelog = (string) ($check['changelog'] ?? '');

        return [
            'name' => 'TVH SEO AI Bridge',
            'version' => $latest,
            'download_url' => (string) ($check['package_url'] ?? ''),
            'author' => 'TVH',
            'author_profile' => (string) ($check['release_url'] ?? GitHub_Release_Client::RELEASES_HTML),
            'requires' => '6.0',
            'tested' => '',
            'requires_php' => '8.1',
            'last_updated' => (string) ($check['checked_at'] ?? ''),
            'sections' => [
                'description' => 'WordPress bridge for Omnichannel SEO AI.',
                'installation' => '',
                'changelog' => $changelog,
            ],
        ];
    }

    public function installed_version(): string
    {
        $header = '';
        $file = $this->plugin_file();
        if ($file !== '' && function_exists('get_plugin_data')) {
            $data = get_plugin_data($file, false, false);
            $header = trim((string) ($data['Version'] ?? ''));
        }

        if ($header !== '') {
            return $header;
        }

        return defined('OMI_SEO_AI_BRIDGE_VERSION')
            ? trim((string) OMI_SEO_AI_BRIDGE_VERSION)
            : '0.0.0';
    }

    /**
     * @return array<string, mixed>
     */
    private function run_upgrader(string $packageUrl, string $newVersion): array
    {
        if ($this->upgrader !== null) {
            return ($this->upgrader)($packageUrl, $newVersion);
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        if (! class_exists(\Plugin_Upgrader::class)) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/misc.php';
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        }

        $basename = $this->plugin_basename();
        $this->inject_update_transient($packageUrl, $newVersion, $basename);

        $skin = class_exists(\Automatic_Upgrader_Skin::class)
            ? new \Automatic_Upgrader_Skin()
            : new \WP_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($basename);

        if ($result === true) {
            return ['ok' => true, 'message' => ''];
        }

        $message = 'Cập nhật plugin thất bại.';
        if (is_wp_error($result)) {
            $message = $result->get_error_message() !== ''
                ? 'Cập nhật plugin thất bại.'
                : $message;
        }

        return [
            'ok' => false,
            'code' => 'upgrade_failed',
            'message' => $message,
        ];
    }

    private function inject_update_transient(string $packageUrl, string $newVersion, string $basename): void
    {
        $current = function_exists('get_site_transient') ? get_site_transient('update_plugins') : false;
        if (! is_object($current)) {
            $current = new \stdClass();
        }
        if (! isset($current->response) || ! is_array($current->response)) {
            $current->response = [];
        }

        $item = new \stdClass();
        $item->slug = $this->plugin_slug();
        $item->plugin = $basename;
        $item->new_version = $newVersion;
        $item->package = $packageUrl;
        $item->url = GitHub_Release_Client::RELEASES_HTML;
        $current->response[$basename] = $item;

        if (function_exists('set_site_transient')) {
            set_site_transient('update_plugins', $current);
        }
    }

    private function plugin_file(): string
    {
        if (defined('OMI_SEO_AI_BRIDGE_PATH')) {
            return OMI_SEO_AI_BRIDGE_PATH.'omi-seo-ai-bridge.php';
        }

        return '';
    }

    private function plugin_basename(): string
    {
        if (defined('OMI_SEO_AI_BRIDGE_BASENAME')) {
            return (string) OMI_SEO_AI_BRIDGE_BASENAME;
        }

        return 'wp-seo-ai/omi-seo-ai-bridge.php';
    }

    private function plugin_slug(): string
    {
        $dir = dirname($this->plugin_basename());

        return ($dir === '.' || $dir === '\\') ? 'wp-seo-ai' : $dir;
    }

    private function plugin_is_active(): bool
    {
        if (! function_exists('is_plugin_active')) {
            return true;
        }

        return is_plugin_active($this->plugin_basename());
    }

    private function reactivate_plugin(): void
    {
        if (function_exists('activate_plugin')) {
            activate_plugin($this->plugin_basename(), '', false, true);
        }
    }

    private function human_check_error(string $code, string $installed, string $fallback): string
    {
        return match ($code) {
            'github_asset_missing' => $fallback !== '' ? $fallback : 'Bản phát hành không có gói cài đặt hợp lệ.',
            'github_invalid_tag' => $fallback !== '' ? $fallback : 'Bản phát hành GitHub không có phiên bản hợp lệ.',
            default => 'Không thể kiểm tra phiên bản mới.'.($installed !== '' ? ' Phiên bản đang cài: '.$installed : ''),
        };
    }
}
