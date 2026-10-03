<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

/** Page composition only: eligibility, SSO and publication delivery keep their existing contracts. */
final class FansUiDiscovery
{
    public static function explorer(string $api, string $publicBase, string $signIn): void
    {
        ?>
        <section class="fu-discovery" data-fans-explorer data-api="<?php echo esc_url($api); ?>" data-public-base="<?php echo esc_url($publicBase); ?>" aria-labelledby="fu-explore-title">
            <header class="fu-discovery__heading"><p class="fu-panel__kicker">Des univers à découvrir</p><h1 id="fu-explore-title">Explorer Fans</h1><p>Rencontrez les créateurs et explorez leurs univers.</p></header>
            <div data-fans-hero></div>
            <div class="fu-filters" role="group" aria-label="Filtrer par catégorie">
                <?php foreach (['' => 'Toutes les catégories', 'arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Savoirs', 'lifestyle' => 'Art de vivre'] as $value => $label): ?>
                    <button type="button" data-category="<?php echo esc_attr($value); ?>" aria-pressed="<?php echo $value === '' ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>
            <p class="fu-live" data-fans-status role="status" aria-live="polite">Chargement des créateurs…</p>
            <div data-fans-results></div>
            <?php self::signIn($signIn); ?>
            <noscript><p>Activez JavaScript pour découvrir les créateurs.</p></noscript>
        </section>
        <?php
    }

    public static function profile(string $api, string $creatorId, string $role, string $signIn): void
    {
        ?>
        <div class="fu-public-creator">
            <section data-fans-profile data-api="<?php echo esc_url($api); ?>" data-creator-id="<?php echo esc_attr($creatorId); ?>" aria-labelledby="fu-profile-title">
                <a class="fu-back" href="<?php echo esc_url(FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, 'explorer')); ?>">← Explorer</a>
                <h1 class="fu-discovery__sr" id="fu-profile-title">Profil créateur</h1>
                <p class="fu-live" data-fans-status role="status" aria-live="polite">Chargement du profil…</p>
                <div data-fans-results></div>
                <?php if (\Faluss\Platform\Fans\Messaging\MessageModule::available() && \Faluss\Platform\Fans\Profiles\CreatorProfileService::activeOwner($creatorId) !== get_current_user_id()): ?>
                    <a class="fu-link fu-public-creator__action" href="<?php echo esc_url(add_query_arg('creator', $creatorId, FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, 'messages'))); ?>">Adresser une demande de message ↗</a>
                <?php endif; ?>
            </section>
            <?php self::signIn($signIn); ?>
            <?php FansUiReading::publications($creatorId, true); ?>
        </div>
        <?php
    }

    private static function signIn(string $form): void
    {
        if ($form === '') { return; }
        ?>
        <aside class="fu-discovery__signin" aria-label="Rejoindre Fans"><p>Un univers vous inspire ? <span>Rejoignez Fans.</span></p><?php echo $form; // Existing trusted, nonce-protected SSO form. ?></aside>
        <?php
    }
}
