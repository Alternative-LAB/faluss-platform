<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

/** Existing Fans components; no new shell, global navigation or public territorial directory. */
final class FansUiTerritoryView
{
    public static function render(FansUiSessions $state): void
    {
        $value = $state->territory; if ($value === null) { return; }
        $row = $value->declaration; $revision = $row['revision'];
        echo '<h2>Mon territoire d’activité</h2><section class="fu-panel fu-hof-session__editor"><h3>' . esc_html(self::state($row['state'])) . '</h3>';
        if ($value->currentPolicy !== null) {
            $policy = $value->currentPolicy;
            $name = $policy['territories'][$row['territory_ref'] ?? '']['name'] ?? '';
            if ($name !== '') { echo '<p>' . esc_html($name . ' · ' . $row['country']) . '</p>'; }
            self::criteria($policy);
        }
        if (in_array($row['state'],['pending','appealed'],true)) { echo '<p>Votre déclaration attend un examen.</p>'; }
        if ($row['state'] === 'rejected') {
            echo '<p>' . esc_html(match ($row['reason']) {'territory_unsubstantiated' => 'Le territoire n’a pas pu être établi.', 'needs_revision' => 'Votre déclaration doit être réexaminée.', default => 'Les critères ne sont pas remplis.'}) . '</p>';
            echo '<form class="fu-hof-session__appeal" method="post" action="' . esc_url(self::url()) . '">'; self::fields('territory_appeal',$revision);
            echo '<label for="fu-territory-appeal">Expliquer votre recours</label><textarea id="fu-territory-appeal" name="explanation" required maxlength="1000" rows="4">' . esc_textarea($state->appealText) . '</textarea><button class="fu-button" type="submit">Envoyer le recours</button></form>';
        }
        if ($row['state'] !== 'absent' && $row['state'] !== 'withdrawn') {
            echo '<details><summary>Retirer ma déclaration</summary><form method="post" action="' . esc_url(self::url()) . '">'; self::fields('territory_withdraw',$revision);
            echo '<label><input type="checkbox" name="confirm_withdraw" value="yes" required> Confirmer le retrait et la fermeture des nouvelles contributions territoriales concernées</label><button class="fu-button fu-button--outline" type="submit">Retirer la déclaration</button></form></details>';
        }
        echo '</section>';
        if ($state->active) {
            echo '<h3>Déclarer mon activité principale</h3>';
            if ($value->policies === []) { echo '<p>Aucun critère de territoire disponible sur cette page.</p>'; }
            foreach ($value->policies as $index => $policy) {
                echo '<details' . (($row['policy_id'] ?? '') === $policy['policy_id'] ? ' open' : '') . '><summary>' . esc_html(mb_substr($policy['criteria_text'],0,100,'UTF-8')) . '</summary>';
                echo '<form class="fu-panel fu-hof-session__editor" method="post" action="' . esc_url(self::url()) . '">'; self::fields('territory_submit',$revision);
                self::criteria($policy); echo '<input type="hidden" name="policy_id" value="' . esc_attr($policy['policy_id']) . '">';
                echo '<label for="fu-territory-' . esc_attr((string) $index) . '">Territoire d’activité principal</label><select id="fu-territory-' . esc_attr((string) $index) . '" name="territory_ref" required><option value="">Choisir un territoire</option>';
                foreach ($policy['territories'] as $reference => $territory) { echo '<option value="' . esc_attr($reference) . '">' . esc_html($territory['name'] . ' · ' . $territory['country']) . '</option>'; }
                echo '</select><p>Une nouvelle déclaration remplace l’admission courante jusqu’à son examen.</p><button class="fu-button" type="submit">Soumettre ma déclaration</button></form></details>';
            }
            self::pagination($value->policies,$value->policyCursor,'policy_cursor','policy_id','Critères suivants');
        }
        echo '<h3>Fermetures à confirmer</h3>';
        if ($value->progress !== null) {
            $progress = $value->progress; echo '<section class="fu-panel"><p>' . esc_html(self::phase($progress['change']['phase'])) . '</p><p>' . esc_html((string) $progress['applied']) . ' / ' . esc_html((string) $progress['total']) . ' confirmations.</p>';
            if ($progress['change']['phase'] !== 'completed') {
                echo '<form method="post" action="' . esc_url(self::url(['change' => $value->changeId])) . '">'; self::fields('territory_resume',$revision);
                echo '<input type="hidden" name="change_id" value="' . esc_attr($value->changeId) . '"><button class="fu-button" type="submit">Poursuivre la confirmation</button></form>';
            }
            echo '</section>';
        }
        if ($value->changes === []) { echo '<p>Aucune fermeture sur cette page.</p>'; }
        foreach ($value->changes as $change) {
            echo '<p><a class="fu-link" href="' . esc_url(self::url(['change' => $change['change_id']])) . '">' . esc_html(self::phase($change['phase'])) . ' · ' . esc_html(self::date($change['updated_at'])) . '</a></p>';
        }
        self::pagination($value->changes,$value->changeCursor,'change_cursor','change_id','Fermetures suivantes');
    }

    /** @param array<string,mixed> $policy */
    public static function criteria(array $policy): void
    { echo '<p class="fu-hof-session__text">' . esc_html($policy['criteria_text']) . '</p><p><a class="fu-link" href="' . esc_url($policy['criteria_url']) . '" rel="noreferrer noopener">Lire les critères de territoire ↗</a></p>'; }

    public static function fields(string $action, string $revision): void
    {
        foreach (['hof_session_action' => $action,'territory_revision' => $revision,'hof_session_nonce' => wp_create_nonce('fans_hof_sessions')] as $key => $value) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
    }

    public static function state(string $state): string
    { return match ($state) {'pending' => 'En attente d’examen','approved' => 'Territoire approuvé','rejected' => 'Déclaration refusée','appealed' => 'Recours en cours','withdrawn' => 'Déclaration retirée',default => 'Aucune déclaration'}; }
    public static function phase(string $phase): string
    { return match ($phase) {'collecting' => 'Fermeture en préparation','draining' => 'Fermeture en cours de confirmation','completed' => 'Fermeture confirmée',default => 'Confirmation indisponible'}; }
    public static function date(string $utc): string
    { return wp_date('d/m/Y à H:i:s',(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->getTimestamp()) . ' · ' . wp_timezone()->getName(); }

    /** @param array<string,string> $args */
    public static function url(array $args = []): string
    { return add_query_arg($args,FansUiSessions::url('territory')); }

    /** @param list<array<string,mixed>> $rows */
    private static function pagination(array $rows, string $cursor, string $parameter, string $key, string $label): void
    {
        echo '<nav class="fu-hof__pagination" aria-label="' . esc_attr($label) . '">';
        if ($cursor !== '') { echo '<a href="' . esc_url(self::url()) . '">Première page</a>'; }
        if (count($rows) === 20) { echo '<a href="' . esc_url(self::url([$parameter => $rows[19][$key]])) . '">' . esc_html($label) . ' →</a>'; }
        echo '</nav>';
    }
}
