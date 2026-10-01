<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Fans\Sso\FansAccountDirectory;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Messaging\MessageOperations;

final class BackOfficeView
{
    public static function render(string $view): void
    {
        if (!BackOffice::allowed()) { return; }
        match ($view) {
            'accounts', 'search' => self::accounts($view === 'search'),
            'modules' => self::modules(),
            'operations' => BackOfficeOperations::render(),
            'staff' => BackOfficeStaff::render(),
            'catalog' => BackOfficeCatalog::render(),
            default => self::overview(),
        };
    }

    private static function overview(): void
    {
        echo '<h1>Administration Fans</h1><p class="fb-meta">Examiner les comptes et contenus, traiter les dossiers et vérifier les services disponibles.</p><div class="fb-grid">';
        foreach (BackOffice::sections() as $key => $title) {
            if ($key === 'overview') { continue; }
            echo '<article class="fb-card"><h2>' . esc_html($title) . '</h2><a href="' . esc_url(BackOffice::url(['view' => $key])) . '">Ouvrir ' . esc_html($title) . ' →</a></article>';
        }
        echo '</div><p>Les sections reflètent vos habilitations et les modules disponibles. Activer un profil ne valide ni un partenariat commercial, ni sa présentation, ni ses publications ou images.</p>';
    }

    private static function accounts(bool $search): void
    {
        $query = ModerationPanel::field('q', $_GET); $cursor = ModerationPanel::field('cursor', $_GET);
        $user = ModerationPanel::field('user', $_GET);
        echo '<h1>' . ($search ? 'Recherche interne' : 'Comptes liés') . '</h1>';
        if ($user !== '') {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $user) !== 1 || FansAccountDirectory::account((int) $user) === null) { self::error('Compte indisponible.', 404); return; }
            self::account((int) $user, true); return;
        }
        ?>
        <form class="fb-search" method="get" action="<?php echo esc_url(BackOffice::url()); ?>">
            <input type="hidden" name="view" value="<?php echo $search ? 'search' : 'accounts'; ?>">
            <label for="fb-query">E-mail local, Faluss ID<?php echo $search ? ' ou référence exacte de profil, publication, image ou dossier' : ''; ?></label>
            <input id="fb-query" name="q" value="<?php echo esc_attr($query); ?>" maxlength="191"><button type="submit">Rechercher</button>
        </form>
        <?php
        if ($cursor !== '' && preg_match('/^[1-9][0-9]{0,9}$/D', $cursor) !== 1) { self::error('Curseur invalide.', 400); return; }
        $page = FansAccountDirectory::search($query, (int) $cursor);
        if ($page instanceof \WP_Error) { self::error('Recherche indisponible.', (int) ($page->get_error_data()['status'] ?? 503)); return; }
        echo '<h2>Comptes liés</h2><div class="fb-grid">';
        foreach ($page['items'] as $row) { self::account($row['user_id'], false); }
        echo '</div>';
        if ($page['items'] === []) { echo '<p class="fb-empty">Aucun compte lié ne correspond à cette recherche locale.</p>'; }
        echo '<nav class="fb-pagination" aria-label="Pagination des comptes"><a href="' . esc_url(BackOffice::url(['view' => $search ? 'search' : 'accounts', 'q' => $query])) . '">Revenir au début</a>';
        if ($page['next_cursor'] !== null) { echo '<a href="' . esc_url(BackOffice::url(['view' => $search ? 'search' : 'accounts', 'q' => $query, 'cursor' => (string) $page['next_cursor']])) . '">Page suivante →</a>'; }
        echo '</nav>';
        if ($search && EditorialService::validId($query)) { BackOfficeSearch::reference($query); }
        if ($search) { echo '<p class="fb-meta">Recherche locale limitée aux comptes et références exactes. Aucun contenu de conversation ni motif interne n’est indexé.</p>'; }
    }

    private static function account(int $id, bool $detail): void
    {
        $profile = CreatorProfileService::administration(null, $id);
        echo '<article class="fb-card faluss-moderation"><h2>' . ($detail ? 'Fiche du compte local' : 'Compte lié') . '</h2>';
        AccountContext::member($id, $profile);
        BackOfficeMetrics::render($id, $profile);
        if (!$detail) { echo '<a href="' . esc_url(BackOffice::url(['view' => 'accounts', 'user' => (string) $id])) . '">Examiner le compte →</a>'; }
        if ($detail && $profile !== null) {
            foreach (['profiles' => 'Admission et journal', 'editorial' => 'Présentation éditoriale'] as $view => $label) {
                if (isset(BackOffice::sections()[$view])) { echo '<p><a href="' . esc_url(BackOffice::url(['view' => $view, 'item' => $profile['creator_id']])) . '">' . esc_html($label) . ' →</a></p>'; }
            }
        }
        echo '</article>';
    }

    private static function modules(): void
    {
        echo '<h1>Modules et configuration</h1><p>Lecture seule. Aucun contrôle de cette page ne change un flag, une licence ou un secret.</p><table class="fb-table"><thead><tr><th>Fonction</th><th>État effectif</th></tr></thead><tbody>';
        $sections = BackOffice::sections();
        foreach (['accounts' => 'Liaisons SSO', 'profiles' => 'Profils Créateur', 'editorial' => 'Présentations', 'texts' => 'Publications', 'images' => 'Images privées', 'messages' => 'Signalements', 'catalog' => 'Catalogue'] as $key => $label) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . (isset($sections[$key]) ? 'Disponible pour votre compte' : 'Module fermé, prérequis absent ou habilitation insuffisante') . '</td></tr>';
        }
        echo '<tr><th>Notifications privées</th><td>'.(\Faluss\Platform\Fans\Notifications\NotificationSchema::ready()?'Stockage vérifié ; lecture réservée au destinataire':'Stockage indisponible : décisions émettrices refusées').'</td></tr>';
        echo '<tr><th>HoF et classement calculé</th><td>Indisponibles : contrat Hub non ratifié</td></tr><tr><th>Paiement et réservations</th><td>Indisponibles</td></tr></tbody></table>';
        if (MessageOperations::allowed()) { echo '<p><a href="' . esc_url(BackOffice::url(['view' => 'operations'])) . '">Vérifier les schémas et la rétention messagerie →</a></p>'; }
    }

    public static function error(string $message, int $status): void
    { status_header($status); echo '<p class="fb-empty" role="alert">' . esc_html($message) . '</p>'; }
}
