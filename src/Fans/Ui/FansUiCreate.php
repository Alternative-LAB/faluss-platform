<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

/** Presentation of the existing publication form; commercial types have no mutation endpoint. */
final class FansUiCreate
{
    public static function render(?FansUiAuthor $model): void
    {
        $types = ['prestation' => 'Prestation', 'publication' => 'Publication', 'produit' => 'Produit', 'service' => 'Service'];
        ?>
        <div class="fu-create" id="fu-create" hidden role="dialog" aria-modal="false" aria-labelledby="fu-create-heading" data-fans-private-reading data-api="<?php echo esc_url(rest_url('faluss-fans/v1/')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">
            <div class="fu-create__shade" data-create-close></div>
            <section class="fu-create__selector"><h2 id="fu-create-heading">Créer</h2>
                <?php foreach ($types as $key => $label): ?><button type="button" data-create-type="<?php echo esc_attr($key); ?>" aria-pressed="false"><span aria-hidden="true">✳</span><?php echo esc_html($label); ?></button><?php endforeach; ?>
                <button type="button" class="fu-create__close" data-create-close>Fermer <span aria-hidden="true">×</span></button>
            </section>
            <div class="fu-create__panels">
                <section class="fu-create__panel fu-author" data-create-panel="publication" hidden aria-labelledby="fu-compose-title">
                    <h2 id="fu-compose-title" tabindex="-1">Publier une publication</h2><p>Texte et création destinés à votre communauté.</p>
                    <?php if ($model !== null && $model->available && $model->active): ?>
                        <form method="post" action="<?php echo esc_url(FansUiRoutes::url('creator', 'creer')); ?>" class="fu-author__form">
                            <?php echo wp_nonce_field('fans_author', 'fans_author_nonce', false, false); ?>
                            <input type="hidden" name="author_action" value="create"><input type="hidden" name="publication_id" value=""><input type="hidden" name="revision" value="0"><input type="hidden" name="creation_key" value="<?php echo esc_attr($model->key); ?>">
                            <label for="fu-compose-text">Votre publication</label><textarea id="fu-compose-text" name="text" rows="6" required maxlength="8000" placeholder="Exprimez-vous…"></textarea>
                            <div class="fu-create__tools"><button type="button" data-create-images>Image</button><button type="button" data-create-emoji aria-expanded="false" aria-controls="fu-compose-emojis">☺ Émoji</button></div>
                            <div id="fu-compose-emojis" hidden aria-label="Choisir un émoji"><?php foreach (['😊', '❤️', '✨', '👏', '🎨', '🎵'] as $emoji): ?><button type="button" data-emoji="<?php echo esc_attr($emoji); ?>" aria-label="Insérer <?php echo esc_attr($emoji); ?>"><?php echo esc_html($emoji); ?></button><?php endforeach; ?></div>
                            <p data-create-selection>Aucune image sélectionnée.</p>
                            <p class="fu-footnote">Votre publication et son image seront soumises à la modération.</p>
                            <p role="status" data-create-result></p>
                            <button type="submit">Soumettre</button>
                        </form>
                    <?php else: ?><p role="status">La publication n’est pas ouverte pour votre profil pour le moment.</p><?php endif; ?>
                </section>
                <section class="fu-create__panel" data-create-panel="images" hidden aria-labelledby="fu-compose-images-title">
                    <h2 id="fu-compose-images-title" tabindex="-1">Image de la publication</h2>
                    <button type="button" data-create-image-return>← Retour au brouillon</button>
                    <p>Votre texte reste dans ce brouillon. Une nouvelle image doit être approuvée avant de pouvoir être sélectionnée.</p>
                    <form data-create-upload><label for="fu-compose-file">Déposer une image privée</label><input type="file" id="fu-compose-file" name="image" accept="image/jpeg,image/png" required><p>JPEG ou PNG, 2 Mio maximum. Cinq images conservées maximum.</p><button type="submit">Déposer en privé</button></form>
                    <p role="status" data-create-image-status></p>
                    <button type="button" data-create-image-refresh>Actualiser mes images</button>
                    <button type="button" data-create-image-none>Continuer sans image</button>
                    <div class="fu-create__images" data-create-image-list></div>
                    <p><a href="<?php echo esc_url(FansUiRoutes::url('creator', 'images')); ?>" target="_blank" rel="noopener">Gérer ma galerie privée dans un autre onglet ↗</a></p>
                </section>
                <?php foreach (['prestation', 'produit', 'service'] as $type): ?>
                    <section class="fu-create__panel" data-create-panel="<?php echo esc_attr($type); ?>" hidden aria-labelledby="fu-compose-<?php echo esc_attr($type); ?>">
                        <h2 id="fu-compose-<?php echo esc_attr($type); ?>" tabindex="-1"><?php echo $type === 'prestation' ? 'Proposer une prestation' : ($type === 'service' ? 'Proposer un service' : 'Créer un produit'); ?></h2>
                        <p><?php echo match ($type) { 'prestation' => 'Séance ou rendez-vous à proposer.', 'service' => 'Accompagnement personnalisé.', default => 'Objet ou création à proposer.' }; ?></p>
                        <label for="fu-<?php echo esc_attr($type); ?>-title">Titre</label><input id="fu-<?php echo esc_attr($type); ?>-title" maxlength="120" autocomplete="off">
                        <label for="fu-<?php echo esc_attr($type); ?>-description">Description</label><textarea id="fu-<?php echo esc_attr($type); ?>-description" rows="4" maxlength="2000"></textarea>
                        <?php if ($type === 'produit'): ?><label for="fu-product-photos">Photos</label><input id="fu-product-photos" type="file" accept="image/png,image/jpeg,image/webp" multiple disabled><label for="fu-product-price">Prix</label><input id="fu-product-price" inputmode="decimal" placeholder="Prix du produit" disabled>
                        <?php elseif ($type === 'prestation'): ?><label for="fu-performance-duration">Durée de la séance</label><input id="fu-performance-duration" placeholder="À préciser"><label for="fu-performance-location">Lieu ou à distance</label><input id="fu-performance-location" placeholder="Modalités de la séance">
                        <?php else: ?><label for="fu-service-format">Format de l’accompagnement</label><input id="fu-service-format" placeholder="Individuel, collectif…"><label for="fu-service-terms">Modalités</label><textarea id="fu-service-terms" rows="2"></textarea><?php endif; ?>
                        <p class="fu-create__notice">Cette création sera disponible prochainement. Ces champs ne sont ni enregistrés ni envoyés.</p><button type="button" disabled>À venir</button>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
