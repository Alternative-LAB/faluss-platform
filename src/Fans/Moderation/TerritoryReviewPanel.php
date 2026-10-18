<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Hof\ClosedSessionManagement;
use Faluss\Platform\Fans\Hof\ClosedTerritoryActions;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChangeSchema;
use Faluss\Platform\Fans\Hof\ClosedTerritoryChanges;
use Faluss\Platform\Fans\Hof\TerritorySchema;
use Faluss\Platform\Fans\Hof\TerritoryService;
use Faluss\Platform\Fans\Ui\FansUiTerritoryView;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Administration in the existing panel; no policy attestation or privilege granted by rendering. */
final class TerritoryReviewPanel
{
    public static function available(): bool
    {
        if (!BackOffice::allowed()) { return false; }
        try { global $wpdb; if (!$wpdb instanceof \wpdb) { return false; } ClosedSessionManagement::services($wpdb); return TerritorySchema::ready($wpdb); }
        catch (ModelViolation) { return false; }
    }

    public static function render(): void
    {
        if (!self::available()) { BackOfficeView::error('Examen territorial indisponible.',403); return; }
        global $wpdb; $service = new TerritoryService($wpdb);
        try {
            echo '<h1>Territoires d’activité</h1><p class="fb-meta">Horaires affichés dans le fuseau WordPress : ' . esc_html(wp_timezone()->getName()) . '.</p>';
            $creator = self::field('creator',$_GET);
            if ($creator === '') {
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { throw new ModelViolation('hof_invalid_territory_input'); }
                $rows = $service->review(self::field('territory_cursor',$_GET));
                foreach ($rows as $row) {
                    echo '<article class="fb-card"><h2>' . esc_html(FansUiTerritoryView::state($row['state'])) . '</h2>'; AccountContext::creator($row['creator_id']);
                    echo '<p>' . esc_html(FansUiTerritoryView::date($row['updated_at'])) . '</p><a href="' . esc_url(self::url($row['creator_id'])) . '">Examiner la déclaration →</a></article>';
                }
                if ($rows === []) { echo '<p class="fb-empty">Aucune déclaration sur cette page.</p>'; }
                echo '<nav class="fb-pagination" aria-label="Déclarations territoriales"><a href="' . esc_url(self::url()) . '">Première page</a>';
                if (count($rows) === 20) { echo '<a href="' . esc_url(BackOffice::url(['view' => 'territories','territory_cursor' => $rows[19]['creator_id']])) . '">Déclarations suivantes →</a>'; }
                echo '</nav>'; return;
            }
            $before = self::field('history_cursor',$_GET);
            if ($before !== '' && (preg_match('/^[1-9][0-9]{0,9}$/D',$before) !== 1 || (int) $before > 2147483646)) { throw new ModelViolation('hof_invalid_territory_cursor'); }
            $service->examine($creator);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                try { $status = self::submit($service,$creator); }
                catch (ModelViolation $error) { $status = self::status($error->reason); }
                status_header($status);
                echo '<p role="status" class="fb-empty">' . esc_html(match ($status) {
                    200 => 'Décision enregistrée. L’état courant est affiché.',202 => 'La fermeture reste en cours de confirmation.',
                    400 => 'Vérifiez la décision, le motif et la confirmation.',403 => 'Autorisation ou confirmation invalide.',
                    409 => 'La déclaration a changé. Relisez sa révision courante.',default => 'La dernière étape n’est pas confirmée. Consultez l’état enregistré avant de réessayer.',
                }) . '</p>';
            }
            $value = $service->examine($creator,$before === '' ? 2147483646 : (int) $before); $row = $value['declaration'];
            echo '<article class="fb-card faluss-moderation"><h2>' . esc_html(FansUiTerritoryView::state($row['state'])) . '</h2>'; AccountContext::creator($creator);
            $name = $value['policy']['territories'][$row['territory_ref']]['name'] ?? '';
            if ($name !== '') { echo '<p>' . esc_html($name . ' · ' . $row['country']) . '</p>'; }
            FansUiTerritoryView::criteria($value['policy']);
            if ($row['appeal_text'] !== '') { echo '<h3>Recours du Créateur</h3><p class="fm-text">' . esc_html($row['appeal_text']) . '</p>'; }
            if (in_array($row['state'],['pending','appealed','approved'],true)) {
                echo '<form method="post" action="' . esc_url(self::url($creator)) . '">'; self::fields($creator,$row['revision']);
                echo '<label for="fb-territory-reason">Motif de la décision</label><select id="fb-territory-reason" name="reason">';
                foreach (['criteria_satisfied' => 'Critères remplis','criteria_not_met' => 'Critères non remplis','territory_unsubstantiated' => 'Territoire non établi','needs_revision' => 'Nouvel examen nécessaire'] as $key => $label) { echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>'; }
                echo '</select>';
                if ($row['state'] === 'approved') {
                    echo '<label><input type="checkbox" name="confirm_revoke" value="yes" required> Révoquer cette admission et fermer les nouvelles contributions territoriales concernées</label><button name="territory_review_action" value="revoke" type="submit">Révoquer l’admission</button>';
                } else { echo '<button name="territory_review_action" value="approve" type="submit">Approuver</button><button name="territory_review_action" value="reject" type="submit">Refuser</button>'; }
                echo '</form>';
            }
            echo '<h3>Historique des décisions</h3>';
            foreach ($value['history'] as $entry) { echo '<p>' . esc_html($entry['action'] . ' · ' . $entry['reason'] . ' · ' . FansUiTerritoryView::date($entry['occurred_at'])) . '</p>'; }
            if (count($value['history']) === 20) { echo '<a href="' . esc_url(self::url($creator,['history_cursor' => $value['history'][19]['revision']])) . '">Décisions antérieures →</a>'; }
            echo '</article>';
            if (ClosedTerritoryChangeSchema::ready($wpdb)) { self::changes($creator,$row['revision']); }
        } catch (ModelViolation $error) { BackOfficeView::error('Lecture territoriale indisponible.',self::status($error->reason)); }
    }

