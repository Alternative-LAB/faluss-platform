<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

final class CatalogAdminPage
{
    private const PAGE = 'faluss-platform-catalog';

    private string $pageHook = '';

    public function __construct(private readonly CatalogEntitlementProvider $entitlements)
    {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        $this->pageHook = (string) add_submenu_page(
            'faluss-platform',
            __('Catalogue de thèmes', 'faluss-platform'),
            __('Catalogue de thèmes', 'faluss-platform'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->pageHook || !current_user_can('manage_options')) {
            return;
        }

        $pluginFile = dirname(__DIR__, 2) . '/faluss-platform.php';
        $version = defined('FALUSS_PLATFORM_VERSION') ? (string) constant('FALUSS_PLATFORM_VERSION') : '0.1.0';
        wp_enqueue_style('faluss-platform-admin', plugins_url('assets/admin.css', $pluginFile), [], $version);
        wp_enqueue_style('faluss-platform-catalog', plugins_url('assets/catalog.css', $pluginFile), ['faluss-platform-admin'], $version);
        wp_enqueue_media();
        wp_enqueue_script('faluss-platform-catalog', plugins_url('assets/catalog.js', $pluginFile), ['jquery', 'media-views'], $version, true);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        $stored = get_option(CatalogThemeReader::OPTION, []);
        $reader = new CatalogThemeReader(is_array($stored) ? $stored : []);
        $definitions = $this->entitlements->available();
        ?>
        <div class="wrap faluss-admin faluss-catalog">
            <div class="faluss-admin__hero">
                <span class="faluss-admin__eyebrow"><?php echo esc_html__('Faluss Platform', 'faluss-platform'); ?></span>
                <h1><?php echo esc_html__('Catalogue de thèmes', 'faluss-platform'); ?></h1>
                <p><?php echo esc_html__('Préréglages de présentation pour les cartes Faluss Link. Les droits et abonnements restent gérés par leurs services respectifs.', 'faluss-platform'); ?></p>
            </div>
            <?php $this->renderNotice(); ?>
            <section class="faluss-admin__card">
                <h2><?php echo esc_html__('Thème système', 'faluss-platform'); ?></h2>
                <p><?php echo esc_html__('Faluss par défaut est toujours actif et ne peut pas être modifié ni supprimé.', 'faluss-platform'); ?></p>
            </section>
            <h2><?php echo esc_html__('Thèmes de carte', 'faluss-platform'); ?></h2>
            <?php foreach ($reader->allForScope() as $theme): ?>
                <?php if (empty($theme['system'])): ?>
                    <?php $this->renderForm($theme, false, $definitions); ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <h2><?php echo esc_html__('Créer un thème', 'faluss-platform'); ?></h2>
            <?php $this->renderForm(self::newTheme(), true, $definitions); ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $theme
     *  @param array<string, string>|false $definitions
     */
    private function renderForm(array $theme, bool $create, array|false $definitions): void
    {
        $action = $create ? 'faluss_catalog_create_theme' : 'faluss_catalog_update_theme';
        $url = admin_url('admin-post.php');
        ?>
        <form class="faluss-admin__card faluss-catalog__form" method="post" action="<?php echo esc_url($url); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php if (!$create): ?>
                <input type="hidden" name="theme_slug" value="<?php echo esc_attr((string) $theme['slug']); ?>">
                <h3><?php echo esc_html((string) $theme['name']); ?></h3>
            <?php endif; ?>
            <?php wp_nonce_field($action, 'faluss_catalog_nonce'); ?>
            <div class="faluss-admin__fields">
                <?php $this->input('name', 'Nom', (string) $theme['name'], 'text'); ?>
                <?php if (!$create): ?>
                    <?php $this->input('stable_slug', 'Identifiant stable', (string) $theme['slug'], 'text'); ?>
                <?php endif; ?>
                <?php $this->select('active', 'État', ['1' => 'Actif', '0' => 'Inactif'], (string) $theme['active']); ?>
                <?php $this->input('sort_order', 'Ordre d’affichage', (string) $theme['sort_order'], 'number'); ?>
                <?php $this->input('page_background', 'Fond de carte', (string) $theme['page_background'], 'color'); ?>
                <?php $this->input('hero_transition_color', 'Transition du hero', (string) $theme['hero_transition_color'], 'color'); ?>
                <?php $this->select('name_color', 'Couleur du nom', ['#BE79FF' => 'Rose', '#FFFFFF' => 'Blanc', '#000000' => 'Noir', '#82206B' => 'Prune'], (string) $theme['name_color']); ?>
                <?php $this->select('alignment', 'Alignement', ['left' => 'Gauche', 'center' => 'Centré'], (string) $theme['alignment']); ?>
                <?php $this->select('social_variant', 'Réseaux', ['outline' => 'Icônes contour', 'full' => 'Logos pleins'], (string) $theme['social_variant']); ?>
                <?php $this->select('link_style', 'Boutons de liens', ['dark' => 'Sombre', 'light' => 'Clair', 'outline' => 'Contour'], (string) $theme['link_style']); ?>
                <?php $this->renderEntitlement($theme, $definitions); ?>
                <label class="faluss-admin__field faluss-catalog__media">
                    <span><?php echo esc_html__('Image de prévisualisation', 'faluss-platform'); ?></span>
                    <input type="hidden" name="preview_attachment_id" value="<?php echo esc_attr((string) $theme['preview_attachment_id']); ?>">
                    <button class="button faluss-catalog__media-button" type="button"><?php echo esc_html__('Choisir une image', 'faluss-platform'); ?></button>
                    <?php $image = (int) $theme['preview_attachment_id'] ? wp_get_attachment_image_url((int) $theme['preview_attachment_id'], 'medium') : false; ?>
                    <img class="faluss-catalog__preview" src="<?php echo esc_url($image ?: ''); ?>" alt="" <?php echo $image ? '' : 'hidden'; ?>>
                </label>
            </div>
            <?php submit_button($create ? __('Créer le thème', 'faluss-platform') : __('Enregistrer le thème', 'faluss-platform'), 'primary', 'submit', false); ?>
        </form>
        <?php if (!$create): ?>
            <form class="faluss-catalog__delete" method="post" action="<?php echo esc_url($url); ?>">
                <input type="hidden" name="action" value="faluss_catalog_delete_theme">
                <input type="hidden" name="theme_slug" value="<?php echo esc_attr((string) $theme['slug']); ?>">
                <?php wp_nonce_field('faluss_catalog_delete_theme', 'faluss_catalog_nonce'); ?>
                <button class="button-link-delete" type="submit"><?php echo esc_html__('Supprimer', 'faluss-platform'); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /** @param array<string, mixed> $theme
     *  @param array<string, string>|false $definitions
     */
    private function renderEntitlement(array $theme, array|false $definitions): void
    {
        $current = (string) ($theme['entitlement_code'] ?? '');
        ?>
        <label class="faluss-admin__field">
            <span><?php echo esc_html__('Droit requis', 'faluss-platform'); ?></span>
            <?php if ($definitions === false): ?>
                <input type="hidden" name="entitlement_code" value="<?php echo esc_attr($current); ?>">
                <span class="description"><?php echo esc_html__('Connector indisponible : le droit existant reste inchangé.', 'faluss-platform'); ?></span>
            <?php else: ?>
                <select name="entitlement_code">
                    <option value=""><?php echo esc_html__('Inclus — aucun droit requis', 'faluss-platform'); ?></option>
                    <?php foreach ($definitions as $code => $label): ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($current, $code); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </label>
        <?php
    }

    private function input(string $key, string $label, string $value, string $type): void
    {
        ?>
        <label class="faluss-admin__field">
            <span><?php echo esc_html($label); ?></span>
            <input name="<?php echo esc_attr($key); ?>" type="<?php echo esc_attr($type); ?>" value="<?php echo esc_attr($value); ?>"
                <?php if ($key === 'name'): ?> maxlength="80" required<?php endif; ?>
                <?php if ($key === 'stable_slug'): ?> readonly<?php endif; ?>
                <?php if ($key === 'sort_order'): ?> min="1" max="9999"<?php endif; ?>>
        </label>
        <?php
    }

    /** @param array<string|int, string> $options */
    private function select(string $key, string $label, array $options, string $value): void
    {
        ?>
        <label class="faluss-admin__field">
            <span><?php echo esc_html($label); ?></span>
            <select name="<?php echo esc_attr($key); ?>">
                <?php foreach ($options as $option => $name): ?>
                    <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($value, (string) $option); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
    }

    private function renderNotice(): void
    {
        $raw = $_GET['faluss_catalog_notice'] ?? null;
        $notice = is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';
        $messages = [
            'created' => 'Thème créé.',
            'updated' => 'Thème enregistré.',
            'deleted' => 'Thème supprimé.',
            'invalid' => 'Le thème ne peut pas être enregistré.',
        ];

        if (isset($messages[$notice])) {
            $state = $notice === 'invalid' ? 'error' : 'success';
            echo '<div class="notice notice-' . esc_attr($state) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
    }

    /** @return array<string, mixed> */
    private static function newTheme(): array
    {
        $theme = CatalogThemeReader::systemTheme();
        $theme['name'] = '';
        $theme['slug'] = '';
        $theme['sort_order'] = 10;
        $theme['preview_attachment_id'] = 0;
        unset($theme['system']);

        return $theme;
    }
}
