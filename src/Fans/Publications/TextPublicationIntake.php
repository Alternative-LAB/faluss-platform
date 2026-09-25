<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Publications;

/** Admission uses the committed audit as its counter; no transient or separate quota ledger. */
final class TextPublicationIntake
{
    public const PENDING_LIMIT = 20;
    public const HOURLY_LIMIT = 30;
    public const DAILY_LIMIT = 100;

    public static function lock(string $creatorId): ?string
    {
        global $wpdb;
        $name = 'fans_intake_' . substr(hash('sha256', TextPublicationSchema::table() . ':' . $creatorId), 0, 48);
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $name)) === 1 ? $name : null;
    }

    public static function release(string $name): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /** Call only after the creator lock and START TRANSACTION, before writing. */
    public static function check(string $creatorId, bool $addsPending = true): ?\WP_Error
    {
        global $wpdb;
        $publications = TextPublicationSchema::table();
        $pending = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $publications . '` WHERE creator_id=%s AND state=%s', $creatorId, 'pending'));
        if (self::databaseError() || !is_numeric($pending)) { return self::error('publication_intake_unavailable', 503); }
        if ($addsPending && (int) $pending >= self::PENDING_LIMIT) { return self::error('publication_pending_quota', 429); }
        $counts = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS daily_count,'
            . ' COALESCE(SUM(d.occurred_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR),0) AS hourly_count'
            . ' FROM `' . TextPublicationSchema::table(true) . '` d INNER JOIN `' . $publications . '` p ON p.publication_id=d.publication_id'
            . ' WHERE p.creator_id=%s AND d.action IN (%s,%s) AND d.occurred_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR',
            $creatorId, 'create', 'edit'), 'ARRAY_A');
        if (self::databaseError() || !is_array($counts) || !is_numeric($counts['hourly_count'] ?? null) || !is_numeric($counts['daily_count'] ?? null)) {
            return self::error('publication_intake_unavailable', 503);
        }
        if ((int) $counts['hourly_count'] >= self::HOURLY_LIMIT) { return self::error('publication_hourly_quota', 429); }
        if ((int) $counts['daily_count'] >= self::DAILY_LIMIT) { return self::error('publication_daily_quota', 429); }
        return null;
    }

    /** @phpstan-impure Reads the error of the most recent database operation. */
    private static function databaseError(): bool
    {
        global $wpdb;
        return $wpdb->last_error !== '';
    }

    private static function error(string $code, int $status): \WP_Error
    {
        return new \WP_Error($code, 'Admission de texte indisponible.', ['status' => $status]);
    }
}
