<?php

declare(strict_types=1);

namespace Faluss\Platform\Admin;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\ModuleRegistry;
use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Core\UpdateClient;

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
        add_action('admin_post_faluss_platform_save_license', [$this, 'saveLicense']);
    }

    public function saveLicense(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        check_admin_referer('faluss_platform_save_license');

        if (UpdateClient::usesConstant()) {
            $status = 'constant';
        } elseif (isset($_POST['remove_license'])) {
            delete_option(UpdateClient::LICENSE_OPTION);
            $status = 'removed';
        } else {
            $submitted = isset($_POST['license_key']) && is_string($_POST['license_key'])
                ? wp_unslash($_POST['license_key'])
                : '';
            $license = UpdateClient::sanitize($submitted);

            if ($license === '') {
                $status = 'invalid';
            } else {
                update_option(UpdateClient::LICENSE_OPTION, $license, false);
                $status = 'saved';
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'license_status' => $status,
        ], admin_url('admin.php')));
        exit;
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
            (string) constant('FALUSS_PLATFORM_VERSION')
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
        $licenseStatus = isset($_GET['license_status']) && is_string($_GET['license_status'])
            ? sanitize_key(wp_unslash($_GET['license_status']))
            : '';
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
            <section class="faluss-admin__card faluss-admin__updates" aria-labelledby="faluss-updates-title">
                <h2 id="faluss-updates-title"><?php echo esc_html__('Mises à jour privées', 'faluss-platform'); ?></h2>
                <p><?php echo esc_html__('La licence autorise ce site à rechercher et télécharger les versions publiées par Faluss.', 'faluss-platform'); ?></p>
                <?php if ($licenseStatus !== ''): ?>
                    <div class="notice inline <?php echo $licenseStatus === 'saved' || $licenseStatus === 'removed' ? 'notice-success' : 'notice-error'; ?>">
                        <p><?php echo esc_html($this->licenseNotice($licenseStatus)); ?></p>
                    </div>
                <?php endif; ?>
                <div class="faluss-admin__update-status">
                    <span class="faluss-admin__badge <?php echo UpdateClient::hasLicense() ? 'is-active' : 'is-inactive'; ?>">
                        <?php echo UpdateClient::hasLicense() ? esc_html__('Licence configurée', 'faluss-platform') : esc_html__('Licence absente', 'faluss-platform'); ?>
                    </span>
                    <code><?php echo esc_html(UpdateClient::METADATA_URL); ?></code>
                </div>
                <?php if (UpdateClient::usesConstant()): ?>
                    <p><?php echo esc_html__('La licence est définie par FALUSS_PLATFORM_LICENSE_KEY et doit être modifiée dans la configuration du serveur.', 'faluss-platform'); ?></p>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="faluss-admin__license-form">
                        <input type="hidden" name="action" value="faluss_platform_save_license">
                        <?php wp_nonce_field('faluss_platform_save_license'); ?>
                        <label class="faluss-admin__field" for="faluss-platform-license-key">
                            <span><?php echo esc_html__('Nouvelle clé de licence', 'faluss-platform'); ?></span>
                            <input id="faluss-platform-license-key" name="license_key" type="password" autocomplete="new-password" minlength="20" maxlength="128" pattern="[A-Za-z0-9_-]{20,128}">
                        </label>
                        <div class="faluss-admin__license-actions">
                            <button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer la licence', 'faluss-platform'); ?></button>
                            <?php if (UpdateClient::hasLicense()): ?>
                                <button type="submit" name="remove_license" value="1" class="button"><?php echo esc_html__('Retirer la licence', 'faluss-platform'); ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
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

    private function licenseNotice(string $status): string
    {
        return match ($status) {
            'saved' => __('Licence enregistrée.', 'faluss-platform'),
            'removed' => __('Licence retirée.', 'faluss-platform'),
            'constant' => __('La licence est gérée par la configuration du serveur.', 'faluss-platform'),
            default => __('La clé fournie est invalide.', 'faluss-platform'),
        };
    }
}
