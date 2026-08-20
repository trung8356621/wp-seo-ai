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
            'taxonomy_catalog_v1' => [
                'available' => true,
                'provider' => 'omi_bridge',
                'version' => 1,
                'schema' => Taxonomy_Catalog::SCHEMA,
                'taxonomies' => Taxonomy_Catalog::SUPPORTED,
                'endpoint' => 'GET /omi-seo-ai/v1/taxonomy-catalog/{taxonomy}',
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

        $localEngine = [
            'content_manifest' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'metadata_only_articles' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'heartbeat' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'cache_purge' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'link_health_batch' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'broken_links_v2' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'link_graph' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'operation_idempotency' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'plugin_update' => [
                'available' => true,
                'provider' => 'omi_bridge',
                'source' => 'github_release',
            ],
            'github_release_update' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'manual_update' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'keyword_dictionary_apply' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'link_analysis_batch' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
            'post_observe' => [
                'available' => true,
                'provider' => 'omi_bridge',
            ],
        ];
        $capabilities = array_merge($capabilities, $localEngine);

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
