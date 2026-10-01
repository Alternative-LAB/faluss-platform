<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Profiles\CreatorProfilesModule;
use Faluss\Platform\Fans\Profiles\EditorialService;

/** Native forms call the existing status REST route; no domain-table access. */
final class CreatorAdmissionView
{
    public static function url(string $status = 'pending', string $item = ''): string
    {
        return ModerationPanel::url(['view' => 'profiles', 'status' => $status, 'item' => $item]);
    }

    public static function nav(): void
    {
        if (CreatorProfilesModule::available()) {
            echo '<a href="' . esc_url(self::url()) . '">Profils Créateur</a>';
        }
    }

    public static function submit(): \WP_REST_Response
    {
        $id = ModerationPanel::field('item_id', $_POST);
        $revision = ModerationPanel::field('revision', $_POST);
        $status = ModerationPanel::field('status', $_POST);
        if (!CreatorProfilesModule::available()) { return new \WP_REST_Response([], 503); }
        if (!EditorialService::validId($id) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision) !== 1
            || (int) $revision >= 2147483646 || !in_array($status, ['active', 'suspended'], true)
            || ModerationPanel::field('confirm', $_POST) !== 'yes' || $_FILES !== []
            || array_diff(array_keys($_POST), ['kind', 'item_id', 'revision', 'status', 'confirm', '_wpnonce', '_wp_http_referer']) !== []) {
            return new \WP_REST_Response(['code' => 'invalid_panel_input'], 400);
        }
        return ModerationPanel::request('POST', 'creators/' . $id . '/status', ['status' => $status, 'revision' => (int) $revision]);
    }

    public static function render(?\WP_REST_Response $result): void
    {
        $status = ModerationPanel::field('status', $_GET) ?: 'pending';
        $item = ModerationPanel::field('item', $_GET);
        $cursor = ModerationPanel::field('cursor', $_GET);
        $response = $item !== '' ? (EditorialService::validId($item) ? ModerationPanel::request('GET', 'creators/' . $item . '/private') : new \WP_REST_Response([], 400))
            : ModerationPanel::request('GET', 'creators/moderation', ['status' => $status] + ($cursor !== '' ? ['cursor' => $cursor] : []));
        if ($response->get_status() >= 400) { status_header($response->get_status()); }
        $page = $response->get_data();
        ?>
        <div class="wrap faluss-moderation">
            <header><p class="fm-brand">Faluss <span>by Alternative LAB</span></p><h1>Profils Créateur</h1>
                <p>Examiner les demandes de profil et décider de leur visibilité dans Fans.</p></header>
            <nav aria-label="Modération Fans">
                <a href="<?php echo esc_url(self::url()); ?>" aria-current="page">Profils Créateur</a>
                <?php if (\Faluss\Platform\Fans\Publications\TextPublicationsModule::available()): ?><a href="<?php echo esc_url(ModerationView::url(false)); ?>">Textes en attente</a><?php endif; ?>
                <?php if (\Faluss\Platform\Fans\Images\ImagesModule::available()): ?><a href="<?php echo esc_url(ModerationView::url(true)); ?>">Quarantaine images</a><?php endif; ?>
                <?php if (\Faluss\Platform\Fans\Profiles\EditorialModule::available()): ?><a href="<?php echo esc_url(EditorialModerationView::url()); ?>">Présentations</a><?php endif; ?>
                <?php MessageReportPanel::nav(); ?>
            </nav>
            <p class="fm-warning">Activer le profil ne valide ni le partenariat commercial, ni les contenus éditoriaux, ni les images. Ce n’est pas une vérification d’identité. Le nom, la bio et le portrait restent soumis à leur approbation séparée.</p>
            <?php if ($result !== null): ?><div class="fm-notice" role="status"><strong><?php echo $result->get_status() === 200 ? 'Décision confirmée et journalisée.' : 'Décision non confirmée.'; ?></strong>
                <p><?php echo $result->get_status() === 409 ? 'Une autre décision a modifié ce profil. Relisez son état et son journal avant de décider à nouveau.' : ($result->get_status() === 200 ? 'Le statut a été enregistré. Les autres validations restent indépendantes.' : 'Vérifiez les droits, le service et le journal avant toute nouvelle tentative.'); ?></p>
                <a href="<?php echo esc_url(self::url($status, $item)); ?>">Recharger sans renvoyer la décision</a></div><?php endif; ?>
            <nav aria-label="État des profils"><?php foreach (['pending' => 'En attente', 'active' => 'Actifs', 'suspended' => 'Suspendus'] as $value => $label): ?>
                <a href="<?php echo esc_url(self::url($value)); ?>" <?php echo $status === $value ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?></nav>
            <?php if ($response->get_status() !== 200 || !is_array($page)): ?><p role="alert">Profils indisponibles. Vérifiez les permissions et le journal de statut, puis rechargez.</p>
            <?php elseif ($item !== ''): self::item($page, $status);
            else: ?>
                <h2><?php echo esc_html(self::label($status)); ?></h2>
                <?php if ($page['items'] === []): ?><p>Aucun profil dans cette file.</p><?php endif; ?>
                <div class="fm-items"><?php foreach ($page['items'] as $row): ?>
                    <article class="fm-card"><h3>Demande · <?php echo esc_html(self::category($row['category'])); ?></h3>
                        <?php AccountContext::creator($row['creator_id']); ?>
                        <p class="fm-meta"><?php echo esc_html(self::label($row['status'])); ?> · demande du <?php echo esc_html($row['created_at']); ?> UTC</p>
                        <p class="fm-id">Référence privée : <?php echo esc_html($row['creator_id']); ?></p>
                        <a href="<?php echo esc_url(self::url($status, $row['creator_id'])); ?>">Examiner le profil et son journal →</a>
                    </article>
                <?php endforeach; ?></div>
                <nav aria-label="Pagination"><a href="<?php echo esc_url(self::url($status)); ?>">Revenir au début</a>
                    <?php if ($page['next_cursor'] !== null): ?><a href="<?php echo esc_url(add_query_arg('cursor', $page['next_cursor'], self::url($status))); ?>">Page suivante →</a><?php endif; ?>
                </nav><p>20 profils par page, triés par référence. La file évolue après chaque décision.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $row */
    private static function item(array $row, string $filter): void
    {
        $id = $row['creator_id'];
        ?>
        <article class="fm-card">
            <div class="fm-meta"><strong><?php echo esc_html(self::label($row['status'])); ?></strong><span>Révision de statut <?php echo esc_html((string) $row['status_revision']); ?></span></div>
            <h2>Profil · <?php echo esc_html(self::category($row['category'])); ?></h2>
            <?php AccountContext::creator($id); ?>
            <p class="fm-id">Référence privée : <?php echo esc_html($id); ?><br>Demande : <?php echo esc_html($row['created_at']); ?> UTC<br>Mis à jour : <?php echo esc_html($row['updated_at']); ?> UTC</p>
            <p><?php echo $row['status'] === 'active' ? 'Profil public. Seules les données éditoriales approuvées séparément peuvent être diffusées.' : 'Profil absent des lectures publiques. Aucun nom ni portrait n’est approuvé par cette décision.'; ?></p>
            <form class="fm-decision" method="post" action="<?php echo esc_url(self::url($filter, $id)); ?>">
                <?php wp_nonce_field('fans_moderation'); ?>
                <input type="hidden" name="kind" value="creator-profile"><input type="hidden" name="item_id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="revision" value="<?php echo esc_attr((string) $row['status_revision']); ?>">
                <label for="profile-status">Décision de statut</label><select required id="profile-status" name="status">
                    <option value="">Choisir après examen</option>
                    <?php if ($row['status'] !== 'active'): ?><option value="active">Activer le profil</option><?php endif; ?>
                    <?php if ($row['status'] !== 'suspended'): ?><option value="suspended">Suspendre le profil</option><?php endif; ?>
                </select>
                <label class="fm-confirm"><input required type="checkbox" name="confirm" value="yes">Je confirme cette décision de statut uniquement ; les validations commerciales, éditoriales et des images restent séparées.</label>
                <button type="submit">Confirmer la décision</button>
            </form>
            <details class="fm-journal" open><summary>Journal · 20 dernières décisions</summary>
                <?php if ($row['journal'] === []): ?><p>Aucune décision journalisée. Révision initiale 0 ; un éventuel statut antérieur à cette version n’est pas reconstitué.</p><?php endif; ?>
                <?php foreach ($row['journal'] as $entry): ?><p>Révision <?php echo esc_html((string) $entry['revision']); ?> · <?php echo esc_html(self::label($entry['previous_status'])); ?> → <?php echo esc_html(self::label($entry['status'])); ?><br>Administrateur local <?php echo esc_html((string) $entry['actor_id']); ?> · <?php echo esc_html($entry['occurred_at']); ?> UTC</p><?php endforeach; ?>
            </details>
        </article>
        <?php
    }

    private static function label(string $status): string
    { return match ($status) { 'pending' => 'En attente', 'active' => 'Actif', 'suspended' => 'Suspendu', default => 'État indisponible' }; }

    private static function category(string $category): string
    { return match ($category) { 'arts' => 'Arts', 'music' => 'Musique', 'games' => 'Jeux', 'learning' => 'Apprentissage', 'lifestyle' => 'Lifestyle', default => 'Catégorie indisponible' }; }
}
