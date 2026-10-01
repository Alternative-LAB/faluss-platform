<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialModule;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Sso\FansAccountDirectory;

/** One private account card shared by all native and standalone administration views. */
final class AccountContext
{
    public static function creator(string $id): bool
    {
        if (!FansAccountDirectory::allowed()) { return false; }
        $profile = CreatorProfileService::administration($id);
        return self::member((int) ($profile['wp_user_id'] ?? 0), $profile);
    }

    /** @param array<string,mixed>|null $profile */
    public static function member(int $userId, ?array $profile = null): bool
    {
        if (!FansAccountDirectory::allowed()) { return false; }
        $account = FansAccountDirectory::account($userId);
        $profile ??= CreatorProfileService::administration(null, $userId);
        $editorial = $profile !== null && EditorialModule::available() ? EditorialService::publicById($profile['creator_id']) : null;
        ?>
        <aside class="fm-account" aria-label="Compte local lié">
            <?php if ($editorial !== null && $editorial['portrait'] === true): ?>
                <img width="56" height="56" alt="Portrait approuvé" loading="lazy" referrerpolicy="no-referrer" src="<?php echo esc_url(rest_url('faluss-fans/v1/creators/' . $profile['creator_id'] . '/portrait/' . $editorial['revision'])); ?>">
            <?php endif; ?>
            <dl><dt>E-mail du compte local</dt><dd><?php echo esc_html($account['email'] ?? 'Indisponible'); ?></dd>
                <dt>Faluss ID</dt><dd><?php echo esc_html($account['faluss_id'] ?? 'Liaison absente ou indisponible'); ?></dd>
                <dt>Handle</dt><dd>Non fourni par le contrat actuel</dd>
                <dt>Statut Fans</dt><dd><?php echo $profile === null ? 'Fan sans profil Créateur' : 'Créateur · ' . esc_html(['pending' => 'en attente', 'active' => 'actif', 'suspended' => 'suspendu'][$profile['status']] ?? 'indisponible'); ?></dd>
                <?php if ($editorial !== null): ?><dt>Nom public approuvé</dt><dd><?php echo esc_html($editorial['public_name']); ?></dd><?php endif; ?>
            </dl>
            <?php if ($account === null || !$account['linked']): ?><p class="fm-warning">Liaison membre absente, invalide ou compte privilégié. Ne pas approuver avant vérification ; les approbations sont refusées côté serveur.</p><?php endif; ?>
            <p class="fm-account__notice">Données locales Fans, sans vérification d’identité civile ni administration du compte Me. Aucun portrait non approuvé n’est utilisé comme identité.</p>
        </aside>
        <?php
        return $account !== null && $account['linked'];
    }
}
