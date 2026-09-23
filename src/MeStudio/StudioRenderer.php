<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Link\LinkStudioContract;

final class StudioRenderer
{
    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $extensions
     */
    public static function studioExtension(array $state, array $extensions): string
    {
        $preferences = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        ob_start();
        ?>
        <section class="faluss-me-studio-v2" data-me-studio-v2-design aria-labelledby="faluss-me-studio-v2-title">
            <header class="faluss-me-studio-v2__heading">
                <p>STUDIO V2</p>
                <h2 id="faluss-me-studio-v2-title"><?php esc_html_e('Personnalisation atomique', 'faluss-platform'); ?></h2>
                <p><?php esc_html_e('Chaque réglage utilise la même carte que votre aperçu et votre profil public.', 'faluss-platform'); ?></p>
            </header>
            <div class="faluss-me-studio-v2__status" role="status" aria-live="polite"></div>
            <div class="faluss-me-studio-v2__grid">
                <?php self::choiceGroup('Structure', 'structure', ['simple' => 'Simple', 'atomic' => 'Atomique'], $preferences); ?>
                <?php self::choiceGroup('Texture des boutons', 'button_texture', ['grain' => 'Granulée', 'smooth' => 'Lisse', 'camo' => 'Camo'], $preferences); ?>
                <?php self::choiceGroup('Forme de la photo principale', 'avatar_shape', ['round' => 'Ronde', 'rounded' => 'Arrondie', 'square' => 'Carrée'], $preferences); ?>
                <?php self::choiceGroup('Effets de la photo principale', 'avatar_effect', ['none' => 'Aucun', 'border' => 'Bordure', 'shadow' => 'Ombre', 'both' => 'Les 2'], $preferences); ?>
                <?php self::choiceGroup('Taille de l’image de fond', 'wallpaper_size', ['compact' => 'Compacte', 'cover' => 'Couverture'], $preferences); ?>
                <?php self::choiceGroup('Effet de l’image de fond', 'wallpaper_effect', ['none' => 'Aucun', 'gradient' => 'Dégradé'], $preferences); ?>
                <?php self::choiceGroup('Disposition des liens', 'links_mode', ['neutral' => 'Liens neutres', 'image-grid' => 'Boutons avec images'], $preferences); ?>
                <?php self::choiceGroup('Largeur des liens', 'link_width', ['wide' => 'Large', 'compact' => 'Courte'], $preferences); ?>
                <?php self::choiceGroup('Style des réseaux', 'social_style', self::socialStyles(), $preferences); ?>
                <label class="faluss-me-studio-v2__color"><?php esc_html_e('Couleur uniforme des bulles', 'faluss-platform'); ?><input name="social_color" type="hidden" value="<?php echo esc_attr((string) ($preferences['social_color'] ?? '')); ?>"><input type="color" value="<?php echo esc_attr($preferences['social_color'] ?: '#ED4343'); ?>" data-me-studio-social-color><span><?php esc_html_e('La couleur du logo officiel reste inchangée.', 'faluss-platform'); ?></span></label>
            </div>
            <?php if ($extensions !== []) : ?>
                <section class="faluss-me-studio-v2__extensions" aria-labelledby="faluss-me-studio-extensions-title"><h3 id="faluss-me-studio-extensions-title"><?php esc_html_e('Modules disponibles', 'faluss-platform'); ?></h3><ul><?php foreach ($extensions as $extension) : ?><li><strong><?php echo esc_html($extension['id']); ?></strong><span><?php echo esc_html($extension['placement']); ?></span></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <button class="faluss-me-studio-v2__save" type="button" data-me-studio-save><?php esc_html_e('Enregistrer le design', 'faluss-platform'); ?></button>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $state */
    public static function onboarding(string $step, array $state): string
    {
        $preferences = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
        $steps = [
            'wizard_structure', 'wizard_atomic_warning', 'wizard_atomic_colors', 'wizard_atomic_buttons',
            'wizard_atomic_avatar_upload', 'wizard_atomic_avatar', 'wizard_atomic_wallpaper_upload',
            'wizard_atomic_wallpaper', 'wizard_atomic_networks',
        ];
        $index = array_search($step, $steps, true);
        $index = $index === false ? 0 : $index;
        $progress = ($index + 1) / count($steps);
        $withoutPreview = in_array($step, ['wizard_structure', 'wizard_atomic_warning', 'wizard_atomic_avatar_upload', 'wizard_atomic_wallpaper_upload'], true);
        $canSkip = in_array($step, ['wizard_atomic_wallpaper_upload', 'wizard_atomic_networks'], true);
        $back = plugins_url('assets/link/images/faluss-onboarding-header-back.svg', dirname(__DIR__, 2) . '/faluss-platform.php');
        $logo = plugins_url('assets/link/images/faluss-onboarding-header-logo.png', dirname(__DIR__, 2) . '/faluss-platform.php');
        ob_start();
        ?>
        <section class="faluss-me-onboarding-v2 faluss-me-onboarding-v2--<?php echo esc_attr(str_replace('wizard_', '', $step)); ?><?php echo $withoutPreview ? ' faluss-me-onboarding-v2--without-preview' : ''; ?>" data-me-studio-onboarding data-step="<?php echo esc_attr($step); ?>" data-aggregate-version="<?php echo esc_attr((string) ($state['version'] ?? '')); ?>" style="--fmo-progress:<?php echo esc_attr((string) $progress); ?>">
            <header class="faluss-me-onboarding-v2__topbar">
                <button type="button" data-me-onboarding-back aria-label="<?php esc_attr_e('Revenir à l’étape précédente', 'faluss-platform'); ?>"<?php echo $step === 'wizard_structure' ? ' hidden' : ''; ?>><img src="<?php echo esc_url($back); ?>" width="44" height="44" alt=""></button>
                <div class="faluss-me-onboarding-v2__progress" role="progressbar" aria-valuemin="1" aria-valuemax="<?php echo count($steps); ?>" aria-valuenow="<?php echo $index + 1; ?>"><span></span></div>
                <span class="faluss-me-onboarding-v2__logo"><img src="<?php echo esc_url($logo); ?>" width="35" height="35" alt="Faluss"></span>
            </header>
            <?php if (!$withoutPreview) : ?><aside class="faluss-me-onboarding-v2__preview" aria-label="<?php esc_attr_e('Aperçu de votre Faluss', 'faluss-platform'); ?>" data-me-preview><?php echo $state['preview_html']; ?></aside><?php endif; ?>
            <form class="faluss-me-onboarding-v2__panel" novalidate>
                <div class="faluss-me-onboarding-v2__status" role="status" aria-live="polite"></div>
                <div class="faluss-me-onboarding-v2__error" role="alert" hidden></div>
                <?php self::onboardingPanel($step, $state, $preferences, $profile); ?>
                <footer class="faluss-me-onboarding-v2__actions">
                    <?php if ($canSkip) : ?><button type="button" class="faluss-me-onboarding-v2__skip" data-me-onboarding-skip><?php esc_html_e('Passer', 'faluss-platform'); ?></button><?php endif; ?>
                    <button type="submit" class="faluss-me-onboarding-v2__continue"><?php echo esc_html($step === 'wizard_atomic_warning' ? 'J’ai compris' : 'Continuer'); ?></button>
                </footer>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $preferences
     * @param array<string, mixed> $profile
     */
    private static function onboardingPanel(string $step, array $state, array $preferences, array $profile): void
    {
        $palette = ['#000000', '#191919', '#737373', '#DDDDDD', '#FFFFFF', '#321752', '#350D0D', '#1A7061', '#A748B5'];
        if ($step === 'wizard_structure') : ?>
            <?php $structurePreviews = is_array($state['structure_previews'] ?? null) ? $state['structure_previews'] : []; ?>
            <div class="faluss-me-onboarding-v2__structure"><h1>Choisis la structure</h1><p>De multiples Faluss sont possibles, tu peux en changer plus tard</p><?php self::segments('structure', ['simple' => 'Simple', 'atomic' => 'Atomique'], $preferences['structure'] ?? 'simple'); ?><div class="faluss-me-onboarding-v2__structure-cards"><label><input type="radio" name="structure_card" value="simple" <?php checked(($preferences['structure'] ?? 'simple'), 'simple'); ?>><span class="faluss-me-onboarding-v2__structure-preview"><?php echo $structurePreviews['simple'] ?? $state['preview_html']; ?></span><strong>Faluss par défaut</strong><small>Une couleur de fond unie, des boutons simples, une photo de profil.</small></label><label><input type="radio" name="structure_card" value="atomic" <?php checked(($preferences['structure'] ?? ''), 'atomic'); ?>><span class="faluss-me-onboarding-v2__structure-preview faluss-me-onboarding-v2__structure-preview--atomic"><?php echo $structurePreviews['atomic'] ?? $state['preview_html']; ?></span><strong>Faluss amélioré ++</strong><small>Ajuste chaque élément qui composera ton link, pour un rendu unique.</small></label></div></div>
        <?php elseif ($step === 'wizard_atomic_warning') : ?>
            <div class="faluss-me-onboarding-v2__warning"><h1>Structure Atomique</h1><p>L’édition en mode atomique rallonge les étapes de création de votre Faluss.me, vous pouvez changer tout ce qui est visible et aller bien plus loin qu’ailleurs.</p><button type="button" data-me-onboarding-back>Retour</button></div>
        <?php elseif ($step === 'wizard_atomic_colors') : ?>
            <h1>Couleurs principales</h1><?php self::tabs('colors', ['background' => 'Arrière Plan', 'buttons' => 'Boutons']); ?><div data-me-tab-panel="background"><fieldset class="faluss-me-onboarding-v2__palette"><legend>Arrière Plan</legend><?php self::palette('page_background', (string) ($preferences['page_background'] ?? '#FFFDF5'), $palette); ?></fieldset></div><div data-me-tab-panel="buttons" hidden inert><fieldset class="faluss-me-onboarding-v2__palette"><legend>Boutons</legend><?php self::palette('button_color', (string) ($preferences['button_color'] ?? '#080808'), $palette); ?></fieldset></div>
        <?php elseif ($step === 'wizard_atomic_buttons') : ?>
            <h1>Les boutons</h1><?php self::tabs('buttons', ['shape' => 'Forme', 'texture' => 'Texture']); ?><div data-me-tab-panel="shape"><?php self::cards('link_style', ['outline' => 'Formel', 'solid' => 'Visuel', 'light' => 'Minutieux'], (string) ($preferences['link_style'] ?? 'solid')); ?></div><div data-me-tab-panel="texture" hidden inert><?php self::cards('button_texture', ['grain' => 'Granulée', 'smooth' => 'Lisse', 'camo' => 'Camo'], (string) ($preferences['button_texture'] ?? 'smooth')); ?></div>
        <?php elseif ($step === 'wizard_atomic_avatar_upload') : ?>
            <div class="faluss-me-onboarding-v2__upload"><h1>Choisis la photo qui te représentera</h1><p>Tu peux la changer quand tu veux dans le studio</p><input name="avatar_attachment_id" type="hidden" value="<?php echo (int) ($profile['avatar_attachment_id'] ?? 0); ?>"><label><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-me-upload="avatar"><span class="faluss-me-onboarding-v2__upload-area"><?php if (!empty($profile['avatar_attachment_id'])) { echo wp_get_attachment_image((int) $profile['avatar_attachment_id'], 'medium', false, ['alt' => '']); } else { ?><b>＋</b><strong>Importer l’image</strong><?php } ?></span><strong><?php echo esc_html((string) ($profile['display_name'] ?? 'Mon Faluss')); ?></strong></label></div>
        <?php elseif ($step === 'wizard_atomic_avatar') : ?>
            <h1>La photo principale</h1><?php self::tabs('avatar', ['shape' => 'Forme', 'effects' => 'Effets']); ?><div data-me-tab-panel="shape"><?php self::cards('avatar_shape', ['round' => 'Ronde', 'rounded' => 'Arrondie', 'square' => 'Carrée'], (string) ($preferences['avatar_shape'] ?? 'round')); ?></div><div data-me-tab-panel="effects" hidden inert><?php self::cards('avatar_effect', ['border' => 'Bordure', 'shadow' => 'Ombre', 'both' => 'Les 2'], (string) ($preferences['avatar_effect'] ?? 'border')); ?></div>
        <?php elseif ($step === 'wizard_atomic_wallpaper_upload') : ?>
            <div class="faluss-me-onboarding-v2__upload"><h1>Ajoute une photo de couverture</h1><p>Un effet de défilement génial s’activera</p><input name="cover_attachment_id" type="hidden" value="<?php echo (int) ($preferences['cover_attachment_id'] ?? 0); ?>"><label><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-me-upload="cover"><span class="faluss-me-onboarding-v2__upload-area"><?php if (!empty($preferences['cover_url'])) { ?><img src="<?php echo esc_url($preferences['cover_url']); ?>" alt=""><?php } else { ?><b>＋</b><strong>Importer l’image</strong><?php } ?></span><strong><?php echo esc_html((string) ($profile['display_name'] ?? 'Mon Faluss')); ?></strong></label></div>
        <?php elseif ($step === 'wizard_atomic_wallpaper') : ?>
            <h1>L’image de fond</h1><?php self::tabs('wallpaper', ['size' => 'Taille', 'effect' => 'Effet']); ?><div data-me-tab-panel="size"><?php self::cards('wallpaper_size', ['compact' => 'Compacte', 'cover' => 'Couverture'], (string) ($preferences['wallpaper_size'] ?? 'compact')); ?></div><div data-me-tab-panel="effect" hidden inert><?php self::cards('wallpaper_effect', ['none' => 'Aucun', 'gradient' => 'Dégradé'], (string) ($preferences['wallpaper_effect'] ?? 'gradient')); ?></div>
        <?php elseif ($step === 'wizard_atomic_networks') : ?>
            <h1>Les réseaux</h1><?php self::tabs('networks', ['style' => 'Style', 'colors' => 'Couleurs']); ?><div data-me-tab-panel="style"><?php self::socialStyleCards((string) ($preferences['social_style'] ?? 'brand-light')); ?><p class="faluss-me-onboarding-v2__selection">Sélection <strong data-me-social-selection><?php echo esc_html(self::socialStyles()[$preferences['social_style'] ?? 'brand-light'] ?? 'Marques claires'); ?></strong></p></div><div data-me-tab-panel="colors" hidden inert><fieldset class="faluss-me-onboarding-v2__palette"><legend>Couleurs</legend><?php self::palette('social_color', (string) ($preferences['social_color'] ?? ''), $palette); ?></fieldset></div>
        <?php endif;
    }

    /**
     * @param array<string, string> $choices
     * @param array<string, mixed> $preferences
     */
    private static function choiceGroup(string $legend, string $name, array $choices, array $preferences): void
    {
        ?><fieldset class="faluss-me-studio-v2__choices"><legend><?php echo esc_html($legend); ?></legend><?php foreach ($choices as $value => $label) : ?><label><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked(($preferences[$name] ?? ''), $value); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?></fieldset><?php
    }

    /** @param array<string, string> $choices */
    private static function segments(string $name, array $choices, string $selected): void
    {
        ?><fieldset class="faluss-me-onboarding-v2__segments"><legend class="screen-reader-text"><?php echo esc_html($name); ?></legend><?php foreach ($choices as $value => $label) : ?><label><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($selected, $value); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?></fieldset><?php
    }

    /** @param array<string, string> $tabs */
    private static function tabs(string $group, array $tabs): void
    {
        ?><div class="faluss-me-onboarding-v2__tabs" role="tablist" aria-label="<?php echo esc_attr($group); ?>"><?php $first = true; foreach ($tabs as $value => $label) : ?><button type="button" role="tab" data-me-tab="<?php echo esc_attr($value); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html($label); ?></button><?php $first = false; endforeach; ?></div><?php
    }

    /** @param array<string, string> $choices */
    private static function cards(string $name, array $choices, string $selected, string $class = ''): void
    {
        ?><fieldset class="faluss-me-onboarding-v2__cards <?php echo esc_attr($class); ?>"><legend class="screen-reader-text"><?php echo esc_html($name); ?></legend><?php foreach ($choices as $value => $label) : ?><label class="faluss-me-onboarding-v2__card faluss-me-onboarding-v2__card--<?php echo esc_attr($value); ?>"><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($selected, $value); ?>><span aria-hidden="true"></span><strong><?php echo esc_html($label); ?></strong></label><?php endforeach; ?></fieldset><?php
    }

    /** @param list<string> $palette */
    private static function palette(string $name, string $selected, array $palette): void
    {
        $selected = strtoupper($selected);
        $custom = preg_match('/^#[0-9A-F]{6}$/D', $selected) === 1 ? $selected : '#ED4343';
        $preset = in_array($selected, $palette, true);
        foreach ($palette as $color) : ?><label style="--fmo-color:<?php echo esc_attr($color); ?>"><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($color); ?>" <?php checked($selected, $color); ?>><span aria-hidden="true"></span><b class="screen-reader-text"><?php echo esc_html($color); ?></b></label><?php endforeach; ?><label class="faluss-me-onboarding-v2__custom-color"><?php if (!$preset && $selected !== '') : ?><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($selected); ?>" data-custom-radio="<?php echo esc_attr($name); ?>" checked hidden><?php endif; ?><input type="color" name="<?php echo esc_attr($name); ?>_custom" value="<?php echo esc_attr($custom); ?>" data-color-target="<?php echo esc_attr($name); ?>"><span aria-hidden="true">🎨</span><b class="screen-reader-text"><?php esc_html_e('Couleur personnalisée', 'faluss-platform'); ?></b></label><?php
    }

    private static function socialStyleCards(string $selected): void
    {
        $catalog = array_filter(LinkStudioContract::socialCatalog(), static fn (array $settings): bool => !empty($settings['active']));
        $catalog = array_slice($catalog, 0, 6, true);
        ?><fieldset class="faluss-me-onboarding-v2__cards faluss-me-onboarding-v2__social-styles"><legend class="screen-reader-text">Style des réseaux</legend><?php foreach (self::socialStyles() as $value => $label) : $assetType = str_starts_with($value, 'brand-') ? 'full' : 'outline'; ?><label class="faluss-me-onboarding-v2__card faluss-me-onboarding-v2__social-style faluss-me-onboarding-v2__social-style--<?php echo esc_attr($value); ?>"><input type="radio" name="social_style" value="<?php echo esc_attr($value); ?>" data-social-style-label="<?php echo esc_attr($label); ?>" <?php checked($selected, $value); ?>><span aria-hidden="true"><?php foreach ($catalog as $network => $settings) : $asset = is_array($settings[$assetType] ?? null) ? $settings[$assetType] : []; $src = is_string($asset['src'] ?? null) ? $asset['src'] : ''; ?><i><?php if ($src !== '') : ?><img src="<?php echo esc_url($src); ?>"<?php if (!empty($asset['srcset'])) : ?> srcset="<?php echo esc_attr($asset['srcset']); ?>"<?php endif; ?><?php if (!empty($asset['sizes'])) : ?> sizes="<?php echo esc_attr($asset['sizes']); ?>"<?php endif; ?> alt=""><?php else : ?><b><?php echo esc_html(strtoupper(substr((string) $network, 0, 1))); ?></b><?php endif; ?></i><?php endforeach; ?></span><strong><?php echo esc_html($label); ?></strong></label><?php endforeach; ?></fieldset><?php
    }

    /** @return array<string, string> */
    private static function socialStyles(): array
    {
        return [
            'brand-light' => 'Marques claires', 'brand-dark' => 'Marques sombres', 'outline-dark' => 'Contours noirs',
            'outline-light' => 'Contours blancs', 'mono-light' => 'Monochrome clair', 'mono-dark' => 'Monochrome sombre',
            'tint-pink' => 'Teinte rose', 'tint-mint' => 'Teinte menthe', 'solid-custom' => 'Couleur unie',
        ];
    }
}
