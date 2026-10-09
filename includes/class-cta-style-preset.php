<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validated CTA presentation presets. No arbitrary CSS, script, or HTML.
 */
final class Cta_Style_Preset
{
    /** @var list<string> */
    public const ALIASES = ['zalo', 'facebook', 'email', 'phone', 'address', 'website', 'products'];

    /**
     * @return array<string, mixed>
     */
    public static function library(): array
    {
        $path = dirname(__DIR__).'/assets/cta/presets.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['schema_version' => 1, 'presets' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function preset(string $style): ?array
    {
        $library = self::library();
        $presets = is_array($library['presets'] ?? null) ? $library['presets'] : [];
        $style = strtolower(trim($style));
        if (! isset($presets[$style]) || ! is_array($presets[$style])) {
            return isset($presets['soft']) && is_array($presets['soft']) ? $presets['soft'] : null;
        }
        $errors = self::validate($presets[$style]);

        return $errors === [] ? $presets[$style] : (is_array($presets['soft'] ?? null) ? $presets['soft'] : null);
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return list<string>
     */
    public static function validate(array $preset): array
    {
        $errors = [];
        $enums = [
            'layout' => ['stack', 'inline'],
            'theme_color' => ['primary', 'secondary', 'accent'],
            'background' => ['surface', 'tint', 'transparent'],
            'text' => ['text', 'on_primary'],
            'border' => ['none', 'subtle', 'strong'],
            'icon' => ['none', 'arrow', 'chat'],
        ];
        foreach ($enums as $key => $allowed) {
            if (! in_array((string) ($preset[$key] ?? ''), $allowed, true)) {
                $errors[] = $key;
            }
        }
        foreach (['spacing' => [8, 32], 'radius' => [0, 24]] as $key => $range) {
            $value = $preset[$key] ?? null;
            if (! is_int($value) || $value < $range[0] || $value > $range[1]) {
                $errors[] = $key;
            }
        }
        foreach ($preset as $key => $value) {
            if (is_string($value) && preg_match('/url\s*\(|expression\s*\(|javascript:|<script/i', $value) === 1) {
                $errors[] = 'unsafe_'.$key;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $destinations
     */
    public static function render(string $content, string $style, array $destinations): string
    {
        $preset = self::preset($style);
        if ($preset === null) {
            return '';
        }
        $resolvedStyle = self::canonicalStyle($style);
        $content = self::stripNested($content);
        $html = self::resolveAliases($content, $destinations);
        $icon = (string) ($preset['icon'] ?? 'none');
        $iconHtml = $icon === 'none' ? '' : '<span class="seo-ops-cta__icon" aria-hidden="true">'.($icon === 'chat' ? '●' : '→').'</span>';

        return '<aside class="seo-ops-cta seo-ops-cta--'.esc_attr_fallback($resolvedStyle).' seo-ops-cta--'.esc_attr_fallback((string) $preset['layout']).'" style="'.esc_attr_fallback(self::css($preset)).'" data-cta-style="'.esc_attr_fallback($resolvedStyle).'">'
            .$iconHtml
            .'<div class="seo-ops-cta__body">'.$html.'</div></aside>';
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    public static function css(array $preset): string
    {
        $color = self::token((string) ($preset['theme_color'] ?? 'primary'));
        $text = ($preset['text'] ?? '') === 'on_primary' ? 'Canvas' : self::token('text');
        $background = match ((string) ($preset['background'] ?? 'surface')) {
            'transparent' => 'transparent',
            'tint' => 'color-mix(in srgb, '.$color.' 12%, '.self::token('surface').')',
            default => self::token('surface'),
        };
        $border = match ((string) ($preset['border'] ?? 'subtle')) {
            'none' => '0',
            'strong' => '2px solid '.$color,
            default => '1px solid color-mix(in srgb, '.$color.' 35%, transparent)',
        };

        return '--seo-ops-pad:'.(int) $preset['spacing'].'px;--seo-ops-radius:'.(int) $preset['radius'].'px;background:'.$background.';color:'.$text.';border:'.$border.';';
    }

    public static function token(string $name): string
    {
        return match ($name) {
            'secondary' => 'var(--wp--preset--color--secondary, var(--secondary-color, CanvasText))',
            'accent' => 'var(--wp--preset--color--accent, var(--primary-color, Highlight))',
            'surface' => 'var(--wp--preset--color--base, Canvas)',
            'text' => 'var(--wp--preset--color--contrast, CanvasText)',
            default => 'var(--wp--preset--color--primary, var(--primary-color, CanvasText))',
        };
    }

    public static function canonicalStyle(string $style): string
    {
        $style = strtolower(trim($style));
        $presets = self::library()['presets'] ?? [];

        return is_array($presets) && isset($presets[$style]) ? $style : 'soft';
    }

    private static function stripNested(string $content): string
    {
        return (string) preg_replace('/\[\/?seo_ops_cta\b[^\]]*\]/i', '', $content);
    }

    /**
     * @param  array<string, string>  $destinations
     */
    private static function resolveAliases(string $content, array $destinations): string
    {
        $content = wp_kses_post_fallback($content);

        return (string) preg_replace_callback('/\[([a-z]+)\]/i', static function (array $match) use ($destinations): string {
            $alias = strtolower($match[1]);
            if (! in_array($alias, self::ALIASES, true)) {
                return '';
            }
            $destination = trim((string) ($destinations[$alias] ?? ''));
            if (! self::safeDestination($destination)) {
                return esc_html_fallback(self::label($alias));
            }
            $href = esc_url_fallback($destination);
            if ($href === '') {
                return esc_html_fallback(self::label($alias));
            }

            return '<a href="'.$href.'">'.esc_html_fallback(self::label($alias)).'</a>';
        }, $content);
    }

    private static function safeDestination(string $value): bool
    {
        if ($value === '' || preg_match('/\s|javascript:|data:/i', $value) === 1) {
            return false;
        }

        return preg_match('#^(https?://|mailto:|tel:)#i', $value) === 1;
    }

    private static function label(string $alias): string
    {
        return match ($alias) {
            'zalo' => 'Zalo',
            'facebook' => 'Facebook',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'products' => 'products',
            default => 'website',
        };
    }
}

function esc_html_fallback(string $value): string
{
    if (function_exists('esc_html')) {
        return esc_html($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_attr_fallback(string $value): string
{
    if (function_exists('esc_attr')) {
        return esc_attr($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_url_fallback(string $value): string
{
    if (function_exists('esc_url')) {
        return esc_url($value);
    }
    if (preg_match('#^(https?://|mailto:|tel:)#i', $value) !== 1) {
        return '';
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function wp_kses_post_fallback(string $value): string
{
    if (function_exists('wp_kses_post')) {
        return wp_kses_post($value);
    }

    return strip_tags($value, '<a><strong><em>');
}
