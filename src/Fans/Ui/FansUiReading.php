<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Read-only views; all ownership and publication decisions stay in existing services. */
final class FansUiReading
{
    public static function home(string $role): void
    {
        $items = $role === 'creator' ? [
            ['mon-profil', 'Votre profil', 'Consultez le statut et la catégorie de votre profil.'],
            ['creer', 'Créer', 'Choisissez le type de création que vous souhaitez proposer.'],
            ['boutique', 'Ma boutique', 'Les offres et réservations ne sont pas encore disponibles.'],
        ] : [
            ['explorer', 'Découvrir', 'Consultez les fiches publiques et les textes approuvés.'],
            ['classement-fans', 'Classement Fans', 'Découvrez les règles prévues ; aucun classement n’est encore ouvert.'],
            ['espace', 'Votre espace', 'La progression personnelle n’est pas encore disponible.'],
        ];
        ?>
        <section class="fu-content" aria-labelledby="fu-home-title">
            <h2 id="fu-home-title">À portée de main</h2>
            <div class="fu-home-grid">
                <?php foreach ($items as [$view, $title, $description]) : ?>
                    <article class="fu-panel"><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p>
                    <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($role, $view)); ?>">Ouvrir <?php echo esc_html($title); ?> ↗</a></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        self::publications();
    }

    public static function ownProfile(): void
    {
        $profile = CreatorProfileService::own();
        $categories = ['arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Savoirs', 'lifestyle' => 'Art de vivre'];
        $states = ['active' => 'Profil actif', 'pending' => 'En attente de validation', 'suspended' => 'Profil suspendu'];
        ?>
        <section class="fu-content" data-fans-private-reading aria-labelledby="fu-own-title">
            <h2 id="fu-own-title">Votre profil créateur</h2>
            <?php if ($profile === null) : ?>
                <p class="fu-live">Votre profil ne peut pas être chargé. Rechargez la page pour vérifier votre accès.</p>
            <?php else : ?>
                <article class="fu-panel fu-profile">
                    <div class="fu-profile__glyph" aria-hidden="true"></div>
                    <div><p class="fu-panel__kicker"><?php echo esc_html($categories[$profile['category']]); ?></p>
                        <h3><?php echo esc_html($states[$profile['status']]); ?></h3>
                        <p>Nom public et portrait indisponibles. Leur édition n’est pas encore proposée.</p>
                        <?php if ($profile['status'] === 'active') : ?>
                            <a class="fu-link" href="<?php echo esc_url(home_url('/faluss-fans/creators/' . $profile['creator_id'])); ?>">Voir ma fiche publique ↗</a>
                        <?php else : ?>
                            <p>Votre fiche n’est pas publique. La publication de nouveaux contenus est fermée.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function publications(): void
    {
        ?>
        <section class="fu-content" data-fans-publications data-api="<?php echo esc_url(rest_url('faluss-fans/v1/text-publications')); ?>"
                 data-public-base="<?php echo esc_url(home_url('/faluss-fans/creators/')); ?>" aria-labelledby="fu-texts-title">
            <div class="fu-section-heading"><div><p class="fu-panel__kicker">Publications</p><h2 id="fu-texts-title">Textes publics récents</h2></div></div>
            <p class="fu-footnote">Textes approuvés de la communauté. Les noms publics et portraits des auteurs ne sont pas encore disponibles.</p>
            <p class="fu-live" data-text-status role="status">Chargement des publications…</p>
            <div class="fu-text-grid" data-text-results></div>
            <div class="fu-text-controls"><button type="button" data-text-refresh>Recommencer la lecture</button>
                <button type="button" data-text-next hidden>Page suivante</button></div>
            <noscript><p>Activez JavaScript pour charger les publications publiques.</p></noscript>
        </section>
        <?php
    }
}
