<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

/** Presentation of authorized B6 DTOs only; no calculation, identity mapping or invented chart. */
final class FansUiHofView
{
    private const CATEGORIES = ['arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Savoirs', 'lifestyle' => 'Art de vivre'];

    /** $signIn is the existing server-rendered, nonce-protected SSO form. */
    public static function render(FansUiHof $state, string $signIn = ''): void
    {
        $role = $state->role === 'visitor' ? 'fan' : $state->role;
        ?>
        <div class="fu-hof">
            <nav class="fu-hof__tabs" aria-label="Hall of Fame">
                <?php foreach (['hof' => 'Sessions', 'classements' => 'Classements permanents'] as $view => $label): ?>
                    <a href="<?php echo esc_url(FansUiRoutes::url($role, $view)); ?>" <?php if ($state->view === $view): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
                <?php if ($state->role === 'fan'): ?><a href="<?php echo esc_url(FansUiRoutes::url('fan', 'classement-fans')); ?>" <?php if ($state->view === 'classement-fans'): ?>aria-current="page"<?php endif; ?>>Classement Fans</a><?php endif; ?>
                <?php if (in_array($state->role, ['fan', 'creator'], true)): ?><a href="<?php echo esc_url(FansUiRoutes::url($role, $role === 'creator' ? 'progression' : 'espace')); ?>" <?php if (in_array($state->view, ['espace', 'progression'], true)): ?>aria-current="page"<?php endif; ?>>Relevé mensuel</a><?php endif; ?>
            </nav>
            <?php if (in_array($state->view, ['espace', 'progression'], true)): ?>
                <?php self::statement($state); ?>
            <?php elseif ($state->view === 'hof/session'): ?>
                <?php self::unavailable('Session indisponible', 'Cette session ne peut pas être affichée pour le moment.'); ?>
            <?php else: ?>
                <?php if ($state->view === 'hof'): ?>
                    <section class="fu-hof__section" aria-labelledby="fu-hof-sessions"><h2 id="fu-hof-sessions">Les sessions du Hall of Fame</h2>
                        <?php self::unavailable('Sessions indisponibles', 'Les sessions ouvertes seront présentées ici.'); ?>
                    </section>
                <?php endif; ?>
                <section class="fu-hof__section" aria-labelledby="fu-hof-rankings">
                    <div class="fu-hof__section-heading"><div><p class="fu-panel__kicker"><?php echo $state->family === 'fan' ? 'Les fans soutiennent la création' : 'Le Hall of Fame'; ?></p><h2 id="fu-hof-rankings"><?php echo $state->family === 'fan' ? 'Classement Fans' : 'Classement des créateurs'; ?></h2></div><p>Permanent · général et par catégorie</p></div>
                    <form class="fu-hof__filter" method="get" action="<?php echo esc_url(FansUiRoutes::url($role, $state->view)); ?>">
                        <label for="fu-hof-category">Catégorie</label><select id="fu-hof-category" name="category"><option value="">Toutes les catégories</option><?php foreach (self::CATEGORIES as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state->category, $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><button class="fu-button fu-button--outline" type="submit">Afficher</button>
                    </form>
                    <?php if ($state->ranking === null): ?>
                        <?php self::unavailable('Classement indisponible', 'Le classement ne peut pas être affiché pour le moment.'); ?>
                    <?php elseif ($state->ranking['items'] === []): ?>
                        <div class="fu-panel fu-hof__empty"><h3>Le classement commence ici</h3><p>Aucun participant à afficher pour le moment.</p><a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($role, 'explorer')); ?>">Découvrir les créateurs ↗</a></div>
                    <?php else: ?>
                        <?php if ($state->view === 'hof'): ?><div class="fu-hof__podium"><?php foreach (array_slice($state->ranking['items'], 0, 3) as $row): ?><article class="fu-panel"><span class="fu-hof__place" aria-label="Place <?php echo esc_attr($row['position']); ?>"><?php echo esc_html($row['position']); ?></span><h3><?php echo esc_html($row['name']); ?></h3><p class="fu-hof__points"><?php echo esc_html(self::points($row['points'])); ?> <small>points</small></p></article><?php endforeach; ?></div><?php endif; ?>
                        <?php self::table($state); ?>
                    <?php endif; ?>
                    <?php if ($state->ranking !== null): ?><p class="fu-hof__updated"><?php echo esc_html(self::updated($state->ranking['source_at'])); ?></p><?php endif; ?>
                    <?php if ($state->family === 'fan'): ?><p class="fu-hof__note">Les PF achetés comptent lorsqu’ils sont effectivement attribués à un créateur. Un pack seul n’ajoute aucun point.</p><?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if ($state->role === 'visitor' && $signIn !== ''): ?><aside class="fu-hof__signin"><div><h2>Votre espace Fans</h2><p>Connectez-vous pour retrouver votre progression.</p></div><?php echo $signIn; // Trusted existing SSO form; no client-side reconstruction. ?></aside><?php endif; ?>
        </div>
        <?php
    }

