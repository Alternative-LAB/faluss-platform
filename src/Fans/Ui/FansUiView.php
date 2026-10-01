<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiView
{
    /** @var array<string,list<array{string,string,string,string}>> */
    private const NAV = [
        'visitor' => [
            ['explorer', 'Explorer', 'compass', 'explorer'],
            ['hof', 'HoF', 'trophy', 'hof'],
        ],
        'fan' => [
            ['accueil', 'Accueil', 'home', 'accueil'],
            ['explorer', 'Explorer', 'compass', 'explorer'],
            ['hof', 'HoF', 'trophy', 'hof'],
            ['classement-fans', 'Classement Fans', 'chart', 'classement-fans'],
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
        'profile-unavailable' => ['Découvrir', 'Profil indisponible', '', 'Ce profil n’est pas public ou n’existe pas.'],
        'creator-unavailable' => ['Votre espace Fans', 'Espace créateur indisponible', '', 'Cet espace nécessite un profil créateur associé à votre compte.'],
        'visitor:connexion' => ['Votre espace Fans', 'Continuer avec Faluss', '', 'Connectez-vous via Faluss Identity pour accéder à cet espace personnel.'],
        'visitor:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'Découvrez les présentations approuvées. Les profils incomplets restent signalés.'],
        'visitor:hof' => ['Hall of Fame', 'Le Hall of Fame', 'hof', 'Le Hall of Fame se prépare. Retrouvez ici les prochaines sessions de création.'],
        'fan:accueil' => ['Espace Fans', 'Bienvenue dans votre espace', 'accueil', 'Les parcours Fans s’ouvrent progressivement. Explorer permet de consulter les profils créateurs publiés.'],
        'fan:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'Découvrez les profils et présentations approuvés par la modération.'],
        'fan:hof' => ['Hall of Fame', 'Le Hall of Fame', 'hof', 'Les prochaines sessions du Hall of Fame apparaîtront ici.'],
        'fan:hof/session' => ['Hall of Fame', 'Session HoF', 'hof', 'Aucune session active ne peut être affichée ou rejointe pour le moment.'],
        'fan:classements' => ['Hall of Fame', 'Classements', 'hof', 'Les classements ne sont pas encore disponibles. Aucun rang n’est estimé.'],
        'fan:classement-fans' => ['Les fans soutiennent la création', 'Classement Fans', 'classement-fans', 'Votre place reposera uniquement sur les PF effectivement attribués à des créateurs, après attestation.'],
        'fan:messages' => ['Échanges', 'Messages', 'messages', 'La messagerie n’est pas encore disponible. Aucun message ne peut être envoyé.'],
        'fan:espace' => ['Votre espace', 'Espace personnel', 'espace', 'La progression et les gains PC ne sont pas encore disponibles.'],
        'creator:accueil' => ['Espace créateur', 'Votre espace Fans', 'accueil', 'Retrouvez vos publications, votre présentation et vos conversations.'],
        'creator:explorer' => ['Découvrir', 'Explorer les créateurs', 'explorer', 'Découvrez les profils et présentations approuvés par la modération.'],
        'creator:hof' => ['Hall of Fame', 'Le Hall of Fame', 'hof', 'Les prochaines sessions du Hall of Fame apparaîtront ici.'],
        'creator:hof/session' => ['Hall of Fame', 'Session HoF', 'hof', 'Aucune session active ne peut être affichée ou rejointe pour le moment.'],
        'creator:classements' => ['Hall of Fame', 'Classements', 'hof', 'Les classements seront présentés à leur ouverture.'],
        'creator:messages' => ['Échanges', 'Messages', 'messages', 'La messagerie n’est pas encore disponible. Aucun message ne peut être envoyé.'],
        'creator:progression' => ['Espace créateur', 'Progression', 'progression', 'Votre progression apparaîtra ici lorsque le Hall of Fame ouvrira.'],
        'creator:creer' => ['Espace créateur', 'Que souhaitez-vous créer ?', 'creer', 'Vos publications et images sont examinées avant publication. Prestations, services et produits restent indisponibles.'],
        'creator:boutique' => ['Espace créateur', 'Ma boutique', 'boutique', 'La gestion des offres, les réservations et les commandes ne sont pas encore disponibles.'],
        'creator:mon-profil' => ['Espace créateur', 'Mon profil', 'mon-profil', 'Gérez votre présentation publique et suivez son état de modération.'],
        'public-profile' => ['Découvrir', 'Profil créateur', 'explorer', 'Seuls les champs éditoriaux approuvés sont présentés ici.'],
    ];

    public static function render(string $role, string $view, ?string $creatorId, int $status = 200, string $signIn = '', ?FansUiAuthor $author = null, ?FansUiAdmission $admission = null, ?FansUiEditorial $editorial = null, ?FansUiImages $images = null, ?FansUiMessages $messages = null, ?FansUiAuthor $composer = null): void
    {
        $errorView = in_array($view, ['profile-unavailable', 'creator-unavailable'], true);
        $page = self::PAGES[$view === 'public-profile' || $errorView ? $view : $role . ':' . $view] ?? null;
        if ($page === null || !isset(self::NAV[$role])) {
            return;
        }
        $title = $page[1];
        // Use native Unicode glyphs on the standalone app; do not fetch emoji images from a CDN.
        if (function_exists('remove_action')) { remove_action('wp_head', 'print_emoji_detection_script', 7); }
        if ($messages?->available) { $page[3]='Vos demandes et conversations privées. Chaque échange respecte le choix de son destinataire.'; }
        $api = rest_url('faluss-fans/v1/creators');
        $publicBase = home_url('/app/creators/');
        wp_enqueue_style('faluss-fans-ui-v2', plugins_url('assets/fans-ui-v2.css', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'));
        if ($messages !== null) { wp_enqueue_style('faluss-fans-messages', plugins_url('assets/fans-messages.css', dirname(__DIR__, 3) . '/faluss-platform.php'), ['faluss-fans-ui-v2'], (string)constant('FALUSS_PLATFORM_VERSION')); }
        wp_enqueue_script('faluss-fans-ui-v2', plugins_url('assets/fans-ui-v2.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true);
        wp_enqueue_script('faluss-fans-reading', plugins_url('assets/fans-ui-reading.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true);
        if ($role === 'creator') { wp_enqueue_script('faluss-fans-create', plugins_url('assets/fans-create.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true); }
        if ($editorial !== null || $images !== null) { wp_enqueue_script('faluss-fans-private-images', plugins_url('assets/fans-private-images.js', dirname(__DIR__, 3) . '/faluss-platform.php'), [], (string) constant('FALUSS_PLATFORM_VERSION'), true); }
        status_header($status);
        ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?> · Faluss Fans</title>
<?php wp_head(); ?>
</head>
<body class="faluss-fans-ui-page<?php echo $messages!==null?' fu-messages-page':''; ?>">
<a class="fu-skip" href="#fu-main">Aller au contenu</a>
<div class="fu-app" data-fans-role="<?php echo esc_attr($role); ?>">
    <?php self::navigation($role, $page[2]); ?>
    <main class="fu-main" id="fu-main" tabindex="-1">
        <header class="<?php echo in_array($view, ['accueil', 'explorer', 'hof'], true) ? 'fu-banner' : 'fu-heading'; ?>">
            <div class="fu-banner__texture" aria-hidden="true"></div>
            <p class="fu-eyebrow"><?php echo esc_html($page[0]); ?></p>
            <h1><?php echo esc_html($title); ?></h1>
            <p class="fu-banner__subtitle"><?php echo esc_html($page[3]); ?></p>
        </header>
        <?php if ($role === 'visitor' && !$errorView) : ?>
            <section class="fu-signin fu-panel" aria-label="Connexion Faluss">
                <div><h2><?php echo $view === 'connexion' ? 'Connexion requise' : 'Retrouvez votre espace'; ?></h2>
                <p>Connectez-vous ou créez votre compte sur Faluss Identity. Vous reviendrez sur cette page après connexion.</p>
                <?php if ($signIn === '') : ?><p>Utilisez une session de membre pour continuer ; ce compte ne peut pas être lié par ce parcours.</p><?php endif; ?></div>
                <?php echo $signIn; // Trusted server-rendered SSO form, all values escaped by FansSsoService. ?>
            </section>
        <?php endif; ?>
        <?php if ($view === 'explorer') : ?>
            <?php self::explorer($api, $publicBase); ?>
            <?php FansUiReading::publications(); ?>
        <?php elseif ($view === 'accueil') : ?>
            <?php FansUiReading::home($role); ?>
        <?php elseif ($view === 'mon-profil') : ?>
            <?php FansUiReading::ownProfile(); ?>
            <?php if ($editorial !== null) { FansUiEditorial::render($editorial); } ?>
        <?php elseif ($view === 'public-profile' && $creatorId !== null) : ?>
            <?php self::publicProfile($api, $creatorId, $role); ?>
            <?php FansUiReading::publications($creatorId); ?>
        <?php elseif ($view === 'creer') : ?>
            <?php self::creationChoices($author !== null && $author->available, $images !== null && $images->available); ?>
            <?php if ($author !== null) { FansUiAuthorView::render($author); } ?>
            <?php if ($images !== null) { FansUiImagesView::render($images); } ?>
        <?php elseif ($view === 'messages' && $messages !== null) : ?>
            <?php FansUiMessageView::render($messages); ?>
        <?php elseif ($view === 'classement-fans') : ?>
            <?php self::fanRanking(); ?>
        <?php elseif ($view === 'connexion') : ?>
            <p class="fu-footnote">Les pages Explorer et HoF restent consultables sans connexion. Aucun compte ni droit créateur n’est créé avant validation par Faluss Identity.</p>
        <?php else : ?>
            <section class="fu-panel fu-panel--status" aria-labelledby="fu-status-title">
                <span class="fu-status-mark" aria-hidden="true">◌</span>
                <div>
                    <p class="fu-panel__kicker"><?php echo $errorView ? 'Accès indisponible' : 'À venir'; ?></p>
                    <h2 id="fu-status-title"><?php echo $errorView ? 'Cette page ne peut pas être affichée' : ($view === 'espace' ? 'Progression indisponible' : 'Bientôt sur Fans'); ?></h2>
                    <p><?php echo esc_html($page[3]); ?></p>
                    <?php if ($errorView) : ?>
                        <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($role === 'visitor' ? 'fan' : $role, 'explorer')); ?>">Retour à Explorer <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($admission !== null) { FansUiReading::admission($admission); } ?>
        <footer class="fu-footer">Faluss Fans · Certaines fonctionnalités arrivent progressivement.</footer>
    </main>
</div>
<?php if ($role === 'creator' && $composer !== null) { FansUiCreate::render($composer); } ?>
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
                       <?php echo $role === 'creator' && $view === 'creer' ? 'data-create-open aria-haspopup="dialog" aria-controls="fu-create"' : ''; ?>
                       <?php echo $active === $key ? 'aria-current="page"' : ''; ?>>
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
            <div class="fu-section-heading"><div><p class="fu-panel__kicker">Découvrir</p><h2 id="fu-explore-title">Profils créateurs</h2></div></div>
            <p class="fu-discovery-note">Les noms, bios et portraits ne sont diffusés qu’après approbation. Une fiche sans présentation approuvée reste explicitement incomplète.</p>
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
            <?php if (\Faluss\Platform\Fans\Messaging\MessageModule::available() && \Faluss\Platform\Fans\Profiles\CreatorProfileService::activeOwner($creatorId)!==get_current_user_id()): ?>
            <a class="fu-link" href="<?php echo esc_url(add_query_arg('creator',$creatorId,FansUiRoutes::url($role==='visitor'?'fan':$role,'messages'))); ?>">Adresser une demande de message ↗</a>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function creationChoices(bool $textsAvailable = false, bool $imagesAvailable = false): void
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
                    <article class="fu-choice"><span class="fu-choice__ornament" aria-hidden="true">✳</span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p>
                    <?php if ($title === 'Publication' && ($textsAvailable || $imagesAvailable)) : ?>
                        <?php if ($textsAvailable): ?><a class="fu-link" href="#fu-author">Gérer mes textes ↓</a><?php endif; ?>
                        <?php if ($imagesAvailable): ?><a class="fu-link" href="#fu-images">Gérer mes images ↓</a><?php endif; ?>
                    <?php else : ?><span class="fu-closed">Indisponible pour le moment</span><?php endif; ?></div></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private static function fanRanking(): void
    {
        ?>
        <section class="fu-ranking" aria-labelledby="fu-ranking-status">
            <div class="fu-panel fu-panel--status">
                <span class="fu-status-mark" aria-hidden="true">◌</span>
                <div>
                    <p class="fu-panel__kicker">Classement Fans</p>
                    <h2 id="fu-ranking-status">Classement indisponible</h2>
                    <p>Les attributions attestées et leurs corrections ne sont pas encore disponibles. Votre score et votre rang ne peuvent pas être calculés.</p>
                    <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url('fan', 'explorer')); ?>">Découvrir les créateurs <span aria-hidden="true">↗</span></a>
                </div>
            </div>
            <div class="fu-ranking__rules">
                <article class="fu-panel">
                    <p class="fu-panel__kicker">Ce qui comptera</p>
                    <h2>Vos PF attribués</h2>
                    <p>Seuls les PF effectivement attribués à des créateurs et attestés pourront contribuer. Acheter un pack sans attribuer ses PF ne donnera aucun point.</p>
                    <p>Une même attribution ne comptera qu’une fois. Les annulations, remboursements et corrections devront être pris en compte.</p>
                </article>
                <article class="fu-panel">
                    <p class="fu-panel__kicker">Avant l’ouverture</p>
                    <h2>Des règles à finaliser</h2>
                    <p>La période, les égalités, la visibilité des pseudonymes et la place des invités restent à décider.</p>
                    <p>Ce classement des fans est distinct du Hall of Fame des créateurs. Aucun classement public n’est lancé.</p>
                </article>
            </div>
        </section>
        <?php
    }
}
