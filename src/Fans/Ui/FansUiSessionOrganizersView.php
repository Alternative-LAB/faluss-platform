<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiSessionOrganizersView
{
    public static function render(FansUiSessions $state): void
    {
        $view = $state->organizers;
        if ($view === null || $state->detail === null) { return; }
        echo '<section class="fu-panel fu-hof-session__summary"><h3>Coorganisation</h3><ul>';
        foreach ($view->items as $item) {
            $label = match ($item['state']) { 'accepted' => 'Acceptée', 'invited' => 'Invitation en attente', 'refused' => 'Invitation refusée', default => 'Indisponible' };
            echo '<li>' . esc_html($item['name'] ?? 'Présentation indisponible') . ($item['id'] === $state->creatorId ? ' · Vous' : '') . ' · ' . esc_html($label) . '</li>';
        }
        echo '</ul>';
        if ($state->httpStatus() >= 400) {
            echo '<p><a class="fu-link" href="' . esc_url(FansUiSessions::url('session', $state->id)) . '">Relire les invitations courantes →</a></p></section>'; return;
        }
        if ($state->detail['session']['state'] === 'draft' && $view->ownState === 'invited') {
            echo '<p>Cette invitation concerne la coorganisation. Votre participation reste un choix distinct.</p>';
            if ($state->active) { self::answer($state, 'accept_organizer', 'Accepter la coorganisation'); }
            self::answer($state, 'refuse_organizer', 'Refuser l’invitation');
        }
        if ($state->detail['session']['state'] === 'draft' && $view->manager) {
            echo '<p>Les invitations en attente doivent être acceptées ou refusées avant l’ouverture.</p>';
            if ($view->candidates !== []) {
                echo '<form class="fu-hof-session__summary fu-hof-session__invitation" method="post" action="' . esc_url(FansUiSessions::url('session', $state->id)) . '">';
                FansUiSessionForm::fields('invite_organizer', $state->detail['session']['revision'], $state->review['revision'] ?? '0');
                echo '<label for="fu-session-organizer">Inviter un Créateur</label><select id="fu-session-organizer" name="organizer_target" required><option value="">Choisir un Créateur</option>';
                foreach ($view->candidates as $candidate) { echo '<option value="' . esc_attr($candidate['id']) . '">' . esc_html($candidate['name']) . '</option>'; }
                echo '</select><button class="fu-button fu-button--outline" type="submit">Envoyer l’invitation</button></form>';
            } else { echo '<p>Aucun Créateur disponible sur cette page.</p>'; }
            echo '<nav class="fu-hof__pagination" aria-label="Pages des Créateurs à inviter">';
            if ($view->paged) { echo '<a href="' . esc_url(FansUiSessions::url('session', $state->id)) . '">Première page</a>'; }
            if ($view->next !== null) { echo '<a href="' . esc_url(add_query_arg('coorganizer_cursor', $view->next, FansUiSessions::url('session', $state->id))) . '">Créateurs suivants →</a>'; }
            echo '</nav>';
        }
        echo '</section>';
    }

    private static function answer(FansUiSessions $state, string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(FansUiSessions::url('session', $state->id)) . '">';
        FansUiSessionForm::fields($action, $state->detail['session']['revision'] ?? '0', '0');
        echo '<button class="fu-button fu-button--outline" type="submit">' . esc_html($label) . '</button></form>';
    }
}
