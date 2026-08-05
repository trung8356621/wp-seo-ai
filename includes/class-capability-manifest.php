<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * site_sync.v1 Capability Manifest — capability-driven via provider adapters.
 */
final class Capability_Manifest
{
    public const SCHEMA = 'site_sync.v1';

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $adapter = Seo_Provider_Adapter_Registry::active();
        $providerCaps = $adapter->capabilities();
        $core = [
            'internal_link' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'contact_discovery' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'taxonomy' => [
                'available' => true,
                'provider' => 'wordpress',
            ],
            'product_category_taxonomy_export' => [
                'available' => taxonomy_exists('product_cat'),
                'provider' => 'wordpress',
                'version' => 1,
                'schema' => [
                    'taxonomy' => 'product_cat',
                    'fields' => [
                        'term_id',
                        'parent_term_id',
                        'name',
                        'slug',
                        'url',
                        'post_count',
                        'page_type',
                    ],
                ],
            ],
            'permalink' => [
                'available' => true,
                'provider' => 'wordpress',
            ],
        ];

        $capabilities = array_merge($core, $providerCaps);
        // Ensure seo_score is never invented when provider does not expose it.
        if (! isset($capabilities['seo_score'])) {
            $capabilities['seo_score'] = ['available' => false, 'provider' => null];
        }
        if (! isset($capabilities['focus_keyword'])) {
            $capabilities['focus_keyword'] = ['available' => false, 'provider' => null];
        }
        if (! isset($capabilities['seo_metadata'])) {
            $capabilities['seo_metadata'] = ['available' => false, 'provider' => null];
        }

        return [
            'schema' => self::SCHEMA,
            'site_url' => home_url('/'),
            'bridge_version' => defined('OMI_SEO_AI_BRIDGE_VERSION') ? (string) OMI_SEO_AI_BRIDGE_VERSION : '',
            'detected_at' => gmdate('c'),
            'provider' => [
                'id' => $adapter->id(),
                'display_name' => $adapter->display_name(),
                'version' => $adapter->version(),
                'edition' => $adapter->edition(),
            ],
            'capabilities' => $capabilities,
        ];
    }
}
