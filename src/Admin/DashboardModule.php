<?php

declare(strict_types=1);

namespace Faluss\Platform\Admin;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;

final class DashboardModule implements Module
{
    private const PAGE = 'faluss-platform';

    private string $pageHook = '';

    public function __construct(
        private readonly SiteRole $role,
        private readonly ModuleRegistry $registry
    ) {
    }

    public function id(): string
    {
        return 'admin-dashboard';
    }

    public function roles(): array
    {
        return [SiteRole::Me, SiteRole::Hub];
    }

    public function dependencies(): array
    {
        return [];
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerPage(): void
    {
        $this->pageHook = add_menu_page(
            __('Faluss Platform', 'faluss-platform'),
            __('Faluss', 'faluss-platform'),
            'manage_options',
            self::PAGE,
            [$this, 'render'],
            'dashicons-layout',
            58
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->pageHook || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            'faluss-platform-admin',
            plugins_url('assets/admin.css', dirname(__DIR__, 2) . '/faluss-platform.php'),
            [],
            '0.1.0'
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        $role = $this->role === SiteRole::Me ? 'faluss.me' : 'faluss.com';
        $modules = $this->registry->activeModuleIds();
        $activePlugins = get_option('active_plugins', []);
        $inventory = new LegacyPluginInventory(
            $this->role,
            is_array($activePlugins) ? array_values(array_filter($activePlugins, 'is_string')) : []
        );
        ?>
        <div class="wrap faluss-admin">
            <div class="faluss-admin__hero">
                <span class="faluss-admin__eyebrow"><?php echo esc_html__('Administration unifiée', 'faluss-platform'); ?></span>
                <h1><?php echo esc_html__('Faluss Platform', 'faluss-platform'); ?></h1>
                <p><?php echo esc_html__('Un espace de pilotage pour les modules de votre site, leurs diagnostics et les réglages disponibles.', 'faluss-platform'); ?></p>
            </div>
            <div class="faluss-admin__grid">
                <section class="faluss-admin__card" aria-labelledby="faluss-site-title">
                    <h2 id="faluss-site-title"><?php echo esc_html__('Site configuré', 'faluss-platform'); ?></h2>
                    <p class="faluss-admin__value"><?php echo esc_html($role); ?></p>
                    <p><?php echo esc_html__('Rôle défini dans la configuration de WordPress.', 'faluss-platform'); ?></p>
                </section>
                <section class="faluss-admin__card" aria-labelledby="faluss-modules-title">
                    <h2 id="faluss-modules-title"><?php echo esc_html__('Modules chargés', 'faluss-platform'); ?></h2>
                    <p class="faluss-admin__value"><?php echo esc_html((string) count($modules)); ?></p>
                    <p><?php echo esc_html__('La liste évoluera progressivement, après validation de chaque migration.', 'faluss-platform'); ?></p>
                </section>
                <section class="faluss-admin__card" aria-labelledby="faluss-legacy-count-title">
                    <h2 id="faluss-legacy-count-title"><?php echo esc_html__('Plugins Faluss historiques actifs', 'faluss-platform'); ?></h2>
                    <p class="faluss-admin__value"><?php echo esc_html((string) $inventory->activeCount()); ?></p>
                    <p><?php echo esc_html__('Inventaire local, sans appel à l’autre site.', 'faluss-platform'); ?></p>
                </section>
            </div>
            <section class="faluss-admin__card" aria-labelledby="faluss-status-title">
                <h2 id="faluss-status-title"><?php echo esc_html__('État des modules', 'faluss-platform'); ?></h2>
                <ul class="faluss-admin__modules">
                    <?php foreach ($modules as $module): ?>
                        <li><span class="faluss-admin__dot" aria-hidden="true"></span><?php echo esc_html($module); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="faluss-admin__card faluss-admin__inventory" aria-labelledby="faluss-legacy-title">
                <h2 id="faluss-legacy-title"><?php echo esc_html__('Inventaire des plugins historiques', 'faluss-platform'); ?></h2>
                <p><?php echo esc_html__('État des extensions connues sur ce WordPress. Ce relevé ne vérifie pas leur bon fonctionnement.', 'faluss-platform'); ?></p>
                <div class="faluss-admin__table-scroll">
                    <table class="widefat striped">
                        <thead><tr>
                            <th scope="col"><?php echo esc_html__('Extension', 'faluss-platform'); ?></th>
                            <th scope="col"><?php echo esc_html__('Fichier', 'faluss-platform'); ?></th>
                            <th scope="col"><?php echo esc_html__('État', 'faluss-platform'); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($inventory->rows() as $plugin): ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($plugin['name']); ?></th>
                                    <td><code><?php echo esc_html($plugin['basename']); ?></code></td>
                                    <td><span class="faluss-admin__badge <?php echo $plugin['active'] ? 'is-active' : 'is-inactive'; ?>"><?php echo $plugin['active'] ? esc_html__('Actif', 'faluss-platform') : esc_html__('Inactif', 'faluss-platform'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }
}
