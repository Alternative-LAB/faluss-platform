<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Hof\ClosedRankingPreferences;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingValues;
use Faluss\Platform\Fans\Hof\RankingVisibility;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Private adapter to the existing alias decisions; no consent grant or identity change. */
final class RankingAliasPanel
{
    public static function available(): bool
    {
        if (!BackOffice::allowed()) { return false; }
        try {
            global $wpdb;
            if (!$wpdb instanceof \wpdb) { return false; }
            ClosedRankingPreferences::visibility($wpdb);
            return true;
        } catch (ModelViolation) { return false; }
    }

    public static function render(): void
    {
        if (!self::available()) { BackOfficeView::error('Modération des pseudonymes indisponible.', 403); return; }
        global $wpdb;
        try {
            $service = ClosedRankingPreferences::visibility($wpdb);
            $member = self::field('member', $_GET);
            if ($member !== '' && preg_match('/^[1-9][0-9]{0,9}$/D', $member) !== 1) { throw new ModelViolation('hof_invalid_input'); }
            echo '<h1>Pseudonymes de classement Fans</h1><p>Examiner les propositions et retirer une approbation. Le membre choisit séparément sa visibilité publique.</p>';
            echo '<p class="fb-meta">Horaires affichés dans le fuseau WordPress : ' . esc_html(wp_timezone()->getName()) . '.</p>';
            if ($member !== '') {
                $result = null;
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                    try { self::submit($service, (int) $member); $result = 200; }
                    catch (ModelViolation $error) { $result = self::status($error->reason); }
                    status_header($result);
                    echo '<p role="status" class="fb-empty">' . esc_html(match ($result) {
                        200 => 'Décision enregistrée. L’état courant est affiché.',
                        400 => 'Vérifiez la décision, son motif et la confirmation de retrait.',
                        403 => 'Autorisation ou nonce invalide. Rechargez avant de décider.',
                        404 => 'Compte lié indisponible.',
                        409 => 'Une autre décision a modifié cette révision. Relisez l’état courant.',
                        default => 'Décision non confirmée. Rechargez pour vérifier l’état enregistré.',
                    }) . '</p>';
                }
                self::detail((int) $member, $service->inspectForReview((int) $member));
                return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { throw new ModelViolation('hof_invalid_input'); }
            $cursor = self::field('hof_cursor', $_GET);
            if ($cursor !== '' && preg_match('/^[1-9][0-9]{0,9}$/D', $cursor) !== 1) { throw new ModelViolation('hof_invalid_input'); }
            $rows = $service->review((int) $cursor);
            echo '<h2>Propositions en attente</h2><div class="fb-grid">';
            foreach ($rows as $row) {
                echo '<article class="fb-card"><h3>' . esc_html($row['pending_alias']) . '</h3><p class="fb-meta">' . esc_html(self::date($row['updated_at'])) . '</p><a href="' . esc_url(BackOffice::url(['view' => 'ranking', 'member' => $row['wp_user_id']])) . '">Examiner la proposition →</a></article>';
            }
            echo '</div>';
            if ($rows === []) { echo '<p class="fb-empty">Aucune proposition en attente sur cette page.</p>'; }
            echo '<nav class="fb-pagination" aria-label="Pages des propositions"><a href="' . esc_url(BackOffice::url(['view' => 'ranking'])) . '">Première page</a>';
            if (count($rows) === 20) { echo '<a href="' . esc_url(BackOffice::url(['view' => 'ranking', 'hof_cursor' => $rows[19]['wp_user_id']])) . '">Propositions suivantes →</a>'; }
            echo '</nav>';
        } catch (ModelViolation $error) { BackOfficeView::error('Lecture des pseudonymes indisponible.', self::status($error->reason)); }
    }

