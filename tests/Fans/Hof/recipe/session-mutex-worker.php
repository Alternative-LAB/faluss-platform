<?php

declare(strict_types=1);

use Faluss\Platform\Fans\Hof\SessionStore;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** @param array<string,mixed> $input
 * @return array<string,mixed> */
function sessionMutexRecipe(array $input): array
{
    if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe'
        || preg_match('#^/var/tmp/fans-admission-wp-[A-Za-z0-9_-]+/wordpress/$#D', ABSPATH) !== 1) { throw new RuntimeException('Disposable fixture only'); }
    global $wpdb;
    $store = new SessionStore($wpdb);
    $wpdb->query('CREATE TEMPORARY TABLE hof_session_mutex_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
    $nested = ($input['mode'] ?? '') === 'nested';
    if ($nested) { $wpdb->query('START TRANSACTION'); $wpdb->query('INSERT INTO hof_session_mutex_probe VALUES (1)'); }
    $error = null; $value = null;
    try {
        $operation = static function () use ($wpdb,$input): array {
            $before = (string) $wpdb->get_var('SELECT @@in_transaction');
            if (($input['mode'] ?? '') === 'ordinary') {
                $wpdb->query('INSERT INTO hof_session_mutex_probe VALUES (1)');
                return ['before' => $before,'during' => (string) $wpdb->get_var('SELECT @@in_transaction')];
            }
            if (($input['mode'] ?? '') === 'reject') { throw new ModelViolation('hof_fixture_refused'); }
            $wpdb->query('START TRANSACTION'); $wpdb->query('INSERT INTO hof_session_mutex_probe VALUES (1)');
            if (($input['mode'] ?? '') === 'unfinished') { return ['before' => $before]; }
            if (isset($input['hold'])) {
                if (realpath(dirname($input['hold'])) !== realpath(dirname(rtrim(ABSPATH,'/')))) { throw new RuntimeException('Private fixture path required'); }
                file_put_contents($input['hold'] . '.ready','ready'); $until = microtime(true) + 15;
                while (!is_file($input['hold'] . '.release')) {
                    if (microtime(true) > $until) { throw new RuntimeException('Session mutex interleaving timed out'); }
                    usleep(10000);
                }
            }
            $during = (string) $wpdb->get_var('SELECT @@in_transaction'); $wpdb->query('ROLLBACK');
            return ['before' => $before,'during' => $during];
        };
        $value = ($input['mode'] ?? '') === 'ordinary' ? $store->transaction($operation) : $store->withGovernanceMutex($operation);
    } catch (ModelViolation $failure) { $error = $failure->reason; }
    $preserved = (string) $wpdb->get_var('SELECT @@in_transaction');
    if ($nested) { $wpdb->query('ROLLBACK'); }
    $lock = 'fans_hof_' . substr(hash('sha256',$wpdb->prefix . '|sessions'),0,48);
    return ['value' => $value,'error' => $error,'transaction_after' => $preserved,
        'probe_rows' => (string) $wpdb->get_var('SELECT COUNT(*) FROM hof_session_mutex_probe'),
        'mutex_free' => (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)',$lock))];
}
