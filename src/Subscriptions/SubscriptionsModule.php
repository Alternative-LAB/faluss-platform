<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class SubscriptionsModule implements Module
{
    public const VERSION = '0.2.7';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'subscriptions';
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
        self::loadCompatibilityLayer();

        // The platform registers modules on plugins_loaded priority 20, after
        // the historical priority-1 installer would already have run.
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            \Faluss_Subscriptions_Schema::maybe_install();
        }
        \Faluss_Subscriptions_Admin::boot();
        \Faluss_Subscriptions_Webhooks::boot();
        \Faluss_Subscriptions_Notifications::boot();
        \Faluss_Subscriptions_Returns::boot();
    }

    public static function activate(): void
    {
        if (!self::enabledForHub()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Subscriptions_Schema::activate();
        \Faluss_Subscriptions_Admin::grant_capability();
        \Faluss_Subscriptions_Notifications::schedule();
    }

    public static function deactivate(): void
    {
        if (!self::enabledForHub()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Subscriptions_Notifications::deactivate();
        \Faluss_Subscriptions_Schema::deactivate();
    }

    private static function enabledForHub(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Hub
            && defined('FALUSS_PLATFORM_SUBSCRIPTIONS')
            && constant('FALUSS_PLATFORM_SUBSCRIPTIONS') === true;
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Faluss_Subscriptions_Schema',
            'Faluss_Subscriptions_Catalog',
            'Faluss_Subscriptions_Audit',
            'Faluss_Subscriptions_Repository',
            'Faluss_Subscriptions_Trials',
            'Faluss_Subscriptions_Entitlements',
            'Faluss_Subscriptions_Resolver',
            'Faluss_Subscriptions_Stripe_Config',
            'Faluss_Subscriptions_Stripe_Sdk',
            'Faluss_Subscriptions_Stripe_Adapter',
            'Faluss_Subscriptions_Billing',
            'Faluss_Subscriptions_Webhooks',
            'Faluss_Subscriptions_Notifications',
            'Faluss_Subscriptions_Reconciliation',
            'Faluss_Subscriptions_Returns',
            'Faluss_Subscriptions_Diagnostics',
            'Faluss_Subscriptions_Admin_Notices',
            'Faluss_Subscriptions_Admin',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Subscriptions plugin is already loaded.');
            }
        }

        $root = dirname(__DIR__, 2);
        if (!defined('FALUSS_SUBSCRIPTIONS_FILE')) {
            define('FALUSS_SUBSCRIPTIONS_FILE', $root . '/faluss-platform.php');
        }
        if (!defined('FALUSS_SUBSCRIPTIONS_DIR')) {
            define('FALUSS_SUBSCRIPTIONS_DIR', $root . '/');
        }
        if (!defined('FALUSS_SUBSCRIPTIONS_URL')) {
            define('FALUSS_SUBSCRIPTIONS_URL', rtrim(plugins_url('', $root . '/faluss-platform.php'), '/') . '/');
        }
        if (!defined('FALUSS_SUBSCRIPTIONS_VERSION')) {
            define('FALUSS_SUBSCRIPTIONS_VERSION', self::VERSION);
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-faluss-subscriptions-schema.php',
            'class-faluss-subscriptions-catalog.php',
            'class-faluss-subscriptions-audit.php',
            'class-faluss-subscriptions-repository.php',
            'class-faluss-subscriptions-trials.php',
            'class-faluss-subscriptions-entitlements.php',
            'class-faluss-subscriptions-resolver.php',
            'class-faluss-subscriptions-stripe-config.php',
            'class-faluss-subscriptions-stripe-sdk.php',
            'class-faluss-subscriptions-billing.php',
            'class-faluss-subscriptions-webhooks.php',
            'class-faluss-subscriptions-notifications.php',
            'class-faluss-subscriptions-returns.php',
            'class-faluss-subscriptions-diagnostics.php',
            'class-faluss-subscriptions-admin-notices.php',
            'class-faluss-subscriptions-admin.php',
        ] as $file) {
            require_once $legacy . $file;
        }

        self::$compatibilityLoaded = true;
    }
}
