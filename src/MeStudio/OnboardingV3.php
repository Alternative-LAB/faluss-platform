<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Identity\IdentityContract;
use Faluss\Platform\Link\LinkStudioContract;

/** One opt-in route and one cursor graph for both V3 modes. */
final class OnboardingV3
{
    private const COMMON = ['v3_mode', 'v3_identity', 'v3_socials', 'v3_links'];
    private const ATOMIC = ['v3_colors', 'v3_buttons', 'v3_avatar', 'v3_wallpaper', 'v3_name', 'v3_network_style'];

    public static function enabled(): bool
    {
        return defined('FALUSS_PLATFORM_ONBOARDING_V3') && constant('FALUSS_PLATFORM_ONBOARDING_V3') === true;
    }

    public static function register(): void
    {
        add_action('wp_ajax_faluss_studio_v3_manage', [self::class, 'manageStudio']);
        add_action('wp_ajax_faluss_studio_v3_upload_content', [self::class, 'uploadContent']);
        add_action('wp_ajax_faluss_studio_v3_save', [self::class, 'saveStudio']);
        add_action('wp_ajax_faluss_onboarding_v3_transition', [self::class, 'transition']);
        add_action('wp_ajax_faluss_onboarding_v3_identity_draft', [self::class, 'identityDraft']);
        add_action('wp_ajax_faluss_onboarding_v3_preview', [self::class, 'preview']);
        add_action('wp_ajax_faluss_onboarding_v3_publish', [self::class, 'publish']);
        add_action('wp_ajax_faluss_onboarding_v3_upload_avatar', [self::class, 'uploadAvatar']);
        add_action('wp_ajax_faluss_onboarding_v3_upload_cover', [self::class, 'uploadCover']);
    }

    /** @return list<string> */
    public static function sequence(string $mode): array
    {
        return array_merge(self::COMMON, $mode === 'atomic' ? self::ATOMIC : [], ['v3_review']);
    }

    /** @param array<string, mixed> $preferences */
    public static function mode(string $cursor, array $preferences): string
    {
        if (str_starts_with($cursor, 'wizard_atomic_') || str_ends_with($cursor, '_atomic')) { return 'atomic'; }
        if (str_ends_with($cursor, '_simple')) { return 'simple'; }
        return ($preferences['structure'] ?? 'simple') === 'atomic' ? 'atomic' : 'simple';
    }

    /** @param array<string, mixed> $profile */
    public static function step(string $cursor, string $mode, array $profile = []): string
    {
        if (($profile['publication_status'] ?? '') === 'published') {
            return 'v3_success';
        }
        if (in_array($cursor, self::sequence($mode), true)) {
            return $cursor;
        }
        if (str_starts_with($cursor, 'v3_identity_')) {
            return 'v3_identity';
        }
        if (str_starts_with($cursor, 'v3_mode_')) {
            return 'v3_mode';
        }
        $historical = [
            'choice' => 'v3_mode', 'identifier' => 'v3_identity', 'studio' => 'v3_mode',
            'wizard_structure' => 'v3_mode', 'wizard_name' => 'v3_identity', 'wizard_avatar' => 'v3_identity',
            'wizard_header' => 'v3_socials', 'wizard_socials' => 'v3_socials', 'wizard_links' => 'v3_links',
            'wizard_atomic_warning' => 'v3_colors', 'wizard_atomic_colors' => 'v3_colors',
            'wizard_atomic_buttons' => 'v3_buttons', 'wizard_atomic_avatar_upload' => 'v3_avatar',
            'wizard_atomic_avatar' => 'v3_avatar', 'wizard_atomic_wallpaper_upload' => 'v3_wallpaper',
            'wizard_atomic_wallpaper' => 'v3_wallpaper', 'wizard_atomic_networks' => 'v3_network_style',
            'wizard_finish' => 'v3_review',
        ];
        if ($cursor === 'wizard_style') {
            return $mode === 'atomic' ? 'v3_colors' : 'v3_socials';
        }
        $step = $historical[$cursor] ?? 'v3_mode';
        return in_array($step, self::sequence($mode), true) ? $step : 'v3_review';
    }

