<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Identity\IdentityContract;
use Faluss\Platform\Link\LinkStudioContract;

/** Member workspace. Shares V3 editors and the canonical card, never the onboarding shell. */
final class StudioV3
{
    private const GROUPS = [
        'links' => ['v3_links' => 'Liens', 'v3_collections' => 'Collections', 'v3_contents' => 'Contenus et ordre'],
        'design' => ['v3_mode' => 'Structure', 'v3_colors' => 'Couleurs', 'v3_buttons' => 'Boutons', 'v3_avatar' => 'Avatar', 'v3_wallpaper' => 'Fond', 'v3_name' => 'Nom', 'v3_network_style' => 'Style des réseaux'],
        'profile' => ['v3_identity' => 'Identité', 'v3_socials' => 'Réseaux', 'v3_settings' => 'Réglages'],
    ];

    public static function render(): string
    {
        if (!is_user_logged_in()) { return OnboardingV3::login(); }
        $context = IdentityContract::onboardingV3Context();
        $state = LinkStudioContract::state();
        if (is_wp_error($state)) { return OnboardingV3::unavailable((string) $state->get_error_code()); }
        $profile = (array) ($state['profile'] ?? []);
        if (($profile['publication_status'] ?? '') !== 'published' && ($context['step'] ?? '') !== 'complete') {
            return OnboardingV3::render();
        }
        MeStudioAssets::enqueueStudioV3();
        $requested = isset($_GET['v3_section']) && is_string($_GET['v3_section']) ? sanitize_key(wp_unslash($_GET['v3_section'])) : 'v3_links';
        $section = 'v3_links';
        $group = 'links';
        foreach (self::GROUPS as $key => $sections) {
            if (isset($sections[$requested])) { $section = $requested; $group = $key; }
        }
        $slug = (string) ($profile['public_slug'] ?? '');
        $mode = ($state['preferences']['structure'] ?? '') === 'atomic' ? 'atomic' : 'simple';
        $state['studio'] = true;
        $state['canonical_slug'] = $slug;
        $avatar = !empty($profile['avatar_attachment_id']) ? (string) wp_get_attachment_image_url((int) $profile['avatar_attachment_id'], 'thumbnail') : '';
        $name = (string) ($profile['display_name'] ?? '');
        ob_start();
        ?>
        <section class="faluss-studio-v3" data-faluss-studio-v3 data-studio-config="<?php echo esc_attr((string) wp_json_encode(MeStudioAssets::studioV3Config())); ?>" data-studio="true" data-step="<?php echo esc_attr($section); ?>" data-mode="<?php echo esc_attr($mode); ?>" data-version="<?php echo esc_attr((string) ($state['version'] ?? '')); ?>">
            <header class="faluss-studio-v3__header">
                <a href="<?php echo esc_url(home_url('/')); ?>" aria-label="Accueil Faluss">←</a>
                <strong>Studio</strong>
                <button type="button" data-studio-preview-open>Aperçu ↗</button>
            </header>
            <div class="faluss-studio-v3__member">
                <?php if ($avatar !== '') : ?><img src="<?php echo esc_url($avatar); ?>" alt=""><?php else : ?><span class="faluss-studio-v3__avatar" aria-hidden="true"><?php echo esc_html('F'); ?></span><?php endif; ?>
                <div><strong><?php echo esc_html($name); ?></strong><p>@<?php echo esc_html($slug); ?></p></div>
                <?php if (($profile['publication_status'] ?? '') === 'published') : ?><a href="<?php echo esc_url(home_url('/' . $slug . '/')); ?>" target="_blank" rel="noopener" aria-label="Ouvrir ma carte publique">↗</a><?php endif; ?>
            </div>
            <nav class="faluss-studio-v3__tabs" aria-label="Rubriques du Studio">
                <?php foreach (self::GROUPS[$group] as $key => $label) { self::tab($key, $label, $key === $section); } ?>
            </nav>
            <form class="faluss-studio-v3__workspace" data-v3-panel novalidate>
                <div data-v3-scroll>
                    <?php if (StudioV3Management::handles($section)) { StudioV3Management::render($section, $state); } else { OnboardingV3::controls($section, $mode, $state, $slug); } ?>
                </div>
                <p data-v3-error role="alert" hidden></p><p data-v3-status role="status" aria-live="polite"></p>
                <?php if (!StudioV3Management::handles($section)) : ?><button class="faluss-studio-v3__save" type="submit" data-v3-primary>Enregistrer</button><?php endif; ?>
            </form>
            <nav class="faluss-studio-v3__bottom" aria-label="Navigation principale">
                <?php self::tab('v3_links', 'Liens', $group === 'links'); ?>
                <button type="button" aria-disabled="true" aria-describedby="faluss-studio-shop-note">Shop</button>
                <?php self::tab('v3_colors', 'Design', $group === 'design'); self::tab('v3_identity', 'Profil', $group === 'profile'); ?>
            </nav>
            <p id="faluss-studio-shop-note" class="faluss-studio-v3__shop-note">Shop n’est pas encore disponible sur ce site.</p>
            <dialog class="faluss-studio-v3__preview" aria-label="Aperçu complet de ta carte">
                <button type="button" data-studio-preview-close autofocus>Fermer l’aperçu ×</button>
                <div data-v3-preview><?php echo (string) ($state['preview_html'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Canonical Link renderer. ?></div>
            </dialog>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function tab(string $section, string $label, bool $active): void
    {
        echo '<a data-studio-navigate href="' . esc_url(add_query_arg(['v3_section' => $section], get_permalink() ?: home_url('/studio/'))) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
}
