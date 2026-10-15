<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

use Faluss\Platform\Core\SiteRole;

/** Private account binding owned by SSO. No HTTP route, email lookup or account creation. */
final class FansLinkedAccounts
{
    public static function memberInTransaction(string $falussId): ?int
    {
        if (!self::uuid($falussId)) { return null; }
        return self::read('faluss_id', strtolower($falussId))['wp_user_id'] ?? null;
    }

    public static function identityInTransaction(int $member): ?string
    {
        if ($member < 1) { return null; }
        return self::read('wp_user_id', $member)['faluss_id'] ?? null;
    }

    /** Caller owns the primary InnoDB transaction. Reads never begin, commit or repair it.
     * @return array{wp_user_id:int,faluss_id:string}|null */
    private static function read(string $column, int|string $value): ?array
    {
        global $wpdb;
        if (SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) !== SiteRole::Fans
            || !defined('FALUSS_PLATFORM_FANS_SSO') || constant('FALUSS_PLATFORM_FANS_SSO') !== true
            || !self::primaryTransaction() || !FansSsoSchema::ready()) { return null; }
        $table = FansSsoSchema::tables()['links'] ?? null;
        if ($table === null) { return null; }
        $link = $wpdb->get_row($wpdb->prepare('SELECT wp_user_id,faluss_id FROM %i WHERE %i='
            . ($column === 'wp_user_id' ? '%d' : '%s') . ' LIMIT 1 FOR UPDATE', $table, $column, $value), 'ARRAY_A');
        if (!is_array($link) || !self::healthy() || !self::uuid($link['faluss_id'] ?? null)
            || !is_numeric($link['wp_user_id'] ?? null) || (int) $link['wp_user_id'] < 1
            || (string) (int) $link['wp_user_id'] !== (string) $link['wp_user_id']) { return null; }
        $member = (int) $link['wp_user_id'];
        $exists = $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE ID=%d LIMIT 1 FOR UPDATE', $wpdb->users, $member));
        if (!self::healthy() || (string) $exists !== (string) $member) { return null; }
        // WordPress object/meta caches are not an authorization source in this locking read.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_value FROM %i WHERE user_id=%d AND meta_key=%s'
            . ' ORDER BY umeta_id FOR UPDATE', $wpdb->usermeta, $member, $wpdb->get_blog_prefix() . 'capabilities'), 'ARRAY_A');
        if (!is_array($rows) || !self::healthy() || count($rows) !== 1
            || !is_string($rows[0]['meta_value'] ?? null) || !is_serialized($rows[0]['meta_value'])) { return null; }
        try { $capabilities = @unserialize($rows[0]['meta_value'], ['allowed_classes' => false]); }
        catch (\Throwable) { return null; }
        if (!is_array($capabilities)) { return null; }
        $roles = [];
        foreach ($capabilities as $role => $enabled) {
            if ($enabled && is_string($role) && wp_roles()->is_role($role)) { $roles[] = $role; }
        }
        // Preserve the SSO member contract: exactly the local subscriber role, never administrator/editor.
        if ($roles !== ['subscriber']) { return null; }
        return ['wp_user_id' => $member, 'faluss_id' => strtolower($link['faluss_id'])];
    }

    private static function primaryTransaction(): bool
    {
        global $wpdb;
        $state = $wpdb->get_row('SELECT @@in_transaction AS active,@@read_only AS replica', 'ARRAY_A');
        if (!is_array($state) || !self::healthy() || (string) ($state['active'] ?? '') !== '1'
            || (string) ($state['replica'] ?? '') !== '0') { return false; }
        foreach ([$wpdb->users, $wpdb->usermeta] as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table), 'ARRAY_A');
            if (!is_array($status) || !self::healthy() || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) { return false; }
        }
        return true;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    /** SQL updates wpdb error state between reads.
     * @phpstan-impure */
    private static function healthy(): bool
    {
        global $wpdb;
        return $wpdb->last_error === '';
    }
}
