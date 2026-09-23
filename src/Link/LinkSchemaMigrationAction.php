<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

/** Explicit, privileged migration control for already-active Link installations. */
final class LinkSchemaMigrationAction
{
    private const ACTION = 'faluss_link_migrate_v4';
    private const NONCE_ACTION = 'faluss_link_migrate_v4';
    private const NONCE_FIELD = 'faluss_link_migrate_v4_nonce';
    private const SCREEN = 'settings_page_faluss-link-networks';

    public static function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    public static function handle(): void
    {
        $nonce = isset($_POST[self::NONCE_FIELD]) && is_string($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Accès refusé.');
        }

        $result = \Faluss_Link_Schema::migrate_v4();
        wp_safe_redirect(admin_url('options-general.php?page=faluss-link-networks&migration_v4=' . ($result ? 'success' : 'failed')));
        exit;
    }

    public static function renderNotice(): void
    {
        if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!is_object($screen) || $screen->id !== self::SCREEN) {
            return;
        }

        $status = isset($_GET['migration_v4']) && is_string($_GET['migration_v4'])
            ? sanitize_key(wp_unslash($_GET['migration_v4']))
            : '';
        if ($status === 'success') {
            echo '<div class="notice notice-success"><p>La migration Link V4 est terminée.</p></div>';
        } elseif ($status === 'failed') {
            echo '<div class="notice notice-error"><p>La migration Link V4 n’a pas abouti. Elle peut être relancée après diagnostic.</p></div>';
        }
        if (\Faluss_Link_Schema::composition_ready()) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>Link fonctionne encore sur son schéma historique. La migration V4 reste volontairement manuelle.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <p><button type="submit" class="button button-primary">Migrer Link vers V4</button></p>
            </form>
        </div>
        <?php
    }
}
