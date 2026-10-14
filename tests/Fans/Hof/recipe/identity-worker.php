<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\RankingIdentity;
use Faluss\Platform\Fans\Hof\RankingVisibleProjection;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansLinkedAccounts;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** @param array<string,mixed> $input
 * @return array<string,mixed> */
function rankingIdentityRecipe(array $input): array
{
    if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe'
        || preg_match('#^/var/tmp/fans-admission-wp-[A-Za-z0-9_-]+/wordpress/$#D', ABSPATH) !== 1) {
        throw new RuntimeException('Disposable fixture only');
    }
    global $wpdb;
    $identity = new RankingIdentity($wpdb);
    if ($input['action'] === 'identity_outside') {
        $error = null;
        try { $identity->memberInTransaction($input['subject']); }
        catch (ModelViolation $failure) { $error = $failure->getMessage(); }
        return ['member' => FansLinkedAccounts::memberInTransaction($input['subject']),
            'subject' => FansLinkedAccounts::identityInTransaction($input['member']),
            'creator' => CreatorProfileService::activeIdForOwnerInTransaction($input['member']), 'error' => $error];
    }
    $wpdb->query('CREATE TEMPORARY TABLE hof_identity_transaction_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
    // Prime actual WordPress caches; mutations below deliberately bypass them and are rolled back.
    if (isset($input['mutate'])) { get_userdata($input['member']); get_user_meta($input['member']); }
    $wpdb->query('START TRANSACTION');
    try {
        $wpdb->query('INSERT INTO hof_identity_transaction_probe VALUES (1)');
        $member = (int) $input['member'];
        $caps = $wpdb->get_blog_prefix() . 'capabilities';
        $mutation = $input['mutate'] ?? '';
        if ($mutation === 'unlink') {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE wp_user_id=%d', FansSsoSchema::tables()['links'], $member));
        } elseif ($mutation === 'delete_account') {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ID=%d', $wpdb->users, $member));
        } elseif ($mutation === 'missing_role') {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE user_id=%d AND meta_key=%s', $wpdb->usermeta, $member, $caps));
        } elseif ($mutation === 'duplicate_role') {
            $wpdb->query($wpdb->prepare('INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)',
                $wpdb->usermeta, $member, $caps, serialize(['subscriber' => true])));
        } elseif (in_array($mutation, ['administrator', 'editor', 'multiple_roles', 'malformed_role'], true)) {
            $value = match ($mutation) {
                'multiple_roles' => serialize(['subscriber' => true, 'editor' => true]),
                'malformed_role' => 'not serialized capabilities',
                default => serialize([$mutation => true]),
            };
            $wpdb->query($wpdb->prepare('UPDATE %i SET meta_value=%s WHERE user_id=%d AND meta_key=%s', $wpdb->usermeta, $value, $member, $caps));
        }
        if ($wpdb->last_error !== '') { throw new RuntimeException('Controlled fixture mutation failed'); }
        $cached = isset($input['mutate']) ? FansSsoService::linkedMember($member) : null;
        $resolved = $identity->memberInTransaction($input['subject']);
        $creator = $identity->creatorInTransaction($input['subject']);
        $result = ['member' => $resolved, 'subject' => FansLinkedAccounts::identityInTransaction($member), 'creator' => $creator,
            'fan_name' => $identity->visibleNameInTransaction('fan', $input['subject']),
            'creator_name' => $identity->visibleNameInTransaction('creator', $input['subject']), 'cached_member' => $cached];
        if (isset($input['ranked'])) {
            $result['display'] = RankingVisibleProjection::build($input['ranked'],
                static fn (string $id): ?string => $identity->visibleNameInTransaction($input['family'], $id));
        }
        if ($input['action'] === 'identity_wrong_connection') {
            try { (new RankingIdentity(clone $wpdb))->memberInTransaction($input['subject']); }
            catch (ModelViolation $failure) { $result['error'] = $failure->getMessage(); }
        }
        $active = (string) $wpdb->get_var('SELECT @@in_transaction') === '1';
        if (isset($input['hold'])) {
            if (realpath(dirname($input['hold'])) !== realpath(dirname(rtrim(ABSPATH, '/')))) { throw new RuntimeException('Private fixture path required'); }
            file_put_contents($input['hold'] . '.ready', 'ready'); $until = microtime(true) + 15;
            while (!is_file($input['hold'] . '.release')) {
                if (microtime(true) > $until) { throw new RuntimeException('Identity interleaving timed out'); }
                usleep(10000);
            }
        }
    } finally { $wpdb->query('ROLLBACK'); }
    return $result + ['transaction_preserved' => $active,
        'probe_rows_after_rollback' => (string) $wpdb->get_var('SELECT COUNT(*) FROM hof_identity_transaction_probe')];
}
