<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Hof\ClosedSessionManagement;
use Faluss\Platform\Fans\Hof\SessionAppeals;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** The existing private appeal decisions, including their atomic Hub admission checkpoint. */
final class ParticipationAppealPanel
{
    public static function available(): bool
    { return SessionReviewPanel::available(); }

    public static function render(): void
    {
        if (!self::available()) { BackOfficeView::error('Recours de participation indisponibles.',403); return; }
        global $wpdb;
        echo '<div class="fb-participation">';
        try {
            $services = ClosedSessionManagement::services($wpdb); $appeals = new SessionAppeals($wpdb);
            $id = self::field('case',$_GET); $status = 200;
            echo '<h1>Recours de participation</h1><p class="fb-meta">Horaires affichés dans le fuseau WordPress : ' . esc_html(wp_timezone()->getName()) . '.</p>';
            if ($id === '') {
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { throw new ModelViolation('hof_invalid_appeal_decision'); }
                $rows = $appeals->list(self::field('appeal_cursor',$_GET));
                echo '<div class="fb-grid">';
                foreach ($rows as $case) {
                    $session = $services['sessions']->inspect($case['session_id'])['session'];
                    echo '<article class="fb-card"><p class="fb-meta">' . esc_html($case['state'] === 'pending' ? 'À examiner' : 'Examiné') . ' · ' . esc_html(self::date($case['updated_at'])) . '</p><h2>' . esc_html($session['title']) . '</h2><p>' . esc_html(self::name($case['creator_id'])) . '</p><a href="' . esc_url(self::url($case['case_id'])) . '">Examiner le recours →</a></article>';
                }
                echo '</div>';
                if ($rows === []) { echo '<p class="fb-empty">Aucun recours sur cette page.</p>'; }
                echo '<nav class="fb-pagination" aria-label="Pages des recours"><a href="' . esc_url(self::url()) . '">Première page</a>';
                if (count($rows) === 20) { echo '<a href="' . esc_url(BackOffice::url(['view' => 'participation','appeal_cursor' => $rows[19]['case_id']])) . '">Recours suivants →</a>'; }
                echo '</nav>'; return;
            }
            $appeals->own($id);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                try { $status = self::submit($appeals,$id); }
                catch (ModelViolation $error) { $status = self::status($error->reason); }
                if ($status < 400) { wp_safe_redirect(self::url($id),303); exit; }
                status_header($status);
                echo '<p class="fb-empty" role="status">' . esc_html(match ($status) {
                    403 => 'Autorisation ou confirmation invalide.',409 => 'Le dossier ou la participation a changé. Relisez l’état courant.',
                    400 => 'Vérifiez la décision et le motif.',default => 'La dernière étape n’est pas confirmée. Relisez l’état enregistré avant de reprendre.',
                }) . '</p>';
            }
            $case = $appeals->own($id); $detail = $services['sessions']->inspect($case['session_id']); $session = $detail['session'];
            echo '<section class="fb-card fb-participation-case"><h2>' . esc_html($session['title']) . '</h2><p>' . esc_html(self::name($case['creator_id'])) . '</p><p class="fb-meta">' . esc_html(self::date($case['updated_at'])) . '</p><p class="fb-participation-case__text">' . esc_html($case['explanation']) . '</p>';
            echo '<p><a href="' . esc_url(BackOffice::url(['view' => 'sessions','session' => $case['session_id']])) . '">Examiner les règles de session →</a></p>';
            if ($status >= 400) { echo '<a href="' . esc_url(self::url($id)) . '">Relire le dossier courant →</a></section>'; return; }
            if ($case['state'] === 'pending') {
                self::begin($case,$session['revision'],'decide');
                echo '<label for="fu-participation-decision">Décision et motif</label><select id="fu-participation-decision" name="case_choice" required><option value="">Choisir une décision</option>';
                try { ClosedSessionManagement::participation($wpdb); $canAdmit = true; } catch (ModelViolation) { $canAdmit = false; }
                if ($canAdmit && $session['state'] === 'open') { echo '<option value="admit|criteria_satisfied">Admettre · critères remplis</option>'; }
                foreach (['uphold|criteria_not_met' => 'Maintenir le refus · critères non remplis','uphold|territory_unsubstantiated' => 'Maintenir le refus · territoire non justifié',
                    'uphold|needs_revision' => 'Maintenir le refus · éléments à revoir','close_without_admission|context_closed' => 'Clore sans admission · session fermée',
                    'close_without_admission|application_replaced' => 'Clore sans admission · demande remplacée','close_without_admission|member_withdrew' => 'Clore sans admission · membre retiré'] as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
                }
                echo '</select><button class="fb-button" type="submit">Enregistrer la décision</button></form>';
            } elseif ($case['result'] === 'admit' && self::sameParticipation($case,$detail['roles'])) {
                echo '<p>Recours favorable enregistré.</p>'; self::begin($case,$session['revision'],'resume');
                echo '<button class="fb-button" type="submit">Vérifier la participation</button></form>';
            } else { echo '<p>Dossier examiné. Cette décision reste attachée à la demande correspondante.</p>'; }
            echo '</section>';
        } catch (ModelViolation $error) { BackOfficeView::error('Lecture du recours indisponible.',self::status($error->reason)); }
        finally { echo '</div>'; }
    }

    private static function submit(SessionAppeals $appeals, string $id): int
    {
        if (self::field('case_id',$_POST) !== $id || wp_verify_nonce(self::field('participation_case_nonce',$_POST),'fans_hof_participation_case_' . $id) === false) {
            throw new ModelViolation('hof_session_forbidden');
        }
        $revision = self::revision('case_revision'); $current = $appeals->own($id);
        if ((int) $current['revision'] !== $revision) { throw new ModelViolation('hof_appeal_revision_conflict'); }
        global $wpdb;
        $action = self::field('participation_case_action',$_POST);
        if ($action === 'resume') {
            if ($current['state'] !== 'decided' || $current['result'] !== 'admit') { throw new ModelViolation('hof_invalid_appeal_decision'); }
            $detail = ClosedSessionManagement::services($wpdb)['sessions']->inspect($current['session_id']);
            if (!self::sameParticipation($current,$detail['roles'])) { throw new ModelViolation('hof_appeal_application_changed'); }
            $bridge = ClosedSessionManagement::participation($wpdb);
            $progress = $bridge->advance($current['session_id'],self::revision('session_revision'),$current['creator_id']);
            return isset($progress['participation_state']) ? 200 : 202;
        }
        if ($action !== 'decide') { throw new ModelViolation('hof_invalid_appeal_decision'); }
        $choice = explode('|',self::field('case_choice',$_POST));
        if (count($choice) !== 2) { throw new ModelViolation('hof_invalid_appeal_decision'); }
        if ($choice[0] !== 'admit') { $appeals->decide($id,$revision,$choice[0],$choice[1]); return 200; }
        if ($choice[1] !== 'criteria_satisfied') { throw new ModelViolation('hof_invalid_appeal_decision'); }
        $bridge = ClosedSessionManagement::participation($wpdb); $decided = $bridge->admitAppeal($id,$revision);
        $session = ClosedSessionManagement::services($wpdb)['sessions']->inspect($decided['session_id'])['session'];
        try { $progress = $bridge->advance($decided['session_id'],(int) $session['revision'],$decided['creator_id']); }
        catch (ModelViolation) { return 202; }
        return isset($progress['participation_state']) ? 200 : 202;
    }

    /** @param array<string,string> $case */
    private static function begin(array $case, string $sessionRevision, string $action): void
    {
        echo '<form class="fb-hof-session-review__form" method="post" action="' . esc_url(self::url($case['case_id'])) . '"><input type="hidden" name="participation_case_nonce" value="' . esc_attr(wp_create_nonce('fans_hof_participation_case_' . $case['case_id'])) . '">';
        foreach (['case_id' => $case['case_id'],'case_revision' => $case['revision'],'session_revision' => $sessionRevision,'participation_case_action' => $action] as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
    }

    private static function revision(string $field): int
    {
        $value = self::field($field,$_POST);
        if (preg_match('/^[1-9][0-9]{0,9}$/D',$value) !== 1 || (int) $value >= 2147483646) { throw new ModelViolation('hof_invalid_appeal_decision'); }
        return (int) $value;
    }
    /** @param array<string,string> $case
     * @param list<array<string,string>> $roles */
    private static function sameParticipation(array $case, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($role['creator_id'] !== $case['creator_id'] || $role['role'] !== 'participant') { continue; }
            $offset = match ($role['state']) { 'admitted' => 1,'closing' => 2,'withdrawn' => 3,default => 0 };
            return $offset > 0 && (int) $role['revision'] === (int) $case['role_revision']+$offset;
        }
        return false;
    }
    private static function name(string $id): string { return EditorialService::publicById($id)['public_name'] ?? 'Présentation indisponible'; }
    private static function date(string $utc): string
    { return wp_date('d/m/Y à H:i',(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->getTimestamp()) . ' · ' . wp_timezone()->getName(); }
    private static function url(string $id = ''): string { return BackOffice::url(array_filter(['view' => 'participation','case' => $id])); }
    /** @param array<string,mixed> $source */
    private static function field(string $field, array $source): string { return ModerationPanel::field($field,$source); }
    private static function status(string $reason): int
    { return match ($reason) { 'hof_forbidden','hof_session_forbidden','hof_appeal_forbidden' => 403,'hof_appeal_not_found','hof_session_not_found' => 404,
        'hof_appeal_revision_conflict','hof_appeal_application_changed','hof_participation_conflict','hof_invalid_session_transition','hof_approved_creator_required' => 409,
        default => str_starts_with($reason,'hof_invalid_') || $reason === 'invalid_identity_or_reference' ? 400 : 503 }; }
}
