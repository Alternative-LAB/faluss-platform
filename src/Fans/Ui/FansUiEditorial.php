<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialModule;
use Faluss\Platform\Fans\Profiles\EditorialService;

/** Owner form dispatches through REST, exactly like the moderation panel. */
final class FansUiEditorial
{
    public bool $active = false;
    public ?\WP_REST_Response $result = null;
    /** @var array<string,mixed>|null */
    public ?array $row = null;
    /** @var list<array<string,mixed>> */
    public array $images = [];

    public static function load(): self
    {
        $view = new self();
        $profile = CreatorProfileService::own();
        if (!EditorialModule::available() || $profile === null) { return $view; }
        $view->active = $profile['status'] === 'active';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $view->result = self::submit($profile['creator_id']);
        }
        $response = ModerationPanel::request('GET', 'creators/me/editorial');
        $data = $response->get_data();
        $view->row = $response->get_status() === 200 && is_array($data) ? $data : null;
        $response = ModerationPanel::request('GET', 'images/portraits');
        $images = $response->get_data();
        if ($response->get_status() === 200 && is_array($images) && is_array($images['items'] ?? null)) {
            $view->images = array_values(array_filter($images['items'], static fn (array $image): bool => $image['state'] === 'approved'));
        }
        return $view;
    }
    private static function submit(string $id): \WP_REST_Response
    {
        $field = static fn (string $key): string => ModerationPanel::field($key, $_POST);
        if (wp_verify_nonce($field('fans_editorial_nonce'), 'fans_editorial') === false) { return new \WP_REST_Response([], 403); }
        $revision = $field('revision'); $action = $field('editorial_action');
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $revision) !== 1 || (int) $revision >= 2147483646) { return new \WP_REST_Response([], 400); }
        if ($action === 'withdraw' && $field('confirm_withdraw') === 'yes') {
            return ModerationPanel::request('POST', 'editorial/' . $id . '/withdraw', ['revision' => (int) $revision]);
        }
        if ($action !== 'submit') { return new \WP_REST_Response([], 400); }
        $portrait = $field('portrait');
        $parts = $portrait === '' ? ['', '0'] : explode(':', $portrait);
        if (count($parts) !== 2 || ($portrait !== '' && (!EditorialService::validId($parts[0]) || preg_match('/^[1-9][0-9]{0,9}$/D', $parts[1]) !== 1))) {
            return new \WP_REST_Response([], 400);
        }
        return ModerationPanel::request('POST', 'creators/me/editorial', ['revision' => (int) $revision,
            'public_name' => $field('public_name'), 'bio' => $field('bio'), 'portrait_id' => $parts[0], 'portrait_revision' => (int) $parts[1]]);
    }
    public function httpStatus(): int { return $this->result?->get_status() ?? 200; }

    public static function render(self $view): void
    {
        $row = $view->row;
        $states = ['absent' => 'À compléter', 'pending' => 'En attente de modération', 'approved' => 'Présentation approuvée', 'rejected' => 'Présentation refusée', 'withdrawn' => 'Présentation retirée'];
        $url = FansUiRoutes::url('creator', 'mon-profil');
        ?>
        <section class="fu-content fu-editorial" data-fans-private-reading data-fans-image-previews data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" aria-labelledby="fu-editorial-title">
            <h2 id="fu-editorial-title">Votre présentation publique</h2>
            <p>Choisissez votre nom de création, votre bio et votre portrait. Ils seront visibles après approbation. Votre compte Faluss Identity reste inchangé.</p>
            <?php if ($view->result !== null): ?><p role="status" class="fu-author__notice"><?php echo match ($view->result->get_status()) {
                200 => 'Action confirmée. L’état courant est affiché ci-dessous.',
                409 => 'La révision ou le portrait a changé. Relisez l’état courant avant de soumettre à nouveau.',
                400 => 'Vérifiez les champs et la confirmation de retrait.',
                403 => 'Session ou droits insuffisants. Rechargez pour vérifier votre accès.',
                429 => 'Trop de modifications récentes. Réessayez plus tard.',
                default => 'Action non confirmée. Rechargez pour vérifier l’état enregistré.',
            }; ?></p><?php endif; ?>
            <?php if ($row === null): ?><p class="fu-live">L’édition de la présentation est indisponible pour le moment.</p>
            <?php else: ?>
                <p class="fu-panel__kicker"><?php echo esc_html($states[$row['state']] ?? 'État indisponible'); ?></p>
                <?php if (in_array($row['state'], ['rejected', 'withdrawn'], true)): ?><p>Les champs ont été effacés. Vous pouvez proposer une nouvelle présentation si votre profil est actif.</p><?php endif; ?>
                <?php if ($view->active): ?>
                    <form method="post" action="<?php echo esc_url($url); ?>" class="fu-panel fu-editorial__form">
                        <?php echo wp_nonce_field('fans_editorial', 'fans_editorial_nonce', false, false); ?>
                        <input type="hidden" name="editorial_action" value="submit"><input type="hidden" name="revision" value="<?php echo esc_attr((string) $row['revision']); ?>">
                        <label for="fu-public-name">Nom public <span>1 à 80 caractères</span></label>
                        <input id="fu-public-name" name="public_name" required maxlength="80" autocomplete="off" value="<?php echo esc_attr((string) $row['public_name']); ?>">
                        <label for="fu-bio">Bio <span>Facultative · 1 000 caractères maximum</span></label>
                        <textarea id="fu-bio" name="bio" maxlength="1000" rows="5"><?php echo esc_textarea((string) $row['bio']); ?></textarea>
                        <fieldset><legend>Portrait</legend><p>Choisissez une image privée déjà approuvée. Le portrait sera revu dans le contexte de votre présentation.</p>
                            <label class="fu-editorial__choice"><input type="radio" name="portrait" value="" <?php echo $row['portrait_id'] === '' ? 'checked' : ''; ?>> Sans portrait</label>
                            <?php $found = false; foreach ($view->images as $i => $image):
                                $selected = $image['image_id'] === $row['portrait_id'] && (int) $image['revision'] === (int) $row['portrait_revision']; $found = $found || $selected; ?>
                                <label class="fu-editorial__choice"><input type="radio" name="portrait" value="<?php echo esc_attr($image['image_id'] . ':' . $image['revision']); ?>" <?php echo $selected ? 'checked' : ''; ?>>Image approuvée <?php echo (int) $i + 1; ?></label>
                                <div class="fu-private-preview"><button type="button" data-private-image="<?php echo esc_url(rest_url('faluss-fans/v1/images/' . $image['image_id'] . '/preview/' . $image['revision'])); ?>">Examiner l’image <?php echo (int) $i + 1; ?></button><p role="status"></p><div data-private-image-output></div></div>
                            <?php endforeach; ?>
                            <?php if ($row['portrait_id'] !== '' && !$found): ?><p role="status">Le portrait précédent n’est plus sélectionnable. Choisissez une image disponible ou « Sans portrait ».</p><?php endif; ?>
                            <?php if ($view->images === []): ?><p>Aucune image approuvée disponible.</p><?php endif; ?>
                        </fieldset>
                        <p class="fu-footnote">Soumettre retire immédiatement la présentation publique précédente jusqu’à la prochaine approbation.</p>
                        <button type="submit">Soumettre à la modération →</button>
                    </form>
                <?php else: ?><p>Profil non actif : les modifications sont fermées. Vous pouvez encore retirer votre présentation.</p><?php endif; ?>
                <?php if (in_array($row['state'], ['pending', 'approved'], true)): ?>
                    <form method="post" action="<?php echo esc_url($url); ?>" class="fu-panel fu-editorial__form">
                        <?php echo wp_nonce_field('fans_editorial', 'fans_editorial_nonce', false, false); ?>
                        <input type="hidden" name="editorial_action" value="withdraw"><input type="hidden" name="revision" value="<?php echo esc_attr((string) $row['revision']); ?>">
                        <label class="fu-editorial__choice"><input type="checkbox" name="confirm_withdraw" value="yes" required> Effacer mon nom public, ma bio et la référence de mon portrait de cette présentation.</label>
                        <button type="submit">Retirer ma présentation</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }
}
