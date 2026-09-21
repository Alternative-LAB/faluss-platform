<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class IdentityClientModule implements Module
{
    private const REWRITE_OPTION = 'faluss_platform_identity_client_rewrite_version';
    private const REWRITE_VERSION = '1';

    public function id(): string
    {
        return 'identity-client';
    }

    public function roles(): array
    {
        return [SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        if (class_exists('Faluss_Identity_Client', false)
            || class_exists('Faluss_Identity_Client_Schema', false)
        ) {
            throw new LogicException('The legacy Identity Client is already loaded.');
        }
        if (self::isIdentityHost()) {
            return;
        }

        if (!IdentityClientSchema::maybeInstall()) {
            throw new LogicException('Identity Client schema is unavailable.');
        }
        require_once __DIR__ . '/LegacyIdentityClientFacades.php';
        IdentityClientService::register();
        IdentityClientAppsRegistryAdapter::boot();
        add_action('wp_enqueue_scripts', [IdentityClientService::class, 'assets']);
        add_action('elementor/widgets/register', [self::class, 'widget']);
        add_action('admin_init', [self::class, 'maybeFlushRewrite'], 100);
        if (is_admin()) {
            IdentityClientAdmin::register();
        }
        self::loadTextdomain();
    }

    public static function activate(): bool
    {
        if (self::isIdentityHost() || !IdentityClientSchema::installOrVerify()) {
            return false;
        }
        IdentityClientService::rewrite();
        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);

        return true;
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    public static function maybeFlushRewrite(): void
    {
        if (get_option(self::REWRITE_OPTION) === self::REWRITE_VERSION) {
            return;
        }
        IdentityClientService::rewrite();
        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);
    }

    public static function loadTextdomain(): void
    {
        load_plugin_textdomain('faluss-platform', false, 'faluss-platform/languages');
    }

    public static function widget(mixed $manager): void
    {
        if (!class_exists('Elementor\\Widget_Base')
            || !is_object($manager)
            || !is_callable([$manager, 'register'])
        ) {
            return;
        }

        require_once __DIR__ . '/LegacyIdentityClientElementorWidget.php';
        $manager->register(new \Faluss_Identity_Client_Elementor_Widget());
    }

    private static function isIdentityHost(): bool
    {
        $parts = wp_parse_url(home_url('/'));

        return is_array($parts)
            && isset($parts['host'])
            && in_array(strtolower((string) $parts['host']), ['faluss.me', 'www.faluss.me'], true);
    }
}
