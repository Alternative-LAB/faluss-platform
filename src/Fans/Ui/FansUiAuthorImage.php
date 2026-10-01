<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Publications\TextPublicationService;

/** Explicit native association adapter; Publications rechecks every permission and revision. */
final class FansUiAuthorImage
{
    /** @return array{image_id:?string,image_revision:?int}|null */
    public static function input(string $choice, string $confirmation): ?array
    {
        if ($confirmation !== 'yes') { return null; }
        if ($choice === 'none') { return ['image_id' => null, 'image_revision' => null]; }
        $parts = explode(':', $choice);
        if (count($parts) !== 2 || !TextPublicationService::validId($parts[0])
            || preg_match('/^[1-9][0-9]{0,9}$/D', $parts[1]) !== 1 || (int) $parts[1] >= 2147483647) { return null; }
        return ['image_id' => $parts[0], 'image_revision' => (int) $parts[1]];
    }

    public static function render(FansUiAuthor $model): void
    {
        $item = $model->item;
        if ($item === null || !$model->active || !in_array($item['state'], ['pending', 'approved'], true) || $item['body'] === '') { return; }
        $data = $model->imageOptions?->get_data();
        $choices = $model->imageOptions?->get_status() === 200 && is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : null;
        $url = FansUiRoutes::url('creator', 'creer') . '?publication=' . rawurlencode($item['publication_id']);
        ?>
        <section class="fu-panel fu-author-image" data-fans-image-previews data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" aria-labelledby="fu-author-image-title">
            <h3 id="fu-author-image-title">Image de cette publication</h3>
            <p><?php echo $model->imageId === null ? 'Aucune image actuellement utilisable sur ce texte.' : 'Ce texte possède une image approuvée utilisable actuellement.'; ?></p>
            <p>Associer, remplacer ou détacher une image masque le texte public jusqu’à sa nouvelle approbation. L’image et le texte sont examinés ensemble.</p>
            <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url('creator', 'images')); ?>#fu-images">Déposer ou retirer une image dans ma galerie ↗</a>
            <form class="fu-editorial__form" method="post" action="<?php echo esc_url($url); ?>#fu-author">
                <?php echo wp_nonce_field('fans_author', 'fans_author_nonce', false, false); ?>
                <input type="hidden" name="author_action" value="image">
                <input type="hidden" name="publication_id" value="<?php echo esc_attr($item['publication_id']); ?>">
                <input type="hidden" name="revision" value="<?php echo (int) $item['revision']; ?>">
                <fieldset><legend>Choisir l’image après examen</legend>
                    <label class="fu-editorial__choice"><input type="radio" name="image_choice" value="none" required> Sans image — détacher la référence</label>
                    <?php if ($choices === null): ?><p>Liste des images indisponible. Vous pouvez encore demander un détachement explicite.</p>
                    <?php elseif ($choices === []): ?><p>Aucune image approuvée à proposer pour le moment.</p>
                    <?php else: foreach ($choices as $index => $image): ?>
                        <label class="fu-editorial__choice"><input type="radio" name="image_choice" value="<?php echo esc_attr($image['image_id'] . ':' . $image['revision']); ?>" required> Image approuvée <?php echo (int) $index + 1; ?><?php echo $model->imageId === $image['image_id'] ? ' · associée actuellement' : ''; ?></label>
                        <div class="fu-private-preview"><button type="button" data-private-image="<?php echo esc_url(rest_url('faluss-fans/v1/images/' . $image['image_id'] . '/preview/' . $image['revision'])); ?>">Examiner l’image <?php echo (int) $index + 1; ?></button><p role="status"></p><div data-private-image-output></div></div>
                    <?php endforeach; endif; ?>
                </fieldset>
                <label class="fu-editorial__choice"><input type="checkbox" name="confirm_image_review" value="yes" required> Je confirme ce choix et sa nouvelle modération.</label>
                <button type="submit">Soumettre le choix de l’image</button>
                <p class="fu-footnote">Après une réponse incertaine, relisez la fiche. Aucune copie ni publication automatique de l’image.</p>
            </form>
        </section>
        <?php
    }
}
