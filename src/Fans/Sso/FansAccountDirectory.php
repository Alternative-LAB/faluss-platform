<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use Faluss\Platform\Core\SiteRole;

/** Private local account projection. Never an Identity administration or civil-identity claim. */
final class FansAccountDirectory
{
    public static function allowed(): bool
    {
        return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) === SiteRole::Fans
            && current_user_can('manage_options') && FansSsoSchema::ready();
    }

    /** @return array{user_id:int,email:?string,faluss_id:?string,linked:bool,handle:null}|null */
    public static function account(int $id): ?array
    {
        if (!self::allowed() || $id < 1) { return null; }
        $user = get_userdata($id);
        if (!$user instanceof \WP_User) { return null; }
        $table = FansSsoSchema::tables()['links'] ?? null;
        if ($table === null) { return null; }
        global $wpdb;
        $link = $wpdb->get_var($wpdb->prepare('SELECT faluss_id FROM `' . $table . '` WHERE wp_user_id=%d LIMIT 1', $id));
        $subject = is_string($link) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $link) === 1 ? strtolower($link) : null;
        if ($wpdb->last_error !== '') { return null; }
        return ['user_id' => $id, 'email' => is_email($user->user_email) ? $user->user_email : null,
            'faluss_id' => $subject, 'linked' => $subject !== null && FansSsoService::linkedMember($id), 'handle' => null];
    }

    /** Email/Faluss ID search stays local; no technical user_login masquerades as a handle.
     * @return array{items:list<array<string,mixed>>,next_cursor:?int}|\WP_Error */
    public static function search(string $query = '', int $after = 0): array|\WP_Error
    {
        if (!self::allowed()) { return new \WP_Error('fans_accounts_forbidden', 'Accès non autorisé.', ['status' => 403]); }
        $table = FansSsoSchema::tables()['links'] ?? null;
        if ($table === null) { return new \WP_Error('fans_accounts_unavailable', 'Comptes indisponibles.', ['status' => 503]); }
        if (strlen($query) > 191 || $after < 0) { return new \WP_Error('invalid_account_search', 'Recherche invalide.', ['status' => 400]); }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($query) . '%';
        $ids = $wpdb->get_col($wpdb->prepare('SELECT u.ID FROM `' . $wpdb->users . '` u INNER JOIN `'
            . $table . '` l ON l.wp_user_id=u.ID WHERE u.ID>%d AND (u.user_email LIKE %s OR l.faluss_id LIKE %s) ORDER BY u.ID LIMIT 21', $after, $like, $like));
        if (!is_array($ids) || $wpdb->last_error !== '') { return new \WP_Error('fans_accounts_unavailable', 'Comptes indisponibles.', ['status' => 503]); }
        $more = count($ids) > 20; $ids = array_slice($ids, 0, 20); $items = [];
        foreach ($ids as $id) { $row = self::account((int) $id); if ($row !== null) { $items[] = $row; } }
        return ['items' => $items, 'next_cursor' => $more ? (int) end($ids) : null];
    }
}
