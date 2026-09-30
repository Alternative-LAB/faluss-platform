<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Access;

use Faluss\Platform\Core\SiteRole;

/** Site-wide WordPress chrome policy, independent of Fans feature/schema flags. */
final class WordPressAccess
{
    public static function register(SiteRole $role): void
    {
        if ($role !== SiteRole::Fans) {
            return;
        }
        add_filter('show_admin_bar', [self::class, 'adminBarVisible'], PHP_INT_MAX);
        // init precedes wp-admin menu capability checks (which can reject before admin_init).
        add_action('init', [self::class, 'protectAdmin'], 0);
    }

    public static function adminBarVisible(bool $show): bool
    {
        return self::isAdministrator();
    }

    public static function protectAdmin(): void
    {
        // These are transport endpoints, not admin screens. Their own nonce/capability
        // checks remain authoritative (including the native Fans SSO POST handler).
        if (!is_admin() || !is_user_logged_in() || self::isAdministrator() || wp_doing_ajax()
            || in_array($GLOBALS['pagenow'] ?? '', ['admin-post.php', 'admin-ajax.php'], true)
        ) {
            return;
        }
        nocache_headers();
        wp_safe_redirect(home_url('/'), 302, 'Faluss Fans');
        exit;
    }

    private static function isAdministrator(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        return (in_array('administrator', wp_get_current_user()->roles, true) && current_user_can('manage_options'))
            || (is_multisite() && is_super_admin());
    }
}
