<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generic, Core-owned entitlement definitions and grants.
 *
 * This service deliberately has no knowledge of any product, currency,
 * subscription, payment or identity provider. Callers use opaque subject IDs.
 */
final class Token_Engine_Entitlements {
    const TYPE_THEME = 'theme';
    const SOURCE_MANUAL = 'manual';

    /** @return array<int,array<string,mixed>> */
    public static function definitions() {
        global $wpdb;
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Token_Engine_Schema::entitlement_definitions_table() . ' ORDER BY project_key ASC,label ASC,id ASC', ARRAY_A );
    }

    /** @return array<int,array<string,mixed>> Active definitions compatible with one authorized surface. */
    public static function active_definitions_for_project( $project_key ) {
        global $wpdb;
        $project_key = self::project_key( $project_key );
        if ( '' === $project_key || ! Token_Engine_Schema::is_ready() ) {
            return array();
        }
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT entitlement_code,label,entitlement_type FROM ' . Token_Engine_Schema::entitlement_definitions_table() . ' WHERE project_key=%s AND active=1 ORDER BY label ASC,id ASC',
                $project_key
            ),
            ARRAY_A
        );
    }

    /** @return array<string,mixed>|null */
    public static function find_definition( $code ) {
        global $wpdb;
        $code = self::code( $code );
        if ( '' === $code || ! Token_Engine_Schema::is_ready() ) {
            return null;
        }
        $definition = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::entitlement_definitions_table() . ' WHERE entitlement_code=%s', $code ), ARRAY_A );
        return is_array( $definition ) ? $definition : null;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function create_definition( $values ) {
        global $wpdb;
        $definition = self::normalise_definition( $values );
        if ( is_wp_error( $definition ) ) {
            return $definition;
        }
        $now = current_time( 'mysql', true );
        $created = $wpdb->insert(
            Token_Engine_Schema::entitlement_definitions_table(),
            $definition + array( 'created_at' => $now, 'updated_at' => $now ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        return false === $created ? self::error( 'entitlement_definition_conflict', __( 'Ce droit existe déjà.', 'token-engine' ) ) : self::find_definition( $definition['entitlement_code'] );
    }

    /** @return true|WP_Error */
    public static function update_definition( $id, $values ) {
        global $wpdb;
        $current = self::definition_by_id( absint( $id ) );
        if ( ! $current ) {
            return self::error( 'entitlement_definition_invalid', __( 'Le droit est introuvable.', 'token-engine' ) );
        }
        $values['entitlement_code'] = $current['entitlement_code'];
        $definition = self::normalise_definition( $values );
        if ( is_wp_error( $definition ) ) {
            return $definition;
        }
        $updated = $wpdb->update(
            Token_Engine_Schema::entitlement_definitions_table(),
            $definition + array( 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $current['id'] ),
            array( '%s', '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        return false === $updated ? self::error( 'entitlement_definition_update_failed', __( 'Le droit ne peut pas être enregistré.', 'token-engine' ) ) : true;
    }

    /**
     * Add one manual grant. A stable operation reference makes a resubmitted
     * WordPress form idempotent; a short Core lock prevents duplicate active
     * grants even when two administrators submit at the same time.
     *
     * @return array<string,mixed>|WP_Error
     */
    public static function create_manual_grant( $values ) {
        global $wpdb;
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'schema_not_ready', __( 'Le schéma du moteur n’est pas prêt.', 'token-engine' ) );
        }
        $subject_id = self::subject_id( $values['subject_id'] ?? '' );
        $operation_reference = self::operation_reference( $values['operation_reference'] ?? '' );
        $definition = self::find_definition( $values['entitlement_code'] ?? '' );
        $starts_at = self::datetime( $values['starts_at'] ?? current_time( 'mysql', true ) );
        $ends_at = self::nullable_datetime( $values['ends_at'] ?? '' );
        if ( '' === $subject_id || '' === $operation_reference || ! $definition || empty( $definition['active'] ) || '' === $starts_at || ( null !== $ends_at && $ends_at <= $starts_at ) ) {
            return self::error( 'entitlement_grant_invalid', __( 'L’attribution de droit est invalide.', 'token-engine' ) );
        }

        $existing = self::grant_by_operation( $operation_reference );
        if ( $existing ) {
            return self::grant_result( $existing, true );
        }
        $lock = 'token_engine_entitlement_' . substr( hash( 'sha256', $subject_id . '|' . $definition['id'] ), 0, 32 );
        if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 10 ) ) ) {
            return self::error( 'entitlement_grant_busy', __( 'L’attribution est temporairement indisponible.', 'token-engine' ) );
        }
        try {
            $existing = self::grant_by_operation( $operation_reference );
            if ( $existing ) {
                return self::grant_result( $existing, true );
            }
            $active = self::active_or_scheduled_grant( $subject_id, (int) $definition['id'] );
            if ( $active ) {
                return self::grant_result( $active, true );
            }
            $now = current_time( 'mysql', true );
            $grant = array(
                'grant_uuid' => wp_generate_uuid4(),
                'subject_id' => $subject_id,
                'entitlement_id' => (int) $definition['id'],
                'source' => self::SOURCE_MANUAL,
                'operation_reference' => $operation_reference,
                'starts_at' => $starts_at,
                'ends_at' => $ends_at,
                'revoked_at' => null,
                'revoke_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            );
            $created = $wpdb->insert(
                Token_Engine_Schema::entitlement_grants_table(),
                $grant,
                array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            if ( false === $created ) {
                $existing = self::grant_by_operation( $operation_reference );
                return $existing ? self::grant_result( $existing, true ) : self::error( 'entitlement_grant_failed', __( 'Le droit ne peut pas être attribué.', 'token-engine' ) );
            }
            return self::grant_result( self::grant_by_id( (int) $wpdb->insert_id ), false );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    /** Revocation only changes the lifecycle fields; the original grant stays auditable. */
    public static function revoke_grant( $grant_id, $reason = '' ) {
        global $wpdb;
        $grant = self::grant_by_id( absint( $grant_id ) );
        if ( ! $grant ) {
            return self::error( 'entitlement_grant_missing', __( 'L’attribution est introuvable.', 'token-engine' ) );
        }
        if ( ! empty( $grant['revoked_at'] ) ) {
            return self::grant_result( $grant, true );
        }
        $now = current_time( 'mysql', true );
        $updated = $wpdb->update(
            Token_Engine_Schema::entitlement_grants_table(),
            array( 'revoked_at' => $now, 'revoke_reason' => self::nullable_text( $reason, 191 ), 'updated_at' => $now ),
            array( 'id' => (int) $grant['id'], 'revoked_at' => null ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%s' )
        );
        if ( false === $updated ) {
            return self::error( 'entitlement_grant_revoke_failed', __( 'Le droit ne peut pas être révoqué.', 'token-engine' ) );
        }
        return self::grant_result( self::grant_by_id( (int) $grant['id'] ), false );
    }

    /** @return array<int,array<string,mixed>> */
    public static function grant_history( $limit = 100 ) {
        global $wpdb;
        $limit = min( 200, max( 1, absint( $limit ) ) );
        return (array) $wpdb->get_results(
            'SELECT grants.*, definitions.entitlement_code,definitions.label,definitions.project_key,definitions.entitlement_type FROM ' . Token_Engine_Schema::entitlement_grants_table() . ' AS grants INNER JOIN ' . Token_Engine_Schema::entitlement_definitions_table() . ' AS definitions ON definitions.id=grants.entitlement_id ORDER BY grants.id DESC LIMIT ' . $limit,
            ARRAY_A
        );
    }

    /** @return array<int,array{code:string,label:string,type:string}> */
    public static function active_entitlements_for_subject( $subject_id, $project_key ) {
        global $wpdb;
        $subject_id = self::subject_id( $subject_id );
        $project_key = self::project_key( $project_key );
        if ( '' === $subject_id || '' === $project_key || ! Token_Engine_Schema::is_ready() ) {
            return array();
        }
        $now = current_time( 'mysql', true );
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT definitions.entitlement_code AS code,definitions.label,definitions.entitlement_type AS type FROM ' . Token_Engine_Schema::entitlement_grants_table() . ' AS grants INNER JOIN ' . Token_Engine_Schema::entitlement_definitions_table() . ' AS definitions ON definitions.id=grants.entitlement_id WHERE grants.subject_id=%s AND definitions.project_key=%s AND definitions.active=1 AND grants.revoked_at IS NULL AND grants.starts_at <= %s AND (grants.ends_at IS NULL OR grants.ends_at > %s) ORDER BY definitions.label ASC,definitions.id ASC',
                $subject_id,
                $project_key,
                $now,
                $now
            ),
            ARRAY_A
        );
    }

    public static function subject_has_entitlement( $subject_id, $project_key, $code ) {
        $code = self::code( $code );
        if ( '' === $code ) {
            return false;
        }
        foreach ( self::active_entitlements_for_subject( $subject_id, $project_key ) as $entitlement ) {
            if ( hash_equals( $code, (string) ( $entitlement['code'] ?? '' ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function definition_state( $definition ) {
        return is_array( $definition ) && ! empty( $definition['active'] ) ? 'active' : 'inactive';
    }

    public static function grant_state( $grant ) {
        if ( ! is_array( $grant ) || ! empty( $grant['revoked_at'] ) ) {
            return 'revoked';
        }
        $now = current_time( 'mysql', true );
        if ( ! empty( $grant['ends_at'] ) && (string) $grant['ends_at'] <= $now ) {
            return 'expired';
        }
        if ( (string) ( $grant['starts_at'] ?? '' ) > $now ) {
            return 'scheduled';
        }
        return 'active';
    }

    private static function normalise_definition( $values ) {
        $code = self::code( $values['entitlement_code'] ?? '' );
        $label = self::text( $values['label'] ?? '', 120 );
        $project_key = self::project_key( $values['project_key'] ?? '' );
        $type = self::type( $values['entitlement_type'] ?? self::TYPE_THEME );
        if ( '' === $code || '' === $label || '' === $project_key || '' === $type || ! Token_Engine_Service::find_project( $project_key ) ) {
            return self::error( 'entitlement_definition_invalid', __( 'La définition de droit est invalide.', 'token-engine' ) );
        }
        return array(
            'entitlement_code' => $code,
            'label' => $label,
            'project_key' => $project_key,
            'entitlement_type' => $type,
            'active' => empty( $values['active'] ) ? 0 : 1,
        );
    }

    private static function definition_by_id( $id ) {
        global $wpdb;
        if ( ! $id ) {
            return null;
        }
        $definition = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::entitlement_definitions_table() . ' WHERE id=%d', $id ), ARRAY_A );
        return is_array( $definition ) ? $definition : null;
    }

    private static function grant_by_id( $id ) {
        global $wpdb;
        if ( ! $id ) {
            return null;
        }
        $grant = $wpdb->get_row( $wpdb->prepare( 'SELECT grants.*, definitions.entitlement_code,definitions.label,definitions.project_key,definitions.entitlement_type FROM ' . Token_Engine_Schema::entitlement_grants_table() . ' AS grants INNER JOIN ' . Token_Engine_Schema::entitlement_definitions_table() . ' AS definitions ON definitions.id=grants.entitlement_id WHERE grants.id=%d', $id ), ARRAY_A );
        return is_array( $grant ) ? $grant : null;
    }

    private static function grant_by_operation( $operation_reference ) {
        global $wpdb;
        $grant = $wpdb->get_row( $wpdb->prepare( 'SELECT grants.*, definitions.entitlement_code,definitions.label,definitions.project_key,definitions.entitlement_type FROM ' . Token_Engine_Schema::entitlement_grants_table() . ' AS grants INNER JOIN ' . Token_Engine_Schema::entitlement_definitions_table() . ' AS definitions ON definitions.id=grants.entitlement_id WHERE grants.operation_reference=%s', $operation_reference ), ARRAY_A );
        return is_array( $grant ) ? $grant : null;
    }

    private static function active_or_scheduled_grant( $subject_id, $entitlement_id ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $grant = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT grants.*, definitions.entitlement_code,definitions.label,definitions.project_key,definitions.entitlement_type FROM ' . Token_Engine_Schema::entitlement_grants_table() . ' AS grants INNER JOIN ' . Token_Engine_Schema::entitlement_definitions_table() . ' AS definitions ON definitions.id=grants.entitlement_id WHERE grants.subject_id=%s AND grants.entitlement_id=%d AND grants.revoked_at IS NULL AND (grants.ends_at IS NULL OR grants.ends_at > %s) ORDER BY grants.id DESC LIMIT 1',
                $subject_id,
                $entitlement_id,
                $now
            ),
            ARRAY_A
        );
        return is_array( $grant ) ? $grant : null;
    }

    private static function grant_result( $grant, $idempotent ) {
        return array(
            'id' => (int) ( $grant['id'] ?? 0 ),
            'grant_uuid' => (string) ( $grant['grant_uuid'] ?? '' ),
            'entitlement_code' => (string) ( $grant['entitlement_code'] ?? '' ),
            'state' => self::grant_state( $grant ),
            'idempotent' => (bool) $idempotent,
        );
    }

    private static function code( $value ) {
        $value = strtolower( self::text( $value, 120 ) );
        return preg_match( '/^[a-z0-9][a-z0-9_.-]{1,119}$/', $value ) ? $value : '';
    }
    private static function project_key( $value ) {
        $value = strtolower( self::text( $value, 64 ) );
        return preg_match( '/^[a-z0-9][a-z0-9_-]{1,63}$/', $value ) ? $value : '';
    }
    private static function subject_id( $value ) { return self::text( $value, 191 ); }
    private static function operation_reference( $value ) {
        $value = self::text( $value, 191 );
        return preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,190}$/', $value ) ? $value : '';
    }
    private static function type( $value ) { return self::TYPE_THEME === $value ? $value : ''; }
    private static function datetime( $value ) {
        $time = is_string( $value ) ? strtotime( $value ) : false;
        return false === $time ? '' : gmdate( 'Y-m-d H:i:s', $time );
    }
    private static function nullable_datetime( $value ) {
        return '' === trim( (string) $value ) ? null : self::datetime( $value );
    }
    private static function nullable_text( $value, $length ) {
        $value = self::text( $value, $length );
        return '' === $value ? null : $value;
    }
    private static function text( $value, $length ) {
        $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
        return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length );
    }
    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }
}