    public static function render(bool $studio = false): string
    {
        if ($studio) { return StudioV3::render(); }
        if (!is_user_logged_in()) { return self::login(); }
        MeStudioAssets::enqueueOnboardingV3();
        $context = IdentityContract::onboardingV3Context();
        $state = LinkStudioContract::state();
        if (empty($context['available']) || is_wp_error($state)) {
            return self::unavailable(is_wp_error($state) ? (string) $state->get_error_code() : 'identity_unavailable');
        }
        $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
        $preferences = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        $mode = self::mode((string) ($context['step'] ?? ''), $preferences);
        $step = self::step((string) ($context['step'] ?? ''), $mode, $profile);
        $steps = self::sequence($mode);
        $index = array_search($step, $steps, true);
        $draft = in_array($step, ['v3_mode', 'v3_identity'], true) ? IdentityContract::onboardingV3IdentityDraft() : [];
        if ((string) ($profile['public_slug'] ?? '') !== '') { unset($draft['public_slug']); }
        $preview = LinkStudioContract::previewOnboardingV3($draft);
        $previewHtml = in_array($step, ['v3_review', 'v3_success'], true)
            ? (string) ($state['preview_html'] ?? '')
            : (!is_wp_error($preview) && is_string($preview['preview_html'] ?? null)
                ? $preview['preview_html'] : (string) ($state['preview_html'] ?? ''));
        $slug = (string) (($profile['public_slug'] ?? '') ?: ($draft['public_slug'] ?? ''));
        $controlsState = $state;
        $controlsState['studio'] = false;
        $controlsState['profile'] = array_replace($profile, $draft);
        $controlsState['canonical_slug'] = (string) ($profile['public_slug'] ?? '');
        if ($step === 'v3_success') { return self::confirmation($slug); }
        ob_start();
        ?>
        <section class="faluss-onboarding-v3" data-faluss-onboarding-v3 data-studio="false" data-step="<?php echo esc_attr($step); ?>" data-mode="<?php echo esc_attr($mode); ?>" data-version="<?php echo esc_attr((string) ($state['version'] ?? '')); ?>">
            <header class="faluss-onboarding-v3__header">
                <button class="faluss-onboarding-v3__back" type="button" data-v3-back aria-label="Retour" <?php echo $step === 'v3_mode' || $step === 'v3_success' ? 'hidden' : ''; ?>>←</button>

                <div class="faluss-onboarding-v3__progress" role="progressbar" aria-label="Progression" aria-valuemin="1" aria-valuemax="<?php echo count($steps); ?>" aria-valuenow="<?php echo $index === false ? count($steps) : $index + 1; ?>">
                    <?php foreach ($steps as $number => $_) : ?><span <?php echo $index !== false && $number <= $index ? 'class="is-complete"' : ''; ?>></span><?php endforeach; ?>
                </div>
                <img class="faluss-onboarding-v3__logo" src="<?php echo esc_url(plugins_url('assets/link/images/faluss-onboarding-header-logo.png', dirname(__DIR__, 2) . '/faluss-platform.php')); ?>" alt="Faluss Me">
            </header>
            <div class="faluss-onboarding-v3__stage" aria-label="Aperçu de votre Faluss">
                <div class="faluss-onboarding-v3__phone"><div class="faluss-onboarding-v3__phone-screen" data-v3-preview><?php echo $previewHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link returns escaped canonical card markup. ?></div></div>
            </div>
            <form class="faluss-onboarding-v3__panel" data-v3-panel novalidate>
                <button class="faluss-onboarding-v3__grabber" type="button" data-v3-grabber aria-label="Agrandir le panneau" aria-expanded="false"><span></span></button>
                <div class="faluss-onboarding-v3__scroll" data-v3-scroll>
                    <?php self::controls($step, $mode, $controlsState, $slug); ?>
                    <p class="faluss-onboarding-v3__error" data-v3-error role="alert" hidden></p>
                    <p class="faluss-onboarding-v3__status" data-v3-status role="status" aria-live="polite"></p>
                </div>
                <footer class="faluss-onboarding-v3__actions">
                    <?php if (in_array($step, ['v3_socials', 'v3_links', 'v3_avatar', 'v3_wallpaper', 'v3_network_style'], true)) : ?><button type="button" class="faluss-onboarding-v3__skip" data-v3-skip>Passer</button><?php endif; ?>
                    <?php if ($step !== 'v3_success') : ?><button type="submit" class="faluss-onboarding-v3__primary" data-v3-primary><?php echo esc_html($step === 'v3_identity' && empty($controlsState['canonical_slug']) ? 'Revendiquer mon identifiant' : ($step === 'v3_review' ? 'Publier mon Faluss' : 'Continuer')); ?> <span aria-hidden="true">→</span></button><?php endif; ?>
                </footer>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $state */
    public static function controls(string $step, string $mode, array $state, string $slug): void
    {
        $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
        $prefs = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        $titles = [
            'v3_mode' => !empty($state['studio']) ? 'Structure de ta carte' : 'Choisis ton rythme', 'v3_identity' => (empty($state['canonical_slug']) ? 'Revendique ton identifiant' : 'Ton identité'), 'v3_socials' => 'Tes réseaux',
            'v3_links' => 'Tes liens', 'v3_colors' => 'Couleurs', 'v3_buttons' => 'Boutons',
            'v3_avatar' => 'Photo de profil', 'v3_wallpaper' => 'Image de fond', 'v3_name' => 'Ton nom',
            'v3_network_style' => 'Tes réseaux', 'v3_review' => 'Dernier regard',
            'v3_success' => 'Ton Faluss est en ligne',
        ];
        ?><h1 tabindex="-1"><?php echo esc_html($titles[$step] ?? 'Ton Faluss'); ?></h1><?php
        switch ($step) {
            case 'v3_mode':
                ?><p>Deux façons de créer ton link.</p><div class="faluss-onboarding-v3__mode-cards">
                <?php self::choices('structure', ['simple' => 'Simple · 2 min', 'atomic' => 'Atomique · à ton image'], $mode); ?>
                </div><p class="faluss-onboarding-v3__hint">Simple : l’essentiel, tout de suite. Atomique : personnalise chaque détail après tes liens.</p><?php
                break;
            case 'v3_identity':
                ?><p><?php echo empty($state['canonical_slug']) ? 'Choisis ton adresse Faluss avant de personnaliser ta carte. Sa disponibilité est vérifiée lors de la réservation.' : 'Ton identifiant est réservé. Tu peux modifier ton nom et ta photo.'; ?></p>
                <label>Nom affiché<input name="display_name" maxlength="80" required value="<?php echo esc_attr((string) ($profile['display_name'] ?? '')); ?>"></label>
                <label>@identifiant<input name="public_slug" maxlength="40" pattern="[a-z0-9][a-z0-9-]{1,39}" required value="<?php echo esc_attr($slug); ?>" <?php echo (string) ($state['canonical_slug'] ?? '') !== '' ? 'readonly' : ''; ?>></label>
                <label>Photo de profil · facultative<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-v3-upload="avatar"></label>
                <input type="hidden" name="avatar_attachment_id" value="<?php echo (int) ($profile['avatar_attachment_id'] ?? 0); ?>">
                <p class="faluss-onboarding-v3__hint">L’identifiant devient permanent dès sa réservation.</p><?php
                break;
            case 'v3_socials':
                ?><p>Ajoute les réseaux que tu souhaites montrer.</p><div class="faluss-onboarding-v3__network-list" data-v3-networks><?php
                $saved = [];
                foreach ((array) ($prefs['social_links'] ?? []) as $social) {
                    if (is_array($social)) { $saved[$social['network'] ?? ''] = (string) ($social['url'] ?? ''); }
                }
                foreach (LinkStudioContract::socialCatalog() as $network => $entry) {
                    if (empty($entry['active'])) { continue; }
                    $asset = (string) (($entry['full']['src'] ?? '') ?: ($entry['outline']['src'] ?? ''));
                    ?><div class="faluss-onboarding-v3__network" data-network="<?php echo esc_attr((string) $network); ?>">
                        <label><input type="checkbox" data-v3-network-choice <?php checked(isset($saved[$network])); ?>><span class="faluss-onboarding-v3__network-icon"><?php if ($asset !== '') : ?><img src="<?php echo esc_url($asset); ?>" alt=""><?php else : ?><span aria-hidden="true">!</span><?php endif; ?></span><?php echo esc_html((string) ($entry['label'] ?? $network)); ?></label>
                        <?php if ($asset === '') : ?><small>Média absent dans le catalogue Link</small><?php endif; ?>
                        <input type="url" data-v3-network-url aria-label="URL <?php echo esc_attr((string) ($entry['label'] ?? $network)); ?>" placeholder="https://" value="<?php echo esc_attr($saved[$network] ?? ''); ?>" <?php echo isset($saved[$network]) ? '' : 'hidden'; ?>>
                    </div><?php
                }
                ?></div><?php
                break;
            case 'v3_links':
                ?><p>Ajoute, modifie et ordonne les liens qui comptent.</p><div data-v3-links><?php
                foreach ((array) ($state['blocks'] ?? []) as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'link') { self::linkRow($block); }
                }
                ?></div><button type="button" class="faluss-onboarding-v3__secondary" data-v3-add-link>＋ Ajouter un lien</button><?php
                break;
            case 'v3_colors':
                self::tabs('colors', ['background' => 'Fond', 'buttons' => 'Boutons']);
                ?><div data-v3-tab-panel="background"><?php self::colors('page_background', (string) ($prefs['page_background'] ?? '#FFFDF5')); ?></div>
                <div data-v3-tab-panel="buttons" hidden inert><?php self::colors('button_color', (string) ($prefs['button_color'] ?? '#080808')); ?></div><?php
                break;
            case 'v3_buttons':
                self::tabs('buttons', ['shape' => 'Forme', 'texture' => 'Texture']);
                ?><div data-v3-tab-panel="shape"><?php self::choices('link_style', ['outline' => 'Formel', 'solid' => 'Visuel', 'light' => 'Minutieux'], (string) ($prefs['link_style'] ?? 'solid')); ?></div>
                <div data-v3-tab-panel="texture" hidden inert><?php self::choices('button_texture', ['smooth' => 'Lisse', 'grain' => 'Granulée', 'camo' => 'Camo'], (string) ($prefs['button_texture'] ?? 'smooth')); ?></div><?php
                break;
            case 'v3_avatar':
                ?><?php if (!empty($profile['avatar_attachment_id'])) { echo wp_get_attachment_image((int) $profile['avatar_attachment_id'], 'thumbnail', false, ['class' => 'faluss-onboarding-v3__retained-avatar', 'alt' => 'Photo retenue']); } ?><label>Remplacer la photo · facultatif<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-v3-upload="avatar"></label><input type="hidden" name="avatar_attachment_id" value="<?php echo (int) ($profile['avatar_attachment_id'] ?? 0); ?>">
                <?php self::tabs('avatar', ['shape' => 'Forme', 'effects' => 'Effets']); ?>
                <div data-v3-tab-panel="shape"><?php self::choices('avatar_shape', ['round' => 'Rond', 'rounded' => 'Arrondi', 'square' => 'Carré'], (string) ($prefs['avatar_shape'] ?? 'round')); ?></div>
                <div data-v3-tab-panel="effects" hidden inert><?php self::choices('avatar_effect', ['none' => 'Aucun', 'border' => 'Bordure', 'shadow' => 'Ombre', 'both' => 'Les deux'], (string) ($prefs['avatar_effect'] ?? 'border')); ?></div><?php
                break;
            case 'v3_wallpaper':
                ?><label>Importer une image · facultatif<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-v3-upload="cover"></label><input type="hidden" name="cover_attachment_id" value="<?php echo (int) ($prefs['cover_attachment_id'] ?? 0); ?>">
                <?php self::tabs('wallpaper', ['size' => 'Taille', 'effect' => 'Effet']); ?>
                <div data-v3-tab-panel="size"><?php self::choices('wallpaper_size', ['compact' => 'Compacte', 'cover' => 'Couverture'], (string) ($prefs['wallpaper_size'] ?? 'compact')); ?></div>
                <div data-v3-tab-panel="effect" hidden inert><?php self::choices('wallpaper_effect', ['none' => 'Aucun', 'gradient' => 'Dégradé'], (string) ($prefs['wallpaper_effect'] ?? 'gradient')); ?></div><?php
                break;
            case 'v3_name':
                $options = LinkStudioContract::nameOptions();
                ?><label>Typographie<select name="name_font"><?php foreach (['outfit', 'system', 'serif'] as $key) : if (!isset($options['fonts'][$key])) { continue; } ?><option value="<?php echo esc_attr($key); ?>" <?php selected((string) ($prefs['name_font'] ?? 'outfit'), $key); ?>><?php echo esc_html($options['fonts'][$key]['label']); ?></option><?php endforeach; ?></select></label>
                <fieldset><legend>Graisse</legend><?php self::choices('name_weight', ['400' => 'Normal · 400', '700' => 'Fort · 700'], in_array((string) ($prefs['name_weight'] ?? ''), ['400', '700'], true) ? (string) $prefs['name_weight'] : '700'); ?></fieldset>
                <fieldset><legend>Couleur</legend><?php self::choices('name_color', (array) ($options['colors'] ?? []), (string) ($prefs['name_color'] ?? '#000000')); ?></fieldset><?php
                break;
            case 'v3_network_style':
                self::tabs('network-style', ['style' => 'Style', 'color' => 'Couleur']);
                ?><div data-v3-tab-panel="style"><?php self::choices('social_style', ['brand-light' => 'Marques claires', 'brand-dark' => 'Marques sombres', 'outline-dark' => 'Contours noirs', 'outline-light' => 'Contours blancs', 'mono-dark' => 'Monochrome sombre', 'mono-light' => 'Monochrome clair'], (string) ($prefs['social_style'] ?? 'brand-light')); ?></div>
                <div data-v3-tab-panel="color" hidden inert><?php self::colors('social_color', (string) ($prefs['social_color'] ?? '')); ?></div><?php
                break;
            case 'v3_review':
                ?><p>Vérifie ton aperçu avant de publier. Tu pourras encore modifier ta carte dans le Studio.</p>
                <ul class="faluss-onboarding-v3__review"><li>Identité : <?php echo esc_html((string) ($profile['display_name'] ?? '')); ?> — @<?php echo esc_html($slug); ?></li><li>Réseaux : <?php echo count((array) ($prefs['social_links'] ?? [])); ?></li><li>Liens : <?php echo count(array_filter((array) ($state['blocks'] ?? []), static fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'link')); ?></li></ul><?php
                break;
            case 'v3_success':
                ?><p>Ton lien est prêt à être partagé.</p><p><strong>@<?php echo esc_html($slug); ?></strong></p><div class="faluss-onboarding-v3__success-actions"><a class="faluss-onboarding-v3__primary" href="<?php echo esc_url(home_url('/' . $slug . '/')); ?>">Voir mon Faluss</a><a class="faluss-onboarding-v3__secondary" href="<?php echo esc_url(home_url('/mon-faluss/')); ?>">Ouvrir le Studio</a></div><?php
                break;
        }
    }

