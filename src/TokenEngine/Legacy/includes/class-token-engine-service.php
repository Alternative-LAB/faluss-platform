<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Internal PHP-only facade. Connectors must use this class, never the tables directly. */
final class Token_Engine_Service {
    const SETTINGS_OPTION = 'token_engine_settings';
    const DIRECTIONS = array( 'credit', 'debit' );
    const SCOPES = array( 'global', 'project' );
    const TRIGGERS = array( 'event', 'claim' );
    const PERIODICITIES = array( 'none', 'once', 'daily', 'cooldown' );
    const DAILY_REWARD_RULE_KEY = 'daily_reward';

    public static function configuration() {
        $stored = get_option( self::SETTINGS_OPTION, array() );
        return array(
            'unit_code' => self::unit_code( $stored['unit_code'] ?? '' ),
            'unit_singular' => self::text( $stored['unit_singular'] ?? '', 80 ),
            'unit_plural' => self::text( $stored['unit_plural'] ?? '', 80 ),
            'reference_timezone' => self::timezone( $stored['reference_timezone'] ?? '' ),
        );
    }

    public static function configuration_is_valid() {
        $settings = self::configuration();
        return '' !== $settings['unit_code'] && '' !== $settings['unit_singular'] && '' !== $settings['unit_plural'] && '' !== $settings['reference_timezone'];
    }

    public static function save_configuration( $values ) {
        $next = array(
            'unit_code' => self::unit_code( $values['unit_code'] ?? '' ),
            'unit_singular' => self::text( $values['unit_singular'] ?? '', 80 ),
            'unit_plural' => self::text( $values['unit_plural'] ?? '', 80 ),
            'reference_timezone' => self::timezone( $values['reference_timezone'] ?? '' ),
        );
        if ( ! self::settings_complete( $next ) ) {
            return self::error( 'invalid_configuration', __( 'La configuration de l’unité est invalide.', 'token-engine' ) );
        }
        $current = self::configuration();
        if ( self::has_transactions() && '' !== $current['unit_code'] && $current['unit_code'] !== $next['unit_code'] ) {
            return self::error( 'unit_code_locked', __( 'Le code de l’unité est verrouillé après la première écriture.', 'token-engine' ) );
        }
        update_option( self::SETTINGS_OPTION, $next, false );
        return $next;
    }

