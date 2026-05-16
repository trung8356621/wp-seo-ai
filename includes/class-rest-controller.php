<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class Rest_Controller
{
    public const NAMESPACE = 'omi-seo-ai/v1';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/sync', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_sync'],
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'is_test' => [
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => static fn ($value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                ],
                'limit_per_type' => [
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => static fn ($value): int => max(0, (int) $value),
                ],
            ],
        ]);
    }

    public static function authorize(WP_REST_Request $request): bool
    {
        $expected = (string) get_option(OMI_SEO_AI_BRIDGE_OPTION_READ, '');
        if ($expected === '') {
            return false;
        }

        $token = self::extract_bearer_token($request);
        if ($token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function handle_sync(WP_REST_Request $request): WP_REST_Response
    {
        $isTest = (bool) $request->get_param('is_test');
        $limitPerType = (int) $request->get_param('limit_per_type');
        if ($limitPerType <= 0 && $isTest) {
            $limitPerType = 2;
        }

        $provider = new Sync_Provider();
        $payload = $provider->collect($limitPerType);

        return new WP_REST_Response([
            'success' => true,
            'message' => $isTest
                ? 'Test sync payload generated.'
                : 'Sync payload generated.',
            'is_test' => $isTest,
            'limit_per_type' => $limitPerType,
            'counts'  => $payload['counts'],
            'items'   => $payload['items'],
        ], 200);
    }

    private static function extract_bearer_token(WP_REST_Request $request): string
    {
        $auth = (string) $request->get_header('authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }

        $alt = (string) $request->get_header('x-omi-read-token');
        if ($alt !== '') {
            return trim($alt);
        }

        return '';
    }
}