    /** @param array<string, mixed> $block */
    private static function linkRow(array $block): void
    {
        ?><div class="faluss-onboarding-v3__link" data-v3-link data-id="<?php echo esc_attr((string) ($block['block_id'] ?? '')); ?>">
            <label>Libellé<input data-v3-link-label maxlength="80" value="<?php echo esc_attr((string) ($block['label'] ?? '')); ?>"></label>
            <label>URL HTTPS<input type="url" data-v3-link-url maxlength="2048" placeholder="https://" value="<?php echo esc_attr((string) ($block['url'] ?? '')); ?>"></label>
            <div><button type="button" data-v3-move="up" aria-label="Monter ce lien">↑</button><button type="button" data-v3-move="down" aria-label="Descendre ce lien">↓</button></div>
        </div><?php
    }

    /** @param array<string, string> $tabs */
    private static function tabs(string $group, array $tabs): void
    {
        ?><div class="faluss-onboarding-v3__tabs" role="tablist" aria-label="<?php echo esc_attr($group); ?>"><?php $first = true; foreach ($tabs as $key => $label) : ?><button type="button" role="tab" data-v3-tab="<?php echo esc_attr($key); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html($label); ?></button><?php $first = false; endforeach; ?></div><?php
    }

    /** @param array<int|string, string> $choices */
    private static function choices(string $name, array $choices, string $selected): void
    {
        ?><div class="faluss-onboarding-v3__choices"><?php foreach ($choices as $value => $label) : ?><label><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" <?php checked($selected, (string) $value); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?></div><?php
    }

