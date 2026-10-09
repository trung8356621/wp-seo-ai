<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * [seo_ops_cta] renders managed CTA copy with a validated style preset.
 */
final class Cta_Shortcode
{
    public const OPTION_ALIASES = 'omi_seo_ops_cta_aliases';

    public static function register(): void
    {
        add_shortcode('seo_ops_cta', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * @param  array<string, string>|string  $attributes
     */
    public static function render(array|string $attributes = [], ?string $content = null): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $style = Cta_Style_Preset::canonicalStyle((string) ($attributes['style'] ?? 'soft'));
        $body = is_string($content) ? trim($content) : '';

        return Cta_Style_Preset::render($body, $style, self::destinations());
    }

    public static function enqueue(): void
    {
        wp_enqueue_style(
            'omi-seo-ops-cta',
            OMI_SEO_AI_BRIDGE_URL.'assets/css/seo-ops-cta.css',
            [],
            OMI_SEO_AI_BRIDGE_VERSION
        );
    }

    /**
     * @return array<string, string>
     */
    public static function destinations(): array
    {
        $stored = get_option(self::OPTION_ALIASES, []);
        $destinations = [];
        if (is_array($stored)) {
            foreach ($stored as $alias => $value) {
                if (is_string($alias) && is_string($value)) {
                    $destinations[strtolower($alias)] = $value;
                }
            }
        }
        $home = home_url('/');
        if (! isset($destinations['website']) && is_string($home) && $home !== '') {
            $destinations['website'] = $home;
        }
        $email = sanitize_email((string) get_option('admin_email'));
        if (! isset($destinations['email']) && $email !== '') {
            $destinations['email'] = 'mailto:'.$email;
        }
        $filtered = apply_filters('omi_seo_ops_cta_destinations', $destinations);

        return is_array($filtered) ? $filtered : $destinations;
    }
}
