<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;
use LogicException;

final class IdentityModule implements Module
{
    public const VERSION = '0.4.15';

    private static bool $compatibilityLoaded = false;

    public function id(): string
    {
        return 'identity';
    }

    public function roles(): array
    {
        return [SiteRole::Me];
    }

    public function dependencies(): array
    {
        return ['admin-dashboard'];
    }

    public function boot(): void
    {
        self::loadCompatibilityLayer();

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && !self::schemaIsReady()) {
            throw new LogicException('Faluss Identity schema is unavailable.');
        }

        \Faluss_Identity_Plugin::load_textdomain();
        \Faluss_Identity_Plugin::boot();
    }

    public static function activate(): void
    {
        if (!self::enabledForMe()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Identity_Plugin::activate();
    }

    public static function deactivate(): void
    {
        if (!self::enabledForMe()) {
            return;
        }

        self::loadCompatibilityLayer();
        \Faluss_Identity_Plugin::deactivate();
    }

    private static function enabledForMe(): bool
    {
        return SiteRole::fromValue(
            defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null
        ) === SiteRole::Me
            && defined('FALUSS_PLATFORM_IDENTITY')
            && constant('FALUSS_PLATFORM_IDENTITY') === true;
    }

    private static function schemaIsReady(): bool
    {
        return (string) get_option(\Faluss_Identity_Schema::OPTION_VERSION, '') === \Faluss_Identity_Schema::VERSION
            && !empty(\Faluss_Identity_Schema::get_status()['ready']);
    }

    private static function loadCompatibilityLayer(): void
    {
        if (self::$compatibilityLoaded) {
            return;
        }

        foreach ([
            'Faluss_Identity_Plugin',
            'Faluss_Identity_Schema',
            'Faluss_Identity_Registry',
            'Faluss_Identity_Member_Session',
            'Faluss_Identity_Front_Preferences',
            'Faluss_Identity_Navigation',
            'Faluss_Identity_Passwordless',
            'Faluss_Identity_Public_Profile',
            'Faluss_Identity_Onboarding',
            'Faluss_Identity_Authorization',
            'Faluss_Identity_SSO_Clients_Admin',
            'Faluss_Identity_Admin_Diagnostic',
            'Faluss_Identity_Elementor_Widget',
            'Faluss_Identity_Public_Profile_Widget_Base',
            'Faluss_Identity_Public_Profile_Editor_Widget',
            'Faluss_Identity_Public_Profile_Widget',
            'Faluss_Identity_Navigation_Elementor_Widget',
            'Faluss_Identity_Onboarding_Elementor_Widget',
        ] as $legacyClass) {
            if (class_exists($legacyClass, false)) {
                throw new LogicException('The legacy Faluss Identity authority is already loaded.');
            }
        }

        $root = dirname(__DIR__, 2);
        if (!defined('FALUSS_IDENTITY_VERSION')) {
            define('FALUSS_IDENTITY_VERSION', self::VERSION);
        }
        if (!defined('FALUSS_IDENTITY_FILE')) {
            define('FALUSS_IDENTITY_FILE', $root . '/faluss-platform.php');
        }
        if (!defined('FALUSS_IDENTITY_DIR')) {
            define('FALUSS_IDENTITY_DIR', __DIR__ . '/Legacy/');
        }

        $legacy = __DIR__ . '/Legacy/includes/';
        foreach ([
            'class-faluss-identity-plugin.php',
            'class-faluss-identity-schema.php',
            'class-faluss-identity-registry.php',
            'class-faluss-identity-member-session.php',
            'class-faluss-identity-front-preferences.php',
            'class-faluss-identity-navigation.php',
            'class-faluss-identity-passwordless.php',
            'class-faluss-identity-public-profile.php',
            'class-faluss-identity-onboarding.php',
            'class-faluss-identity-authorization.php',
            'class-faluss-identity-sso-clients-admin.php',
            'class-faluss-identity-admin-diagnostic.php',
        ] as $file) {
            require_once $legacy . $file;
        }

        self::$compatibilityLoaded = true;
    }
}
