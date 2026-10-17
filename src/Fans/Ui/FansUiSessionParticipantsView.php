<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiSessionParticipantsView
{
    public static function join(FansUiSessions $state): void
    {
        if ($state->detail === null) { return; }
        $row = $state->detail['session']; $own = $state->participants?->own;
        echo '<section class="fu-panel fu-hof-session__summary"><h2>Participer à ' . esc_html($row['title']) . '</h2><p class="fu-hof-session__text">' . esc_html($row['rules_text']) . '</p>';
        echo '<p>La participation est individuelle et volontaire. L’organisateur examine votre demande.</p>';
        if ($own !== null && !in_array($own['state'],['refused','withdrawn'],true)) {
            echo '<p><a class="fu-button fu-button--outline" href="' . esc_url(FansUiSessions::url('session',$state->id)) . '">Retrouver ma demande →</a></p>';
        } elseif ($state->active && $state->httpStatus() < 400) {
            self::begin($state,'request_participation');
            echo '<input type="hidden" name="rules_sha256" value="' . esc_attr($row['frozen_sha256']) . '">';
            echo '<label for="fu-participation-accept"><input id="fu-participation-accept" type="checkbox" name="accept_participation_rules" value="yes" required> J’accepte ces règles pour ma participation.</label>';
            echo '<button class="fu-button" type="submit">Envoyer ma demande</button></form>';
        } else { echo '<p><a class="fu-link" href="' . esc_url(FansUiSessions::url('join',$state->id)) . '">Relire les règles courantes →</a></p>'; }
        echo '</section>';
    }

    public static function render(FansUiSessions $state): void
    {
        $view = $state->participants; $row = $state->detail['session'] ?? null;
        if ($view === null || $row === null) { return; }
        if ($view->own === null && $row['state'] !== 'open' && $view->items === []) { return; }
        echo '<section class="fu-panel fu-hof-session__summary"><h3>Ma participation</h3>';
        if ($view->own === null) {
            echo '<p>La coorganisation ne vous inscrit pas comme participant.</p>';
            if ($state->active && $row['state'] === 'open') { self::entry($state); }
        } else {
            $own = $view->own;
            echo '<p>' . esc_html(self::state($own['state'],$view->checkpoint['opening_applied'])) . '</p>';
            if ($state->participationAppeal !== '') { echo '<details open><summary>Votre texte de recours</summary><p class="fu-hof-session__text">' . esc_html($state->participationAppeal) . '</p></details>'; }
            if ($state->httpStatus() >= 400) {
                echo '<p><a class="fu-link" href="' . esc_url(FansUiSessions::url('session',$state->id)) . '">Relire ma participation →</a></p>';
            } else {
                if (in_array($own['state'],['requested','refused','admitted'],true)) {
                    self::begin($state,'withdraw_participation'); echo '<button class="fu-button fu-button--outline" type="submit">' . ($own['state'] === 'admitted' ? 'Retirer ma participation' : 'Retirer ma demande') . '</button></form>';
                }
                if ($view->transport && ($own['state'] === 'closing' || ($own['state'] === 'admitted' && !$view->checkpoint['opening_applied']))) {
                    self::resume($state,$state->creatorId);
                }
                if ($own['state'] === 'refused') { self::appeal($state); }
                if ($state->active && in_array($own['state'],['refused','withdrawn'],true) && $row['state'] === 'open') { self::entry($state); }
            }
        }
        echo '</section>';
        if (!$view->manager) { return; }
        echo '<section class="fu-panel fu-hof-session__summary"><h3>Demandes et participants</h3><ul class="fu-hof-session__participants">';
        foreach ($view->items as $item) {
            echo '<li><div><strong>' . esc_html($item['name'] ?? 'Présentation indisponible') . '</strong><p>' . esc_html(self::state($item['state'],false)) . '</p></div>';
            if ($state->httpStatus() < 400 && $state->active && $row['state'] === 'open' && $item['state'] === 'requested') {
                if ($view->transport && $item['name'] !== null) {
                    self::begin($state,'admit_participant',$item['id']); echo '<button class="fu-button" type="submit" aria-label="' . esc_attr('Accepter la demande de ' . $item['name']) . '">Accepter</button></form>';
                }
                self::begin($state,'refuse_participant',$item['id']); echo '<button class="fu-button fu-button--outline" type="submit" aria-label="' . esc_attr($item['name'] === null ? 'Refuser cette demande' : 'Refuser la demande de ' . $item['name']) . '">Refuser</button></form>';
            }
            if ($state->httpStatus() < 400 && $view->transport && in_array($item['state'],['admitted','closing'],true)) { self::resume($state,$item['id']); }
            echo '</li>';
        }
        echo '</ul>';
        if ($view->items === []) { echo '<p>Aucune participation sur cette page.</p>'; }
        echo '<nav class="fu-hof__pagination" aria-label="Pages des participants">';
        if ($view->paged) { echo '<a href="' . esc_url(FansUiSessions::url('session',$state->id)) . '">Première page</a>'; }
        if ($view->next !== null) { echo '<a href="' . esc_url(add_query_arg('participant_cursor',$view->next,FansUiSessions::url('session',$state->id))) . '">Participants suivants →</a>'; }
        echo '</nav></section>';
    }

    private static function entry(FansUiSessions $state): void
    { echo '<a class="fu-button fu-button--outline" href="' . esc_url(FansUiSessions::url('join',$state->id)) . '">Demander à participer →</a>'; }

    private static function resume(FansUiSessions $state, string $target): void
    {
        self::begin($state,'resume_participation',$target);
        echo '<button class="fu-button fu-button--outline" type="submit">Vérifier la confirmation</button></form>';
    }

    private static function appeal(FansUiSessions $state): void
    {
        $view = $state->participants;
        if ($view === null || $view->own === null) { return; }
        if ($view->appeal !== null) {
            echo '<p>' . ($view->appeal['state'] === 'pending' ? 'Votre recours est en cours d’examen.' : 'Votre recours a été examiné.') . '</p>';
            if ($view->appeal['state'] === 'decided') { echo '<p>' . esc_html(match ($view->appeal['reason']) {
                'criteria_not_met' => 'Les critères de participation ne sont pas remplis.', 'territory_unsubstantiated' => 'Le territoire d’activité doit être justifié.',
                'needs_revision' => 'Les éléments doivent être révisés.', default => 'Le contexte de cette demande ne permet plus son admission.',
            }) . '</p>'; }
            return;
        }
        self::begin($state,'appeal_participation');
        echo '<input type="hidden" name="role_revision" value="' . esc_attr($view->own['revision']) . '"><label for="fu-participation-appeal">Demander un réexamen</label>';
        echo '<textarea id="fu-participation-appeal" name="explanation" rows="3" required minlength="2" maxlength="1000">' . esc_textarea($state->participationAppeal) . '</textarea>';
        echo '<button class="fu-button fu-button--outline" type="submit">Envoyer mon recours</button></form>';
    }

    private static function begin(FansUiSessions $state, string $action, string $target = ''): void
    {
        $url = FansUiSessions::url($state->mode,$state->id);
        if (($state->participants->manager ?? false) && $state->participants->cursor !== '') {
            $url = add_query_arg('participant_cursor',$state->participants->cursor,$url);
        }
        echo '<form class="fu-hof-session__summary fu-hof-session__participation-form" method="post" action="' . esc_url($url) . '">';
        FansUiSessionForm::fields($action,$state->detail['session']['revision'] ?? '0','0');
        if ($target !== '') { echo '<input type="hidden" name="participant_target" value="' . esc_attr($target) . '">'; }
    }

    private static function state(string $state, bool $confirmed): string
    {
        return match ($state) {
            'requested' => 'Demande en attente.', 'refused' => 'Demande refusée.', 'admitted' => $confirmed ? 'Admission confirmée.' : 'Demande acceptée ; confirmation à vérifier.',
            'closing' => 'Retrait enregistré ; fermeture en cours.', 'withdrawn' => 'Participation retirée.', default => 'Participation indisponible.',
        };
    }
}
