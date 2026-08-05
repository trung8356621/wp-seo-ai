<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Aioseo_Provider_Adapter implements Seo_Provider_Adapter
{
    public function id(): string
    {
        return 'aioseo';
    }

    public function display_name(): string
    {
        return 'All in One SEO';
    }

    public function is_active(): bool
    {
        return defined('AIOSEO_VERSION')
            || class_exists('\\AIOSEO\\Plugin\\AIOSEO')
            || function_exists('aioseo');
    }

    public function version(): ?string
    {
        return defined('AIOSEO_VERSION') ? (string) AIOSEO_VERSION : null;
    }

    public function edition(): ?string
    {
        if (defined('AIOSEO_PRO') || class_exists('\\AIOSEO\\Plugin\\Pro\\AIOSEO')) {
            return 'pro';
        }

        return $this->is_active() ? 'lite' : null;
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

        return [
            'seo_metadata' => $base,
            'focus_keyword' => $base,
            'seo_score' => ['available' => false, 'provider' => null],
            'schema' => $base,
            'redirect' => ['available' => false, 'provider' => null],
            'http_404' => ['available' => false, 'provider' => null],
            'internal_link' => ['available' => true, 'provider' => 'omi_bridge'],
        ];
    }
}
