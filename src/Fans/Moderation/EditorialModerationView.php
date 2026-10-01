<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;

/** Editorial tab in the existing WordPress moderation panel. */
final class EditorialModerationView
{
    public static function url(?string $state = null): string
    { return add_query_arg(['page' => ModerationPanel::PAGE, 'view' => 'editorial', 'state' => $state ?? (ModerationPanel::field('state', $_GET) ?: 'pending')], admin_url('admin.php')); }

    public static function render(?\WP_REST_Response $result): void
    {
        $item = ModerationPanel::field('item', $_GET);
        $state = ModerationPanel::field('state', $_GET) ?: 'pending';
        $response = $item === '' ? ModerationPanel::request('GET', 'editorial/moderation', ['cursor' => ModerationPanel::field('cursor', $_GET), 'state' => $state])
            : (EditorialService::validId($item) ? ModerationPanel::request('GET', 'editorial/' . $item . '/private') : new \WP_REST_Response([], 400));
        $page = $response->get_data();
        if ($item !== '' && $response->get_status() === 200) { $page = ['items' => [$page], 'next_cursor' => null]; }
        ?>
        <div class="wrap faluss-moderation">
            <header><p class="fm-brand">Faluss <span>by Alternative LAB</span></p><h1>Modération Fans · Présentations</h1>
                <p>Nom public, bio et portrait éditoriaux. Cette décision ne vérifie pas l’identité du compte.</p></header>
            <nav aria-label="Modération Fans"><a href="<?php echo esc_url(ModerationView::url(false)); ?>">Textes en attente</a>
                <a href="<?php echo esc_url(ModerationView::url(true)); ?>">Quarantaine images</a><a aria-current="page" href="<?php echo esc_url(self::url()); ?>">Présentations</a><?php CreatorAdmissionView::nav(); ?>
                <?php MessageReportPanel::nav(); ?></nav>
            <?php if ($result !== null): ?><p class="fm-notice" role="status"><?php echo $result->get_status() === 200 ? 'Décision confirmée et journalisée.' : 'Décision non confirmée. Rechargez la fiche et vérifiez sa révision et son journal.'; ?></p>
                <?php $submitted = ModerationPanel::field('item_id', $_POST); if (EditorialService::validId($submitted)): ?>
                    <a href="<?php echo esc_url(add_query_arg('item', $submitted, self::url())); ?>">Relire la fiche et son journal</a>
                <?php endif; ?>
            <?php endif; ?>
            <h2><?php echo $item === '' ? 'Présentations · état sélectionné' : 'Présentation · état courant'; ?></h2>
            <nav aria-label="État des présentations"><?php foreach (['pending' => 'En attente', 'approved' => 'Approuvées', 'rejected' => 'Refusées', 'withdrawn' => 'Retirées'] as $value => $label): ?>
                <a href="<?php echo esc_url(self::url($value)); ?>" <?php echo $value === $state ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?></nav>
            <p class="fm-warning">Examinez le texte et le portrait ensemble. Une proposition ne remplace la dernière présentation approuvée qu’après approbation. La révocation retire immédiatement la version publique et la proposition.</p>
            <?php if ($response->get_status() !== 200 || !is_array($page) || !is_array($page['items'] ?? null)): ?><p role="alert">File indisponible.</p>
            <?php else: ?>
                <?php if ($page['items'] === []): ?><p>Aucune présentation sur cette page.</p><?php endif; ?>
                <div class="fm-items"><?php foreach ($page['items'] as $row): self::item($row); endforeach; ?></div>
                <nav aria-label="Pagination"><a href="<?php echo esc_url(self::url()); ?>">Revenir au début</a>
                    <?php if (is_string($page['next_cursor'] ?? null)): ?><a href="<?php echo esc_url(add_query_arg('cursor', $page['next_cursor'], self::url())); ?>">Page suivante →</a><?php endif; ?></nav>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $row */
    private static function item(array $row): void
    {
        $id = (string) $row['creator_id'];
        $active = CreatorProfileService::publicById($id) !== null;
        ?>
        <article class="fm-card"><div class="fm-meta"><strong><?php echo esc_html((string) $row['state']); ?></strong><span>Révision <?php echo esc_html((string) $row['revision']); ?></span></div>
            <h3><?php echo esc_html($row['public_name'] !== '' ? (string) $row['public_name'] : 'Présentation retirée'); ?></h3>
            <?php $linked = AccountContext::creator($id); ?>
            <p class="fm-id">Créateur : <?php echo esc_html($id); ?></p>
            <a href="<?php echo esc_url(add_query_arg('item', $id, self::url())); ?>">Fiche et journal</a>
            <div class="fm-text"><?php echo esc_html((string) $row['bio']); ?></div>
            <?php if ($row['portrait_id'] !== ''): ModerationView::previewForm((string) $row['portrait_id']); ?>
                <p>Portrait à la révision image <?php echo esc_html((string) $row['portrait_revision']); ?>. Son état et sa propriété sont revérifiés lors de l’approbation.</p>
            <?php else: ?><p>Aucun portrait associé.</p><?php endif; ?>
            <?php if (is_array($row['published'] ?? null)): ?><aside class="fm-card"><h4>Version approuvée · révision <?php echo (int) $row['published']['revision']; ?></h4><strong><?php echo esc_html($row['published']['public_name']); ?></strong><p><?php echo esc_html($row['published']['bio']); ?></p><?php if ($row['published']['portrait_id'] !== ''): ModerationView::previewForm($row['published']['portrait_id']); endif; ?></aside><?php endif; ?>
            <?php if (in_array($row['state'], ['pending', 'approved'], true) || is_array($row['published'] ?? null)): ?>
                <form method="post" action="<?php echo esc_url(self::url()); ?>" class="fm-decision">
                    <?php wp_nonce_field('fans_moderation'); ?>
                    <input type="hidden" name="kind" value="editorial"><input type="hidden" name="item_id" value="<?php echo esc_attr($id); ?>">
                    <input type="hidden" name="revision" value="<?php echo esc_attr((string) $row['revision']); ?>">
                    <label for="reason-<?php echo esc_attr($id); ?>">Décision et motif</label><select required name="reason" id="reason-<?php echo esc_attr($id); ?>">
                        <option value="">Choisir après examen</option>
                        <?php if ($row['state'] === 'pending' && $active && $linked): ?><option value="allowed_editorial">Approuver · présentation autorisée</option><?php endif; ?>
                        <?php if (in_array($row['state'], ['pending', 'approved'], true)): ?><option value="needs_revision">Refuser · correction nécessaire</option><option value="prohibited_content">Refuser · contenu interdit</option><?php endif; ?>
                        <?php if (is_array($row['published'] ?? null)): ?><option value="revoke_prohibited_content">Révoquer la version publique et effacer la proposition · contenu interdit</option><?php endif; ?>
                    </select><p>Refuser une proposition en attente efface cette proposition ; la version approuvée précédente reste visible. Révoquer efface les deux.</p><button type="submit">Confirmer cette décision</button>
                </form>
            <?php endif; ?>
            <?php if (isset($row['journal']) && is_array($row['journal'])): ?><details><summary>Journal · 100 dernières traces maximum</summary><ul class="fm-journal">
                <?php foreach ($row['journal'] as $entry): ?><li><?php echo esc_html(implode(' · ', array_map('strval', $entry))); ?></li><?php endforeach; ?>
            </ul><p>Aucun ancien contenu n’est conservé dans ce journal.</p></details><?php endif; ?>
        </article>
        <?php
    }
}
