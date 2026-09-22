<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Compatibility composition root; Platform owns plugin activation. */
final class Faluss_Federation
{
    private static bool $loaded = false;

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'load']);
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        Faluss_Federation_Providers::boot();
        if (function_exists('do_action')) {
            do_action('faluss_federation_ready');
        }
        Faluss_Federation_Server::boot();
        Faluss_Federation_Admin::boot();
        self::$loaded = true;
    }
}
