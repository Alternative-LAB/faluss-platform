<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

final class FansUiImagesView
{
    public static function render(FansUiImages $model): void
    {
        $url = FansUiRoutes::url('creator', 'creer');
        $page = $model->listing?->get_data();
        $result = $model->result?->get_data();
        $code = is_array($result) ? ($result['code'] ?? '') : '';
        $states = ['pending' => 'En attente de modération', 'approved' => 'Image approuvée', 'rejected' => 'Image refusée', 'withdrawn' => 'Image retirée'];
        ?>
        <section class="fu-content fu-images" id="fu-images" data-fans-private-reading data-fans-image-previews data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" aria-labelledby="fu-images-title">
            <h2 id="fu-images-title">Mes images privées</h2>
            <?php if (!$model->available): ?><p class="fu-live">La gestion des images est indisponible pour le moment.</p>
            <?php else: ?>
                <p>Déposez une image, examinez son aperçu privé puis suivez la modération. L’approbation seule ne la publie pas : vous pourrez ensuite la choisir comme portrait dans Mon profil.</p>
                <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url('creator', 'mon-profil')); ?>">Choisir mon portrait dans Mon profil ↗</a>
                <?php if ($model->result !== null): ?><p class="fu-author__notice" role="status"><?php echo esc_html(match (true) {
                    $model->result->get_status() < 400 => ($result['reused'] ?? false) === true ? 'Cette image est déjà dans votre galerie. Aucun nouveau dépôt n’a été créé.' : 'Action confirmée. Consultez l’état actuel dans la galerie.',
                    $code === 'image_cleanup_required' => 'Le retrait est enregistré et l’accès révoqué. La suppression du fichier exige une intervention de l’administrateur.',
                    $model->result->get_status() === 413 => 'Image trop volumineuse. Le fichier doit respecter la limite de 2 Mio et celle du serveur.',
                    $model->result->get_status() === 415 => 'Format ou contenu refusé. Choisissez un JPEG ou PNG valide, de 4 millions de pixels maximum.',
                    $model->result->get_status() === 429 => 'Limite atteinte : cinq images conservées maximum et un débit de dépôt limité. Consultez la galerie ou réessayez plus tard.',
                    $model->result->get_status() === 409 => 'Cette image a changé. Rechargez la galerie avant toute nouvelle action.',
                    $model->result->get_status() === 403 => 'Session ou droits insuffisants. Rechargez pour vérifier votre accès.',
                    default => 'Action non confirmée. Vérifiez le fichier, la confirmation et l’état de la galerie avant de renvoyer.',
                }); ?></p><?php endif; ?>
                <?php if ($model->active): ?>
                    <form class="fu-panel fu-editorial__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url($url); ?>#fu-images">
                        <?php echo wp_nonce_field('fans_images', 'fans_images_nonce', false, false); ?>
                        <input type="hidden" name="image_action" value="upload"><input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                        <label for="fu-image-file">Choisir un fichier JPEG ou PNG</label>
                        <input type="file" name="image" id="fu-image-file" accept="image/jpeg,image/png" required aria-describedby="fu-image-limits">
                        <p id="fu-image-limits">2 Mio maximum, chaque côté ≤ 4 096 pixels, surface ≤ 4 millions de pixels. Cinq images conservées maximum. Les contenus adultes sont refusés.</p>
                        <button type="submit">Déposer mon image en privé</button>
                        <p class="fu-footnote">Aucune sauvegarde avant l’envoi. En cas de réponse incertaine, rechargez la galerie ; renvoyer le même fichier encore conservé retrouve son état actuel.</p>
                    </form>
                <?php else: ?><p>Votre profil n’est pas actif. Les dépôts et aperçus sont fermés ; vous pouvez encore retirer vos images.</p><?php endif; ?>
                <h3>Votre galerie</h3>
                <nav class="fu-text-controls" aria-label="État des images"><a class="fu-link" href="<?php echo esc_url($url); ?>#fu-images" <?php echo $model->scope === 'live' ? 'aria-current="page"' : ''; ?>>Images conservées</a>
                    <a class="fu-link" href="<?php echo esc_url($url); ?>?image_state=closed#fu-images" <?php echo $model->scope === 'closed' ? 'aria-current="page"' : ''; ?>>Retraits et refus</a></nav>
                <?php if ($model->listing?->get_status() !== 200 || !is_array($page) || !is_array($page['items'] ?? null)): ?><p role="alert">Galerie indisponible. Rechargez avant de renvoyer une image.</p>
                <?php else: ?>
                    <?php if ($page['items'] === []): ?><p>Aucune image sur cette page.</p><?php endif; ?>
                    <div class="fu-image-grid">
                    <?php foreach ($page['items'] as $index => $row): ?>
                        <article class="fu-panel fu-image-card"><p class="fu-panel__kicker"><?php echo esc_html($states[$row['state']] ?? 'État indisponible'); ?></p>
                            <h4>Image <?php echo (int) $index + 1; ?> de cette page</h4>
                            <p>Déposée le <?php echo esc_html((string) ($row['created_at'] ?? 'date indisponible')); ?> UTC</p>
                            <?php if (in_array($row['state'], ['pending', 'approved'], true)): ?>
                                <?php if ($model->active): ?><div class="fu-private-preview"><button type="button" data-private-image="<?php echo esc_url(rest_url('faluss-fans/v1/images/' . $row['image_id'] . '/preview/' . $row['revision'])); ?>">Examiner cette image</button><p role="status"></p><div data-private-image-output></div></div><?php endif; ?>
                                <form method="post" action="<?php echo esc_url($url); ?>#fu-images">
                                    <?php echo wp_nonce_field('fans_images', 'fans_images_nonce', false, false); ?>
                                    <input type="hidden" name="image_action" value="withdraw"><input type="hidden" name="image_id" value="<?php echo esc_attr((string) $row['image_id']); ?>"><input type="hidden" name="image_revision" value="<?php echo esc_attr((string) $row['revision']); ?>">
                                    <label class="fu-editorial__choice"><input type="checkbox" name="confirm_image_withdraw" value="yes" required> Retirer cette image et couper ses utilisations publiques.</label>
                                    <button type="submit">Retirer cette image</button>
                                </form>
                            <?php else: ?><p>L’image n’est plus consultable. Aucun nouvel envoi ne restaure cette version.</p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    </div>
                    <nav class="fu-text-controls" aria-label="Pagination des images privées"><a class="fu-link" href="<?php echo esc_url($url); ?>#fu-images">Recharger depuis le début</a>
                        <?php if (is_string($page['next_cursor'] ?? null)): ?><a class="fu-link" href="<?php echo esc_url($url . '?image_state=' . rawurlencode($model->scope) . '&images_cursor=' . rawurlencode($page['next_cursor'])); ?>#fu-images">Images suivantes →</a><?php endif; ?></nav>
                    <p class="fu-footnote">20 éléments maximum par page, y compris les traces d’images retirées. L’aperçu privé nécessite JavaScript.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }
}
