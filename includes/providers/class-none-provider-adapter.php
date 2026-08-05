<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class None_Provider_Adapter implements Seo_Provider_Adapter
{
    public function id(): string
    {
        return 'none';
    }

    public function display_name(): string
    {
        return 'No SEO plugin';
    }

    public function is_active(): bool
    {
        return true;
    }

    public function version(): ?string
    {
        return null;
    }

    public function edition(): ?string
    {
        return null;
    }

    public function capabilities(): array
    {
        return [
            'seo_metadata' => ['available' => false, 'provider' => null],
            'focus_keyword' => ['available' => false, 'provider' => null],
            'seo_score' => ['available' => false, 'provider' => null],
            'schema' => ['available' => class_exists(Schema_Ld_Exporter::class), 'provider' => 'omi_bridge'],
            'redirect' => ['available' => false, 'provider' => null],
            'http_404' => ['available' => false, 'provider' => null],
            'internal_link' => ['available' => true, 'provider' => 'omi_bridge'],
            'contact_discovery' => ['available' => true, 'provider' => 'omi_bridge'],
            'taxonomy' => ['available' => true, 'provider' => 'wordpress'],
            'permalink' => ['available' => true, 'provider' => 'wordpress'],
        ];
    }
}
