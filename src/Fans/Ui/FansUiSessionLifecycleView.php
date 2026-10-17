<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

/** Explicit lifecycle forms. Progress comes from the existing local row and primary ACK only. */
final class FansUiSessionLifecycleView
{
    public static function render(FansUiSessions $state): void
    {
        if (!$state->isOwner() || $state->detail === null) { return; }
        $row = $state->detail['session'];
        if (!in_array($row['state'], ['draft', 'opening', 'open', 'closing', 'suspended', 'closed', 'cancelled'], true)) { return; }
        echo '<section class="fu-panel fu-hof-session__summary"><h3>Ouverture et clôture</h3>';
        if (!$state->transportAvailable) {
            echo '<p>La confirmation d’ouverture ou de clôture est indisponible pour le moment.</p></section>'; return;
        }
        if ($state->httpStatus() >= 400) {
            echo '<p><a class="fu-link" href="' . esc_url(FansUiSessions::url('session', $state->id)) . '">Relire l’état courant avant de continuer →</a></p></section>'; return;
        }
        if (in_array($row['state'], ['opening', 'closing'], true)) {
            echo '<p>' . ($row['state'] === 'opening' ? 'L’ouverture attend sa confirmation.' : 'Les nouvelles contributions sont fermées. La clôture attend sa confirmation.') . '</p>';
            self::form($state, 'resume', 'Reprendre la vérification');
        }
        if ($state->active && $state->currentReview() && ($state->review['state'] ?? '') === 'approved') {
            if ($row['state'] === 'draft') {
                echo '<p>Les règles, dates, catégorie et portée seront figées dès cette demande.</p>';
                self::form($state, 'open', 'Demander l’ouverture');
            } elseif ($row['state'] === 'suspended') {
                echo '<p>La réadmission conserve les règles et les contributions antérieures corrigées.</p>';
                self::form($state, 'readmit', 'Demander la réadmission');
            }
        }
        if ($state->active && $row['state'] === 'open' && $state->serverNow !== '' && $row['ends_at'] <= $state->serverNow) {
            self::form($state, 'complete', 'Confirmer la fin à l’échéance', 'Je demande la clôture à l’échéance prévue.');
        }
        if ($state->active && in_array($row['state'], ['opening', 'open'], true)) {
            echo '<p>Une annulation conserve l’historique et les corrections, sans désigner de vainqueur.</p>';
            self::form($state, 'cancel', 'Annuler cette session', 'Je confirme l’annulation de cette session.');
        }
        if (in_array($row['state'], ['open', 'closed', 'cancelled', 'suspended'], true)) { self::form($state, 'refresh', 'Vérifier l’affichage'); }
        echo '</section>';
    }

    private static function form(FansUiSessions $state, string $action, string $label, string $confirmation = ''): void
    {
        $row = $state->detail['session'] ?? [];
        echo '<form class="fu-hof-session__summary" method="post" action="' . esc_url(FansUiSessions::url('session', $state->id)) . '">';
        FansUiSessionForm::fields($action, $row['revision'] ?? '0', $state->review['revision'] ?? '0');
        if ($confirmation !== '') {
            echo '<label for="fu-session-confirm-' . esc_attr($action) . '"><input id="fu-session-confirm-' . esc_attr($action) . '" type="checkbox" name="hof_session_confirmation" value="yes" required> ' . esc_html($confirmation) . '</label>';
        }
        echo '<button class="fu-button fu-button--outline" type="submit">' . esc_html($label) . '</button></form>';
    }
}
