<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Internal-only PF ledger facade. It is deliberately separate from the generic
 * Token Engine ledger so historical ALB entries never become Points Faluss.
 */
final class Token_Engine_Points_Service {
    const POLICY_VERSION = '1.0.0';
    const CATEGORY_VERSION = '1.0.0';
    const HUB_OWNER = 'faluss-hub';
    const HUB_REWARD_KEY = 'hub.daily_accrual';
    const ME_OWNER = 'faluss-me';
    const ME_REWARD_KEY = 'me.profile_daily_claim';
    const CLASSES = array( 'earned', 'funded', 'promotional' );
    const DIRECTIONS = array( 'credit', 'debit', 'compensation' );

    /** Returns the server-only, ledger-derived balances for each PF class. */
    public static function balances_by_class( $faluss_id ) {
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'pf_schema_not_ready', __( 'Le sous-ledger PF n’est pas prêt.', 'token-engine' ) );
        }
        $faluss_id = self::faluss_id( $faluss_id );
        if ( '' === $faluss_id ) {
            return self::error( 'pf_invalid_faluss_id', __( 'Le sujet PF est invalide.', 'token-engine' ) );
        }
        return self::balances_unchecked( $faluss_id );
    }

    /**
     * Reads a daily reward state for a trusted owner adapter. The adapter keeps
     * eligibility proof server-side; this method never resolves a WordPress user.
     */
    public static function daily_status( $faluss_id, $owner, $reward_key, $server_proof = array() ) {
        $definition = self::daily_definition( $owner, $reward_key );
        $faluss_id = self::faluss_id( $faluss_id );
        if ( ! $definition || '' === $faluss_id ) {
            return array( 'state' => 'not_supported' );
        }
        if ( ! Token_Engine_Schema::is_ready() ) {
            return array( 'state' => 'unavailable' );
        }
        if ( ! self::valid_server_proof( $definition, $server_proof ) ) {
            return array( 'state' => 'ineligible' );
        }
        $context = self::daily_context( $definition, $faluss_id );
        $existing = self::entry_by_idempotency( $context['idempotency_key'], false );
        return self::daily_state( $definition, $context, (bool) $existing, false );
    }

    /** Trusted Faluss Hub adapters may invoke this explicit 20 PF claim only. */
    public static function claim_hub_daily( $faluss_id, $server_proof = array() ) {
        return self::claim_daily( self::daily_definition( self::HUB_OWNER, self::HUB_REWARD_KEY ), $faluss_id, $server_proof );
    }

    /** Trusted Faluss Me adapters may invoke this explicit 75 PF claim only. */
    public static function claim_me_profile_daily( $faluss_id, $server_proof = array() ) {
        return self::claim_daily( self::daily_definition( self::ME_OWNER, self::ME_REWARD_KEY ), $faluss_id, $server_proof );
    }

    /**
     * Validates an entry shape reserved for a later PF capability, but never
     * writes packs, Fans, cosmetics or manual adjustments in PF-02B.
     */
    public static function validate_future_entry( $values ) {
        $entry = self::normalise_entry( $values );
        if ( is_wp_error( $entry ) ) {
            return $entry;
        }
        return self::error( 'pf_feature_not_enabled', __( 'Cette capacité PF n’est pas activée.', 'token-engine' ) );
    }

    /**
     * Internal compensation primitive for a future trusted server workflow.
     * It cannot be reached through administration, REST, AJAX or a browser.
     */
    public static function compensate_entry( $entry_uuid, $source_owner, $source_event_reference, $policy_version = self::POLICY_VERSION, $administrative_reason = null ) {
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'pf_schema_not_ready', __( 'Le sous-ledger PF n’est pas prêt.', 'token-engine' ) );
        }
        $entry_uuid = self::uuid( $entry_uuid ) ? strtolower( $entry_uuid ) : '';
        $original = '' === $entry_uuid ? null : self::entry_by_uuid( $entry_uuid, false );
        if ( ! $original || 'compensation' === $original['direction'] ) {
            return self::error( 'pf_invalid_compensation', __( 'La compensation PF est invalide.', 'token-engine' ) );
        }
        $idempotency = 'pf.compensation.' . substr( hash( 'sha256', $entry_uuid . '|' . $source_owner . '|' . $source_event_reference . '|' . $policy_version ), 0, 48 );
        return self::write_entry( array(
            'entry_uuid'             => wp_generate_uuid4(),
            'faluss_id'              => $original['faluss_id'],
            'amount_pf'              => (int) $original['amount_pf'],
            'direction'              => 'compensation',
            'economic_class'         => $original['economic_class'],
            'category'               => 'reversal',
            'category_version'       => self::CATEGORY_VERSION,
            'source_owner'           => $source_owner,
            'source_event_reference' => $source_event_reference,
            'idempotency_key'        => $idempotency,
            'policy_version'         => $policy_version,
            'occurred_at'            => gmdate( 'Y-m-d H:i:s' ),
            'compensates_entry_uuid' => $entry_uuid,
            'administrative_reason'  => $administrative_reason,
            'metadata'               => array( 'compensation' => 'server_only' ),
        ) );
    }

    private static function claim_daily( $definition, $faluss_id, $server_proof ) {
        global $wpdb;
        $faluss_id = self::faluss_id( $faluss_id );
        if ( ! $definition || '' === $faluss_id ) {
            return array( 'state' => 'not_supported' );
        }
        if ( ! Token_Engine_Schema::is_ready() ) {
            return array( 'state' => 'unavailable' );
        }
        if ( ! self::valid_server_proof( $definition, $server_proof ) ) {
            return array( 'state' => 'ineligible' );
        }

        $context = self::daily_context( $definition, $faluss_id );
        $lock = 'token_engine_pf_daily_' . substr( hash( 'sha256', $definition['owner'] . '|' . $definition['reward_key'] . '|' . $faluss_id . '|' . $context['logical_date'] . '|' . self::POLICY_VERSION ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return array( 'state' => 'unavailable' );
        }
        try {
            $existing = self::entry_by_idempotency( $context['idempotency_key'], false );
            if ( $existing ) {
                return self::daily_state( $definition, $context, true, false );
            }
            $result = self::write_entry( array(
                'entry_uuid'             => wp_generate_uuid4(),
                'faluss_id'              => $faluss_id,
                'amount_pf'              => $definition['amount_pf'],
                'direction'              => 'credit',
                'economic_class'         => 'earned',
                'category'               => $definition['category'],
                'category_version'       => self::CATEGORY_VERSION,
                'source_owner'           => $definition['owner'],
                'source_event_reference' => $context['source_event_reference'],
                'idempotency_key'        => $context['idempotency_key'],
                'policy_version'         => self::POLICY_VERSION,
                'occurred_at'            => $context['occurred_at'],
                'compensates_entry_uuid' => null,
                'administrative_reason'  => null,
                'metadata'               => array( 'reward_key' => $definition['reward_key'], 'logical_date' => $context['logical_date'] ),
            ) );
            if ( is_wp_error( $result ) ) {
                return array( 'state' => 'unavailable' );
            }
            return self::daily_state( $definition, $context, true, empty( $result['idempotent'] ) );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    /** Writes one fully normalized immutable entry under a subject/class lock. */
    private static function write_entry( $values ) {
        global $wpdb;
        $entry = self::normalise_entry( $values );
        if ( is_wp_error( $entry ) ) {
            return $entry;
        }
        $lock = 'token_engine_pf_' . substr( hash( 'sha256', $entry['faluss_id'] . '|' . $entry['economic_class'] ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return self::error( 'pf_write_busy', __( 'L’écriture PF est temporairement indisponible.', 'token-engine' ) );
        }
        try {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                return self::error( 'pf_transaction_start_failed', __( 'L’écriture PF ne peut pas démarrer.', 'token-engine' ) );
            }
            $existing = self::entry_by_idempotency( $entry['idempotency_key'], true );
            if ( $existing ) {
                $wpdb->query( 'COMMIT' );
                return self::entry_result( $existing, true );
            }
            self::lock_subject_class( $entry['faluss_id'], $entry['economic_class'] );
            $original = null;
            if ( 'compensation' === $entry['direction'] ) {
                $original = self::entry_by_uuid( $entry['compensates_entry_uuid'], true );
                if ( ! $original || 'compensation' === $original['direction'] || $original['faluss_id'] !== $entry['faluss_id'] || $original['economic_class'] !== $entry['economic_class'] ) {
                    $wpdb->query( 'ROLLBACK' );
                    return self::error( 'pf_invalid_compensation', __( 'La compensation PF ne correspond pas à l’écriture d’origine.', 'token-engine' ) );
                }
                if ( self::entry_by_compensated_uuid( $entry['compensates_entry_uuid'], true ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return self::error( 'pf_already_compensated', __( 'Cette écriture PF a déjà été compensée.', 'token-engine' ) );
                }
            }
            $effect = self::balance_effect( $entry, $original );
            $balance = self::balance_for_class( $entry['faluss_id'], $entry['economic_class'] );
            if ( $effect < 0 && abs( $effect ) > $balance ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'pf_insufficient_class_balance', __( 'L’écriture PF rendrait une classe négative.', 'token-engine' ) );
            }
            $inserted = $wpdb->insert(
                Token_Engine_Schema::pf_ledger_table(),
                $entry + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ),
                array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                $existing = self::entry_by_idempotency( $entry['idempotency_key'], false );
                if ( $existing ) {
                    return self::entry_result( $existing, true );
                }
                if ( 'compensation' === $entry['direction'] && self::entry_by_compensated_uuid( $entry['compensates_entry_uuid'], false ) ) {
                    return self::error( 'pf_already_compensated', __( 'Cette écriture PF a déjà été compensée.', 'token-engine' ) );
                }
                return self::error( 'pf_ledger_write_failed', __( 'L’écriture PF ne peut pas être inscrite.', 'token-engine' ) );
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return self::error( 'pf_transaction_commit_failed', __( 'L’écriture PF ne peut pas être validée.', 'token-engine' ) );
            }
            return array( 'entry_uuid' => $entry['entry_uuid'], 'idempotent' => false, 'balances' => self::balances_unchecked( $entry['faluss_id'] ) );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function normalise_entry( $values ) {
        $entry_uuid = self::uuid( $values['entry_uuid'] ?? '' ) ? strtolower( $values['entry_uuid'] ) : '';
        $faluss_id = self::faluss_id( $values['faluss_id'] ?? '' );
        $amount = self::positive_integer( $values['amount_pf'] ?? 0 );
        $direction = in_array( $values['direction'] ?? '', self::DIRECTIONS, true ) ? $values['direction'] : '';
        $economic_class = in_array( $values['economic_class'] ?? '', self::CLASSES, true ) ? $values['economic_class'] : '';
        $category = self::category( $values['category'] ?? '' );
        $category_version = self::version( $values['category_version'] ?? '' );
        $source_owner = self::owner( $values['source_owner'] ?? '' );
        $source_reference = self::opaque_reference( $values['source_event_reference'] ?? '' );
        $idempotency = self::idempotency_key( $values['idempotency_key'] ?? '' );
        $policy_version = self::version( $values['policy_version'] ?? '' );
        $occurred_at = self::utc_datetime( $values['occurred_at'] ?? '' );
        $compensates = null === ( $values['compensates_entry_uuid'] ?? null ) ? null : ( self::uuid( $values['compensates_entry_uuid'] ) ? strtolower( $values['compensates_entry_uuid'] ) : '' );
        $administrative_reason = self::nullable_text( $values['administrative_reason'] ?? null, 191 );
        $metadata = self::metadata( $values['metadata'] ?? array() );
        if ( '' === $entry_uuid || '' === $faluss_id || ! $amount || '' === $direction || '' === $economic_class || '' === $category || '' === $category_version || '' === $source_owner || '' === $source_reference || '' === $idempotency || '' === $policy_version || '' === $occurred_at || ( null !== $compensates && '' === $compensates ) || null === $metadata ) {
            return self::error( 'pf_invalid_entry', __( 'L’écriture PF est invalide.', 'token-engine' ) );
        }
        if ( 'compensation' === $direction ) {
            if ( 'reversal' !== $category || null === $compensates ) {
                return self::error( 'pf_invalid_compensation', __( 'La compensation PF est invalide.', 'token-engine' ) );
            }
        } elseif ( null !== $compensates ) {
            return self::error( 'pf_invalid_compensation_link', __( 'Seule une compensation PF peut référencer une écriture.', 'token-engine' ) );
        }
        if ( 'reversal' === $category && 'compensation' !== $direction ) {
            return self::error( 'pf_invalid_compensation', __( 'Une reversal PF doit être une compensation.', 'token-engine' ) );
        }
        if ( 'daily_accrual' === $category && ( 'credit' !== $direction || 'earned' !== $economic_class || self::HUB_OWNER !== $source_owner || 20 !== $amount ) ) {
            return self::error( 'pf_invalid_daily_entry', __( 'Le reward Hub PF est invalide.', 'token-engine' ) );
        }
        if ( 'profile_daily_claim' === $category && ( 'credit' !== $direction || 'earned' !== $economic_class || self::ME_OWNER !== $source_owner || 75 !== $amount ) ) {
            return self::error( 'pf_invalid_daily_entry', __( 'Le reward Faluss Me PF est invalide.', 'token-engine' ) );
        }
        if ( in_array( $category, array( 'pf_pack_purchase', 'pf_pack_bonus', 'fans_support', 'cosmetic_redemption', 'manual_adjustment' ), true ) ) {
            return self::error( 'pf_feature_not_enabled', __( 'Cette capacité PF n’est pas activée.', 'token-engine' ) );
        }
        if ( 'manual_adjustment' === $category && null === $administrative_reason ) {
            return self::error( 'pf_administrative_reason_required', __( 'Une correction PF exige un motif.', 'token-engine' ) );
        }
        return array(
            'entry_uuid'             => $entry_uuid,
            'faluss_id'              => $faluss_id,
            'amount_pf'              => $amount,
            'direction'              => $direction,
            'economic_class'         => $economic_class,
            'category'               => $category,
            'category_version'       => $category_version,
            'source_owner'           => $source_owner,
            'source_event_reference' => $source_reference,
            'idempotency_key'        => $idempotency,
            'policy_version'         => $policy_version,
            'occurred_at'            => $occurred_at,
            'compensates_entry_uuid' => $compensates,
            'administrative_reason'  => $administrative_reason,
            'metadata'               => $metadata,
        );
    }

    private static function daily_definition( $owner, $reward_key ) {
        $definitions = array(
            self::HUB_OWNER . '|' . self::HUB_REWARD_KEY => array( 'owner' => self::HUB_OWNER, 'reward_key' => self::HUB_REWARD_KEY, 'category' => 'daily_accrual', 'amount_pf' => 20, 'requires_published_card' => false ),
            self::ME_OWNER . '|' . self::ME_REWARD_KEY => array( 'owner' => self::ME_OWNER, 'reward_key' => self::ME_REWARD_KEY, 'category' => 'profile_daily_claim', 'amount_pf' => 75, 'requires_published_card' => true ),
        );
        $key = self::owner( $owner ) . '|' . self::reward_key( $reward_key );
        return $definitions[ $key ] ?? null;
    }

    private static function valid_server_proof( $definition, $proof ) {
        if ( ! is_array( $proof ) || ( $proof['owner'] ?? '' ) !== $definition['owner'] || true !== ( $proof['identity_active'] ?? false ) ) {
            return false;
        }
        return empty( $definition['requires_published_card'] ) || ( true === ( $proof['published_card'] ?? false ) && true === ( $proof['reserved_handle'] ?? false ) );
    }

    private static function daily_context( $definition, $faluss_id ) {
        $timezone = new DateTimeZone( 'Europe/Paris' );
        $now = new DateTimeImmutable( 'now', $timezone );
        $logical_date = $now->format( 'Y-m-d' );
        $scope = implode( '|', array( $definition['owner'], $definition['reward_key'], $faluss_id, $logical_date, self::POLICY_VERSION ) );
        return array(
            'logical_date'           => $logical_date,
            'occurred_at'            => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'idempotency_key'        => 'pf.daily.' . substr( hash( 'sha256', $scope ), 0, 48 ),
            'source_event_reference' => 'daily.' . substr( hash( 'sha256', 'source|' . $scope ), 0, 48 ),
        );
    }

    private static function daily_state( $definition, $context, $claimed, $claimed_now ) {
        return array(
            'state'        => $claimed ? 'claimed' : 'claimable',
            'owner'        => $definition['owner'],
            'reward_key'   => $definition['reward_key'],
            'amount_pf'    => $definition['amount_pf'],
            'economic_class' => 'earned',
            'category'     => $definition['category'],
            'logical_date' => $context['logical_date'],
            'claimed_now'  => (bool) $claimed_now,
        );
    }

    private static function balances_unchecked( $faluss_id ) {
        $balances = array();
        foreach ( self::CLASSES as $economic_class ) {
            $balances[ $economic_class ] = self::balance_for_class( $faluss_id, $economic_class );
        }
        return $balances;
    }

    private static function balance_for_class( $faluss_id, $economic_class ) {
        global $wpdb;
        $table = Token_Engine_Schema::pf_ledger_table();
        $sql = 'SELECT COALESCE(SUM(CASE WHEN entries.direction=\'credit\' THEN entries.amount_pf WHEN entries.direction=\'debit\' THEN -entries.amount_pf WHEN entries.direction=\'compensation\' AND originals.direction=\'credit\' THEN -entries.amount_pf WHEN entries.direction=\'compensation\' AND originals.direction=\'debit\' THEN entries.amount_pf ELSE 0 END),0) FROM `' . $table . '` AS entries LEFT JOIN `' . $table . '` AS originals ON originals.entry_uuid=entries.compensates_entry_uuid WHERE entries.faluss_id=%s AND entries.economic_class=%s';
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $faluss_id, $economic_class ) );
    }

    private static function lock_subject_class( $faluss_id, $economic_class ) {
        global $wpdb;
        $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM `' . Token_Engine_Schema::pf_ledger_table() . '` WHERE faluss_id=%s AND economic_class=%s FOR UPDATE', $faluss_id, $economic_class ), ARRAY_A );
    }

    private static function balance_effect( $entry, $original ) {
        if ( 'credit' === $entry['direction'] ) {
            return (int) $entry['amount_pf'];
        }
        if ( 'debit' === $entry['direction'] ) {
            return -(int) $entry['amount_pf'];
        }
        return 'credit' === ( $original['direction'] ?? '' ) ? -(int) $entry['amount_pf'] : (int) $entry['amount_pf'];
    }

    private static function entry_by_idempotency( $idempotency_key, $lock ) {
        global $wpdb;
        $sql = 'SELECT * FROM `' . Token_Engine_Schema::pf_ledger_table() . '` WHERE idempotency_key=%s' . ( $lock ? ' FOR UPDATE' : '' );
        $entry = $wpdb->get_row( $wpdb->prepare( $sql, $idempotency_key ), ARRAY_A );
        return is_array( $entry ) ? $entry : null;
    }

    private static function entry_by_uuid( $entry_uuid, $lock ) {
        global $wpdb;
        $sql = 'SELECT * FROM `' . Token_Engine_Schema::pf_ledger_table() . '` WHERE entry_uuid=%s' . ( $lock ? ' FOR UPDATE' : '' );
        $entry = $wpdb->get_row( $wpdb->prepare( $sql, $entry_uuid ), ARRAY_A );
        return is_array( $entry ) ? $entry : null;
    }

    private static function entry_by_compensated_uuid( $entry_uuid, $lock ) {
        global $wpdb;
        $sql = 'SELECT * FROM `' . Token_Engine_Schema::pf_ledger_table() . '` WHERE compensates_entry_uuid=%s' . ( $lock ? ' FOR UPDATE' : '' );
        $entry = $wpdb->get_row( $wpdb->prepare( $sql, $entry_uuid ), ARRAY_A );
        return is_array( $entry ) ? $entry : null;
    }

    private static function entry_result( $entry, $idempotent ) {
        return array( 'entry_uuid' => $entry['entry_uuid'], 'idempotent' => (bool) $idempotent, 'balances' => self::balances_unchecked( $entry['faluss_id'] ) );
    }

    private static function faluss_id( $value ) { return self::uuid( $value ) ? strtolower( $value ) : ''; }
    private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ); }
    private static function positive_integer( $value ) { return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-9][0-9]{0,9}$/', (string) $value ) ? (int) $value : 0; }
    private static function category( $value ) { $value = self::text( $value, 64 ); return in_array( $value, array( 'profile_daily_claim', 'daily_accrual', 'pf_pack_purchase', 'pf_pack_bonus', 'fans_support', 'cosmetic_redemption', 'manual_adjustment', 'reversal' ), true ) ? $value : ''; }
    private static function reward_key( $value ) { $value = self::text( $value, 128 ); return preg_match( '/^[a-z][a-z0-9-]{1,63}\.[a-z][a-z0-9_.-]{1,127}$/', $value ) ? $value : ''; }
    private static function owner( $value ) { $value = self::text( $value, 64 ); return preg_match( '/^[a-z][a-z0-9-]{1,63}$/', $value ) ? $value : ''; }
    private static function version( $value ) { $value = self::text( $value, 32 ); return preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/', $value ) ? $value : ''; }
    private static function idempotency_key( $value ) { $value = self::text( $value, 191 ); return preg_match( '/^[a-z0-9][a-z0-9._-]{15,190}$/', $value ) ? $value : ''; }
    private static function opaque_reference( $value ) { $value = self::text( $value, 191 ); return preg_match( '/^[a-z0-9][a-z0-9._-]{15,190}$/', $value ) && ! preg_match( '/(?:^|[._-])(alb|stripe|payment)(?:$|[._-])/', $value ) ? $value : ''; }
    private static function utc_datetime( $value ) { $value = self::text( $value, 19 ); return preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $value ) ? $value : ''; }
    private static function text( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function nullable_text( $value, $length ) { if ( null === $value ) { return null; } $value = self::text( $value, $length ); return '' === $value ? null : $value; }
    private static function metadata( $value ) { if ( ! is_array( $value ) || count( $value ) > 12 || self::forbidden_metadata( $value ) ) { return null; } $json = wp_json_encode( $value ); return is_string( $json ) && strlen( $json ) <= 4096 ? $json : null; }
    private static function forbidden_metadata( $value ) { foreach ( $value as $key => $child ) { if ( is_string( $key ) && preg_match( '/(?:alb|stripe|payment|email|card|customer|checkout|invoice|secret|password|token|wp_user|faluss_id)/i', $key ) ) { return true; } if ( is_string( $child ) && preg_match( '/(?:alb|stripe|payment|email|card|customer|checkout|invoice|secret|password|token|wp_user|faluss_id)/i', $child ) ) { return true; } if ( is_array( $child ) && self::forbidden_metadata( $child ) ) { return true; } } return false; }
    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }
}
