<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

/** Functional selection only; no new HoF visual direction, balance or financial indicator. */
final class FansUiSupportView
{
    public static function render(FansUiSupport $v): void
    {
        ?><section class="fu-hof" data-fans-private-reading aria-labelledby="fu-support-title">
        <a class="fu-link" href="<?php echo esc_url(home_url('/app/creators/'.$v->profile)); ?>">← Profil créateur</a>
        <h2 id="fu-support-title">Choisir les sessions de mon soutien</h2>
        <?php if (!$v->available): ?><p class="fu-hof__note" role="status">Le soutien est indisponible pour le moment.</p>
        <?php else: ?>
            <p class="fu-hof__note">Recette isolée : les PF et les identités utilisés ici sont fictifs.</p>
            <?php if ($v->status>=400): ?><p class="fu-hof-session__notice" role="alert"><?php echo match($v->status) {
                400=>'Vérifiez les champs et les sessions choisies.',403=>'Votre session a expiré ou le formulaire est invalide. Reconnectez-vous puis rechargez cette page.',
                409=>'Une attribution est déjà en cours ou les choix ont changé. Vérifiez son état avant de réessayer.',
                default=>'Résultat non confirmé. Vérifiez l’attribution en cours avant tout nouvel essai.',
            }; ?></p><?php endif; ?>
            <?php if ($v->attempt!==null):self::attempt($v);
            else:self::selection($v);endif; ?>
        <?php endif; ?></section><?php
    }

    private static function selection(FansUiSupport $v): void
    {
        ?><form class="fu-panel fu-hof-session__editor" method="post" action="<?php echo esc_url(FansUiSupport::url($v->role,$v->profile)); ?>">
            <?php self::fields($v); ?>
            <h3><?php echo esc_html($v->name); ?></h3>
            <label for="fu-support-quantity">PF achetés à attribuer</label>
            <input id="fu-support-quantity" name="quantity" inputmode="numeric" pattern="[1-9][0-9]{0,15}" maxlength="16" required value="<?php echo esc_attr($v->quantity); ?>">
            <fieldset><legend>Sessions pour ce soutien — dix maximum, facultatif</legend>
                <p class="fu-hof__note">Aucune session n’est présélectionnée. Les choix valent uniquement pour cette attribution ; ils ne peuvent pas être ajoutés après sa confirmation.</p>
                <?php if ($v->items===[] && $v->retained===[]): ?><p>Aucune session admissible sur cette page. Le soutien peut rester limité aux classements persistants autorisés.</p><?php endif; ?>
                <?php foreach (array_merge($v->retained,$v->items) as $i=>$row): ?>
                    <label class="fu-support-choice" for="fu-support-session-<?php echo (int)$i; ?>">
                        <input type="checkbox" id="fu-support-session-<?php echo (int)$i; ?>" name="sessions[]" value="<?php echo esc_attr($row['session_id']); ?>" <?php checked(in_array($row['session_id'],$v->selected,true)); ?>>
                        <span><strong><?php echo esc_html($row['title']); ?></strong><small><?php echo esc_html(match($row['scope']) {'local'=>'Locale','national'=>'Nationale',default=>'Internationale'}); ?> · <?php echo esc_html(self::date($row['ends_at'])); ?></small><span><?php echo esc_html($row['rules_text']); ?></span></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($v->next!==null): ?><button class="fu-button fu-button--outline" type="submit" name="support_action" value="page" formnovalidate>Sessions suivantes</button><input type="hidden" name="choice_cursor" value="<?php echo esc_attr($v->next); ?>"><?php endif; ?>
            <p class="fu-hof__note">La préparation réserve les PF fictifs. Vous confirmerez ensuite l’attribution avec ces mêmes sessions.</p>
            <button class="fu-button" type="submit" name="support_action" value="reserve">Préparer ce soutien</button>
        </form><?php
    }

    private static function attempt(FansUiSupport $v): void
    {
        $row=$v->attempt;
        if ($row===null) { return; }
        if ($row['creator_profile_id']!==$v->profile): ?>
            <p>Une autre attribution doit être terminée avant de préparer ce soutien.</p>
            <a class="fu-button" href="<?php echo esc_url(FansUiSupport::url($v->role,$row['creator_profile_id'])); ?>">Retrouver mon attribution en cours</a>
        <?php return;endif; ?>
        <div class="fu-panel"><h3><?php echo esc_html($v->name); ?></h3>
            <p><strong><?php echo esc_html($v->quantity); ?> PF fictifs</strong> · <?php echo count($v->selected); ?> session(s) choisie(s)</p>
            <p role="status"><?php echo match($row['phase']) {
                'reserved'=>'Préparation confirmée. Les choix sont figés pour cette attribution.',
                'confirmed'=>'Attribution confirmée par Hub. Les projections restent soumises aux corrections attestées.',
                'released'=>'Réservation libérée. Aucun soutien confirmé par cette réservation.',
                'expired'=>'Réservation expirée. Aucun nouveau débit ne sera tenté avec d’autres choix.',
                'retry'=>'La dernière opération n’est pas confirmée. Le même essai peut être repris avec sa clé conservée.',
                default=>'Résultat encore incertain. Vérifiez auprès de Hub avant toute nouvelle attribution.',
            }; ?></p>
            <?php foreach (match($row['phase']) {
                'reserved'=>['confirm'=>'Confirmer cette attribution','release'=>'Libérer la réservation'],
                'retry'=>['retry'=>'Réessayer la même opération'],
                'unknown'=>['lookup'=>'Vérifier le résultat auprès de Hub'],
                default=>['new'=>'Préparer un autre soutien'],
            } as $action=>$label): ?>
                <form method="post" action="<?php echo esc_url(FansUiSupport::url($v->role,$v->profile)); ?>"><?php self::fields($v); ?><button class="fu-button<?php echo $action==='release'?' fu-button--outline':''; ?>" name="support_action" value="<?php echo esc_attr($action); ?>" type="submit"><?php echo esc_html($label); ?></button></form>
            <?php endforeach; ?>
        </div><?php
    }

    private static function fields(FansUiSupport $v): void
    {
        wp_nonce_field('fans_support_'.$v->profile,'fans_support_nonce',false);
        echo '<input type="hidden" name="attribution" value="'.esc_attr($v->id).'">';
    }

    private static function date(string $instant): string
    {
        return wp_date('d/m/Y à H:i',\Faluss\Platform\Fans\Hof\RankingCalendar::utc($instant)->getTimestamp()).' · '.wp_timezone()->getName();
    }
}