    public static function has_transactions() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Token_Engine_Schema::ledger_table() ) > 0;
    }

    public static function find_project( $project_key, $active_only = false ) {
        global $wpdb;
        $project_key = self::project_key( $project_key );
        if ( '' === $project_key ) { return null; }
        $sql = 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' WHERE project_key=%s' . ( $active_only ? ' AND active=1' : '' );
        $project = $wpdb->get_row( $wpdb->prepare( $sql, $project_key ), ARRAY_A );
        return is_array( $project ) ? $project : null;
    }

    public static function active_projects() {
        global $wpdb;
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' WHERE active=1 ORDER BY name ASC, id ASC', ARRAY_A );
    }

    public static function create_project( $values ) {
        global $wpdb;
        $key = self::project_key( $values['project_key'] ?? '' );
        $name = self::text( $values['name'] ?? '', 120 );
        if ( '' === $key || '' === $name ) {
            return self::error( 'invalid_project', __( 'Le projet est invalide.', 'token-engine' ) );
        }
        $now = current_time( 'mysql', true );
        $created = $wpdb->insert( Token_Engine_Schema::projects_table(), array( 'project_key' => $key, 'name' => $name, 'active' => empty( $values['active'] ) ? 0 : 1, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%d', '%s', '%s' ) );
        return false === $created ? self::error( 'project_conflict', __( 'Cette clé de projet existe déjà.', 'token-engine' ) ) : self::find_project( $key );
    }

    public static function update_project( $id, $values ) {
        global $wpdb;
        $id = absint( $id );
        $name = self::text( $values['name'] ?? '', 120 );
        if ( ! $id || '' === $name ) {
            return self::error( 'invalid_project', __( 'Le projet est invalide.', 'token-engine' ) );
        }
        $updated = $wpdb->update( Token_Engine_Schema::projects_table(), array( 'name' => $name, 'active' => empty( $values['active'] ) ? 0 : 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%d', '%s' ), array( '%d' ) );
        return false === $updated ? self::error( 'project_update_failed', __( 'Le projet ne peut pas être enregistré.', 'token-engine' ) ) : true;
    }

    public static function find_rule( $rule_key ) {
        global $wpdb;
        $rule_key = self::rule_key( $rule_key );
        if ( '' === $rule_key ) { return null; }
        $rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::rules_table() . ' WHERE rule_key=%s', $rule_key ), ARRAY_A );
        return is_array( $rule ) ? $rule : null;
    }

    public static function rules() {
        global $wpdb;
        return (array) $wpdb->get_results( 'SELECT rules.*, projects.name AS project_name FROM ' . Token_Engine_Schema::rules_table() . ' AS rules LEFT JOIN ' . Token_Engine_Schema::projects_table() . ' AS projects ON projects.id=rules.project_id ORDER BY rules.id DESC', ARRAY_A );
    }

    public static function create_rule( $values ) {
        global $wpdb;
        $rule = self::normalise_rule( $values );
        if ( is_wp_error( $rule ) ) { return $rule; }
        $now = current_time( 'mysql', true );
        $created = $wpdb->insert( Token_Engine_Schema::rules_table(), $rule + array( 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ) );
        return false === $created ? self::error( 'rule_conflict', __( 'Cette clé de règle existe déjà.', 'token-engine' ) ) : self::find_rule( $rule['rule_key'] );
    }

    public static function update_rule( $id, $values ) {
        global $wpdb;
        $id = absint( $id );
        $current = $id ? self::rule_by_id( $id ) : null;
        if ( ! $current ) {
            return self::error( 'invalid_rule', __( 'La règle est invalide.', 'token-engine' ) );
        }
        $values['rule_key'] = $current['rule_key'];
        $rule = self::normalise_rule( $values );
        if ( is_wp_error( $rule ) ) { return $rule; }
        $updated = $wpdb->update( Token_Engine_Schema::rules_table(), $rule + array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ), array( '%d' ) );
        return false === $updated ? self::error( 'rule_update_failed', __( 'La règle ne peut pas être enregistrée.', 'token-engine' ) ) : true;
    }

    /** Returns the balance projection; the ledger remains the only stored source of truth. */
    public static function balance( $subject_id, $project_key = '' ) {
        global $wpdb;
        $subject_id = self::subject_id( $subject_id );
        $project_key = '' === $project_key ? '' : self::project_key( $project_key );
        if ( '' === $subject_id || ( '' !== $project_key && ! $project_key ) ) { return 0; }
        $sql = 'SELECT COALESCE(SUM(CASE WHEN direction=\'credit\' THEN amount ELSE -amount END),0) FROM ' . Token_Engine_Schema::ledger_table() . ' WHERE subject_id=%s';
        $args = array( $subject_id );
        if ( '' !== $project_key ) { $sql .= ' AND project_key=%s'; $args[] = $project_key; }
        return max( 0, (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ) );
    }

    /**
     * Returns only the current subject's safe daily-reward projection. The
     * global rule, calendar and ledger stay exclusively in the Core.
     */
    public static function daily_reward_status( $subject_id, $project_key ) {
        $subject_id = self::subject_id( $subject_id );
        if ( '' === $subject_id ) {
            return array( 'state' => 'subject_unavailable' );
        }
        $context = self::daily_reward_context( $project_key );
        if ( is_wp_error( $context ) ) {
            return self::daily_reward_error_state( $context );
        }
        if ( ! $context ) {
            return array( 'state' => 'rule_unavailable' );
        }
        return self::daily_reward_state( $subject_id, $context, false );
    }

    /**
     * Returns the configured public offer without accepting or resolving a
     * subject. This lets a local presentation invite an anonymous visitor to
     * authenticate while keeping every eligibility decision in the Core.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function daily_reward_offer( $project_key ) {
        $context = self::daily_reward_context( $project_key );
        if ( is_wp_error( $context ) ) {
            return self::daily_reward_error_state( $context );
        }
        if ( ! $context ) {
            return array( 'state' => 'rule_unavailable' );
        }
        return array(
            'state' => 'available',
            'amount' => (int) $context['rule']['amount'],
            'unit' => self::configuration()['unit_code'],
        );
    }

    /**
     * Atomically claims the configured global daily_reward for one subject.
     * The lock intentionally excludes the emitter project: the same global
     * rule cannot be claimed through two authorized surfaces concurrently.
     */
    public static function claim_daily_reward( $subject_id, $project_key ) {
        global $wpdb;
        $subject_id = self::subject_id( $subject_id );
        if ( '' === $subject_id ) {
            return array( 'state' => 'subject_unavailable' );
        }
        $context = self::daily_reward_context( $project_key );
        if ( is_wp_error( $context ) ) {
            return self::daily_reward_error_state( $context );
        }
        if ( ! $context ) {
            return array( 'state' => 'rule_unavailable' );
        }

        $lock = 'token_engine_reward_' . substr( hash( 'sha256', $subject_id . '|' . self::DAILY_REWARD_RULE_KEY ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return array( 'state' => 'transient_error' );
        }
        try {
            /* Re-evaluate after acquiring the global lock, not before it. */
            $context = self::daily_reward_context( $project_key );
            if ( is_wp_error( $context ) ) {
                return self::daily_reward_error_state( $context );
            }
            if ( ! $context ) {
                return array( 'state' => 'rule_unavailable' );
            }
            $state = self::daily_reward_state( $subject_id, $context, false );
            if ( 'available' !== $state['state'] ) {
                return $state;
            }
            $entry = self::write_transaction( array(
                'subject_id' => $subject_id,
                'project_key' => $context['project']['project_key'],
                'rule_key' => self::DAILY_REWARD_RULE_KEY,
                'direction' => 'credit',
                'amount' => (int) $context['rule']['amount'],
                'idempotency_key' => 'reward.daily.' . substr( hash( 'sha256', $subject_id . '|' . self::DAILY_REWARD_RULE_KEY . '|' . $context['day_key'] ), 0, 48 ),
                'source_reference' => 'connector_daily_reward',
                'metadata' => array( 'reward' => 'daily', 'emitter_project' => $context['project']['project_key'] ),
            ) );
            if ( is_wp_error( $entry ) ) {
                return self::daily_reward_error_state( $entry );
            }
            $state = self::daily_reward_state( $subject_id, $context, true );
            if ( ! empty( $entry['idempotent'] ) ) {
                return $state;
            }
            $state['state'] = 'granted';
            $state['claimed_now'] = true;
            return $state;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    /**
     * Atomically records one credit/debit. A repeated idempotency key returns
     * its existing entry; a debit is rejected before it could make a projection negative.
     */
    public static function write_transaction( $values ) {
        global $wpdb;
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'schema_not_ready', __( 'Le schéma du moteur n’est pas prêt.', 'token-engine' ) );
        }
        if ( ! self::configuration_is_valid() ) {
            return self::error( 'not_configured', __( 'Configurez d’abord l’unité de cette instance.', 'token-engine' ) );
        }
        $transaction = self::normalise_transaction( $values );
        if ( is_wp_error( $transaction ) ) { return $transaction; }
        $lock = 'token_engine_write_' . substr( hash( 'sha256', $transaction['subject_id'] . '|' . $transaction['project_key'] ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return self::error( 'write_busy', __( 'L’écriture est temporairement indisponible.', 'token-engine' ) );
        }
        try {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                return self::error( 'transaction_start_failed', __( 'L’écriture ne peut pas être démarrée.', 'token-engine' ) );
            }
            $existing = self::ledger_by_idempotency( $transaction['idempotency_key'], true );
            if ( $existing ) {
                $wpdb->query( 'COMMIT' );
                return self::result_from_entry( $existing, true );
            }
            $balance = self::balance( $transaction['subject_id'], $transaction['project_key'] );
            if ( 'debit' === $transaction['direction'] && $transaction['amount'] > $balance ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'insufficient_balance', __( 'Le débit rendrait le solde négatif.', 'token-engine' ) );
            }
            $inserted = $wpdb->insert( Token_Engine_Schema::ledger_table(), $transaction + array( 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                $existing = self::ledger_by_idempotency( $transaction['idempotency_key'], false );
                return $existing ? self::result_from_entry( $existing, true ) : self::error( 'ledger_write_failed', __( 'L’écriture ne peut pas être inscrite.', 'token-engine' ) );
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'transaction_commit_failed', __( 'L’écriture ne peut pas être validée.', 'token-engine' ) );
            }
            return array( 'id' => (int) $wpdb->insert_id, 'transaction_uuid' => $transaction['transaction_uuid'], 'idempotent' => false, 'balance' => self::balance( $transaction['subject_id'], $transaction['project_key'] ) );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function ledger_entries( $limit = 100 ) {
        global $wpdb;
        $limit = min( 200, max( 1, absint( $limit ) ) );
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Token_Engine_Schema::ledger_table() . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A );
    }

    /**
     * Non-mutating readiness check for the daily rule. A global rule has no
     * owning project: the authenticated project identifies the authorized
     * emitting surface only.
     */
    public static function daily_reward_diagnostic( $project_key ) {
        if ( ! Token_Engine_Schema::is_ready() || ! self::configuration_is_valid() ) {
            return array( 'state' => 'configuration_invalid', 'project_active' => false, 'rule_available' => false, 'global_scope_accepted' => false );
        }
        $project = self::find_project( $project_key, true );
        if ( ! $project ) {
            return array( 'state' => 'configuration_invalid', 'project_active' => false, 'rule_available' => false, 'global_scope_accepted' => false );
        }
        $rule = self::find_rule( self::DAILY_REWARD_RULE_KEY );
        $rule_available = is_array( $rule ) && ! empty( $rule['active'] ) && 'claim' === $rule['trigger_type'] && 'daily' === $rule['periodicity'] && self::positive_integer( $rule['amount'] ?? 0 );
        $global_scope_accepted = $rule_available && 'global' === $rule['scope'] && empty( $rule['project_id'] );
        return array(
            'state' => $global_scope_accepted ? 'ready' : 'rule_unavailable',
            'project_active' => true,
            'rule_available' => (bool) $rule_available,
            'global_scope_accepted' => (bool) $global_scope_accepted,
        );
    }

    /** @return array<string,mixed>|WP_Error|null */
    private static function daily_reward_context( $project_key ) {
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'schema_not_ready', __( 'Le schéma du moteur n’est pas prêt.', 'token-engine' ) );
        }
        if ( ! self::configuration_is_valid() ) {
            return self::error( 'not_configured', __( 'Configurez d’abord l’unité de cette instance.', 'token-engine' ) );
        }
        $project = self::find_project( $project_key, true );
        $rule = self::find_rule( self::DAILY_REWARD_RULE_KEY );
        if ( ! $project || ! $rule || empty( $rule['active'] ) || 'global' !== $rule['scope'] || ! empty( $rule['project_id'] ) || 'claim' !== $rule['trigger_type'] || 'daily' !== $rule['periodicity'] || ! self::positive_integer( $rule['amount'] ?? 0 ) ) {
            return null;
        }
        try {
            $timezone = new DateTimeZone( self::configuration()['reference_timezone'] );
            $now = new DateTimeImmutable( 'now', $timezone );
            $start = $now->setTime( 0, 0, 0 );
            $next = $start->modify( '+1 day' );
        } catch ( Exception $exception ) {
            return null;
        }
        return array(
            'project' => $project,
            'rule' => $rule,
            'timezone' => $timezone,
            'window_start_utc' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'next_available_at' => $next->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
            'day_key' => $start->format( 'Y-m-d' ),
        );
    }

    /** @return array<string,mixed> */
    private static function daily_reward_state( $subject_id, $context, $claimed_now ) {
        global $wpdb;
        $entry = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . Token_Engine_Schema::ledger_table() . ' WHERE subject_id=%s AND rule_key=%s AND direction=%s AND created_at >= %s ORDER BY created_at DESC,id DESC LIMIT 1',
                $subject_id,
                self::DAILY_REWARD_RULE_KEY,
                'credit',
                $context['window_start_utc']
            ),
            ARRAY_A
        );
        $settings = self::configuration();
        $state = array(
            'state' => is_array( $entry ) ? 'already_claimed' : 'available',
            'amount' => (int) $context['rule']['amount'],
            'unit' => $settings['unit_code'],
            'next_available_at' => is_array( $entry ) ? $context['next_available_at'] : '',
            'balance' => self::balance( $subject_id, $context['project']['project_key'] ),
            'claimed_now' => (bool) $claimed_now,
        );
        return $state;
    }

    /** Maps Core infrastructure failures to the bounded reward outcome contract. */
    private static function daily_reward_error_state( $error ) {
        $code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';
        if ( in_array( $code, array( 'schema_not_ready', 'not_configured' ), true ) ) {
            return array( 'state' => 'configuration_invalid' );
        }
        return array( 'state' => 'transient_error' );
    }

    private static function normalise_rule( $values ) {
        $key = self::rule_key( $values['rule_key'] ?? '' );
        $scope = in_array( $values['scope'] ?? '', self::SCOPES, true ) ? $values['scope'] : '';
        $trigger = in_array( $values['trigger_type'] ?? '', self::TRIGGERS, true ) ? $values['trigger_type'] : '';
        $periodicity = in_array( $values['periodicity'] ?? '', self::PERIODICITIES, true ) ? $values['periodicity'] : '';
        $amount = self::positive_integer( $values['amount'] ?? 0 );
        $project_id = absint( $values['project_id'] ?? 0 );
        if ( '' === $key || '' === $scope || '' === $trigger || '' === $periodicity || ! $amount ) {
            return self::error( 'invalid_rule', __( 'La règle est invalide.', 'token-engine' ) );
        }
        if ( 'global' === $scope ) { $project_id = 0; }
        if ( 'project' === $scope && ! self::project_by_id( $project_id ) ) {
            return self::error( 'invalid_rule_project', __( 'La règle doit viser un projet existant.', 'token-engine' ) );
        }
        $cooldown = 'cooldown' === $periodicity ? self::positive_integer( $values['cooldown_seconds'] ?? 0 ) : 0;
        if ( 'cooldown' === $periodicity && ! $cooldown ) {
            return self::error( 'invalid_cooldown', __( 'Le cooldown est invalide.', 'token-engine' ) );
        }
        return array( 'rule_key' => $key, 'project_id' => $project_id ?: null, 'scope' => $scope, 'trigger_type' => $trigger, 'periodicity' => $periodicity, 'cooldown_seconds' => $cooldown ?: null, 'amount' => $amount, 'active' => empty( $values['active'] ) ? 0 : 1 );
    }

    private static function normalise_transaction( $values ) {
        $subject = self::subject_id( $values['subject_id'] ?? '' );
        $project = self::project_key( $values['project_key'] ?? '' );
        $direction = in_array( $values['direction'] ?? '', self::DIRECTIONS, true ) ? $values['direction'] : '';
        $amount = self::positive_integer( $values['amount'] ?? 0 );
        $idempotency = self::idempotency_key( $values['idempotency_key'] ?? '' );
        $uuid = isset( $values['transaction_uuid'] ) && '' !== (string) $values['transaction_uuid'] ? strtolower( (string) $values['transaction_uuid'] ) : wp_generate_uuid4();
        $rule_key = '' === (string) ( $values['rule_key'] ?? '' ) ? null : self::rule_key( $values['rule_key'] );
        $metadata = self::metadata( $values['metadata'] ?? null );
        if ( '' === $subject || '' === $project || '' === $direction || ! $amount || '' === $idempotency || ! self::uuid( $uuid ) || ( null === $metadata && null !== ( $values['metadata'] ?? null ) ) ) {
            return self::error( 'invalid_transaction', __( 'La transaction est invalide.', 'token-engine' ) );
        }
        $active_project = self::find_project( $project, true );
        if ( ! $active_project ) {
            return self::error( 'inactive_project', __( 'Le projet doit être actif pour une écriture.', 'token-engine' ) );
        }
        if ( null !== $rule_key ) {
            $rule = self::find_rule( $rule_key );
            if ( ! $rule || empty( $rule['active'] ) || ( 'project' === $rule['scope'] && (int) $rule['project_id'] !== (int) $active_project['id'] ) ) {
                return self::error( 'invalid_transaction_rule', __( 'La règle associée est invalide.', 'token-engine' ) );
            }
        }
        return array( 'transaction_uuid' => $uuid, 'subject_id' => $subject, 'project_key' => $project, 'rule_key' => $rule_key, 'direction' => $direction, 'amount' => $amount, 'idempotency_key' => $idempotency, 'source_reference' => self::nullable_text( $values['source_reference'] ?? '', 191 ), 'metadata' => $metadata );
    }

    private static function ledger_by_idempotency( $key, $lock ) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . Token_Engine_Schema::ledger_table() . ' WHERE idempotency_key=%s' . ( $lock ? ' FOR UPDATE' : '' );
        $entry = $wpdb->get_row( $wpdb->prepare( $sql, $key ), ARRAY_A );
        return is_array( $entry ) ? $entry : null;
    }

    private static function result_from_entry( $entry, $idempotent ) {
        return array( 'id' => (int) $entry['id'], 'transaction_uuid' => $entry['transaction_uuid'], 'idempotent' => (bool) $idempotent, 'balance' => self::balance( $entry['subject_id'], $entry['project_key'] ) );
    }

    private static function project_by_id( $id ) {
        global $wpdb;
        if ( ! $id ) { return null; }
        $project = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' WHERE id=%d', $id ), ARRAY_A );
        return is_array( $project ) ? $project : null;
    }

    private static function rule_by_id( $id ) {
        global $wpdb;
        $rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::rules_table() . ' WHERE id=%d', $id ), ARRAY_A );
        return is_array( $rule ) ? $rule : null;
    }

    private static function settings_complete( $settings ) { return '' !== $settings['unit_code'] && '' !== $settings['unit_singular'] && '' !== $settings['unit_plural'] && '' !== $settings['reference_timezone']; }
    private static function project_key( $value ) { $value = strtolower( self::text( $value, 64 ) ); return preg_match( '/^[a-z0-9][a-z0-9_-]{1,63}$/', $value ) ? $value : ''; }
    private static function rule_key( $value ) { $value = strtolower( self::text( $value, 96 ) ); return preg_match( '/^[a-z0-9][a-z0-9_.-]{1,95}$/', $value ) ? $value : ''; }
    private static function subject_id( $value ) { return self::text( $value, 191 ); }
    private static function idempotency_key( $value ) { $value = self::text( $value, 191 ); return preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,190}$/', $value ) ? $value : ''; }
    private static function unit_code( $value ) { $value = strtoupper( self::text( $value, 16 ) ); return preg_match( '/^[A-Z][A-Z0-9_-]{1,15}$/', $value ) ? $value : ''; }
    private static function timezone( $value ) { $value = self::text( $value, 64 ); return in_array( $value, timezone_identifiers_list(), true ) ? $value : ''; }
    private static function uuid( $value ) { return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $value ); }
    private static function positive_integer( $value ) { return is_int( $value ) || is_string( $value ) ? ( preg_match( '/^[1-9][0-9]{0,17}$/', (string) $value ) ? (int) $value : 0 ) : 0; }
    private static function text( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function nullable_text( $value, $length ) { $value = self::text( $value, $length ); return '' === $value ? null : $value; }
    private static function metadata( $value ) { if ( null === $value || '' === $value ) { return null; } $decoded = is_string( $value ) ? json_decode( wp_unslash( $value ), true ) : $value; if ( ! is_array( $decoded ) ) { return null; } $json = wp_json_encode( $decoded ); return is_string( $json ) && strlen( $json ) <= 4096 ? $json : null; }
    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }
}