    private static function submit(RankingVisibility $service, int $member): void
    {
        if (self::field('member_id', $_POST) !== (string) $member) { throw new ModelViolation('hof_invalid_input'); }
        if (wp_verify_nonce(self::field('hof_review_nonce', $_POST), 'fans_hof_review_' . $member) === false) { throw new ModelViolation('hof_forbidden'); }
        $revision = self::field('revision', $_POST); $decision = self::field('hof_review_action', $_POST);
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision) !== 1 || (int) $revision >= 2147483646
            || !in_array($decision, ['approve', 'reject', 'revoke'], true)
            || ($decision === 'revoke' && self::field('confirm_revoke', $_POST) !== 'yes')) { throw new ModelViolation('hof_invalid_input'); }
        $service->decide($member, (int) $revision, $decision, self::field('reason', $_POST));
    }

    /** @param array<string,string> $row */
    private static function detail(int $member, array $row): void
    {
        echo '<article class="fb-card faluss-moderation fb-hof-alias"><h2>Proposition et nom approuvé</h2><p><a href="' . esc_url(BackOffice::url(['view' => 'accounts', 'user' => (string) $member])) . '">Examiner le compte lié →</a></p>';
        if ($row['updated_at'] !== '') { echo '<p class="fb-meta">' . esc_html(self::date($row['updated_at'])) . '</p>'; }
        echo '<p>Présence publique Fan : ' . ($row['fan_public'] === '1' ? 'accord donné par le membre' : 'privée') . '.</p>';
        if ($row['approved_alias'] !== '') { echo '<p>Pseudonyme approuvé : <strong>' . esc_html($row['approved_alias']) . '</strong></p>'; }
        if ($row['alias_state'] === 'pending') {
            echo '<p>Proposition en attente : <strong>' . esc_html($row['pending_alias']) . '</strong></p>';
            self::form($member, $row['revision'], 'approve'); self::form($member, $row['revision'], 'reject');
        } else { echo '<p>Aucune proposition en attente.</p>'; }
        if ($row['approved_alias'] !== '') { self::form($member, $row['revision'], 'revoke'); }
        echo '</article><p><a href="' . esc_url(BackOffice::url(['view' => 'ranking'])) . '">Retour aux propositions</a></p>';
    }

    private static function form(int $member, string $revision, string $action): void
    {
        $url = BackOffice::url(['view' => 'ranking', 'member' => (string) $member]);
        echo '<form class="fb-hof-alias__form" method="post" action="' . esc_url($url) . '">';
        echo '<input type="hidden" name="hof_review_nonce" id="hof-review-nonce-' . esc_attr($action) . '" value="' . esc_attr(wp_create_nonce('fans_hof_review_' . $member)) . '">';
        echo '<input type="hidden" name="member_id" value="' . $member . '"><input type="hidden" name="revision" value="' . esc_attr($revision) . '"><input type="hidden" name="hof_review_action" value="' . esc_attr($action) . '">';
        if ($action === 'approve') { echo '<input type="hidden" name="reason" value="allowed_alias">'; }
        else {
            $id = 'hof-reason-' . $action;
            echo '<label for="' . esc_attr($id) . '">Motif</label><select id="' . esc_attr($id) . '" name="reason" required><option value="needs_revision">À revoir</option><option value="prohibited_content">Contenu interdit</option></select>';
        }
        if ($action === 'revoke') { echo '<label class="fb-hof-alias__confirm"><input type="checkbox" name="confirm_revoke" value="yes" required> Retirer immédiatement le pseudonyme approuvé et l’accord de visibilité Fan.</label>'; }
        echo '<button type="submit" class="fb-button">' . match ($action) { 'approve' => 'Approuver le pseudonyme', 'reject' => 'Refuser la proposition', default => 'Révoquer le pseudonyme' } . '</button></form>';
    }

    private static function date(string $utc): string
    { $date = new \DateTimeImmutable(RankingValues::utc($utc), new \DateTimeZone('UTC')); return wp_date('d/m/Y à H:i:s', $date->getTimestamp()) . ' · ' . wp_timezone()->getName(); }
    /** @param array<string,mixed> $source */
    private static function field(string $key, array $source): string { return ModerationPanel::field($key, $source); }
    private static function status(string $reason): int
    { return match ($reason) { 'hof_forbidden' => 403, 'hof_invalid_input', 'hof_invalid_decision' => 400, 'hof_linked_member_required' => 404, 'hof_revision_conflict' => 409, default => 503 }; }
}