    private static function table(FansUiHof $state): void
    {
        if ($state->ranking === null) { return; }
        $pages = max(1, (int) ceil(count($state->ranking['items']) / 20));
        $page = min($state->page, $pages);
        $role = $state->role === 'visitor' ? 'fan' : $state->role;
        $base = FansUiRoutes::url($role, $state->view);
        ?>
        <div class="fu-panel fu-hof__table"><table><caption class="screen-reader-text"><?php echo $state->family === 'fan' ? 'Classement Fans' : 'Classement des créateurs'; ?></caption><thead><tr><th scope="col">Place</th><th scope="col"><?php echo $state->family === 'fan' ? 'Fan' : 'Créateur'; ?></th><th scope="col">Points</th></tr></thead><tbody><?php foreach (array_slice($state->ranking['items'], ($page - 1) * 20, 20) as $row): ?><tr><td><?php echo esc_html($row['position']); ?></td><th scope="row"><?php echo esc_html($row['name']); ?></th><td><?php echo esc_html(self::points($row['points'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php if ($pages > 1): ?><nav class="fu-hof__pagination" aria-label="Pages du classement"><?php if ($page > 1): ?><a class="fu-link" href="<?php echo esc_url(add_query_arg(['category' => $state->category, 'rank_page' => $page - 1], $base)); ?>">← Précédente</a><?php endif; ?><span aria-current="page"><?php echo esc_html((string) $page); ?> / <?php echo esc_html((string) $pages); ?></span><?php if ($page < $pages): ?><a class="fu-link" href="<?php echo esc_url(add_query_arg(['category' => $state->category, 'rank_page' => $page + 1], $base)); ?>">Suivante →</a><?php endif; ?></nav><?php endif; ?>
        <?php
    }

    private static function statement(FansUiHof $state): void
    {
        ?>
        <section class="fu-hof__section" aria-labelledby="fu-hof-statement"><div class="fu-hof__section-heading"><div><p class="fu-panel__kicker">Votre relevé privé</p><h2 id="fu-hof-statement">Mois par mois</h2></div><p>Mois civil · Europe/Paris</p></div>
            <form class="fu-hof__filter" method="get" action="<?php echo esc_url(FansUiRoutes::url($state->role, $state->view)); ?>"><label for="fu-hof-month">Mois</label><input type="month" id="fu-hof-month" name="month" value="<?php echo esc_attr($state->month); ?>" required><button class="fu-button fu-button--outline" type="submit">Consulter</button></form>
            <?php if ($state->statement === null): ?><?php self::unavailable('Relevé indisponible', 'Votre relevé ne peut pas être affiché pour ce mois.'); ?><?php else: ?>
                <article class="fu-panel fu-hof__statement"><div><p class="fu-panel__kicker"><?php echo esc_html($state->month); ?></p><h3><?php echo $state->family === 'fan' ? 'Vos PF attribués admissibles' : 'Vos points HoF du mois'; ?></h3><p class="fu-hof__metric"><?php echo esc_html(self::points($state->statement['points'])); ?></p></div><div><p>Ce relevé mensuel est privé. Les classements permanents conservent leur progression d’un mois à l’autre.</p><p class="fu-hof__updated"><?php echo esc_html(self::updated($state->statement['source_at'])); ?></p></div></article>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function unavailable(string $title, string $text): void
    { ?><div class="fu-panel fu-hof__empty"><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($text); ?></p></div><?php }

    public static function points(string $value): string
    { return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $value); }

    private static function updated(string $instant): string
    {
        $date = new \DateTimeImmutable($instant, new \DateTimeZone('UTC'));
        return 'Actualisé le ' . wp_date('d/m/Y à H:i', $date->getTimestamp()) . ' · ' . wp_timezone()->getName();
    }
}