    private static function colors(string $name, string $selected): void
    {
        $nullable = $name === 'social_color';
        $palette = ['#FFFFFF', '#E5E5E5', '#DED4E4', '#FFE1E5', '#FF515B', '#FFD3BD', '#ADB8A8', '#292929'];
        ?><div class="faluss-onboarding-v3__colors"><?php if ($nullable) : ?><label><input type="radio" name="social_color" value="" <?php checked($selected, ''); ?>><span style="--v3-swatch:#fff">Auto</span></label><?php endif; ?><?php foreach ($palette as $color) : ?><label><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($color); ?>" <?php checked(strtoupper($selected), $color); ?>><span style="--v3-swatch:<?php echo esc_attr($color); ?>"></span></label><?php endforeach; ?><label class="faluss-onboarding-v3__custom">Autre<input type="color" data-v3-color="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($selected !== '' ? $selected : '#080808'); ?>" <?php echo $selected !== '' && !in_array(strtoupper($selected), $palette, true) ? 'data-selected="true"' : ''; ?>></label></div><?php
    }

    public static function login(): string
    {
        $url = IdentityContract::loginUrl('create_card', home_url('/mon-faluss/'));
        return '<section class="faluss-onboarding-v3__unavailable"><h1>Retrouve ton Faluss</h1><p>Connecte-toi pour reprendre ta création ou ouvrir ton Studio.</p><a href="' . esc_url($url ?: home_url('/login/')) . '">Se connecter</a></section>';
    }

