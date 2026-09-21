<?php

declare(strict_types=1);

namespace Faluss\Platform\Theme;

use Faluss\Platform\Core\Module;
use Faluss\Platform\Core\SiteRole;

final class ThemeTokensModule implements Module
{
    private const PAGE = 'faluss-platform-theme';

    private string $pageHook = '';

    public function id(): string
    {
        return 'theme-tokens';
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
        add_action('wp_enqueue_scripts', [$this, 'enqueueTokens']);
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function enqueueTokens(): void
    {
        $values = ThemeTokenSet::fromStorage(get_option(ThemeTokenSet::OPTION, []));
        wp_register_style('faluss-theme-tokens', false, [], null);
        wp_enqueue_style('faluss-theme-tokens');
        wp_add_inline_style('faluss-theme-tokens', ThemeTokenSet::css($values));
    }

    public function registerPage(): void
    {
        $this->pageHook = (string) add_submenu_page(
            'faluss-platform',
            __('Identité visuelle', 'faluss-platform'),
            __('Identité visuelle', 'faluss-platform'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting('faluss_platform_theme', ThemeTokenSet::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [ThemeTokenSet::class, 'sanitize'],
        ]);
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== $this->pageHook || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style('faluss-platform-admin', plugins_url('assets/admin.css', dirname(__DIR__, 2) . '/faluss-platform.php'), [], '0.1.0');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".faluss-theme-color").wpColorPicker();});');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        $values = ThemeTokenSet::fromStorage(get_option(ThemeTokenSet::OPTION, []));
        $colors = [
            'canvas' => 'Fond de page', 'surface' => 'Surface', 'ink' => 'Texte principal',
            'muted' => 'Texte discret', 'accent' => 'Accent', 'action' => 'Bouton principal',
            'action_text' => 'Texte du bouton', 'action_hover' => 'Bouton au survol',
            'action_active' => 'Bouton actif', 'border_color' => 'Bordure',
        ];
        $radii = [
            'card_radius' => 'Arrondi des cartes',
            'control_radius' => 'Arrondi des contrôles',
            'pill_radius' => 'Arrondi pill',
        ];
        ?>
        <div class="wrap faluss-admin">
            <div class="faluss-admin__hero">
                <span class="faluss-admin__eyebrow"><?php echo esc_html__('Faluss Platform', 'faluss-platform'); ?></span>
                <h1><?php echo esc_html__('Identité visuelle', 'faluss-platform'); ?></h1>
                <p><?php echo esc_html__('Les valeurs ci-dessous alimentent les variables CSS du site. Les widgets Elementor peuvent les surcharger localement.', 'faluss-platform'); ?></p>
            </div>
            <div class="faluss-admin__card">
                <form method="post" action="options.php">
                    <?php settings_fields('faluss_platform_theme'); ?>
                    <h2><?php echo esc_html__('Couleurs', 'faluss-platform'); ?></h2>
                    <div class="faluss-admin__fields">
                        <?php foreach ($colors as $key => $label): ?>
                            <label class="faluss-admin__field" for="faluss-theme-<?php echo esc_attr($key); ?>">
                                <span><?php echo esc_html($label); ?></span>
                                <input id="faluss-theme-<?php echo esc_attr($key); ?>" class="faluss-theme-color" name="<?php echo esc_attr(ThemeTokenSet::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($values[$key]); ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <h2><?php echo esc_html__('Formes et relief', 'faluss-platform'); ?></h2>
                    <div class="faluss-admin__fields">
                        <label class="faluss-admin__field" for="faluss-theme-border-opacity">
                            <span><?php echo esc_html__('Opacité de bordure (%)', 'faluss-platform'); ?></span>
                            <input id="faluss-theme-border-opacity" type="number" min="0" max="100" name="<?php echo esc_attr(ThemeTokenSet::OPTION); ?>[border_opacity]" value="<?php echo esc_attr($values['border_opacity']); ?>">
                        </label>
                        <label class="faluss-admin__field" for="faluss-theme-shadow">
                            <span><?php echo esc_html__('Ombre de carte', 'faluss-platform'); ?></span>
                            <select id="faluss-theme-shadow" name="<?php echo esc_attr(ThemeTokenSet::OPTION); ?>[shadow]">
                                <?php foreach (ThemeTokenSet::shadows() as $key => $shadow): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($values['shadow'], $key); ?>><?php echo esc_html($key); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php foreach ($radii as $key => $label): ?>
                            <label class="faluss-admin__field" for="faluss-theme-<?php echo esc_attr($key); ?>">
                                <span><?php echo esc_html($label); ?></span>
                                <input id="faluss-theme-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(ThemeTokenSet::OPTION); ?>[<?php echo esc_attr($key); ?>]" pattern="[0-9]{1,3}px" value="<?php echo esc_attr($values[$key]); ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php submit_button(__('Enregistrer les réglages', 'faluss-platform')); ?>
                </form>
                <form method="post" action="options.php" class="faluss-admin__reset">
                    <?php settings_fields('faluss_platform_theme'); ?>
                    <?php foreach (ThemeTokenSet::defaults() as $key => $value): ?>
                        <input type="hidden" name="<?php echo esc_attr(ThemeTokenSet::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
                    <?php endforeach; ?>
                    <button class="button" type="submit"><?php echo esc_html__('Restaurer les valeurs par défaut', 'faluss-platform'); ?></button>
                </form>
                <h2><?php echo esc_html__('Aperçu', 'faluss-platform'); ?></h2>
                <div class="faluss-theme-preview" style="<?php echo esc_attr(ThemeTokenSet::previewCss($values)); ?>">
                    <div class="faluss-theme-preview__card">
                        <strong><?php echo esc_html__('Une carte Faluss', 'faluss-platform'); ?></strong>
                        <p><?php echo esc_html__('Une surface calme, lisible et cohérente.', 'faluss-platform'); ?></p>
                        <button type="button"><?php echo esc_html__('Action principale', 'faluss-platform'); ?></button>
                        <button class="is-hover" type="button"><?php echo esc_html__('Survol', 'faluss-platform'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
