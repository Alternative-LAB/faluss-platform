<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Escaped, server-rendered forms; no optimistic mutation or persistent browser state. */
final class ModerationView
{
    public static function url(bool $images, ?string $cursor = null): string
    {
        return add_query_arg(array_filter(['page' => ModerationPanel::PAGE, 'view' => $images ? 'images' : 'texts', 'cursor' => $cursor]), admin_url('admin.php'));
    }

    public static function render(bool $images, \WP_REST_Response $response, ?\WP_REST_Response $result, bool $detail = false): void
    {
        $page = $response->get_data();
        ?>
        <div class="wrap faluss-moderation">
            <header><p class="fm-brand">Faluss <span>by Alternative LAB</span></p><h1>Modération Fans</h1>
                <p>Examiner une révision, puis décider. Chaque décision confirmée est conservée dans le journal.</p></header>
            <nav aria-label="Modération Fans">
                <a href="<?php echo esc_url(self::url(false)); ?>" <?php echo !$images ? 'aria-current="page"' : ''; ?>>Textes en attente</a>
                <a href="<?php echo esc_url(self::url(true)); ?>" <?php echo $images ? 'aria-current="page"' : ''; ?>>Quarantaine images</a>
            </nav>
            <p class="fm-warning">La revue humaine reste nécessaire : aucun filtre ne garantit la détection de tout contenu interdit. Un profil actif n’est pas une identité vérifiée.</p>
            <?php if ($result !== null): ?>
                <div class="fm-notice" role="status"><strong><?php echo $result->get_status() === 200 ? 'Décision confirmée par le serveur et journalisée.' : 'La décision n’est pas confirmée comme réussie.'; ?></strong>
                    <?php if ($result->get_status() !== 200): ?>
                        <p><?php echo esc_html($result->get_status() === 409 ? 'Conflit de révision : relisez la version courante avant de décider à nouveau.' : 'Accès, état ou service indisponible. Rechargez et vérifiez le journal avant toute nouvelle tentative. Une révocation peut être enregistrée malgré un échec de nettoyage.'); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(self::url($images)); ?>">Recharger la file</a>
                    <?php $submitted = ModerationPanel::field('item_id', $_POST); ?>
                    <?php if (ModerationPanel::field('kind', $_POST) === 'text-publications' && \Faluss\Platform\Fans\Publications\TextPublicationService::validId($submitted)): ?>
                        · <a href="<?php echo esc_url(add_query_arg('item', $submitted, self::url(false))); ?>">Relire la fiche et son journal</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <h2><?php echo $images ? 'Images privées · tous les états' : ($detail ? 'Texte sélectionné · état courant' : 'Textes · plus anciens en premier'); ?></h2>
            <?php if ($response->get_status() !== 200 || !is_array($page) || !is_array($page['items'] ?? null)): ?>
                <p role="alert">File indisponible. Vérifiez les droits et les modules, puis rechargez.</p>
            <?php else: ?>
                <?php if ($page['items'] === []): ?><p>Aucun élément sur cette page.</p><?php endif; ?>
                <div class="fm-items">
                <?php foreach ($page['items'] as $row): ?>
                    <?php if (is_array($row)) { self::item($row, $images); } ?>
                <?php endforeach; ?>
                </div>
                <nav aria-label="Pagination"><a href="<?php echo esc_url(self::url($images)); ?>">Revenir au début</a>
                    <?php if (is_string($page['next_cursor'] ?? null)): ?><a href="<?php echo esc_url(self::url($images, $page['next_cursor'])); ?>">Page suivante →</a><?php endif; ?>
                </nav>
                <p>20 éléments maximum par page. La file évolue après chaque action : repartez du début pour retrouver les éléments modifiés.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $row */
    private static function item(array $row, bool $images): void
    {
        $kind = $images ? 'images' : 'text-publications';
        $id = (string) $row[$images ? 'image_id' : 'publication_id'];
        $active = $images || CreatorProfileService::publicById($row['creator_id']) !== null;
        ?>
        <article class="fm-card" aria-label="<?php echo esc_attr(($images ? 'Image ' : 'Texte ') . $id); ?>">
            <div class="fm-meta"><strong><?php echo esc_html((string) $row['state']); ?></strong><span>Révision <?php echo esc_html((string) $row['revision']); ?></span></div>
            <h3><?php echo $images ? 'Image en quarantaine' : 'Publication textuelle'; ?></h3>
            <p class="fm-id"><?php echo esc_html($id); ?></p>
            <?php if (!$images): ?>
                <a href="<?php echo esc_url(add_query_arg('item', $id, self::url(false))); ?>">Fiche et journal</a>
                <p class="fm-id">Créateur : <?php echo esc_html((string) $row['creator_id']); ?><br>Mis à jour (UTC) : <?php echo esc_html((string) $row['updated_at']); ?></p>
                <p><?php echo $active ? 'Profil actif · approbation éditoriale uniquement.' : 'Profil non public ou indisponible : approbation impossible.'; ?></p>
                <div class="fm-text"><?php echo esc_html((string) $row['body']); ?></div>
                <?php if (is_string($row['image_id'] ?? null)): self::previewForm($row['image_id'], $id, (string) $row['revision']); ?>
                <?php else: ?><p>Aucune image associée actuellement éligible.</p><?php endif; ?>
            <?php elseif (in_array($row['state'], ['pending', 'approved'], true)): ?>
                <?php self::previewForm($id); ?>
                <p>La lecture et l’approbation exigent encore un profil actif et un stockage privé disponible.</p>
            <?php endif; ?>
            <?php if (in_array($row['state'], ['pending', 'approved'], true)): ?>
                <form method="post" action="<?php echo esc_url(self::url($images)); ?>" class="fm-decision">
                    <?php wp_nonce_field('fans_moderation'); ?>
                    <input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
                    <input type="hidden" name="item_id" value="<?php echo esc_attr($id); ?>">
                    <input type="hidden" name="revision" value="<?php echo esc_attr((string) $row['revision']); ?>">
                    <label for="reason-<?php echo esc_attr($id); ?>">Décision et motif</label>
                    <select required name="reason" id="reason-<?php echo esc_attr($id); ?>">
                        <option value="">Choisir après examen</option>
                        <?php if ($row['state'] === 'pending' && $active): ?><option value="<?php echo $images ? 'allowed_image' : 'allowed_text'; ?>">Approuver · contenu autorisé</option><?php endif; ?>
                        <option value="prohibited_content">Refuser · contenu interdit</option><option value="needs_revision">Refuser · correction nécessaire</option>
                    </select>
                    <p>Le refus purge le contenu courant. Relisez le texte et examinez l’image avant de confirmer.</p>
                    <button type="submit">Confirmer cette décision</button>
                </form>
            <?php endif; ?>
            <?php self::journal($kind, $id); ?>
        </article>
        <?php
    }

    private static function previewForm(string $image, string $publication = '', string $revision = ''): void
    {
        ?>
        <form class="fm-preview" method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('fans_preview'); ?>
            <input type="hidden" name="action" value="faluss_fans_preview">
            <input type="hidden" name="image_id" value="<?php echo esc_attr($image); ?>">
            <input type="hidden" name="publication_id" value="<?php echo esc_attr($publication); ?>">
            <input type="hidden" name="revision" value="<?php echo esc_attr($revision); ?>">
            <button class="fm-secondary" type="submit">Examiner l’image privée ↗</button>
            <p>Aperçu privé sur demande, ou nouvel onglet sans JavaScript. Refermez-le après examen : les octets déjà reçus ne peuvent pas être rappelés.</p>
        </form>
        <?php
    }

    private static function journal(string $kind, string $id): void
    {
        $response = ModerationPanel::request('GET', $kind . '/' . $id . '/decisions');
        $rows = $response->get_data();
        ?>
        <details><summary>Journal des décisions<?php echo $kind === 'text-publications' ? ' · 100 dernières traces maximum' : ''; ?></summary>
            <?php if ($response->get_status() !== 200 || !is_array($rows)): ?><p>Journal indisponible.</p>
            <?php else: ?><ul class="fm-journal">
                <?php foreach ($rows as $entry): ?>
                    <li><?php echo esc_html(implode(' · ', array_map('strval', array_intersect_key($entry, array_flip(['revision', 'actor_id', 'action', 'reason', 'occurred_at']))))); ?></li>
                <?php endforeach; ?>
            </ul><p>Révision, acteur WordPress, action, motif et date UTC. Aucun ancien contenu n’est reconstitué.</p><?php endif; ?>
        </details>
        <?php
    }
}
