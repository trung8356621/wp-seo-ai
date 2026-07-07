<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tắt WP-Cron spawn trên request và chặn lên lịch mới — lịch đăng do Laravel xử lý.
 */
final class Wp_Cron_Disabler
{
    public static function register(): void
    {
        add_action('init', [self::class, 'disable_spawn_on_request'], 0);
        add_filter('pre_schedule_event', [self::class, 'block_new_schedules'], 10, 2);
        add_filter('pre_reschedule_event', [self::class, 'block_reschedule'], 10, 2);
    }

    public static function disable_spawn_on_request(): void
    {
        remove_action('init', 'wp_cron');
    }

    /**
     * @param mixed $pre
     * @param object|null $event
     * @return false|mixed
     */
    public static function block_new_schedules($pre, $event)
    {
        unset($event);

        return false;
    }

    /**
     * @param mixed $pre
     * @param object|null $event
     * @return false|mixed
     */
    public static function block_reschedule($pre, $event)
    {
        unset($event);

        return false;
    }

    public static function is_disabled(): bool
    {
        return true;
    }
}
