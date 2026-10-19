<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiRankingPrivacyView
{
    public static function render(FansUiRankingPrivacy $state): void
    {
        if (!$state->available && $state->result === null) { return; }
        $row = $state->row; $fan = $state->role === 'fan';
        $url = FansUiRoutes::url($state->role, $state->view);
        ?>
        <section class="fu-hof fu-hof-preferences" aria-labelledby="fu-hof-privacy-title">
            <details class="fu-panel" <?php if ($state->result !== null): ?>open<?php endif; ?>>
                <summary id="fu-hof-privacy-title">Votre visibilité dans le classement</summary>
                <?php if ($state->result !== null): ?><p role="status"><?php echo esc_html(self::notice($state->result)); ?></p><?php endif; ?>
                <?php if ($row !== null && $state->available): ?>
                    <?php if ($fan): ?>
                        <p>Choisissez un pseudonyme et si vous souhaitez apparaître dans le classement public. Votre pseudonyme doit être approuvé avant d’être affiché.</p>
                        <?php if ($row['approved_alias'] !== ''): ?><p>Votre pseudonyme approuvé : <strong><?php echo esc_html($row['approved_alias']); ?></strong></p><?php endif; ?>
                        <p class="fu-hof__note"><?php echo esc_html(match ($row['alias_state']) { 'pending' => 'Proposition en attente.', 'approved' => 'Pseudonyme approuvé.', 'rejected' => 'La dernière proposition a été refusée ou retirée par la modération.', 'withdrawn' => 'Vous avez retiré votre pseudonyme.', default => 'Aucun pseudonyme approuvé.' }); ?></p>
                        <form method="post" action="<?php echo esc_url($url); ?>" class="fu-hof-preferences__form">
                            <?php self::fields('alias-submit', $row['revision']); ?>
                            <label for="fu-hof-alias">Votre pseudonyme de Fan</label>
                            <input id="fu-hof-alias" name="public_alias" required minlength="2" maxlength="80" autocomplete="off" value="<?php echo esc_attr($state->draftAlias ?? ($row['pending_alias'] !== '' ? $row['pending_alias'] : $row['approved_alias'])); ?>">
                            <p class="fu-hof__note">2 à 80 caractères.</p><button type="submit" class="fu-button fu-button--outline">Soumettre le pseudonyme</button>
                        </form>
                    <?php else: ?><p>Votre nom de création approuvé peut apparaître dans les classements si vous le choisissez.</p><?php endif; ?>
                    <form method="post" action="<?php echo esc_url($url); ?>" class="fu-hof-preferences__form">
                        <?php self::fields('consent', $row['revision']); ?>
                        <label class="fu-hof-preferences__choice"><input type="checkbox" name="ranking_public" value="yes" <?php checked($row[$state->role . '_public'], '1'); ?>> Autoriser ma présence dans le classement public <?php echo $fan ? 'des Fans' : 'des Créateurs'; ?>.</label>
                        <p class="fu-hof__note">Vous pouvez retirer cet accord à tout moment. Votre relevé privé est conservé.</p><button type="submit" class="fu-button fu-button--outline">Enregistrer mon choix</button>
                    </form>
                    <?php if ($fan && ($row['approved_alias'] !== '' || $row['pending_alias'] !== '')): ?>
                        <form method="post" action="<?php echo esc_url($url); ?>" class="fu-hof-preferences__form">
                            <?php self::fields('alias-withdraw', $row['revision']); ?>
                            <label class="fu-hof-preferences__choice"><input type="checkbox" name="confirm_withdraw" value="yes" required> Retirer mon pseudonyme et ma présence dans le classement public des Fans.</label>
                            <button type="submit" class="fu-button fu-button--outline">Retirer mon pseudonyme</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </details>
        </section>
        <?php
    }

    private static function fields(string $action, string $revision): void
    {
        echo '<input type="hidden" name="hof_privacy_nonce" id="hof-privacy-nonce-' . esc_attr($action) . '" value="' . esc_attr(wp_create_nonce('fans_hof_preferences')) . '">';
        echo '<input type="hidden" name="hof_privacy_action" value="' . esc_attr($action) . '"><input type="hidden" name="revision" value="' . esc_attr($revision) . '">';
    }

    public static function notice(\WP_REST_Response $result): string
    {
        $data = $result->get_data();
        if (is_array($data) && ($data['code'] ?? '') === 'hof_approved_creator_required') { return 'Une présentation Créateur active et approuvée est nécessaire pour rendre votre classement public.'; }
        return match ($result->get_status()) {
            200 => 'Votre choix a été enregistré. L’état courant est affiché ci-dessous.',
            400 => 'Vérifiez les champs et la confirmation de retrait.',
            403 => 'Session ou autorisation insuffisante. Rechargez pour vérifier votre accès.',
            409 => 'L’état a changé. Relisez-le avant de soumettre à nouveau.',
            429 => 'Trop de modifications récentes. Réessayez plus tard.',
            default => 'Action non confirmée. Rechargez pour vérifier l’état enregistré.',
        };
    }
}