    private static function submit(TerritoryService $service, string $creator): int
    {
        if (self::field('creator_id',$_POST) !== $creator || wp_verify_nonce(self::field('territory_review_nonce',$_POST),'fans_hof_territory_review_' . $creator) === false) { throw new ModelViolation('hof_session_forbidden'); }
        $action = self::field('territory_review_action',$_POST); $revision = self::field('territory_revision',$_POST);
        if (preg_match('/^[1-9][0-9]{0,9}$/D',$revision) !== 1 || (int) $revision >= 2147483646) { throw new ModelViolation('hof_invalid_territory_input'); }
        if ($action === 'resume') {
            $change = self::field('change',$_GET);
            if ($change === '' || $change !== self::field('change_id',$_POST)) { throw new ModelViolation('hof_invalid_territory_input'); }
            if ($service->examine($creator)['declaration']['revision'] !== $revision) { throw new ModelViolation('hof_territory_revision_conflict'); }
            global $wpdb; $journal = new ClosedTerritoryChanges($wpdb);
            if ($journal->progress($change)['change']['creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
            return (new ClosedTerritoryActions($wpdb))->resume($change)['change']['phase'] === 'completed' ? 200 : 202;
        }
        if (!in_array($action,['approve','reject','revoke'],true) || ($action === 'revoke' && self::field('confirm_revoke',$_POST) !== 'yes')) { throw new ModelViolation('hof_invalid_territory_input'); }
        $service->decide($creator,(int) $revision,$action,self::field('reason',$_POST)); return 200;
    }

    private static function changes(string $creator, string $revision): void
    {
        global $wpdb; $journal = new ClosedTerritoryChanges($wpdb); $cursor = self::field('change_cursor',$_GET);
        $changes = $journal->listing($creator,$cursor); echo '<h2>Fermetures territoriales</h2>';
        foreach ($changes as $row) { echo '<p><a href="' . esc_url(self::url($creator,['change' => $row['change_id']])) . '">' . esc_html(FansUiTerritoryView::phase($row['phase']) . ' · ' . FansUiTerritoryView::date($row['updated_at'])) . '</a></p>'; }
        if (count($changes) === 20) { echo '<a href="' . esc_url(self::url($creator,['change_cursor' => $changes[19]['change_id']])) . '">Fermetures suivantes →</a>'; }
        $id = self::field('change',$_GET); if ($id === '') { return; } $progress = $journal->progress($id);
        if ($progress['change']['creator_id'] !== $creator) { throw new ModelViolation('hof_session_forbidden'); }
        echo '<p>' . esc_html(FansUiTerritoryView::phase($progress['change']['phase'])) . ' · ' . esc_html((string) $progress['applied']) . ' / ' . esc_html((string) $progress['total']) . ' confirmations.</p>';
        if ($progress['change']['phase'] !== 'completed') {
            echo '<form method="post" action="' . esc_url(self::url($creator,['change' => $id])) . '">'; self::fields($creator,$revision);
            echo '<input type="hidden" name="change_id" value="' . esc_attr($id) . '"><button name="territory_review_action" value="resume" type="submit">Poursuivre la confirmation</button></form>';
        }
    }

    private static function fields(string $creator, string $revision): void
    { foreach (['creator_id' => $creator,'territory_revision' => $revision,'territory_review_nonce' => wp_create_nonce('fans_hof_territory_review_' . $creator)] as $key => $value) { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">'; } }
    /** @param array<string,string> $args */
    private static function url(string $creator = '', array $args = []): string
    { return BackOffice::url(['view' => 'territories','creator' => $creator] + $args); }
    /** @param array<string,mixed> $source */
    private static function field(string $name, array $source): string
    { return isset($source[$name]) && is_string($source[$name]) ? trim(wp_unslash($source[$name])) : ''; }
    private static function status(string $reason): int
    { return match ($reason) {'hof_session_forbidden','hof_forbidden' => 403,'hof_territory_not_found','hof_territory_checkpoint_not_found' => 404,'hof_territory_revision_conflict' => 409,default => str_starts_with($reason,'hof_invalid_') ? 400 : 503}; }
}