    public static function unavailable(string $code): string
    {
        return '<section class="faluss-onboarding-v3__unavailable" role="alert"><h1>Création temporairement indisponible</h1><p>Votre brouillon est conservé. Réessayez dans un instant.</p><code>' . esc_html($code) . '</code><a href="">Réessayer</a></section>';
    }

    private static function confirmation(string $slug): string
    {
        ob_start(); ?>
        <section class="faluss-v3-confirmation">
            <h1>Ton Faluss est en ligne</h1><p>Ton lien est prêt à être partagé.</p>
            <p><a href="<?php echo esc_url(home_url('/' . $slug . '/')); ?>"><?php echo esc_html(home_url('/' . $slug . '/')); ?></a></p>
            <div class="faluss-onboarding-v3__success-actions"><a class="faluss-onboarding-v3__primary" href="<?php echo esc_url(home_url('/' . $slug . '/')); ?>">Voir mon Faluss</a><a class="faluss-onboarding-v3__secondary" href="<?php echo esc_url(home_url('/mon-faluss/')); ?>">Ouvrir le Studio</a></div>
        </section>
        <?php return (string) ob_get_clean();
    }

    public static function manageStudio(): void
    {
        self::verify('faluss_studio_v3_manage');
        // The Link contract rejects unknown mutation names and fields. Never accept a subject ID.
        $request = self::postedFields();
        $request['mutation'] = self::text('mutation');
        $request['aggregate_version'] = self::text('version');
        $result = LinkStudioContract::mutate(wp_slash($request));
        if (empty($result['ok'])) { self::failure((string) ($result['code'] ?? 'save_failed'), (int) ($result['status'] ?? 422)); }
        wp_send_json_success($result);
    }

