<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Hof\ClosedSessionManagement;
use Faluss\Platform\Fans\Hof\RankingCalendar;
use Faluss\Platform\Fans\Hof\SessionModeration;
use Faluss\Platform\Fans\Hof\SessionRules;
use Faluss\Platform\Fans\Hof\SessionService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

final class SessionReviewPanel
{
    public static function available(): bool
    {
        if (!BackOffice::allowed()) { return false; }
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { return false; }
            ClosedSessionManagement::services($wpdb); return true;
        } catch (ModelViolation) { return false; }
    }

    public static function render(): void
    {
        if (!self::available()) { BackOfficeView::error('Examen des sessions indisponible.', 403); return; }
        global $wpdb;
        try {
            $services = ClosedSessionManagement::services($wpdb); $sessions = $services['sessions']; $reviews = $services['reviews'];
            $id = self::field('session', $_GET);
            echo '<h1>Examen des sessions HoF</h1><p>L’approbation des règles ne vaut ni ouverture de session ni accord commercial.</p><p class="fb-meta">Horaires affichés dans le fuseau WordPress : ' . esc_html(wp_timezone()->getName()) . '.</p>';
            if ($id !== '') {
                $historyCursor = self::field('history_cursor', $_GET);
                if ($historyCursor !== '' && (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $historyCursor) !== 1 || (int) $historyCursor >= 2147483646)) { throw new ModelViolation('hof_invalid_review_cursor'); }
                $sessions->inspect($id); $status = 200;
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                    try { self::submit($reviews, $id); }
                    catch (ModelViolation $error) { $status = self::status($error->reason); }
                    status_header($status);
                    echo '<p class="fb-empty" role="status">' . esc_html(match ($status) {
                        200 => 'Décision enregistrée. L’état courant est affiché.', 400 => 'Vérifiez la décision, le motif et la confirmation.',
                        403 => 'Autorisation ou confirmation invalide. Rechargez avant de décider.',
                        409 => 'La session ou son examen a changé. Relisez les règles courantes avant de décider.',
                        default => 'Décision non confirmée. Rechargez pour vérifier son enregistrement.',
                    }) . '</p>';
                }
                self::detail($sessions, $reviews, $id, $status === 200, (int) $historyCursor); return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { throw new ModelViolation('hof_invalid_review_decision'); }
            $rows = $reviews->review(self::field('review_cursor', $_GET));
            echo '<div class="fb-grid">';
            foreach ($rows as $row) {
                $session = $sessions->inspect($row['session_id'])['session'];
                $current = $row['rules_sha256'] === SessionRules::digest($session);
                echo '<article class="fb-card"><p class="fb-meta">' . esc_html($current ? self::state($row['state']) : 'Règles modifiées depuis l’examen') . ' · ' . esc_html(self::date($row['updated_at'])) . '</p><h2>' . esc_html($session['title']) . '</h2><a href="' . esc_url(self::url($row['session_id'])) . '">Examiner la session →</a></article>';
            }
            echo '</div>';
            if ($rows === []) { echo '<p class="fb-empty">Aucun examen sur cette page.</p>'; }
            echo '<nav class="fb-pagination" aria-label="Pages des examens"><a href="' . esc_url(self::url()) . '">Première page</a>';
            if (count($rows) === 20) { echo '<a href="' . esc_url(BackOffice::url(['view' => 'sessions', 'review_cursor' => $rows[19]['session_id']])) . '">Examens suivants →</a>'; }
            echo '</nav>';
        } catch (ModelViolation $error) { BackOfficeView::error('Lecture de session indisponible.', self::status($error->reason)); }
    }

    private static function submit(SessionModeration $reviews, string $id): void
    {
        if (self::field('session_id', $_POST) !== $id || wp_verify_nonce(self::field('session_review_nonce', $_POST), 'fans_hof_session_review_' . $id) === false) { throw new ModelViolation('hof_session_forbidden'); }
        $revision = self::field('review_revision', $_POST); $action = self::field('session_review_action', $_POST);
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $revision) !== 1 || (int) $revision >= 2147483646
            || !in_array($action, ['approve', 'reject', 'revoke'], true) || ($action === 'revoke' && self::field('confirm_revoke', $_POST) !== 'yes')) { throw new ModelViolation('hof_invalid_review_decision'); }
        $reviews->decide($id, (int) $revision, $action, self::field('reason', $_POST));
    }

    private static function detail(SessionService $sessions, SessionModeration $reviews, string $id, bool $allow, int $historyCursor): void
    {
        $session = $sessions->inspect($id)['session']; $row = $reviews->own($id);
        echo '<article class="fb-card faluss-moderation fb-hof-session-review"><h2>' . esc_html($session['title']) . '</h2><p class="fm-text">' . esc_html($session['rules_text']) . '</p><p>Début : ' . esc_html(self::date($session['starts_at'])) . '<br>Fin : ' . esc_html(self::date($session['ends_at'])) . '</p>';
        echo '<dl>';
        foreach (['category' => 'Catégorie', 'scope' => 'Portée', 'country' => 'Pays', 'territory_ref' => 'Localité examinée', 'timezone' => 'Fuseau choisi'] as $key => $label) {
            $value = $session[$key];
            if ($key === 'category') { $value = ['arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Savoirs', 'lifestyle' => 'Art de vivre'][$value] ?? 'Indisponible'; }
            if ($key === 'scope') { $value = ['international' => 'Internationale', 'national' => 'Nationale', 'local' => 'Locale'][$value] ?? 'Indisponible'; }
            if ($value !== '') { echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>'; }
        }
        echo '</dl><p><a href="' . esc_url(BackOffice::url(['view' => 'profiles', 'creator' => $session['owner_creator_id']])) . '">Examiner le profil propriétaire →</a></p>';
        if ($row === null) { echo '<p>Aucune proposition soumise à examen.</p></article>'; return; }
        $current = $row['rules_sha256'] === SessionRules::digest($session);
        echo '<p>' . esc_html($current ? self::state($row['state']) : 'Règles modifiées depuis l’examen : une nouvelle soumission est nécessaire.') . ' · ' . esc_html(self::date($row['updated_at'])) . '</p><p>' . esc_html(self::reason($row['reason'])) . '</p>';
        if ($row['appeal_text'] !== '') { echo '<h3>Recours du propriétaire</h3><p class="fm-text">' . esc_html($row['appeal_text']) . '</p>'; }
        if ($session['state'] === 'closing') { echo '<p>Fermeture en cours ; la confirmation Hub reste attendue.</p>'; }
        if ($allow && $current) {
            foreach (in_array($row['state'], ['pending', 'appealed'], true) ? ['approve', 'reject'] : ($row['state'] === 'approved' ? ['revoke'] : []) as $action) { self::form($id, $row['revision'], $action); }
        }
        echo '</article><h2>Journal des décisions</h2>';
        $history = $reviews->history($id, $historyCursor);
        foreach ($history as $event) { echo '<article class="fb-card"><p>Révision ' . esc_html($event['revision']) . ' · ' . esc_html(self::date($event['occurred_at'])) . '</p><p>' . esc_html(self::decision($event['action'])) . ' · ' . esc_html(self::reason($event['reason'])) . '</p></article>'; }
        if (count($history) === 20) { echo '<p><a href="' . esc_url(add_query_arg('history_cursor', $history[19]['revision'], self::url($id))) . '">Décisions suivantes →</a></p>'; }
        echo '<p><a href="' . esc_url(self::url()) . '">Retour aux examens</a></p>';
    }

    private static function form(string $id, string $revision, string $action): void
    {
        echo '<form class="fb-hof-session-review__form" method="post" action="' . esc_url(self::url($id)) . '"><input type="hidden" id="hof-session-review-' . esc_attr($action) . '" name="session_review_nonce" value="' . esc_attr(wp_create_nonce('fans_hof_session_review_' . $id)) . '">';
        foreach (['session_id' => $id, 'review_revision' => $revision, 'session_review_action' => $action] as $name => $value) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
        if ($action === 'approve') { echo '<input type="hidden" name="reason" value="allowed_session">'; }
        else {
            echo '<label for="hof-session-reason-' . esc_attr($action) . '">Motif</label><select id="hof-session-reason-' . esc_attr($action) . '" name="reason" required>';
            foreach (['needs_revision' => 'À revoir', 'prohibited_content' => 'Contenu interdit', 'criteria_not_met' => 'Critères non remplis'] as $value => $label) { echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>'; }
            echo '</select>';
        }
        if ($action === 'revoke') { echo '<label class="fm-confirm"><input type="checkbox" name="confirm_revoke" value="yes" required> Retirer l’approbation des règles.</label>'; }
        echo '<button class="fb-button" type="submit">' . esc_html(match ($action) { 'approve' => 'Approuver les règles', 'reject' => 'Refuser la proposition', default => 'Révoquer l’approbation' }) . '</button></form>';
    }

    private static function url(string $id = ''): string { return BackOffice::url(['view' => 'sessions', 'session' => $id]); }
    private static function date(string $utc): string { return wp_date('d/m/Y à H:i:s', RankingCalendar::utc($utc)->getTimestamp()) . ' · ' . wp_timezone()->getName(); }
    private static function state(string $state): string { return match ($state) { 'pending' => 'En attente', 'approved' => 'Règles approuvées', 'rejected' => 'Proposition refusée', 'appealed' => 'Recours en attente', default => 'Examen indisponible' }; }
    private static function decision(string $action): string { return match ($action) { 'submit' => 'Proposition soumise', 'approve' => 'Approbation', 'reject' => 'Refus', 'revoke' => 'Révocation', 'appeal' => 'Recours reçu', default => 'Décision enregistrée' }; }
    private static function reason(string $reason): string { return match ($reason) { 'needs_revision' => 'À revoir', 'prohibited_content' => 'Contenu interdit', 'criteria_not_met' => 'Critères non remplis', 'allowed_session' => 'Règles admises', 'awaiting_review' => 'Examen demandé', 'member_appeal' => 'Recours du propriétaire', default => '' }; }
    /** @param array<string,mixed> $data */
    private static function field(string $key, array $data): string { return ModerationPanel::field($key, $data); }
    private static function status(string $reason): int { return match ($reason) { 'hof_session_forbidden', 'hof_forbidden' => 403, 'hof_session_not_found', 'hof_review_not_found' => 404, 'hof_review_revision_conflict', 'hof_review_rules_changed', 'hof_approved_creator_required' => 409, default => str_starts_with($reason, 'hof_invalid_') || $reason === 'invalid_identity_or_reference' ? 400 : 503 }; }
}
