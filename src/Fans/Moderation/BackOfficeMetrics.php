<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Publications\TextPublicationService;
use Faluss\Platform\Fans\Images\ImageService;

final class BackOfficeMetrics
{
    /** @param array<string,mixed>|null $profile */
    public static function render(int $user, ?array $profile): void
    {
        if (!BackOffice::allowed()) { return; }
        echo '<h3>Activité locale</h3>';
        if ($profile === null) { echo '<p>Aucun profil Créateur local. Les statistiques de contribution PF sont indisponibles.</p>'; return; }
        foreach (['Publications'=>TextPublicationService::administrationCounts($profile['creator_id']), 'Images'=>ImageService::administrationCounts($profile['creator_id'])] as $title=>$counts) {
            echo '<h4>' . esc_html($title) . '</h4>';
            if ($counts === null) { echo '<p>Statistiques indisponibles : service fermé ou lecture impossible.</p>'; continue; }
            echo '<ul class="fb-stats">';
            foreach (['pending'=>'en attente','approved'=>'approuvées','rejected'=>'refusées','withdrawn'=>'retirées'] as $key=>$label) { echo '<li><strong>' . (int) $counts[$key] . '</strong> ' . esc_html($label) . '</li>'; }
            echo '</ul>';
        }
        echo '<p class="fb-meta">Comptages actuels du stockage Fans. Aucun rang, progression PF, montant ou statistique de conversation privée.</p>';
    }
}