    public static function uploadContent(): void
    {
        self::verify('faluss_studio_v3_upload_content');
        LinkStudioContract::uploadImage('content_image', 'faluss_studio_v3_upload_content');
    }

    public static function saveStudio(): void
    {
        self::verify('faluss_studio_v3_save');
        $step = self::text('step');
        if (!in_array($step, array_merge(self::COMMON, self::ATOMIC), true)) { self::failure('invalid_step', 422); }
        $fields = self::postedFields();
        // Identity alone reserves the immutable slug during onboarding.
        if ($step === 'v3_identity') { unset($fields['public_slug']); }
        $result = LinkStudioContract::saveStudioV3Section($step, $fields, self::text('version'));
        if (empty($result['ok'])) { self::failure((string) ($result['code'] ?? 'save_failed'), (int) ($result['status'] ?? 422)); }
        wp_send_json_success($result);
    }

    public static function transition(): void
    {
        self::verify('faluss_onboarding_v3_transition');
        $context = IdentityContract::onboardingV3Context();
        $state = LinkStudioContract::state();
        if (empty($context['available']) || is_wp_error($state)) { self::failure('unavailable', 503); }
        $mode = self::mode((string) ($context['step'] ?? ''), (array) ($state['preferences'] ?? []));
        $step = self::step((string) ($context['step'] ?? ''), $mode, (array) ($state['profile'] ?? []));
        if ($step !== self::text('step') || $step === 'v3_success' || !hash_equals((string) ($state['version'] ?? ''), self::text('version'))) { self::failure('stale_version', 409); }
        $direction = self::text('direction');
        if (!in_array($direction, ['next', 'back', 'skip'], true)) { self::failure('invalid_direction', 422); }
        $fields = self::postedFields();
        if ($step === 'v3_mode') {
            $choice = (string) ($fields['structure'] ?? '');
            if ($direction !== 'next' || !IdentityContract::beginOnboardingV3($choice)) { self::failure('invalid_mode', 422); }
            wp_send_json_success(['step' => 'v3_identity']);
        }
        if ($step === 'v3_identity' && $direction === 'back') {
            if (!IdentityContract::saveOnboardingV3IdentityDraft($fields)) { self::failure('save_failed', 422); }
            if (!IdentityContract::setOnboardingV3Cursor('v3_mode_' . $mode)) { self::failure('save_failed', 422); }
            wp_send_json_success(['step' => 'v3_mode']);
        }
        $sequence = self::sequence($mode);
        $index = array_search($step, $sequence, true);
        $target = $direction === 'back' ? ($sequence[$index - 1] ?? 'v3_mode') : ($sequence[$index + 1] ?? 'v3_review');
        if ($step === 'v3_identity') {
            $slug = (string) ($fields['public_slug'] ?? '');
            $name = trim((string) ($fields['display_name'] ?? ''));
            if ($name === '' || $slug === '') { self::failure('invalid_identity', 422); }
            $claim = IdentityContract::reserveOnboardingV3Slug($slug);
            if ($claim !== 'claimed') { self::failure('slug_' . $claim, 422); }
            $state = LinkStudioContract::state();
            if (is_wp_error($state)) { self::failure('unavailable', 503); }
            $fields = ['display_name' => $name, 'avatar_attachment_id' => (string) ($fields['avatar_attachment_id'] ?? '0'), 'structure' => $mode];
            $step = 'v3_identity_' . $mode;
        } else {
            $fields = self::contractFields($step, $fields, $state, $direction === 'skip');
        }
        $result = LinkStudioContract::saveOnboardingV3Step($step, $target, $fields, (string) ($state['version'] ?? ''), $mode);
        if (empty($result['ok'])) { self::failure((string) ($result['code'] ?? 'save_failed'), (int) ($result['status'] ?? 422)); }
        if (str_starts_with($step, 'v3_identity_')) { IdentityContract::clearOnboardingV3IdentityDraft(); }
        wp_send_json_success(['step' => $target, 'version' => $result['state']['version'] ?? '']);
    }

