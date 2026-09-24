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
        return [SiteRole::Me, SiteRole::Hub, SiteRole::Fans];
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

        $role = match ($this->role) {
            SiteRole::Me => 'faluss.me',
            SiteRole::Hub => 'faluss.com',
            SiteRole::Fans => 'Faluss Fans',
        };
        $modules = $this->registry->activeModuleIds();
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
            </div>
            <section class="faluss-admin__card" aria-labelledby="faluss-status-title">
                <h2 id="faluss-status-title"><?php echo esc_html__('État des modules', 'faluss-platform'); ?></h2>
                <div class="faluss-admin__module-cards">
                    <?php foreach ($modules as $module): ?>
                        <?php $details = self::moduleDetails($module); ?>
                        <article class="faluss-admin__module-card">
                            <div class="faluss-admin__module-card-head">
                                <span class="faluss-admin__module-icon" aria-hidden="true"><?php echo esc_html($details['short']); ?></span>
                                <span class="faluss-admin__badge is-active"><?php echo esc_html__('Actif', 'faluss-platform'); ?></span>
                            </div>
                            <h3><?php echo esc_html($details['label']); ?></h3>
                            <p><?php echo esc_html($details['description']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="faluss-admin__card faluss-admin__updates" aria-labelledby="faluss-updates-title">
                <h2 id="faluss-updates-title"><?php echo esc_html__('Mises à jour privées', 'faluss-platform'); ?></h2>
                <p><?php echo esc_html__('La licence autorise ce site à rechercher et télécharger les versions publiées par Faluss.', 'faluss-platform'); ?></p>
                <?php if ($licenseStatus !== ''): ?>
                    <div class="notice inline <?php echo $licenseStatus === 'saved' || $licenseStatus === 'removed' ? 'notice-success' : 'notice-error'; ?>">
                        <p><?php echo esc_html($this->licenseNotice($licenseStatus)); ?></p>
                    </div>
                <?php endif; ?>
                <?php if (!UpdateClient::isAvailable()): ?>
                    <div class="notice inline notice-error">
                        <p><?php echo esc_html__('Le client de mises à jour est absent. Réinstallez le paquet officiel Faluss Platform pour rétablir la détection des versions.', 'faluss-platform'); ?></p>
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

    /** @return array{label: string, description: string, short: string} */
    private static function moduleDetails(string $module): array
    {
        $details = [
            'admin-dashboard' => ['label' => 'Administration Faluss', 'description' => 'Vue d’ensemble, diagnostics et réglages communs du site.', 'short' => 'AD'],
            'theme-tokens' => ['label' => 'Identité visuelle', 'description' => 'Gère les couleurs, formes et ombres utilisées par le design Faluss.', 'short' => 'IV'],
            'catalog' => ['label' => 'Catalogue de thèmes', 'description' => 'Gère les thèmes disponibles pour les cartes Faluss Link.', 'short' => 'CT'],
            'token-engine-connector' => ['label' => 'Connecteur Token Engine', 'description' => 'Relie faluss.me au moteur de droits, de solde et de récompenses de faluss.com.', 'short' => 'TE'],
            'identity' => ['label' => 'Identité', 'description' => 'Gère les profils, le passwordless et l’autorité d’identité de faluss.me.', 'short' => 'ID'],
            'identity-client' => ['label' => 'Client Identity', 'description' => 'Connecte faluss.com à l’autorité d’identité via OAuth et PKCE.', 'short' => 'IC'],
            'link' => ['label' => 'Faluss Link', 'description' => 'Gère les cartes publiques, leur édition, les médias et les découvertes.', 'short' => 'FL'],
            'me-studio' => ['label' => 'Me Studio', 'description' => 'Fournit l’interface Studio moderne pour créer et personnaliser une carte.', 'short' => 'MS'],
            'portal' => ['label' => 'Portail membre', 'description' => 'Affiche l’espace membre et orchestre ses applications et services.', 'short' => 'PM'],
            'apps-registry' => ['label' => 'Registre des applications', 'description' => 'Décrit les applications disponibles et leurs capacités compatibles.', 'short' => 'RA'],
            'subscriptions' => ['label' => 'Abonnements', 'description' => 'Gère les offres, essais, droits et intégration de facturation.', 'short' => 'AB'],
            'token-engine' => ['label' => 'Token Engine', 'description' => 'Gère les projets, permissions, droits et ledgers de points PF.', 'short' => 'TE'],
            'events' => ['label' => 'Événements', 'description' => 'Enregistre et traite les événements avec reprise et idempotence.', 'short' => 'EV'],
            'analytics' => ['label' => 'Analytics', 'description' => 'Construit des agrégats anonymisés à partir des événements validés.', 'short' => 'AN'],
            'federation' => ['label' => 'Fédération', 'description' => 'Sécurise les échanges intersites signés entre faluss.me et faluss.com.', 'short' => 'FE'],
        ];

        return $details[$module] ?? [
            'label' => ucwords(str_replace('-', ' ', $module)),
            'description' => 'Module Faluss actif sur ce site.',
            'short' => 'FA',
        ];
    }
}
