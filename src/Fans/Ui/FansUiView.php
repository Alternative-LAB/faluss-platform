<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiView
{
    /** @var array<string,list<array{string,string,string,string}>> */
    private const NAV = [
        'visitor' => [
            ['explorer', 'Explorer', 'compass', 'explorer'],
        ],
        'fan' => [
            ['accueil', 'Accueil', 'home', 'accueil'],
            ['explorer', 'Explorer', 'compass', 'explorer'],
            ['hof', 'HoF', 'trophy', 'hof'],
            ['messages', 'Messages', 'message', 'messages'],
            ['espace', 'Mon espace', 'person', 'espace'],
        ],
        'creator' => [
            ['accueil', 'Accueil', 'home', 'accueil'],
            ['explorer', 'Explorer', 'compass', 'explorer'],
            ['hof', 'HoF', 'trophy', 'hof'],
            ['messages', 'Messages', 'message', 'messages'],
            ['creer', 'Créer', 'plus', 'creer'],
            ['boutique', 'Ma boutique', 'bag', 'boutique'],
            ['progression', 'Progression', 'chart', 'progression'],
            ['mon-profil', 'Mon profil', 'person', 'mon-profil'],
        ],
    ];

    /** @var array<string,array{string,string,string,string}> */
    private const PAGES = [
        'visitor:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'La découverte est en préparation : aucun nom public ni portrait approuvé n’est encore fourni.'],
        'fan:accueil' => ['Espace Fans', 'Bienvenue dans votre espace', 'accueil', 'Les parcours Fans s’ouvrent progressivement. Explorer permet de consulter les profils créateurs publiés.'],
        'fan:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'Seuls les profils approuvés sont visibles. Les noms et portraits ne sont pas encore disponibles.'],
        'fan:hof' => ['Hall of Fame', 'Le Hall of Fame', 'hof', 'Les sessions et les scores HoF persistants ne sont pas disponibles. Aucun point n’est affiché.'],
        'fan:hof/session' => ['Hall of Fame', 'Session HoF', 'hof', 'Aucune session active ne peut être affichée ou rejointe pour le moment.'],
        'fan:classements' => ['Hall of Fame', 'Classements', 'hof', 'Les classements ne sont pas encore disponibles. Aucun rang n’est estimé.'],
        'fan:messages' => ['Échanges', 'Messages', 'messages', 'La messagerie n’est pas encore disponible. Aucun message ne peut être envoyé.'],
        'fan:espace' => ['Votre espace', 'Espace personnel', 'espace', 'La progression et les gains PC ne sont pas encore disponibles.'],
        'creator:accueil' => ['Espace créateur', 'Votre espace Fans', 'accueil', 'Votre espace rassemble les accès créateur. Les résumés de sessions et de communauté attendent leurs capacités serveur.'],
        'creator:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'Seuls les profils approuvés sont visibles. Les noms et portraits ne sont pas encore disponibles.'],
        'creator:hof' => ['Hall of Fame', 'Le Hall of Fame', 'hof', 'Les sessions et le score public en points HoF ne sont pas encore disponibles.'],
        'creator:hof/session' => ['Hall of Fame', 'Session HoF', 'hof', 'Aucune session active ne peut être affichée ou rejointe pour le moment.'],
        'creator:classements' => ['Hall of Fame', 'Classements', 'hof', 'Les classements et les points HoF persistants ne sont pas encore disponibles.'],
        'creator:messages' => ['Échanges', 'Messages', 'messages', 'La messagerie n’est pas encore disponible. Aucun message ne peut être envoyé.'],
        'creator:progression' => ['Espace créateur', 'Progression', 'progression', 'Les points HoF et la progression persistante ne sont pas encore disponibles.'],
        'creator:creer' => ['Espace créateur', 'Que souhaitez-vous créer ?', 'creer', 'Chaque type de création sera ouvert lorsque son parcours serveur complet sera disponible.'],
        'creator:boutique' => ['Espace créateur', 'Ma boutique', 'boutique', 'La gestion des offres, les réservations et les commandes ne sont pas encore disponibles.'],
        'creator:mon-profil' => ['Espace créateur', 'Mon profil', 'mon-profil', 'Cet espace de gestion est distinct du profil public. Les outils d’édition ne sont pas encore disponibles.'],
        'public-profile' => ['Découvrir', 'Profil créateur', 'explorer', 'Fiche publique provisoire : aucun nom ni portrait approuvé n’est encore fourni.'],
    ];

    public static function render(string $role, string $view, ?string $creatorId): void
    {
        $page = self::PAGES[$view === 'public-profile' ? 'public-profile' : $role . ':' . $view] ?? null;
        if ($page === null || !isset(self::NAV[$role])) {
            return;
        }
        $title = $page[1];
        $api = rest_url('faluss-fans/v1/creators');
        $publicBase = home_url('/faluss-fans/creators/');
        wp_enqueue_style('faluss-fans-ui-v2', plugins_url('assets/fans-ui-v2.css', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'));
        wp_enqueue_script('faluss-fans-ui-v2', plugins_url('assets/fans-ui-v2.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true);
        status_header(200);
        ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?> · Faluss Fans</title>
<?php wp_head(); ?>
</head>
<body class="faluss-fans-ui-page">
<a class="fu-skip" href="#fu-main">Aller au contenu</a>
<div class="fu-app" data-fans-role="<?php echo esc_attr($role); ?>">
    <?php self::navigation($role, $page[2]); ?>
    <main class="fu-main" id="fu-main" tabindex="-1">
        <header class="fu-banner">
            <div class="fu-banner__texture" aria-hidden="true"></div>
            <p class="fu-eyebrow"><?php echo esc_html($page[0]); ?></p>
            <h1><?php echo esc_html($title); ?></h1>
            <p class="fu-banner__subtitle"><?php echo esc_html($page[3]); ?></p>
        </header>
        <?php if ($view === 'explorer') : ?>
            <?php self::explorer($api, $publicBase); ?>
        <?php elseif ($view === 'public-profile' && $creatorId !== null) : ?>
            <?php self::publicProfile($api, $creatorId, $role); ?>
        <?php elseif ($view === 'creer') : ?>
            <?php self::creationChoices(); ?>
        <?php else : ?>
            <section class="fu-panel fu-panel--status" aria-labelledby="fu-status-title">
                <span class="fu-status-mark" aria-hidden="true">◌</span>
                <div>
                    <p class="fu-panel__kicker">À venir</p>
                    <h2 id="fu-status-title">Parcours indisponible</h2>
                    <p><?php echo esc_html($page[3]); ?></p>
                    <?php if ($view === 'accueil') : ?>
                        <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($role, 'explorer')); ?>">Explorer les profils <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <footer class="fu-footer">Faluss Fans · Certaines fonctionnalités arrivent progressivement.</footer>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }

    private static function navigation(string $role, string $active): void
    {
        ?>
        <aside class="fu-rail">
            <a class="fu-brand" href="<?php echo esc_url(FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, $role === 'visitor' ? 'explorer' : 'accueil')); ?>" aria-label="Faluss Fans — <?php echo $role === 'visitor' ? 'Explorer' : 'accueil'; ?>" title="Faluss Fans — <?php echo $role === 'visitor' ? 'Explorer' : 'accueil'; ?>">F</a>
            <nav class="fu-nav" aria-label="Navigation <?php echo $role === 'creator' ? 'créateur' : ($role === 'visitor' ? 'publique' : 'Fan'); ?>">
                <?php foreach (self::NAV[$role] as [$view, $label, $icon, $key]) : ?>
                    <a class="fu-nav__item<?php echo $active === $key ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url(FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, $view)); ?>"
                       aria-label="<?php echo esc_attr($label); ?>"
                       title="<?php echo esc_attr($label); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>>
                        <svg aria-hidden="true" viewBox="0 0 24 24"><use href="#fu-icon-<?php echo esc_attr($icon); ?>"></use></svg>
                        <span class="fu-nav__label"><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <span class="fu-rail__caption"><?php echo $role === 'creator' ? 'CRÉATEUR' : ($role === 'visitor' ? 'DÉCOUVERTE' : 'FAN'); ?></span>
        </aside>
        <svg class="fu-icons" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <symbol id="fu-icon-home" viewBox="0 0 24 24"><path d="m3 11 9-7 9 7v9h-6v-6H9v6H3z"/></symbol>
            <symbol id="fu-icon-compass" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/></symbol>
            <symbol id="fu-icon-trophy" viewBox="0 0 24 24"><path d="M7 4h10v5c0 4-2 6-5 6s-5-2-5-6zM7 6H4v3c0 2 1 3 4 3m9-6h3v3c0 2-1 3-4 3m-4 3v4m-4 1h8"/></symbol>
            <symbol id="fu-icon-message" viewBox="0 0 24 24"><path d="M4 5h16v12H9l-5 3z"/></symbol>
            <symbol id="fu-icon-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M7 12h10"/></symbol>
            <symbol id="fu-icon-bag" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6zM9 9V7a3 3 0 0 1 6 0v2"/></symbol>
            <symbol id="fu-icon-chart" viewBox="0 0 24 24"><path d="M4 20h16M6 17l4-5 3 2 5-7"/></symbol>
            <symbol id="fu-icon-person" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></symbol>
        </svg>
        <?php
    }

    private static function explorer(string $api, string $publicBase): void
    {
        ?>
        <section class="fu-content" data-fans-explorer data-api="<?php echo esc_url($api); ?>" data-public-base="<?php echo esc_url($publicBase); ?>" aria-labelledby="fu-explore-title">
            <div class="fu-section-heading"><div><p class="fu-panel__kicker">Découverte en préparation</p><h2 id="fu-explore-title">Profils publics structurés</h2></div></div>
            <p class="fu-discovery-note">Ces fiches indiquent seulement une catégorie approuvée. Sans nom public ni portrait fourni par l’API, elles ne constituent pas encore un catalogue de créateurs identifiable.</p>
            <div class="fu-filters" role="group" aria-label="Filtrer par catégorie">
                <button type="button" data-category="" aria-pressed="true">Toutes les catégories</button>
                <button type="button" data-category="arts" aria-pressed="false">Arts</button>
                <button type="button" data-category="music" aria-pressed="false">Musique</button>
                <button type="button" data-category="games" aria-pressed="false">Jeux</button>
                <button type="button" data-category="learning" aria-pressed="false">Savoirs</button>
                <button type="button" data-category="lifestyle" aria-pressed="false">Art de vivre</button>
            </div>
            <p class="fu-live" data-fans-status role="status" aria-live="polite">Chargement des profils…</p>
            <div class="fu-grid" data-fans-results></div>
        </section>
        <?php
    }

    private static function publicProfile(string $api, string $creatorId, string $role): void
    {
        ?>
        <section class="fu-content" data-fans-profile data-api="<?php echo esc_url($api); ?>" data-creator-id="<?php echo esc_attr($creatorId); ?>" aria-labelledby="fu-profile-title">
            <a class="fu-back" href="<?php echo esc_url(FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, 'explorer')); ?>">← Explorer</a>
            <h2 id="fu-profile-title">Informations publiques</h2>
            <p class="fu-live" data-fans-status role="status" aria-live="polite">Chargement du profil…</p>
            <div data-fans-results></div>
        </section>
        <?php
    }

    private static function creationChoices(): void
    {
        ?>
        <section class="fu-content" aria-labelledby="fu-create-title">
            <div class="fu-section-heading"><div><p class="fu-panel__kicker">Créer</p><h2 id="fu-create-title">Choisir un type</h2></div></div>
            <div class="fu-choice-grid">
                <?php foreach ([
                    ['Publication', 'Texte et création destinés à votre communauté.'],
                    ['Prestation', 'Séance ou rendez-vous à proposer.'],
                    ['Service', 'Accompagnement personnalisé.'],
                    ['Produit', 'Objet ou création à proposer.'],
                ] as [$title, $description]) : ?>
                    <article class="fu-choice"><span class="fu-choice__ornament" aria-hidden="true">✳</span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p><span class="fu-closed">Indisponible pour le moment</span></div></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }
}
