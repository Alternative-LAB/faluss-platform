<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Private connector authentication for the generic Token Engine Core contract. */
final class Token_Engine_Connector_Access {
    const PERMISSION_WALLET_READ = 'wallet.read';
    const PERMISSION_REWARD_CLAIM = 'reward.claim';
    const PERMISSION_ENTITLEMENTS_READ = 'entitlements.read';
    const TOKEN_TTL_SECONDS = 300;
    const PROTOCOL_VERSION = '1';

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        $contract = self::rest_contract();
        register_rest_route( $contract['namespace'], $contract['routes']['token']['path'], array(
            'methods' => $contract['routes']['token']['method'],
            'callback' => array( __CLASS__, 'access_token_response' ),
            'permission_callback' => array( __CLASS__, 'https_only' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['diagnostic']['path'], array(
            'methods' => $contract['routes']['diagnostic']['method'],
            'callback' => array( __CLASS__, 'diagnostic_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['balance']['path'], array(
            'methods' => $contract['routes']['balance']['method'],
            'callback' => array( __CLASS__, 'balance_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['reward_offer']['path'], array(
            'methods' => $contract['routes']['reward_offer']['method'],
            'callback' => array( __CLASS__, 'daily_reward_offer_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['reward_diagnostic']['path'], array(
            'methods' => $contract['routes']['reward_diagnostic']['method'],
            'callback' => array( __CLASS__, 'daily_reward_diagnostic_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['reward_status']['path'], array(
            'methods' => $contract['routes']['reward_status']['method'],
            'callback' => array( __CLASS__, 'daily_reward_status_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['reward_claim']['path'], array(
            'methods' => $contract['routes']['reward_claim']['method'],
            'callback' => array( __CLASS__, 'daily_reward_claim_response' ),
            'permission_callback' => array( __CLASS__, 'wallet_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['entitlement_definitions']['path'], array(
            'methods' => $contract['routes']['entitlement_definitions']['method'],
            'callback' => array( __CLASS__, 'entitlement_definitions_response' ),
            'permission_callback' => array( __CLASS__, 'entitlements_read_permission' ),
        ) );
        register_rest_route( $contract['namespace'], $contract['routes']['entitlement']['path'], array(
            'methods' => $contract['routes']['entitlement']['method'],
            'callback' => array( __CLASS__, 'entitlement_response' ),
            'permission_callback' => array( __CLASS__, 'entitlements_read_permission' ),
        ) );
    }

    /** One Core-owned REST contract for route registration, administration and integrations. */
    public static function rest_contract() {
        return array(
            'site_url' => self::core_site_url(),
            'base_url' => rest_url( 'token-engine/v1/' ),
            'namespace' => 'token-engine/v1',
            'protocol_version' => self::PROTOCOL_VERSION,
            'routes' => array(
                'token' => array( 'path' => '/connector/token', 'method' => 'POST' ),
                'diagnostic' => array( 'path' => '/connector/diagnostic', 'method' => 'GET' ),
                'balance' => array( 'path' => '/connector/balance', 'method' => 'POST' ),
                'reward_offer' => array( 'path' => '/connector/reward/offer', 'method' => 'POST' ),
                'reward_diagnostic' => array( 'path' => '/connector/reward/diagnostic', 'method' => 'POST' ),
                'reward_status' => array( 'path' => '/connector/reward/status', 'method' => 'POST' ),
                'reward_claim' => array( 'path' => '/connector/reward/claim', 'method' => 'POST' ),
                'entitlement_definitions' => array( 'path' => '/connector/entitlements/definitions', 'method' => 'GET' ),
                'entitlement' => array( 'path' => '/connector/entitlement', 'method' => 'POST' ),
            ),
        );
    }

    /** The canonical public site URL that an external Connector stores. */
    public static function core_site_url() {
        return untrailingslashit( home_url( '/' ) );
    }

    /** Technical REST base, derived by WordPress for diagnostics only. */
    public static function rest_base_url() {
        return self::rest_contract()['base_url'];
    }

    /** Generates a public client ID and one-time secret for an active project. */
    public static function generate_credentials( $project_id ) {
        global $wpdb;
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'schema_not_ready' );
        }
        $project = self::project_by_id( absint( $project_id ) );
        if ( ! $project || empty( $project['active'] ) ) {
            return self::error( 'connector_project_inactive' );
        }

        /* Only the very first credential gets the minimum read permission by default.
         * A later secret rotation deliberately preserves a revoked or historical state. */
        $first_credentials = ! self::has_credential_record( $project );
        $permissions = $first_credentials ? array( self::PERMISSION_WALLET_READ ) : self::stored_permissions( $project['connector_permissions'] ?? '' );
        try {
            $secret = self::random_value( 32 );
            $version = wp_generate_uuid4();
            $client_id = ! empty( $project['connector_client_id'] ) ? $project['connector_client_id'] : self::client_id();
        } catch ( Exception $exception ) {
            return self::error( 'connector_generation_failed' );
        }
        $updated = $wpdb->update(
            Token_Engine_Schema::projects_table(),
            array(
                'connector_client_id' => $client_id,
                'connector_secret_hash' => wp_hash_password( $secret ),
                'connector_secret_version' => $version,
                'connector_permissions' => wp_json_encode( $permissions ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $project['id'] ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return self::error( 'connector_generation_failed' );
        }
        return array( 'project_key' => $project['project_key'], 'client_id' => $client_id, 'secret' => $secret, 'permissions' => $permissions );
    }

    /** Explicitly grants or revokes the sole TE-02 connector permission. */
    public static function update_project_permissions( $project_id, $permissions ) {
        global $wpdb;
        $project = self::project_by_id( absint( $project_id ) );
        if ( ! $project ) {
            return self::error( 'connector_project_missing' );
        }
        $permissions = self::normalise_permissions_allow_empty( $permissions );
        $updated = $wpdb->update(
            Token_Engine_Schema::projects_table(),
            array( 'connector_permissions' => wp_json_encode( $permissions ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $project['id'] ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return self::error( 'connector_permission_update_failed' );
        }
        return array( 'project_key' => $project['project_key'], 'permissions' => $permissions );
    }

    /** Toggle only entitlement reading without silently changing wallet/reward permissions. */
    public static function update_project_entitlements_permission( $project_id, $allowed ) {
        $project = self::project_by_id( absint( $project_id ) );
        if ( ! $project ) {
            return self::error( 'connector_project_missing' );
        }
        $permissions = self::stored_permissions( $project['connector_permissions'] ?? '' );
        $permissions = array_values( array_filter( $permissions, static function ( $permission ) { return self::PERMISSION_ENTITLEMENTS_READ !== $permission; } ) );
        if ( $allowed ) {
            $permissions[] = self::PERMISSION_ENTITLEMENTS_READ;
        }
        return self::update_project_permissions( $project_id, $permissions );
    }

    /** Non-sensitive state for the project administration screen. */
    public static function project_connection_status( $project ) {
        if ( ! is_array( $project ) || empty( $project['active'] ) ) {
            return array( 'code' => 'connector_project_inactive', 'permissions' => array() );
        }
        if ( ! self::has_credential_record( $project ) ) {
            return array( 'code' => 'connector_credentials_missing', 'permissions' => array() );
        }
        $permissions = self::stored_permissions( $project['connector_permissions'] ?? '' );
        if ( ! in_array( self::PERMISSION_WALLET_READ, $permissions, true ) ) {
            return array( 'code' => 'connector_permission_wallet_read_missing', 'permissions' => $permissions );
        }
        return array( 'code' => 'connector_ready', 'permissions' => $permissions );
    }

    public static function project_has_credentials( $project ) {
        return 'connector_ready' === self::project_connection_status( $project )['code'];
    }

    /** Issues an opaque, short-lived access token after client-secret proof on HTTPS. */
    public static function issue_token( $client_id, $secret ) {
        global $wpdb;
        if ( ! Token_Engine_Schema::is_ready() ) {
            return self::error( 'schema_not_ready', 503 );
        }
        if ( ! is_ssl() ) {
            return self::error( 'https_required', 403 );
        }
        $project = self::project_by_client_id( $client_id );
        if ( ! $project ) {
            return self::error( 'connector_client_rejected' );
        }
        if ( empty( $project['active'] ) ) { return self::error( 'connector_project_inactive' ); }
        if ( ! self::has_credential_record( $project ) ) { return self::error( 'connector_credentials_missing' ); }
        if ( ! self::stored_permissions( $project['connector_permissions'] ?? '' ) ) { return self::error( 'connector_permissions_missing' ); }
        if ( ! is_string( $secret ) || ! wp_check_password( $secret, $project['connector_secret_hash'] ) ) {
            return self::error( 'connector_secret_rejected' );
        }
        try {
            $token = self::random_value( 32 );
        } catch ( Exception $exception ) {
            return self::error( 'connector_token_failed', 500 );
        }
        $permissions = self::stored_permissions( $project['connector_permissions'] );
        $now = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS );
        $wpdb->query( 'DELETE FROM ' . Token_Engine_Schema::connector_tokens_table() . " WHERE expires_at < '" . esc_sql( $now ) . "'" );
        $inserted = $wpdb->insert(
            Token_Engine_Schema::connector_tokens_table(),
            array(
                'token_hash' => hash( 'sha256', $token ),
                'project_key' => $project['project_key'],
                'secret_version' => $project['connector_secret_version'],
                'permissions' => wp_json_encode( $permissions ),
                'expires_at' => $expires,
                'created_at' => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $inserted ) {
            return self::error( 'connector_token_failed', 500 );
        }
        return array( 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_TTL_SECONDS, 'permissions' => $permissions, 'protocol_version' => self::PROTOCOL_VERSION, 'diagnostic_id' => self::diagnostic_id() );
    }

    /** Returns the active project scoped by a valid bearer token and permission. */
    public static function authorize( $request, $permission = self::PERMISSION_WALLET_READ ) {
        global $wpdb;
        if ( ! Token_Engine_Schema::is_ready() || ! is_ssl() ) {
            return self::error( 'connector_token_rejected' );
        }
        $header = is_object( $request ) && method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'authorization' ) : '';
        if ( 1 !== preg_match( '/^Bearer ([A-Za-z0-9_-]{32,128})$/', trim( $header ), $matches ) ) {
            return self::error( 'connector_token_rejected' );
        }
        $sql = 'SELECT tokens.*, projects.active, projects.connector_secret_version, projects.connector_permissions FROM ' . Token_Engine_Schema::connector_tokens_table() . ' AS tokens INNER JOIN ' . Token_Engine_Schema::projects_table() . ' AS projects ON projects.project_key=tokens.project_key WHERE tokens.token_hash=%s AND tokens.expires_at > %s LIMIT 1';
        $entry = $wpdb->get_row( $wpdb->prepare( $sql, hash( 'sha256', $matches[1] ), current_time( 'mysql', true ) ), ARRAY_A );
        if ( ! is_array( $entry ) || empty( $entry['active'] ) || ! hash_equals( (string) $entry['connector_secret_version'], (string) $entry['secret_version'] ) ) {
            return self::error( 'connector_token_rejected' );
        }
        $permissions = self::stored_permissions( $entry['permissions'] );
        $project_permissions = self::stored_permissions( $entry['connector_permissions'] );
        if ( ! in_array( $permission, $permissions, true ) || ! in_array( $permission, $project_permissions, true ) ) {
            return self::error( self::permission_error_code( $permission ) );
        }
        return array( 'project_key' => $entry['project_key'], 'permissions' => $permissions, 'expires_at' => $entry['expires_at'] );
    }

    public static function https_only() {
        return is_ssl() ? true : self::error( 'https_required' );
    }

    public static function access_token_response( $request ) {
        $result = self::issue_token( $request->get_param( 'client_id' ), $request->get_param( 'client_secret' ) );
        nocache_headers();
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function wallet_read_permission( $request ) {
        $authorized = self::authorize( $request );
        return is_wp_error( $authorized ) ? $authorized : true;
    }

    public static function reward_claim_permission( $request ) {
        $authorized = self::authorize( $request, self::PERMISSION_REWARD_CLAIM );
        return is_wp_error( $authorized ) ? $authorized : true;
    }

    public static function entitlements_read_permission( $request ) {
        $authorized = self::authorize( $request, self::PERMISSION_ENTITLEMENTS_READ );
        return is_wp_error( $authorized ) ? $authorized : true;
    }

    public static function diagnostic_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        return rest_ensure_response( array( 'engine' => 'token-engine', 'protocol_version' => self::PROTOCOL_VERSION, 'connected' => true, 'project_key' => $authorized['project_key'], 'permissions' => $authorized['permissions'], 'diagnostic_id' => self::diagnostic_id() ) );
    }

    public static function balance_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        $subject = self::subject_id( $request->get_param( 'subject_id' ) );
        if ( '' === $subject ) { return self::error( 'invalid_subject', 400 ); }
        return rest_ensure_response( array( 'project_key' => $authorized['project_key'], 'balance' => Token_Engine_Service::balance( $subject, $authorized['project_key'] ) ) );
    }

    /** Compatible definition metadata only: no member grant leaves the Core. */
    public static function entitlement_definitions_response( $request ) {
        $authorized = self::authorize( $request, self::PERMISSION_ENTITLEMENTS_READ );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        return rest_ensure_response( array(
            'project_key' => $authorized['project_key'],
            'definitions' => Token_Engine_Entitlements::active_definitions_for_project( $authorized['project_key'] ),
        ) );
    }

    /** Targeted decision, called only from a trusted local Connector integration. */
    public static function entitlement_response( $request ) {
        $authorized = self::authorize( $request, self::PERMISSION_ENTITLEMENTS_READ );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        $subject = self::subject_id( $request->get_param( 'subject_id' ) );
        $code = self::entitlement_code( $request->get_param( 'entitlement_code' ) );
        if ( '' === $subject || '' === $code ) { return self::error( 'invalid_entitlement_request', 400 ); }
        return rest_ensure_response( array(
            'project_key' => $authorized['project_key'],
            'entitlement_code' => $code,
            'granted' => Token_Engine_Entitlements::subject_has_entitlement( $subject, $authorized['project_key'], $code ),
        ) );
    }

    /** A safe public offer is still authenticated to the authorized Connector project. */
    public static function daily_reward_offer_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        if ( ! self::has_reward_claim_permission( $authorized ) ) {
            return rest_ensure_response( self::daily_reward_payload( array( 'state' => 'permission_denied' ), $authorized['project_key'] ) );
        }
        $result = Token_Engine_Service::daily_reward_offer( $authorized['project_key'] );
        return rest_ensure_response( self::daily_reward_offer_payload( $result, $authorized['project_key'] ) );
    }

    /** Inspects the configured daily rule only; it never resolves a subject or writes the ledger. */
    public static function daily_reward_diagnostic_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        return rest_ensure_response( self::daily_reward_diagnostic_payload( Token_Engine_Service::daily_reward_diagnostic( $authorized['project_key'] ), $authorized['project_key'], self::has_reward_claim_permission( $authorized ) ) );
    }

    /** Status remains scoped to the authenticated project and Connector subject. */
    public static function daily_reward_status_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        if ( ! self::has_reward_claim_permission( $authorized ) ) {
            return rest_ensure_response( self::daily_reward_payload( array( 'state' => 'permission_denied' ), $authorized['project_key'] ) );
        }
        $subject = self::subject_id( $request->get_param( 'subject_id' ) );
        if ( '' === $subject ) { return rest_ensure_response( self::daily_reward_payload( array( 'state' => 'subject_unavailable' ), $authorized['project_key'] ) ); }
        $result = Token_Engine_Service::daily_reward_status( $subject, $authorized['project_key'] );
        return rest_ensure_response( self::daily_reward_payload( $result, $authorized['project_key'] ) );
    }

    /** The Core alone validates eligibility and appends the immutable credit. */
    public static function daily_reward_claim_response( $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        if ( ! self::has_reward_claim_permission( $authorized ) ) {
            return rest_ensure_response( self::daily_reward_payload( array( 'state' => 'permission_denied' ), $authorized['project_key'] ) );
        }
        $subject = self::subject_id( $request->get_param( 'subject_id' ) );
        if ( '' === $subject ) { return rest_ensure_response( self::daily_reward_payload( array( 'state' => 'subject_unavailable' ), $authorized['project_key'] ) ); }
        $result = Token_Engine_Service::claim_daily_reward( $subject, $authorized['project_key'] );
        return rest_ensure_response( self::daily_reward_payload( $result, $authorized['project_key'] ) );
    }

    private static function project_by_id( $id ) {
        global $wpdb;
        if ( ! $id ) { return null; }
        $project = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' WHERE id=%d', $id ), ARRAY_A );
        return is_array( $project ) ? $project : null;
    }

    private static function project_by_client_id( $client_id ) {
        global $wpdb;
        if ( ! self::valid_client_id( $client_id ) ) { return null; }
        $project = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' WHERE connector_client_id=%s LIMIT 1', $client_id ), ARRAY_A );
        return is_array( $project ) ? $project : null;
    }

