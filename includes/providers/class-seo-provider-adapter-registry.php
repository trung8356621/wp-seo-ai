<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

final class Seo_Provider_Adapter_Registry
{
    /**
     * @return list<Seo_Provider_Adapter>
     */
    public static function all(): array
    {
        return [
            new Rank_Math_Provider_Adapter(),
            new Yoast_Provider_Adapter(),
            new Aioseo_Provider_Adapter(),
            new None_Provider_Adapter(),
        ];
    }

    public static function active(): Seo_Provider_Adapter
    {
        foreach ([new Rank_Math_Provider_Adapter(), new Yoast_Provider_Adapter(), new Aioseo_Provider_Adapter()] as $adapter) {
            if ($adapter->is_active()) {
                return $adapter;
            }
        }

        return new None_Provider_Adapter();
    }
}
