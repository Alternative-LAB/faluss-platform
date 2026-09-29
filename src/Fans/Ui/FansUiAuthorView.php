<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

final class FansUiAuthorView
{
    private const STATES = ['pending' => 'En attente de modération', 'approved' => 'Approuvé', 'rejected' => 'Refusé', 'withdrawn' => 'Retiré'];

    public static function render(FansUiAuthor $model): void
    {
        $base = FansUiRoutes::url('creator', 'creer');
        ?>
        <section class="fu-content fu-author" id="fu-author" data-fans-private-reading aria-labelledby="fu-author-title">
            <h2 id="fu-author-title">Mes textes</h2>
            <?php if (!$model->available) : ?>
                <p class="fu-live">La création et la gestion des textes sont indisponibles. Le service n’est pas ouvert.</p>
            <?php else : ?>
                <p>Chaque création ou modification attend une validation humaine avant de devenir publique.</p>
                <p class="fu-footnote">Texte uniquement, sans HTML, jusqu’à 8 000 caractères. Aucun enregistrement avant envoi. Les médias ne sont pas proposés dans ce parcours.</p>
                <?php self::result($model); ?>
                <?php if ($model->error !== '') : ?><p role="alert"><?php echo esc_html($model->error); ?></p><?php endif; ?>
                <div class="fu-author__tools"><a class="fu-link" href="<?php echo esc_url($base); ?>">Nouveau texte / relire la liste</a></div>
                <?php if ($model->result === null && $model->error === '') : ?>
                    <?php if ($model->item !== null) : ?>
                        <?php self::item($model->item, $model->active, true); ?>
                    <?php elseif ($model->active) : ?>
                        <?php self::form('create', '', 0, '', $model->key); ?>
                    <?php else : ?><p class="fu-live">Votre profil doit être actif pour créer ou modifier un texte. Le retrait reste possible.</p><?php endif; ?>
                <?php elseif ($model->result !== null && $model->result->get_status() >= 400 && $model->key !== '') : ?>
                    <p>Après une réponse incertaine, réessayez avec le même texte et la même clé avant de commencer une nouvelle publication.</p>
                    <?php self::form('create', '', 0, $model->text, $model->key, true, $model->result->get_status() >= 500 || $model->result->get_status() === 409); ?>
                <?php endif; ?>
                <?php self::listing($model); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function result(FansUiAuthor $model): void
    {
        if ($model->result === null) { return; }
        $status = $model->result->get_status();
        $row = FansUiAuthor::row($model->result->get_data());
        if ($status < 400 && $row !== null) {
            echo '<p class="fu-author__notice" role="status">Demande traitée. État actuel : ' . esc_html(self::STATES[$row['state']]) . '.</p>';
            return;
        }
        $message = match ($status) {
            400 => 'Envoi invalide. Vérifiez le texte et les champs requis.',
            403 => 'Accès ou session expirée. Rechargez la page et vérifiez votre connexion et le statut du profil.',
            404 => 'Texte introuvable ou indisponible.',
            409 => 'Le texte a changé ou cette demande est en conflit. Relisez la version actuelle avant une nouvelle action.',
            429 => 'Limite de publication atteinte. Attendez ou libérez une place en attente ; aucun nouvel envoi automatique.',
            default => 'Le résultat ne peut pas être confirmé. Vérifiez la liste ou renvoyez exactement la même demande.',
        };
        echo '<p class="fu-author__notice" role="alert">' . esc_html($message) . '</p>';
        if ($model->text !== '' && $model->key === '') {
            echo '<p>Votre texte envoyé, à copier avant de relire la version actuelle :</p><pre class="fu-text-body">' . esc_html($model->text) . '</pre>';
        }
    }

    /** @param array{publication_id:string,revision:int,body:string,state:string} $item */
    private static function item(array $item, bool $active, bool $edit): void
    {
        $base = FansUiRoutes::url('creator', 'creer');
        ?>
        <article class="fu-panel fu-author__item">
            <h3><?php echo esc_html(self::STATES[$item['state']]); ?></h3>
            <p class="fu-text-body"><?php echo esc_html($item['body'] === '' ? 'Texte effacé.' : $item['body']); ?></p>
            <?php if ($edit && $active && $item['state'] !== 'withdrawn') : ?>
                <?php self::form('edit', $item['publication_id'], $item['revision'], $item['body']); ?>
            <?php elseif (!$edit) : ?>
                <a class="fu-link" href="<?php echo esc_url($base . '?publication=' . rawurlencode($item['publication_id'])); ?>">Ouvrir ce texte ↗</a>
            <?php endif; ?>
            <?php if ($edit && $item['state'] !== 'withdrawn') : ?>
                <form method="post" action="<?php echo esc_url($base); ?>" class="fu-author__withdraw">
                    <?php self::hidden('withdraw', $item['publication_id'], $item['revision']); ?>
                    <label><input type="checkbox" name="confirm_withdraw" value="yes" required> Je confirme le retrait définitif et l’effacement de ce texte.</label>
                    <button type="submit">Retirer ce texte</button>
                </form>
            <?php endif; ?>
        </article>
        <?php
    }

    private static function form(string $action, string $id, int $revision, string $text, string $key = '', bool $retry = false, bool $locked = false): void
    {
        $url = FansUiRoutes::url('creator', 'creer') . ($id !== '' ? '?publication=' . rawurlencode($id) : '');
        ?>
        <form method="post" action="<?php echo esc_url($url); ?>" class="fu-author__form">
            <?php self::hidden($action, $id, $revision); ?>
            <?php if ($action === 'create') : ?><input type="hidden" name="creation_key" value="<?php echo esc_attr($key); ?>"><?php endif; ?>
            <label for="fu-author-text"><?php echo $action === 'edit' ? 'Modifier le texte — nouvelle modération requise' : ($retry ? 'Texte de la demande à réessayer' : 'Votre texte'); ?></label>
            <textarea id="fu-author-text" name="text" rows="8" required <?php echo $locked ? 'readonly' : ''; ?>><?php echo esc_html($text); ?></textarea>
            <button type="submit"><?php echo $locked ? 'Réessayer la même demande' : 'Soumettre à la modération'; ?></button>
        </form>
        <?php
    }

    private static function hidden(string $action, string $id, int $revision): void
    {
        echo wp_nonce_field('fans_author', 'fans_author_nonce', false, false);
        foreach (['author_action' => $action, 'publication_id' => $id, 'revision' => (string) $revision] as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
    }

    private static function listing(FansUiAuthor $model): void
    {
        $page = $model->listing?->get_data();
        if ($model->listing?->get_status() !== 200 || !is_array($page) || !is_array($page['items'] ?? null)) {
            echo '<p class="fu-live">Vos textes ne peuvent pas être chargés.</p>';
            return;
        }
        echo '<h3 class="fu-author__list-title">Vos textes enregistrés</h3>';
        if ($page['items'] === []) { echo '<p class="fu-live">Aucun texte enregistré.</p>'; }
        foreach ($page['items'] as $raw) {
            $item = FansUiAuthor::row($raw);
            if ($item !== null) { self::item($item, $model->active, false); }
        }
        if (is_string($page['next_cursor'] ?? null)) {
            echo '<a class="fu-link" href="' . esc_url(FansUiRoutes::url('creator', 'creer') . '?cursor=' . rawurlencode($page['next_cursor'])) . '">Page suivante des textes</a>';
        }
    }
}