    public static function identityDraft(): void
    {
        self::verify('faluss_onboarding_v3_identity_draft');
        $context = IdentityContract::onboardingV3Context();
        $state = LinkStudioContract::state();
        if (empty($context['available']) || is_wp_error($state)) { self::failure('unavailable', 503); }
        $mode = str_ends_with((string) ($context['step'] ?? ''), '_atomic') ? 'atomic' : 'simple';
        if (self::step((string) ($context['step'] ?? ''), $mode, (array) ($state['profile'] ?? [])) !== 'v3_identity'
            || !hash_equals((string) ($state['version'] ?? ''), self::text('version'))) { self::failure('stale_version', 409); }
        if (!IdentityContract::saveOnboardingV3IdentityDraft(self::postedFields())) { self::failure('save_failed', 422); }
        wp_send_json_success(['saved' => true]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function contractFields(string $step, array $fields, array $state, bool $skip): array
    {
        $contracts = [
            'v3_socials' => ['social_networks'], 'v3_links' => ['links'],
            'v3_colors' => ['page_background', 'button_color'], 'v3_buttons' => ['link_style', 'button_texture'],
            'v3_avatar' => ['avatar_attachment_id', 'avatar_shape', 'avatar_effect'],
            'v3_wallpaper' => ['cover_attachment_id', 'wallpaper_size', 'wallpaper_effect'],
            'v3_name' => ['name_font', 'name_weight', 'name_color'],
            'v3_network_style' => ['social_style', 'social_color'], 'v3_review' => [],
        ];
        if (!isset($contracts[$step])) { self::failure('invalid_step', 422); }
        $prefs = is_array($state['preferences'] ?? null) ? $state['preferences'] : [];
        $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
        $result = [];
        foreach ($contracts[$step] as $field) {
            if (!$skip && isset($fields[$field]) && is_scalar($fields[$field])) {
                $result[$field] = (string) $fields[$field];
            } elseif ($field === 'social_networks') {
                $result[$field] = wp_json_encode((array) ($prefs['social_links'] ?? []));
            } elseif ($field === 'links') {
                $result[$field] = wp_json_encode(array_values(array_filter((array) ($state['blocks'] ?? []), static fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'link')));
            } elseif ($field === 'avatar_attachment_id') {
                $result[$field] = (string) ($profile[$field] ?? 0);
            } else {
                $result[$field] = (string) ($prefs[$field] ?? '');
            }
        }
        return $result;
    }

    public static function preview(): void
    {
        self::verify('faluss_onboarding_v3_preview');
        $fields = self::postedFields();
        $result = LinkStudioContract::previewOnboardingV3($fields);
        if (is_wp_error($result)) { self::failure((string) $result->get_error_code(), 422); }
        wp_send_json_success($result);
    }

    public static function publish(): void
    {
        self::verify('faluss_onboarding_v3_publish');
        $result = LinkStudioContract::publishOnboardingV3(self::text('version'));
        if (empty($result['ok'])) { self::failure((string) ($result['code'] ?? 'publish_failed'), (int) ($result['status'] ?? 422)); }
        wp_send_json_success($result);
    }

    public static function uploadAvatar(): void
    {
        self::verify('faluss_onboarding_v3_upload_avatar');
        LinkStudioContract::uploadImage('avatar', 'faluss_onboarding_v3_upload_avatar');
    }

    public static function uploadCover(): void
    {
        self::verify('faluss_onboarding_v3_upload_cover');
        LinkStudioContract::uploadImage('cover', 'faluss_onboarding_v3_upload_cover');
    }

    private static function verify(string $action): void
    {
        if (!self::enabled() || !is_user_logged_in() || !check_ajax_referer($action, 'nonce', false)) { self::failure('forbidden', 403); }
    }

    /** @return array<string, mixed> */
    private static function postedFields(): array
    {
        $raw = isset($_POST['fields']) && is_string($_POST['fields']) ? wp_unslash($_POST['fields']) : '{}';
        if (strlen($raw) > 131072) { self::failure('fields_too_large', 413); }
        $fields = json_decode($raw, true);
        if (!is_array($fields)) { self::failure('invalid_fields', 422); }
        return $fields;
    }

    private static function text(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    private static function failure(string $code, int $status): never
    {
        wp_send_json_error(['code' => $code], $status);
    }
}
