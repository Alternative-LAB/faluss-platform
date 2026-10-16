<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use DateTimeZone;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Native draft editor; never silently resubmit stale content against a newer revision. */
final class FansUiSessionForm
{
    public static function render(FansUiSessions $state): void
    {
        $row = $state->detail['session'] ?? []; $status = $state->httpStatus();
        $conflict = $status === 409 || ($state->postAction === 'edit' && $state->draft !== null && $state->submittedRevision !== ($row['revision'] ?? ''));
        if ($state->draft !== null && ($conflict || !in_array($status, [200, 400], true))) {
            echo '<section class="fu-panel fu-hof-session__editor"><h3>Votre saisie, non enregistrée</h3><p>Conservez les éléments à reprendre avant de recharger la version courante.</p>';
            foreach (['title' => 'Titre', 'rules_text' => 'Règles', 'starts_local' => 'Début', 'ends_local' => 'Fin', 'timezone' => 'Fuseau horaire'] as $key => $label) {
                echo '<h4>' . esc_html($label) . '</h4><p class="fu-hof-session__text">' . esc_html($state->draft[$key] ?? '') . '</p>';
            }
            echo '<a class="fu-button fu-button--outline" href="' . esc_url(FansUiSessions::url($state->mode, $state->id)) . '">Recharger la version courante</a></section>';
            return;
        }
        if (!in_array($status, [200, 400], true)) {
            echo '<p><a class="fu-link" href="' . esc_url(FansUiSessions::url($state->mode, $state->id)) . '">Recharger la version courante →</a></p>';
            return;
        }
        if (!$state->active) {
            echo '<p>Un profil actif avec une présentation approuvée est nécessaire pour créer ou modifier une session.</p><a class="fu-link" href="' . esc_url(FansUiRoutes::url('creator', 'mon-profil')) . '">Voir mon profil →</a>';
            return;
        }
        $values = self::values($state); $creating = $state->mode === 'new';
        ?>
        <form class="fu-panel fu-hof-session__editor" method="post" action="<?php echo esc_url(FansUiSessions::url($state->mode, $state->id)); ?>">
            <?php self::fields($creating ? 'create' : 'edit', $row['revision'] ?? '0', $state->review['revision'] ?? '0'); ?>
            <h3><?php echo $creating ? 'Préparer une session' : 'Modifier le brouillon'; ?></h3>
            <label for="fu-session-title">Titre</label><input id="fu-session-title" name="title" required minlength="2" maxlength="120" value="<?php echo esc_attr($values['title']); ?>">
            <label for="fu-session-rules">Règles et conditions de participation</label><textarea id="fu-session-rules" name="rules_text" rows="6" required minlength="2" maxlength="3000"><?php echo esc_textarea($values['rules_text']); ?></textarea>
            <label for="fu-session-category">Catégorie</label><select id="fu-session-category" name="category" required><?php foreach (FansUiSessionsView::categories() as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($values['category'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <label for="fu-session-scope">Portée</label><select id="fu-session-scope" name="scope" required><?php foreach (['international' => 'Internationale', 'national' => 'Nationale', 'local' => 'Locale'] as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($values['scope'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <p class="fu-hof__note">Pour une portée nationale ou locale, les organisateurs et participants doivent disposer du territoire examiné correspondant. La préparation du brouillon ne vaut pas admission.</p>
            <label for="fu-session-country">Code du pays — portée nationale ou locale</label><input id="fu-session-country" name="country" maxlength="2" pattern="[A-Z]{2}" autocapitalize="characters" placeholder="FR" value="<?php echo esc_attr($values['country']); ?>">
            <label for="fu-session-territory">Référence de la localité examinée — portée locale</label><input id="fu-session-territory" name="territory_ref" maxlength="80" value="<?php echo esc_attr($values['territory_ref']); ?>">
            <p class="fu-hof__note">Laissez ces deux champs vides pour une session internationale.</p>
            <label for="fu-session-zone">Fuseau horaire des dates saisies</label><input id="fu-session-zone" list="fu-session-zones" name="timezone" required maxlength="64" value="<?php echo esc_attr($values['timezone']); ?>">
            <datalist id="fu-session-zones"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?php echo esc_attr($zone); ?>"></option><?php endforeach; ?></datalist>
            <?php foreach (['starts' => 'Début', 'ends' => 'Fin'] as $part => $label): ?>
                <label for="fu-session-<?php echo esc_attr($part); ?>"><?php echo esc_html($label); ?></label><input id="fu-session-<?php echo esc_attr($part); ?>" type="datetime-local" step="1" name="<?php echo esc_attr($part); ?>_local" required value="<?php echo esc_attr($values[$part . '_local']); ?>">
                <?php self::offset($part, $label, $values); ?>
            <?php endforeach; ?>
            <p class="fu-hof__note">90 jours maximum. Les règles, dates, catégorie et portée sont figées après la demande d’ouverture.</p>
            <button class="fu-button" type="submit"><?php echo $creating ? 'Enregistrer le brouillon' : 'Enregistrer les modifications'; ?></button>
        </form>
        <?php
    }

    public static function fields(string $action, string $sessionRevision, string $reviewRevision): void
    {
        echo '<input type="hidden" id="hof-session-nonce-' . esc_attr($action) . '" name="hof_session_nonce" value="' . esc_attr(wp_create_nonce('fans_hof_sessions')) . '">';
        foreach (['hof_session_action' => $action, 'session_revision' => $sessionRevision, 'review_revision' => $reviewRevision] as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
    }

    /** @return array<string,string> */
    private static function values(FansUiSessions $state): array
    {
        $zone = wp_timezone()->getName(); if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) { $zone = 'UTC'; }
        $values = ['title' => '', 'rules_text' => '', 'category' => $state->profileCategory, 'scope' => 'international', 'country' => '', 'territory_ref' => '', 'timezone' => $zone,
            'starts_local' => '', 'ends_local' => '', 'starts_offset' => '', 'ends_offset' => ''];
        if ($state->detail !== null) {
            $row = $state->detail['session']; $values = array_replace($values, array_intersect_key($row, $values));
            foreach (['starts', 'ends'] as $part) {
                $values[$part . '_local'] = FansUiSessionDates::localValue($row[$part . '_at'], $row['timezone']);
                $values[$part . '_offset'] = FansUiSessionDates::offsetValue($row[$part . '_at'], $row['timezone']);
            }
        }
        return $state->draft === null ? $values : array_replace($values, $state->draft);
    }

    /** @param array<string,string> $values */
    private static function offset(string $part, string $label, array $values): void
    {
        try { $choices = FansUiSessionDates::choices($values[$part . '_local'], $values['timezone']); }
        catch (ModelViolation) { $choices = []; }
        echo '<label for="fu-session-' . esc_attr($part) . '-offset">Décalage UTC — ' . esc_html(mb_strtolower($label, 'UTF-8')) . '</label>';
        echo '<select id="fu-session-' . esc_attr($part) . '-offset" name="' . esc_attr($part) . '_offset" ' . (count($choices) > 1 ? 'required' : '') . '><option value="">' . (count($choices) > 1 ? 'Choisissez une occurrence' : 'Automatique') . '</option>';
        foreach ($choices as $choice) { echo '<option value="' . esc_attr($choice) . '" ' . selected($values[$part . '_offset'], $choice, false) . '>UTC' . esc_html($choice) . '</option>'; }
        echo '</select>';
        if (count($choices) > 1) { echo '<p class="fu-hof__note">Cette heure apparaît deux fois au changement d’heure. Choisissez son décalage.</p>'; }
    }
}
