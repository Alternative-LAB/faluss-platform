<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use DateTimeZone;
use Faluss\Platform\Fans\Hof\RankingCalendar;

final class FansUiSessionsView
{
    /** @return array<string,string> */
    public static function categories(): array
    { return ['arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Savoirs', 'lifestyle' => 'Art de vivre']; }

    public static function render(FansUiSessions $state): void
    {
        ?>
        <div class="fu-hof fu-hof-session">
            <nav class="fu-hof__tabs" aria-label="Gestion des sessions"><a href="<?php echo esc_url(FansUiRoutes::url('creator', 'hof')); ?>">Hall of Fame</a><a href="<?php echo esc_url(FansUiSessions::url()); ?>" <?php if ($state->mode === 'sessions'): ?>aria-current="page"<?php endif; ?>>Mes sessions</a><?php if ($state->available && $state->active): ?><a href="<?php echo esc_url(FansUiSessions::url('new')); ?>" <?php if ($state->mode === 'new'): ?>aria-current="page"<?php endif; ?>>Créer une session</a><?php endif; ?></nav>
            <?php if (!$state->available): ?>
                <section class="fu-panel fu-hof__empty"><h2>Gestion des sessions indisponible</h2><p>Ces sessions ne peuvent pas être affichées pour le moment.</p></section>
            <?php else: ?>
                <?php if ($state->result !== null): ?><p role="status" class="fu-panel fu-hof-session__notice"><?php echo esc_html(self::notice($state)); ?></p><?php endif; ?>
                <?php if ($state->mode === 'new'): ?><h2>Créer une session</h2><?php FansUiSessionForm::render($state); ?>
                <?php elseif ($state->mode === 'sessions'): self::listing($state); ?>
                <?php else: self::detail($state); endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function listing(FansUiSessions $state): void
    {
        echo '<h2>Mes sessions</h2>';
        if ($state->items === []) { echo '<div class="fu-panel fu-hof__empty"><h3>Aucune session sur cette page</h3><p>Vos brouillons, invitations et participations apparaîtront ici.</p></div>'; }
        echo '<div class="fu-hof__sessions">';
        foreach ($state->items as $row) {
            echo '<article class="fu-panel fu-hof-session__card"><p class="fu-panel__kicker">' . esc_html(self::sessionState($row['state'])) . '</p><h3><a href="' . esc_url(FansUiSessions::url('session', $row['session_id'])) . '">' . esc_html($row['title']) . '</a></h3><p>' . esc_html(self::period($row)) . '</p><a class="fu-button fu-button--outline" href="' . esc_url(FansUiSessions::url('session', $row['session_id'])) . '">Examiner la session →</a></article>';
        }
        echo '</div><nav class="fu-hof__pagination" aria-label="Pages de mes sessions">';
        if ($state->cursor !== '') { echo '<a href="' . esc_url(FansUiSessions::url()) . '">Première page</a>'; }
        if (count($state->items) === 20) { echo '<a href="' . esc_url(add_query_arg('sessions_cursor', $state->items[19]['session_id'], FansUiSessions::url())) . '">Sessions suivantes →</a>'; }
        echo '</nav>';
    }

    private static function detail(FansUiSessions $state): void
    {
        if ($state->detail === null) { return; }
        $row = $state->detail['session']; $review = $state->review;
        echo '<section class="fu-panel fu-hof-session__summary"><p class="fu-panel__kicker">' . esc_html(self::sessionState($row['state'])) . '</p><h2>' . esc_html($row['title']) . '</h2><p>' . esc_html(self::period($row)) . '</p><p class="fu-hof-session__text">' . esc_html($row['rules_text']) . '</p></section>';
        if ($state->isOwner()) {
            if ($row['state'] === 'draft') { FansUiSessionForm::render($state); }
            echo '<section class="fu-panel fu-hof-session__summary"><h3>Examen des règles</h3>';
            $current = $state->currentReview();
            $label = !$current ? 'Ces règles doivent être soumises à examen.' : match ($review['state'] ?? '') {
                'pending' => 'Proposition en attente.', 'approved' => 'Règles approuvées pour cette version.', 'rejected' => 'Proposition refusée.', 'appealed' => 'Recours en attente.', default => 'Examen indisponible.',
            };
            echo '<p>' . esc_html($label) . '</p>';
            if ($current && ($review['state'] ?? '') === 'rejected') { echo '<p>' . esc_html(match ($review['reason']) { 'needs_revision' => 'Motif : règles à revoir.', 'prohibited_content' => 'Motif : contenu interdit.', 'criteria_not_met' => 'Motif : critères non remplis.', default => 'Consultez la décision avant de soumettre un recours.' }) . '</p>'; }
            if ($state->postAction === 'appeal' && $state->httpStatus() >= 400 && $state->appealText !== '') {
                echo '<details open><summary>Votre texte de recours, non enregistré</summary><p class="fu-hof-session__text">' . esc_html($state->appealText) . '</p></details>';
            }
            $allow = $state->httpStatus() === 200 && $state->active;
            if ($allow && in_array($row['state'], ['draft', 'suspended'], true) && (!$current || !in_array($review['state'] ?? '', ['pending', 'approved', 'appealed'], true))) {
                echo '<form method="post" action="' . esc_url(FansUiSessions::url('session', $state->id)) . '">'; FansUiSessionForm::fields('submit', $row['revision'], $review['revision'] ?? '0');
                echo '<button class="fu-button fu-button--outline" type="submit">Soumettre les règles à examen</button></form>';
            }
            if ($current && ($review['state'] ?? '') === 'rejected') {
                $formRevision = $state->postAction === 'appeal' && $state->httpStatus() >= 400 ? $state->submittedReviewRevision : $review['revision'];
                echo '<form class="fu-hof-session__appeal" method="post" action="' . esc_url(FansUiSessions::url('session', $state->id)) . '">'; FansUiSessionForm::fields('appeal', $row['revision'], $formRevision);
                $stale = $state->postAction === 'appeal' && $state->httpStatus() >= 400 && $state->submittedReviewRevision !== $review['revision'];
                echo '<label for="fu-session-appeal">Motif de votre recours</label><textarea id="fu-session-appeal" name="explanation" required minlength="2" maxlength="1000" rows="4">' . esc_textarea($state->appealText) . '</textarea><button class="fu-button fu-button--outline" type="submit" ' . (!in_array($state->httpStatus(), [200, 400], true) || $stale ? 'disabled' : '') . '>Envoyer le recours</button></form>';
            }
            if ($row['state'] === 'draft') { echo '<p class="fu-hof__note">L’approbation des règles laisse la session en brouillon jusqu’à son ouverture.</p>'; }
            echo '</section>';
        }
        FansUiSessionOrganizersView::render($state);
    }

    /** @param array<string,string> $row */
    private static function period(array $row): string
    {
        try {
            $zone = new DateTimeZone($row['timezone']);
            return wp_date('d/m/Y à H:i', RankingCalendar::utc($row['starts_at'])->getTimestamp(), $zone) . ' — ' . wp_date('d/m/Y à H:i', RankingCalendar::utc($row['ends_at'])->getTimestamp(), $zone) . ' · ' . $row['timezone'];
        } catch (\Exception) { return 'Calendrier indisponible'; }
    }

    private static function sessionState(string $state): string
    { return match ($state) { 'draft' => 'Brouillon', 'opening' => 'Ouverture en cours', 'open' => 'Session ouverte', 'closing' => 'Fermeture en cours', 'closed' => 'Session clôturée', 'cancelled' => 'Session annulée', 'suspended' => 'Session suspendue', default => 'Session indisponible' }; }

    private static function notice(FansUiSessions $state): string
    {
        $data = $state->result?->get_data(); $code = is_array($data) ? ($data['code'] ?? '') : '';
        if ($code === 'hof_ambiguous_local_time') { return 'Cette heure apparaît deux fois au changement d’heure. Choisissez son décalage UTC.'; }
        if ($code === 'hof_nonexistent_local_time') { return 'Cette heure n’existe pas au changement d’heure. Choisissez une autre heure.'; }
        if ($code === 'hof_approved_creator_required') { return 'Un profil actif avec une présentation approuvée est nécessaire pour cette action.'; }
        return match ($state->httpStatus()) {
            200 => 'Votre action a été enregistrée. L’état courant est affiché.', 400 => 'Vérifiez les champs, les dates et le fuseau horaire.',
            403 => 'Accès ou confirmation insuffisante. Rechargez pour vérifier votre accès.', 409 => 'La session a changé ou cette action n’est plus disponible. Relisez la version courante.',
            429 => 'Trop de demandes récentes. Réessayez plus tard.', default => 'Action non confirmée. Consultez vos sessions avant de réessayer.',
        };
    }
}
