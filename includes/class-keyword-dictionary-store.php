<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Compact keyword dictionary from Laravel. Idempotent apply by hash.
 */
final class Keyword_Dictionary_Store
{
    public const OPTION_KEY = 'omi_seo_keyword_dictionary';

    /**
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        $raw = get_option(self::OPTION_KEY, null);

        return is_array($raw) ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function apply(array $params): array
    {
        $hash = trim((string) ($params['dictionary_hash'] ?? $params['hash'] ?? ''));
        $version = trim((string) ($params['dictionary_version'] ?? $params['version'] ?? ''));
        $clusters = is_array($params['clusters'] ?? null) ? $params['clusters'] : [];
        $existing = self::current();
        $existingHash = is_array($existing) ? trim((string) ($existing['hash'] ?? '')) : '';

        if ($hash !== '' && $hash === $existingHash) {
            return [
                'success' => true,
                'noop' => true,
                'already_processed' => true,
                'dictionary_hash' => $hash,
                'dictionary_version' => $version !== '' ? $version : (string) ($existing['version'] ?? ''),
            ];
        }

        $payload = [
            'version' => $version,
            'hash' => $hash,
            'clusters' => $clusters,
            'applied_at' => gmdate('c'),
        ];
        update_option(self::OPTION_KEY, $payload, false);
        Local_Seo_Engine::mark_dictionary_stale();

        return [
            'success' => true,
            'noop' => false,
            'already_processed' => false,
            'dictionary_hash' => $hash,
            'dictionary_version' => $version,
            'clusters' => count($clusters),
        ];
    }
}
