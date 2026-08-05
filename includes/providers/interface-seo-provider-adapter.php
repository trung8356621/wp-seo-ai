<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provider adapter contract — capability from real plugin state, not Laravel hardcode.
 */
interface Seo_Provider_Adapter
{
    public function id(): string;

    public function display_name(): string;

    public function is_active(): bool;

    public function version(): ?string;

    public function edition(): ?string;

    /**
     * @return array<string, array{available: bool, provider: ?string, provider_version?: ?string, score_kind?: ?string}>
     */
    public function capabilities(): array;
}
