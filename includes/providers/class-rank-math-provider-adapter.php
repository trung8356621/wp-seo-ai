<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Rank_Math_Provider_Adapter implements Seo_Provider_Adapter
{
    public function id(): string
    {
        return 'rank_math';
    }

    public function display_name(): string
    {
        return 'Rank Math';
    }

    public function is_active(): bool
    {
        return Seo_Plugin_Resolver::is_rank_math_active();
    }

    public function version(): ?string
    {
        $info = Seo_Plugin_Resolver::site_info();
        $v = (string) ($info['rank_math']['version'] ?? '');

        return $v !== '' ? $v : null;
    }

    public function edition(): ?string
    {
        if (defined('RANK_MATH_PRO_FILE') || class_exists('\\RankMathPro\\Raven')) {
            return 'pro';
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

        return [
            'seo_metadata' => $base,
            'focus_keyword' => $base,
            'seo_score' => array_merge($base, ['score_kind' => 'plugin_assessment']),
            'schema' => $base,
            'redirect' => ['available' => class_exists('\\RankMath\\Redirections\\Redirection'), 'provider' => $this->id()],
            'http_404' => ['available' => class_exists('\\RankMath\\Analytics\\DB') || class_exists('\\RankMath\\Monitor\\Monitor'), 'provider' => $this->id()],
            'internal_link' => ['available' => true, 'provider' => 'omi_bridge'],
        ];
    }
}
