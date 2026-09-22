<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FI-04 authorization-code server. Browser state is an opaque, HttpOnly
 * request handle; client data and state stay in the InnoDB request ledger.
 */
final class Faluss_Identity_Authorization {

    const AUTHORIZE_QUERY_VAR = 'faluss_identity_authorize';
    const TOKEN_QUERY_VAR = 'faluss_identity_token';
    const REQUEST_COOKIE = 'faluss_identity_authorization';
    const REQUEST_TTL = 600;
    // A deferred first-party request keeps only its existing opaque request
    // handle while the member completes the canonical Faluss.me onboarding.
    // The authorization code itself remains short-lived.
    const ONBOARDING_REQUEST_TTL = 3600;
    const CODE_TTL = 60;
    const STYLE_HANDLE = 'faluss-identity-authorization';
    const SCOPE_BASIC = 'identity.basic';
    const SCOPE_EMAIL = 'identity.email';
    const FIRST_PARTY_FALUSS_COM_CALLBACK = 'https://faluss.com/faluss-identity/callback';

    public static function register() {
        self::register_rewrite_rules();
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 0 );
    }

    public static function register_rewrite_rules() {
        add_rewrite_rule( '^oauth/authorize/?$', 'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1', 'top' );
        add_rewrite_rule( '^oauth/token/?$', 'index.php?' . self::TOKEN_QUERY_VAR . '=1', 'top' );
    }

    public static function query_vars( $vars ) {
        $vars[] = self::AUTHORIZE_QUERY_VAR;
        $vars[] = self::TOKEN_QUERY_VAR;
        return $vars;
    }

    public static function dispatch() {
        if ( '1' === (string) get_query_var( self::TOKEN_QUERY_VAR ) ) {
            self::handle_token();
        }
        if ( '1' === (string) get_query_var( self::AUTHORIZE_QUERY_VAR ) ) {
            self::handle_authorize();
        }
    }

    public static function register_assets() {
        wp_register_style( self::STYLE_HANDLE, plugins_url( 'assets/css/faluss-identity-authorization.css', FALUSS_IDENTITY_FILE ), array(), FALUSS_IDENTITY_VERSION );
    }

    /** A stable HMAC means the raw secret is never persisted. */
    public static function hash_client_secret( $secret ) {
        if ( ! is_string( $secret ) || '' === $secret || ! function_exists( 'wp_salt' ) ) {
            return null;
        }
        $salt = wp_salt( 'faluss_identity_client_secret' );
        return is_string( $salt ) && '' !== $salt ? hash_hmac( 'sha256', $secret, $salt ) : null;
    }

    public static function valid_redirect_uri( $uri ) {
        if ( ! is_string( $uri ) || strlen( $uri ) > 2048 || '' === trim( $uri ) || 1 === preg_match( '/[\x00-\x20\x7f]/', $uri ) || false === filter_var( $uri, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        $parts = wp_parse_url( $uri );
        return is_array( $parts )
            && isset( $parts['scheme'], $parts['host'] )
            && 'https' === strtolower( $parts['scheme'] )
            && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] );
    }

    public static function normalize_scopes( $scopes ) {
        if ( ! is_string( $scopes ) || '' === trim( $scopes ) ) {
            return null;
        }
        $items = preg_split( '/\s+/', trim( $scopes ) );
        if ( ! is_array( $items ) || count( $items ) !== count( array_unique( $items ) ) ) {
            return null;
        }
        foreach ( $items as $scope ) {
            if ( ! in_array( $scope, array( self::SCOPE_BASIC, self::SCOPE_EMAIL ), true ) ) {
                return null;
            }
        }
        if ( ! in_array( self::SCOPE_BASIC, $items, true ) ) {
            return null;
        }
        $ordered = array( self::SCOPE_BASIC );
        if ( in_array( self::SCOPE_EMAIL, $items, true ) ) {
            $ordered[] = self::SCOPE_EMAIL;
        }
        return $ordered;
    }

    public static function is_valid_pkce_challenge( $challenge ) {
        return is_string( $challenge ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $challenge );
    }

    public static function is_valid_code_verifier( $verifier ) {
        return is_string( $verifier ) && 1 === preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/D', $verifier );
    }

    private static function handle_authorize() {
        if ( ! self::schema_ready() ) {
            self::render_error( __( 'Le service d’autorisation est momentanément indisponible.', 'faluss-identity' ), 503 );
        }
        if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
            self::handle_consent();
        }

        $has_request_parameters = isset( $_GET['client_id'], $_GET['redirect_uri'], $_GET['response_type'] );
        if ( $has_request_parameters ) {
            $request = self::validate_request( $_GET );
            if ( isset( $request['error'] ) ) {
                self::request_error( $request );
            }
            $raw_handle = self::create_request( $request );
            if ( null === $raw_handle ) {
                self::render_error( __( 'La demande d’autorisation ne peut pas être enregistrée.', 'faluss-identity' ), 503 );
            }
            self::write_request_cookie( $raw_handle );
            self::resume_or_login();
        }

        $request = self::request_from_cookie( array( 'pending', 'onboarding' ) );
        if ( null === $request ) {
            self::render_error( __( 'Cette demande d’autorisation a expiré. Recommencez depuis l’application.', 'faluss-identity' ), 400 );
        }
        self::resume_or_login( $request );
    }

    /** @param array<string, mixed>|null $request */
    private static function resume_or_login( $request = null ) {
        if ( ! is_user_logged_in() ) {
            // The FI-02 form accepts only a local URL. No client URI is sent to login.
            wp_safe_redirect( add_query_arg( 'redirect_to', home_url( '/oauth/authorize' ), home_url( '/login' ) ) );
            exit;
        }
        if ( null === $request ) {
            $request = self::request_from_cookie( array( 'pending', 'onboarding' ) );
        }
        $faluss_id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $faluss_id ) {
            self::clear_request_cookie();
            self::render_error( __( 'Votre session Faluss ne permet pas cette autorisation.', 'faluss-identity' ), 403 );
        }
        if ( self::first_party_auto_approval_allowed( $request ) ) {
            if ( ! self::member_has_completed_onboarding() ) {
                self::defer_for_onboarding( $request );
            }
            self::complete_authorization( $request, $faluss_id, true, array( 'pending', 'onboarding' ) );
        }
        self::render_consent( $request, $faluss_id );
    }

    private static function handle_consent() {
        $request = self::request_from_cookie( array( 'pending' ) );
        $faluss_id = is_user_logged_in() ? Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() ) : null;
        $nonce = isset( $_POST['faluss_identity_authorization_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['faluss_identity_authorization_nonce'] ) ) : '';
        if ( null === $request || null === $faluss_id || ! wp_verify_nonce( $nonce, 'faluss_identity_authorization_' . $request['request_hash'] ) ) {
            self::clear_request_cookie();
            self::render_error( __( 'Cette confirmation n’est plus valide. Recommencez depuis l’application.', 'faluss-identity' ), 400 );
        }

        $decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        if ( 'deny' === $decision ) {
            self::mark_request( $request, 'denied' );
            self::clear_request_cookie();
            self::redirect_client_error( $request['redirect_uri'], 'access_denied', $request['state'] );
        }
        if ( 'approve' !== $decision ) {
            self::render_error( __( 'Choisissez une réponse valide.', 'faluss-identity' ), 400 );
        }

        self::complete_authorization( $request, $faluss_id, false );
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private static function validate_request( $input ) {
        $client_id = isset( $input['client_id'] ) && is_string( $input['client_id'] ) ? trim( wp_unslash( $input['client_id'] ) ) : '';
        $redirect_uri = isset( $input['redirect_uri'] ) && is_string( $input['redirect_uri'] ) ? trim( wp_unslash( $input['redirect_uri'] ) ) : '';
        $state = isset( $input['state'] ) && is_string( $input['state'] ) ? wp_unslash( $input['state'] ) : '';
        $client = self::find_client( $client_id );
        if ( null === $client ) {
            return array( 'error' => 'invalid_client' );
        }
        if ( ! self::client_allows_redirect( $client, $redirect_uri ) ) {
            return array( 'error' => 'invalid_request' );
        }
        if ( ! isset( $input['response_type'] ) || ! is_string( $input['response_type'] ) || 'code' !== $input['response_type'] ) {
            return array( 'error' => 'unsupported_response_type', 'redirect_uri' => $redirect_uri, 'state' => $state );
        }
        $scopes = self::normalize_scopes( isset( $input['scope'] ) && is_string( $input['scope'] ) ? wp_unslash( $input['scope'] ) : '' );
        if ( null === $scopes || ! empty( array_diff( $scopes, $client['allowed_scopes'] ) ) ) {
            return array( 'error' => 'invalid_scope', 'redirect_uri' => $redirect_uri, 'state' => $state );
        }
        $challenge = isset( $input['code_challenge'] ) && is_string( $input['code_challenge'] ) ? wp_unslash( $input['code_challenge'] ) : '';
        if ( ! isset( $input['code_challenge_method'] ) || ! is_string( $input['code_challenge_method'] ) || 'S256' !== $input['code_challenge_method'] || ! self::is_valid_pkce_challenge( $challenge ) ) {
            return array( 'error' => 'invalid_request', 'redirect_uri' => $redirect_uri, 'state' => $state );
        }
        if ( '' === $state || strlen( $state ) > 2048 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $state ) ) {
            return array( 'error' => 'invalid_request', 'redirect_uri' => $redirect_uri, 'state' => '' );
        }
        return array( 'client' => $client, 'redirect_uri' => $redirect_uri, 'scopes' => $scopes, 'pkce_challenge' => $challenge, 'state' => $state );
    }

    /** @param array<string, mixed> $request */
    private static function create_request( $request ) {
        global $wpdb;
        $table = Faluss_Identity_Schema::get_authorization_requests_table();
        if ( '' === $table ) { return null; }
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            try { $handle = random_bytes( 32 ); } catch ( Exception $exception ) { return null; }
            $hash = hash( 'sha256', $handle );
            $now = gmdate( 'Y-m-d H:i:s' );
            $result = $wpdb->query( $wpdb->prepare(
                'INSERT INTO ' . self::quote_identifier( $table ) . ' (request_hash, client_id, redirect_uri, scopes, pkce_challenge, state, status, expires_at, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $hash, $request['client']['client_id'], $request['redirect_uri'], implode( ' ', $request['scopes'] ), $request['pkce_challenge'], $request['state'], 'pending', gmdate( 'Y-m-d H:i:s', time() + self::REQUEST_TTL ), $now
            ) );
            if ( 1 === $result ) { return self::base64url_encode( $handle ); }
        }
        return null;
    }

    /**
     * @param array<int, string> $allowed_statuses
     * @return array<string, mixed>|null
     */
    private static function request_from_cookie( $allowed_statuses = array( 'pending' ) ) {
        $allowed_statuses = is_array( $allowed_statuses ) ? array_values( array_filter( $allowed_statuses, static function( $status ) { return in_array( $status, array( 'pending', 'onboarding' ), true ); } ) ) : array();
        if ( empty( $allowed_statuses ) ) { return null; }
        if ( empty( $_COOKIE[ self::REQUEST_COOKIE ] ) || ! is_string( $_COOKIE[ self::REQUEST_COOKIE ] ) ) { return null; }
        $raw = self::base64url_decode( $_COOKIE[ self::REQUEST_COOKIE ] );
        if ( ! is_string( $raw ) || 32 !== strlen( $raw ) ) { return null; }
        global $wpdb;
        $table = Faluss_Identity_Schema::get_authorization_requests_table();
        if ( '' === $table ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT request_hash, client_id, redirect_uri, scopes, pkce_challenge, state, status, expires_at FROM ' . self::quote_identifier( $table ) . ' WHERE request_hash = %s', hash( 'sha256', $raw ) ), ARRAY_A );
        if ( ! is_array( $row ) || ! in_array( $row['status'], $allowed_statuses, true ) || ! self::future( $row['expires_at'] ) || ! self::valid_redirect_uri( $row['redirect_uri'] ) || ! self::is_valid_pkce_challenge( $row['pkce_challenge'] ) ) { return null; }
        $scopes = self::normalize_scopes( $row['scopes'] );
        $client = self::find_client( $row['client_id'] );
        if ( null === $scopes || null === $client || ! self::client_allows_redirect( $client, $row['redirect_uri'] ) || ! empty( array_diff( $scopes, $client['allowed_scopes'] ) ) ) { return null; }
        $row['scopes'] = $scopes;
        $row['client'] = $client;
        return $row;
    }

    /** @param array<string, mixed> $request */
    /** @param array<string, mixed> $request @param array<int, string> $allowed_statuses */
    private static function approve_and_issue_code( $request, $faluss_id, $allowed_statuses = array( 'pending' ) ) {
        $allowed_statuses = is_array( $allowed_statuses ) ? array_values( array_filter( $allowed_statuses, static function( $status ) { return in_array( $status, array( 'pending', 'onboarding' ), true ); } ) ) : array();
        if ( empty( $allowed_statuses ) ) { return null; }
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['auth_codes'] ) || false === $wpdb->query( 'START TRANSACTION' ) ) { return null; }
        try {
            $locked = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, expires_at FROM ' . self::quote_identifier( Faluss_Identity_Schema::get_authorization_requests_table() ) . ' WHERE request_hash = %s FOR UPDATE', $request['request_hash'] ), ARRAY_A );
            if ( ! is_array( $locked ) || ! in_array( $locked['status'], $allowed_statuses, true ) || ! self::future( $locked['expires_at'] ) ) { $wpdb->query( 'ROLLBACK' ); return null; }
            for ( $attempt = 0; $attempt < 3; ++$attempt ) {
                try { $code = self::base64url_encode( random_bytes( 32 ) ); } catch ( Exception $exception ) { $wpdb->query( 'ROLLBACK' ); return null; }
                $inserted = $wpdb->query( $wpdb->prepare(
                    'INSERT INTO ' . self::quote_identifier( $tables['auth_codes'] ) . ' (code_hash, faluss_id, client_id, redirect_uri, pkce_challenge, scopes, expires_at, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
                    hash( 'sha256', $code ), $faluss_id, $request['client_id'], $request['redirect_uri'], $request['pkce_challenge'], implode( ' ', $request['scopes'] ), gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ), gmdate( 'Y-m-d H:i:s' )
                ) );
                if ( 1 === $inserted ) {
                    $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( Faluss_Identity_Schema::get_authorization_requests_table() ) . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', 'approved', gmdate( 'Y-m-d H:i:s' ), (int) $locked['id'], $locked['status'] ) );
                    if ( 1 === $updated && false !== $wpdb->query( 'COMMIT' ) ) { self::record_audit( 'authorization_code_issued', $request['client_id'] ); return $code; }
                    $wpdb->query( 'ROLLBACK' ); return null;
                }
            }
            $wpdb->query( 'ROLLBACK' );
        } catch ( Exception $exception ) { $wpdb->query( 'ROLLBACK' ); }
        return null;
    }

    private static function handle_token() {
        if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { self::token_error( 'invalid_request', 405 ); }
        if ( ! self::schema_ready() ) { self::token_error( 'temporarily_unavailable', 503 ); }
        $input = wp_unslash( $_POST );
        $client_id = isset( $input['client_id'] ) && is_string( $input['client_id'] ) ? trim( $input['client_id'] ) : '';
        $client = self::find_client( $client_id );
        $redirect_uri = isset( $input['redirect_uri'] ) && is_string( $input['redirect_uri'] ) ? trim( $input['redirect_uri'] ) : '';
        $code = isset( $input['code'] ) && is_string( $input['code'] ) ? $input['code'] : '';
        $verifier = isset( $input['code_verifier'] ) && is_string( $input['code_verifier'] ) ? $input['code_verifier'] : '';
        if ( 'authorization_code' !== ( isset( $input['grant_type'] ) ? $input['grant_type'] : '' ) || null === $client || ! self::client_allows_redirect( $client, $redirect_uri ) || ! self::is_opaque_code( $code ) || ! self::is_valid_code_verifier( $verifier ) ) { self::token_error( 'invalid_grant', 400 ); }
        if ( ! empty( $client['client_secret_hash'] ) ) {
            $secret = isset( $input['client_secret'] ) && is_string( $input['client_secret'] ) ? $input['client_secret'] : '';
            $hash = self::hash_client_secret( $secret );
            if ( null === $hash || ! hash_equals( $client['client_secret_hash'], $hash ) ) { self::token_error( 'invalid_client', 401 ); }
        }

        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['auth_codes'] ) || false === $wpdb->query( 'START TRANSACTION' ) ) { self::token_error( 'temporarily_unavailable', 503 ); }
        try {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, faluss_id, redirect_uri, pkce_challenge, scopes, expires_at, consumed_at FROM ' . self::quote_identifier( $tables['auth_codes'] ) . ' WHERE code_hash = %s AND client_id = %s FOR UPDATE', hash( 'sha256', $code ), $client_id ), ARRAY_A );
            $challenge = self::base64url_encode( hash( 'sha256', $verifier, true ) );
            if ( ! is_array( $row ) || null !== $row['consumed_at'] || ! self::future( $row['expires_at'] ) || ! hash_equals( $row['redirect_uri'], $redirect_uri ) || ! hash_equals( $row['pkce_challenge'], $challenge ) ) { $wpdb->query( 'ROLLBACK' ); self::token_error( 'invalid_grant', 400 ); }
            $consumed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['auth_codes'] ) . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL', gmdate( 'Y-m-d H:i:s' ), (int) $row['id'] ) );
            if ( 1 !== $consumed || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); self::token_error( 'invalid_grant', 400 ); }
        } catch ( Exception $exception ) { $wpdb->query( 'ROLLBACK' ); self::token_error( 'temporarily_unavailable', 503 ); }
        $claims = self::claims( $row['faluss_id'], self::normalize_scopes( $row['scopes'] ) );
        if ( null === $claims ) { self::token_error( 'invalid_grant', 400 ); }
        self::record_audit( 'authorization_code_exchanged', $client_id );
        wp_send_json( $claims, 200 );
    }

    /** @return array<string, mixed>|null */
    private static function claims( $faluss_id, $scopes ) {
        if ( ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || ! is_array( $scopes ) ) { return null; }
        $claims = array( 'faluss_id' => $faluss_id, 'scope' => implode( ' ', $scopes ) );
        if ( in_array( self::SCOPE_EMAIL, $scopes, true ) ) {
            global $wpdb;
            $tables = Faluss_Identity_Schema::get_table_names();
            if ( empty( $tables['profiles'] ) ) { return null; }
            $wp_user_id = $wpdb->get_var( $wpdb->prepare( 'SELECT wp_user_id FROM ' . self::quote_identifier( $tables['profiles'] ) . ' WHERE faluss_id = %s AND status = %s', $faluss_id, 'active' ) );
            $user = $wp_user_id ? get_user_by( 'id', (int) $wp_user_id ) : false;
            if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) { return null; }
            $claims['email'] = $user->user_email;
        }
        if ( class_exists( 'Faluss_Identity_Public_Profile' )
            && method_exists( 'Faluss_Identity_Public_Profile', 'member_app_projection' ) ) {
            $me = Faluss_Identity_Public_Profile::member_app_projection( $faluss_id );
            if ( is_array( $me ) ) {
                $claims['apps'] = array( 'me' => $me );
            }
        }
        return $claims;
    }

    private static function find_client( $client_id ) {
        if ( ! is_string( $client_id ) || '' === $client_id || strlen( $client_id ) > 191 ) { return null; }
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['clients'] ) ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, client_name, client_secret_hash, allowed_scopes, redirect_uris, first_party FROM ' . self::quote_identifier( $tables['clients'] ) . ' WHERE client_id = %s AND status = %s', $client_id, 'active' ), ARRAY_A );
        if ( ! is_array( $row ) ) { return null; }
        $scopes = self::normalize_scopes( $row['allowed_scopes'] );
        $uris = json_decode( $row['redirect_uris'], true );
        if ( null === $scopes || ! is_array( $uris ) || empty( $uris ) ) { return null; }
        foreach ( $uris as $uri ) { if ( ! self::valid_redirect_uri( $uri ) ) { return null; } }
        $row['allowed_scopes'] = $scopes;
        $row['redirect_uris'] = array_values( $uris );
        return $row;
    }

    private static function client_allows_redirect( $client, $redirect_uri ) {
        return is_array( $client ) && self::valid_redirect_uri( $redirect_uri ) && in_array( $redirect_uri, $client['redirect_uris'], true );
    }

    /**
     * Consent remains mandatory by default. The only automatic path is the
     * explicitly marked Faluss.com client and its one exact registered
     * callback, after the normal client, scope and PKCE checks have passed.
     */
    private static function first_party_auto_approval_allowed( $request ) {
        return is_array( $request )
            && ! empty( $request['client']['first_party'] )
            && isset( $request['redirect_uri'], $request['scopes'] )
            && self::FIRST_PARTY_FALUSS_COM_CALLBACK === $request['redirect_uri']
            && self::client_allows_redirect( $request['client'], $request['redirect_uri'] )
            && is_array( $request['scopes'] )
            && in_array( self::SCOPE_BASIC, $request['scopes'], true );
    }

    /**
     * Answers only with an internal route. The original client, callback,
     * state and PKCE challenge remain in the server-side request ledger until
     * this route resumes the authorization.
     *
     * @return string
     */
    public static function pending_onboarding_resume_url() {
        if ( ! is_user_logged_in() ) { return ''; }
        $request = self::request_from_cookie( array( 'onboarding' ) );
        if ( null === $request || ! self::first_party_auto_approval_allowed( $request ) || ! self::member_has_completed_onboarding() ) {
            return '';
        }
        return home_url( '/oauth/authorize/' );
    }

    /**
     * Faluss.com can be auto-approved only after Faluss.me has recorded the
     * member's own onboarding decision. A missing onboarding owner fails
     * closed rather than treating a card (or its absence) as a decision.
     */
    private static function member_has_completed_onboarding() {
        return class_exists( 'Faluss_Identity_Onboarding' )
            && method_exists( 'Faluss_Identity_Onboarding', 'current_member_has_completed_onboarding' )
            && Faluss_Identity_Onboarding::current_member_has_completed_onboarding();
    }

    /** @param array<string, mixed> $request */
    private static function defer_for_onboarding( $request ) {
        if ( ! class_exists( 'Faluss_Identity_Onboarding' ) || ! is_array( $request ) || empty( $request['request_hash'] ) ) {
            self::render_error( __( 'Le parcours Faluss est momentanément indisponible.', 'faluss-identity' ), 503 );
        }
        global $wpdb;
        $table = Faluss_Identity_Schema::get_authorization_requests_table();
        if ( '' === $table || false === $wpdb->query( 'START TRANSACTION' ) ) {
            self::render_error( __( 'Cette demande ne peut pas être reprise pour le moment.', 'faluss-identity' ), 503 );
        }
        $deferred = false;
        $expires_at = '';
        try {
            $locked = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, expires_at FROM ' . self::quote_identifier( $table ) . ' WHERE request_hash = %s FOR UPDATE', $request['request_hash'] ), ARRAY_A );
            if ( ! is_array( $locked ) || ! in_array( $locked['status'], array( 'pending', 'onboarding' ), true ) || ! self::future( $locked['expires_at'] ) ) {
                $wpdb->query( 'ROLLBACK' );
                self::clear_request_cookie();
                self::render_error( __( 'Cette demande d’autorisation a expiré. Recommencez depuis l’application.', 'faluss-identity' ), 400 );
            }
            if ( 'pending' === $locked['status'] ) {
                $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::ONBOARDING_REQUEST_TTL );
                $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET status = %s, expires_at = %s, updated_at = %s WHERE id = %d AND status = %s', 'onboarding', $expires_at, gmdate( 'Y-m-d H:i:s' ), (int) $locked['id'], 'pending' ) );
                if ( 1 !== $updated ) {
                    $wpdb->query( 'ROLLBACK' );
                    self::render_error( __( 'Cette demande ne peut pas être reprise pour le moment.', 'faluss-identity' ), 503 );
                }
                $deferred = true;
            } else {
                $expires_at = $locked['expires_at'];
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                self::render_error( __( 'Cette demande ne peut pas être reprise pour le moment.', 'faluss-identity' ), 503 );
            }
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            self::render_error( __( 'Cette demande ne peut pas être reprise pour le moment.', 'faluss-identity' ), 503 );
        }
        if ( $deferred ) {
            self::record_audit( 'authorization_deferred_for_onboarding', $request['client_id'] );
        }
        self::refresh_request_cookie( $expires_at );
        wp_safe_redirect( Faluss_Identity_Onboarding::onboarding_url() );
        exit;
    }

    /** @param array<string, mixed> $request @param array<int, string> $allowed_statuses */
    private static function complete_authorization( $request, $faluss_id, $automatic, $allowed_statuses = array( 'pending' ) ) {
        $code = self::approve_and_issue_code( $request, $faluss_id, $allowed_statuses );
        self::clear_request_cookie();
        if ( null === $code ) {
            self::render_error( __( 'Cette demande ne peut plus être autorisée. Recommencez depuis l’application.', 'faluss-identity' ), 400 );
        }
        if ( $automatic ) {
            self::record_audit( 'authorization_first_party_auto_approved', $request['client_id'] );
        }
        $url = add_query_arg( array( 'code' => $code, 'state' => $request['state'] ), $request['redirect_uri'] );
        wp_redirect( $url, 302, 'Faluss Identity' );
        exit;
    }

    private static function mark_request( $request, $status ) {
        global $wpdb;
        $table = Faluss_Identity_Schema::get_authorization_requests_table();
        if ( '' !== $table ) { $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET status = %s, updated_at = %s WHERE request_hash = %s AND status = %s', $status, gmdate( 'Y-m-d H:i:s' ), $request['request_hash'], 'pending' ) ); }
        self::record_audit( 'authorization_' . $status, $request['client_id'] );
    }

    private static function render_consent( $request, $faluss_id ) {
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) { self::register_assets(); }
        wp_enqueue_style( self::STYLE_HANDLE );
        status_header( 200 );
        nocache_headers();
        get_header();
        ?>
        <main class="faluss-identity-authorization">
            <section class="faluss-identity-authorization__card" aria-labelledby="faluss-identity-authorization-title">
                <p class="faluss-identity-authorization__eyebrow">FALUSS IDENTITY</p>
                <h1 id="faluss-identity-authorization-title"><?php esc_html_e( 'Autoriser cette application ?', 'faluss-identity' ); ?></h1>
                <p><strong><?php echo esc_html( $request['client']['client_name'] ); ?></strong> <?php esc_html_e( 'demande l’accès aux informations suivantes :', 'faluss-identity' ); ?></p>
                <ul>
                    <li><?php esc_html_e( 'Votre Faluss ID', 'faluss-identity' ); ?></li>
                    <?php if ( in_array( self::SCOPE_EMAIL, $request['scopes'], true ) ) : ?><li><?php esc_html_e( 'Votre adresse e-mail vérifiée', 'faluss-identity' ); ?></li><?php endif; ?>
                    <li><?php esc_html_e( 'L’état publié et la route membre de Faluss Me, si votre carte existe', 'faluss-identity' ); ?></li>
                </ul>
                <p class="faluss-identity-authorization__hint"><?php esc_html_e( 'Aucun contenu de profil public, ni donnée Pro, Date ou Token Engine ne sera accordé.', 'faluss-identity' ); ?></p>
                <form method="post" action="<?php echo esc_url( home_url( '/oauth/authorize' ) ); ?>">
                    <?php wp_nonce_field( 'faluss_identity_authorization_' . $request['request_hash'], 'faluss_identity_authorization_nonce' ); ?>
                    <button type="submit" name="decision" value="approve"><?php esc_html_e( 'Autoriser', 'faluss-identity' ); ?></button>
                    <button type="submit" name="decision" value="deny" class="faluss-identity-authorization__secondary"><?php esc_html_e( 'Refuser', 'faluss-identity' ); ?></button>
                </form>
            </section>
        </main>
        <?php
        get_footer();
        exit;
    }

    /** @param array<string, mixed> $request */
    private static function request_error( $request ) {
        if ( isset( $request['redirect_uri'] ) && self::valid_redirect_uri( $request['redirect_uri'] ) ) { self::redirect_client_error( $request['redirect_uri'], $request['error'], isset( $request['state'] ) ? $request['state'] : '' ); }
        self::render_error( __( 'Cette demande d’autorisation est invalide.', 'faluss-identity' ), 400 );
    }

    private static function redirect_client_error( $redirect_uri, $error, $state ) {
        $args = array( 'error' => sanitize_key( $error ) );
        if ( is_string( $state ) && '' !== $state ) { $args['state'] = $state; }
        wp_redirect( add_query_arg( $args, $redirect_uri ), 302, 'Faluss Identity' );
        exit;
    }

    private static function render_error( $message, $status ) {
        status_header( $status ); nocache_headers();
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) { self::register_assets(); }
        wp_enqueue_style( self::STYLE_HANDLE ); get_header();
        echo '<main class="faluss-identity-authorization"><section class="faluss-identity-authorization__card"><p class="faluss-identity-authorization__eyebrow">FALUSS IDENTITY</p><h1>' . esc_html__( 'Autorisation indisponible', 'faluss-identity' ) . '</h1><p>' . esc_html( $message ) . '</p></section></main>';
        get_footer(); exit;
    }

    private static function token_error( $error, $status ) { status_header( $status ); nocache_headers(); wp_send_json( array( 'error' => sanitize_key( $error ) ), $status ); }
    private static function schema_ready() { $status = Faluss_Identity_Schema::get_status(); return ! empty( $status['ready'] ); }
    private static function future( $value ) { return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) && $value > gmdate( 'Y-m-d H:i:s' ); }
    private static function is_opaque_code( $code ) { return is_string( $code ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $code ); }
    private static function write_request_cookie( $value ) { setcookie( self::REQUEST_COOKIE, $value, self::cookie_options( time() + self::REQUEST_TTL ) ); $_COOKIE[ self::REQUEST_COOKIE ] = $value; }
    private static function refresh_request_cookie( $expires_at ) {
        $value = isset( $_COOKIE[ self::REQUEST_COOKIE ] ) && is_string( $_COOKIE[ self::REQUEST_COOKIE ] ) ? $_COOKIE[ self::REQUEST_COOKIE ] : '';
        $expires = is_string( $expires_at ) ? strtotime( $expires_at . ' UTC' ) : false;
        if ( '' === $value || false === $expires || $expires <= time() ) {
            self::clear_request_cookie();
            return;
        }
        setcookie( self::REQUEST_COOKIE, $value, self::cookie_options( $expires ) );
    }
    private static function clear_request_cookie() { setcookie( self::REQUEST_COOKIE, '', self::cookie_options( time() - 3600 ) ); unset( $_COOKIE[ self::REQUEST_COOKIE ] ); }
    private static function cookie_options( $expires ) { return array( 'expires' => $expires, 'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', 'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ); }
    private static function base64url_encode( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
    private static function base64url_decode( $value ) { if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) { return null; } $decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true ); return false === $decoded ? null : $decoded; }
    private static function quote_identifier( $identifier ) { return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 ); }
    private static function record_audit( $event_type, $client_id = null ) { global $wpdb; $tables = Faluss_Identity_Schema::get_table_names(); if ( empty( $tables['audit'] ) ) { return; } try { $bytes = random_bytes( 16 ); } catch ( Exception $exception ) { return; } $bytes[6] = chr( ( ord( $bytes[6] ) & 15 ) | 64 ); $bytes[8] = chr( ( ord( $bytes[8] ) & 63 ) | 128 ); $hex = bin2hex( $bytes ); $event_id = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 ); $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $tables['audit'] ) . ' (event_id, event_type, client_id, occurred_at, expires_at) VALUES (%s, %s, %s, %s, %s)', $event_id, $event_type, $client_id, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) ) ); }
}
