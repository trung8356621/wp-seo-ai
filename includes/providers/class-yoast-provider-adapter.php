<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Yoast_Provider_Adapter implements Seo_Provider_Adapter
{
    public function id(): string
    {
        return 'yoast';
    }

    public function display_name(): string
    {
        return 'Yoast SEO';
    }

    public function is_active(): bool
    {
        return Seo_Plugin_Resolver::is_yoast_active();
    }

    public function version(): ?string
    {
        $info = Seo_Plugin_Resolver::site_info();
        $v = (string) ($info['yoast']['version'] ?? '');

        return $v !== '' ? $v : null;
    }

    public function edition(): ?string
    {
        if (defined('WPSEO_PREMIUM_FILE') || class_exists('\\WPSEO_Premium')) {
            return 'premium';
        }

        return $this->is_active() ? 'free' : null;
    }

    public function capabilities(): array
    {
        if (! $this->is_active()) {
            return [];
        }
        $v = $this->version();
        $base = [
            'available' => true,
            'provider' => $this->id(),
            'provider_version' => $v,
        ];

        // Do not hardcode "Yoast always missing 404" — detect classes when present.
        $has404 = class_exists('\\WPSEO_Redirect_Option') || class_exists('\\Yoast\\WP\\SEO\\Premium');

        return [
            'seo_metadata' => $base,
            'focus_keyword' => $base,
            'seo_score' => array_merge($base, ['score_kind' => 'plugin_assessment']),
            'schema' => $base,
            'redirect' => ['available' => $has404, 'provider' => $has404 ? $this->id() : null],
            'http_404' => ['available' => false, 'provider' => null],
            'internal_link' => ['available' => true, 'provider' => 'omi_bridge'],
        ];
    }
}