    private static function has_credential_record( $project ) {
        return is_array( $project ) && self::valid_client_id( $project['connector_client_id'] ?? '' ) && ! empty( $project['connector_secret_hash'] );
    }
    private static function client_id() { return 'tec_' . self::random_value( 18 ); }
    private static function random_value( $bytes ) { return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); }
    private static function valid_client_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^tec_[A-Za-z0-9_-]{20,60}$/', $value ); }
    private static function subject_id( $value ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, 191 ) : substr( trim( $value ), 0, 191 ); }
    private static function entitlement_code( $value ) { $value = strtolower( is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '' ); return 1 === preg_match( '/^[a-z0-9][a-z0-9_.-]{1,119}$/', $value ) ? $value : ''; }
    private static function has_reward_claim_permission( $authorized ) { return is_array( $authorized ) && in_array( self::PERMISSION_REWARD_CLAIM, (array) ( $authorized['permissions'] ?? array() ), true ); }
    private static function stored_permissions( $value ) { return self::normalise_permissions_allow_empty( is_array( $value ) ? $value : json_decode( (string) $value, true ) ); }
    private static function valid_permissions( $value ) { return self::stored_permissions( $value ); }
    private static function normalise_permissions_allow_empty( $permissions ) {
        $permissions = is_array( $permissions ) ? $permissions : array();
        $normalised = array();
        foreach ( array( self::PERMISSION_WALLET_READ, self::PERMISSION_REWARD_CLAIM, self::PERMISSION_ENTITLEMENTS_READ ) as $permission ) {
            if ( in_array( $permission, $permissions, true ) ) { $normalised[] = $permission; }
        }
        return $normalised;
    }
    private static function permission_error_code( $permission ) {
        if ( self::PERMISSION_REWARD_CLAIM === $permission ) { return 'connector_permission_reward_claim_missing'; }
        if ( self::PERMISSION_ENTITLEMENTS_READ === $permission ) { return 'connector_permission_entitlements_read_missing'; }
        return 'connector_permission_wallet_read_missing';
    }
    /** @return array<string,mixed> */
    private static function daily_reward_payload( $result, $project_key ) {
        $states = array( 'available', 'granted', 'already_claimed', 'rule_unavailable', 'permission_denied', 'subject_unavailable', 'configuration_invalid', 'transient_error' );
        if ( ! is_array( $result ) || ! in_array( $result['state'] ?? '', $states, true ) ) {
            return array( 'state' => 'transient_error', 'project_key' => $project_key );
        }
        $payload = array( 'state' => $result['state'], 'project_key' => $project_key );
        if ( in_array( $result['state'], array( 'available', 'granted', 'already_claimed' ), true ) ) {
            $payload['amount'] = max( 0, (int) ( $result['amount'] ?? 0 ) );
            $payload['unit'] = sanitize_text_field( (string) ( $result['unit'] ?? '' ) );
            $payload['balance'] = max( 0, (int) ( $result['balance'] ?? 0 ) );
            $payload['next_available_at'] = is_string( $result['next_available_at'] ?? null ) ? $result['next_available_at'] : '';
            $payload['claimed_now'] = ! empty( $result['claimed_now'] );
        }
        return $payload;
    }
    /** @return array<string,mixed> */
    private static function daily_reward_offer_payload( $result, $project_key ) {
        $states = array( 'available', 'rule_unavailable', 'permission_denied', 'configuration_invalid', 'transient_error' );
        if ( ! is_array( $result ) || ! in_array( $result['state'] ?? '', $states, true ) ) {
            return array( 'state' => 'transient_error', 'project_key' => $project_key );
        }
        $payload = array( 'state' => $result['state'], 'project_key' => $project_key );
        if ( 'available' === $result['state'] ) {
            $payload['amount'] = max( 0, (int) ( $result['amount'] ?? 0 ) );
            $payload['unit'] = sanitize_text_field( (string) ( $result['unit'] ?? '' ) );
        }
        return $payload;
    }
    /** @return array<string,mixed> */
    private static function daily_reward_diagnostic_payload( $result, $project_key, $reward_permission ) {
        $states = array( 'ready', 'rule_unavailable', 'configuration_invalid' );
        $state = is_array( $result ) && in_array( $result['state'] ?? '', $states, true ) ? $result['state'] : 'transient_error';
        return array(
            'state' => $state,
            'project_key' => $project_key,
            'reward_claim_authorized' => (bool) $reward_permission,
            'project_active' => ! empty( $result['project_active'] ),
            'rule_available' => ! empty( $result['rule_available'] ),
            'global_scope_accepted' => ! empty( $result['global_scope_accepted'] ),
        );
    }
    private static function diagnostic_id() { return wp_generate_uuid4(); }
    private static function error( $code, $status = 403 ) { return new WP_Error( $code, __( 'L’accès connecteur est refusé.', 'token-engine' ), array( 'status' => $status, 'diagnostic_id' => self::diagnostic_id() ) ); }
}
