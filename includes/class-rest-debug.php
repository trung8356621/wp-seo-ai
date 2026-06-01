<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ghi log REST OMI SEO AI và trả lỗi JSON có chi tiết khi WP_DEBUG.
 */
final class Rest_Debug
{
    private const LOG_FILE = 'omi-seo-ai-rest.log';

    private static bool $fatalLoggerRegistered = false;

    public static function register_fatal_logger(): void
    {
        if (self::$fatalLoggerRegistered) {
            return;
        }

        self::$fatalLoggerRegistered = true;

        register_shutdown_function(static function (): void {
            if (! defined('REST_REQUEST') || ! REST_REQUEST) {
                return;
            }

            $error = error_get_last();
            if (! is_array($error)) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (! in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
                return;
            }

            self::log('shutdown_fatal', $error);
        });
    }

    /**
     * @param  callable(WP_REST_Request): WP_REST_Response  $handler
     */
    public static function wrap(string $route, callable $handler, WP_REST_Request $request): WP_REST_Response
    {
        $started = microtime(true);
        self::log('request', [
            'route'   => $route,
            'method'  => $request->get_method(),
            'post_id' => $request->get_param('id'),
        ]);

        try {
            $response = $handler($request);
            $payload = $response->get_data();
            self::log('response', [
                'route'   => $route,
                'status'  => $response->get_status(),
                'success' => is_array($payload) ? ($payload['success'] ?? null) : null,
                'ms'      => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $response;
        } catch (\Throwable $exception) {
            self::log('exception', [
                'route'     => $route,
                'message'   => $exception->getMessage(),
                'exception' => $exception::class,
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ]);

            return self::error_response(
                'REST handler failed: ' . $route,
                $exception,
                500,
            );
        }
    }

    public static function error_response(
        string $message,
        ?\Throwable $exception = null,
        int $status = 500,
    ): WP_REST_Response {
        $payload = [
            'success' => false,
            'message' => $message,
            'code'    => 'omi_seo_rest_error',
        ];

        if ($exception !== null) {
            $payload['error'] = $exception->getMessage();
            $payload['error_class'] = $exception::class;
            $payload['error_file'] = $exception->getFile() . ':' . $exception->getLine();
        }

        if (self::is_debug_enabled()) {
            $payload['debug'] = self::debug_context($exception, $message);
        }

        return new WP_REST_Response($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $event, array $context = []): void
    {
        if (! self::should_log()) {
            return;
        }

        $line = wp_json_encode([
            'time'    => gmdate('c'),
            'event'   => $event,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($line)) {
            return;
        }

        $path = self::log_path();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function log_path(): string
    {
        if (defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR) && WP_CONTENT_DIR !== '') {
            return WP_CONTENT_DIR . '/' . self::LOG_FILE;
        }

        return dirname(__DIR__, 2) . '/' . self::LOG_FILE;
    }

    public static function is_debug_enabled(): bool
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        return (bool) get_option('omi_seo_ai_rest_debug', false);
    }

    private static function should_log(): bool
    {
        return self::is_debug_enabled() || (bool) get_option('omi_seo_ai_rest_log', true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function debug_context(?\Throwable $exception, string $message): array
    {
        $context = [
            'message'              => $message,
            'wp_version'           => (string) get_bloginfo('version'),
            'php_version'          => PHP_VERSION,
            'plugin_version'       => defined('OMI_SEO_AI_BRIDGE_VERSION') ? OMI_SEO_AI_BRIDGE_VERSION : '',
            'woocommerce_active'   => class_exists('WooCommerce'),
            'log_file'             => self::log_path(),
        ];

        if ($exception !== null) {
            $context['exception'] = $exception::class;
            $context['exception_message'] = $exception->getMessage();
            $context['file'] = $exception->getFile();
            $context['line'] = $exception->getLine();
            $context['trace'] = explode("\n", $exception->getTraceAsString());
        }

        $last = error_get_last();
        if (is_array($last) && ($last['message'] ?? '') !== '') {
            $context['last_php_error'] = $last;
        }

        return $context;
    }
}
