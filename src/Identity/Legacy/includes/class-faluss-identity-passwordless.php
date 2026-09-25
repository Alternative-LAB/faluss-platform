<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FI-02 central passwordless ceremony.
 *
 * The only browser-held state is an HttpOnly pair of independent 256-bit
 * secrets. Database rows keep one-way derivatives only. No request parameter,
 * redirect, audit record or error message contains an email, OTP or secret.
 */
final class Faluss_Identity_Passwordless {

    const COOKIE_NAME = 'faluss_identity_challenge';
    const COOKIE_TTL = 600;
    const MAX_OTP_ATTEMPTS = 5;
    const REQUEST_LIMIT = 5;
    const REQUEST_WINDOW = 900;
    const NOTICE_KEY = 'faluss_identity_notice';
    const STYLE_HANDLE = 'faluss-identity-passwordless';
    const SCRIPT_HANDLE = 'faluss-identity-passwordless-login';

    public static function register() {
        add_shortcode( 'faluss_identity_login', array( __CLASS__, 'shortcode' ) );
        add_action( 'parse_request', array( __CLASS__, 'exclude_login_from_cache' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'send_login_no_cache_headers' ), 0 );
        add_action( 'admin_post_nopriv_faluss_identity_request_code', array( __CLASS__, 'handle_request_code' ) );
        add_action( 'admin_post_faluss_identity_request_code', array( __CLASS__, 'handle_request_code' ) );
        add_action( 'admin_post_nopriv_faluss_identity_verify_code', array( __CLASS__, 'handle_verify_code' ) );
        add_action( 'admin_post_faluss_identity_verify_code', array( __CLASS__, 'handle_verify_code' ) );
        add_action( 'wp_ajax_nopriv_faluss_identity_request_code_ajax', array( __CLASS__, 'handle_request_code_ajax' ) );
        add_action( 'wp_ajax_faluss_identity_request_code_ajax', array( __CLASS__, 'handle_request_code_ajax' ) );
        add_action( 'wp_ajax_nopriv_faluss_identity_verify_code_ajax', array( __CLASS__, 'handle_verify_code_ajax' ) );
        add_action( 'wp_ajax_faluss_identity_verify_code_ajax', array( __CLASS__, 'handle_verify_code_ajax' ) );
        self::exclude_login_from_cache( null );
    }

    /**
     * The login document contains a nonce and renders a cookie-backed ceremony
     * stage. A shared page cache must therefore never store or replay it.
     */
    public static function exclude_login_from_cache( $request ) {
        if ( is_admin() || ! self::is_login_request( $request ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        add_filter( 'wp_headers', array( __CLASS__, 'login_no_cache_headers' ), 99 );
        do_action( 'litespeed_control_set_nocache' );
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function login_no_cache_headers( $headers ) {
        if ( ! self::is_login_request() ) {
            return $headers;
        }
        return array_merge( (array) $headers, array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ) );
    }

    public static function send_login_no_cache_headers() {
        if ( ! self::is_login_request() ) {
            return;
        }
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        do_action( 'litespeed_control_set_nocache' );
    }

    public static function register_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url( 'assets/css/faluss-identity-passwordless.css', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url( 'assets/js/faluss-identity-passwordless-login.js', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION,
            true
        );
    }

    public static function shortcode( $attributes = array() ) {
        return self::render_form( (array) $attributes );
    }

    /**
     * @param array<string, string> $settings Presentation-only customizations.
     * @return string
     */
    public static function render_form( $settings = array() ) {
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
            self::register_assets();
        }
        wp_enqueue_style( self::STYLE_HANDLE );
        wp_enqueue_script( self::SCRIPT_HANDLE );

        $defaults = array(
            'heading'       => __( 'Bienvenue sur Faluss', 'faluss-identity' ),
            'intro'         => __( 'Entrez votre adresse e-mail pour recevoir un code de connexion.', 'faluss-identity' ),
            'redirect_url'  => home_url( '/mon-faluss/' ),
            'accent_color'  => '',
            'page_color'    => '#FFFDF5',
            'card_color'    => '#FFFFFF',
            'text_color'    => '#000000',
            'border_color'  => '#E8E3D9',
            'radius'        => '20',
        );
        $settings = wp_parse_args( $settings, $defaults );
        $action_color = self::sanitize_hex_color( $settings['accent_color'], '' );
        $style = sprintf(
            '--faluss-identity-action:%1$s;--faluss-identity-page:%2$s;--faluss-identity-card:%3$s;--faluss-identity-text:%4$s;--faluss-identity-border:%5$s;--faluss-identity-radius:%6$dpx;',
            '' === $action_color ? 'var(--faluss-action,#080808)' : esc_attr( $action_color ),
            esc_attr( self::sanitize_hex_color( $settings['page_color'], $defaults['page_color'] ) ),
            esc_attr( self::sanitize_hex_color( $settings['card_color'], $defaults['card_color'] ) ),
            esc_attr( self::sanitize_hex_color( $settings['text_color'], $defaults['text_color'] ) ),
            esc_attr( self::sanitize_hex_color( $settings['border_color'], $defaults['border_color'] ) ),
            max( 0, min( 48, (int) $settings['radius'] ) )
        );
        $flow = self::posted_flow();
        $flow_context = self::onboarding_flow_context( $flow );
        $redirect_to = null !== $flow_context ? $flow_context['return_to'] : self::local_redirect( isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : $settings['redirect_url'] );
        $return_to = self::login_return_url( $redirect_to, $flow );
        wp_localize_script( self::SCRIPT_HANDLE, 'falussIdentityLogin', array( 'url' => admin_url( 'admin-ajax.php' ) ) );

        $notice = isset( $_GET[ self::NOTICE_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_KEY ] ) ) : '';
        // A decodable browser cookie is not proof of a usable ceremony. The
        // server row must still be pending, unexpired, and associated with a
        // valid e-mail before the OTP form can be rendered.
        $has_challenge = self::has_active_challenge();

        ob_start();
        ?>
        <section class="faluss-identity-login" data-faluss-identity-login style="<?php echo $style; ?>">
            <div class="faluss-identity-login__content">
                <p class="faluss-identity-login__eyebrow">FALUSS IDENTITY</p>
                <h2><?php echo esc_html( $settings['heading'] ); ?></h2>
                <p class="faluss-identity-login__intro"><?php echo esc_html( $settings['intro'] ); ?></p>
                <?php self::render_notice( $notice ); ?>
                <p class="faluss-identity-login__notice faluss-identity-login__ajax-notice" role="status" aria-live="polite" hidden></p>
                <?php if ( is_user_logged_in() ) : ?>
                    <p class="faluss-identity-login__notice faluss-identity-login__notice--success"><?php esc_html_e( 'Votre session Faluss est active.', 'faluss-identity' ); ?></p>
                <?php elseif ( $has_challenge ) : ?>
                    <?php echo self::render_otp_stage( $redirect_to, $return_to, $flow ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered safe form. ?>
                <?php else : ?>
                    <?php echo self::render_email_stage( $redirect_to, $return_to, $flow ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- server-rendered safe form. ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_email_stage( $redirect_to, $return_to, $flow = '' ) {
        ob_start();
        ?>
        <form class="faluss-identity-login__form" data-faluss-login-stage="email" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="faluss_identity_request_code">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
            <input type="hidden" name="return_to" value="<?php echo esc_url( $return_to ); ?>">
            <?php self::render_flow_field( $flow ); ?>
            <?php wp_nonce_field( 'faluss_identity_request_code', 'faluss_identity_nonce' ); ?>
            <label for="faluss-identity-email"><?php esc_html_e( 'Adresse e-mail', 'faluss-identity' ); ?></label>
            <input id="faluss-identity-email" name="email" type="email" autocomplete="email" maxlength="320" required>
            <button type="submit"><?php esc_html_e( 'Recevoir mon code', 'faluss-identity' ); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_otp_stage( $redirect_to, $return_to, $flow = '' ) {
        ob_start();
        ?>
        <div class="faluss-identity-login__otp-stage" data-faluss-login-stage="otp">
        <form class="faluss-identity-login__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
            <input type="hidden" name="action" value="faluss_identity_verify_code">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
            <input type="hidden" name="return_to" value="<?php echo esc_url( $return_to ); ?>">
            <?php self::render_flow_field( $flow ); ?>
            <?php wp_nonce_field( 'faluss_identity_verify_code', 'faluss_identity_nonce' ); ?>
            <label for="faluss-identity-otp"><?php esc_html_e( 'Code à 6 chiffres', 'faluss-identity' ); ?></label>
            <input id="faluss-identity-otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <button type="submit"><?php esc_html_e( 'Vérifier et continuer', 'faluss-identity' ); ?></button>
        </form>
        <form class="faluss-identity-login__secondary" data-faluss-login-stage="otp" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="faluss_identity_request_code">
            <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
            <input type="hidden" name="return_to" value="<?php echo esc_url( $return_to ); ?>">
            <?php self::render_flow_field( $flow ); ?>
            <?php wp_nonce_field( 'faluss_identity_request_code', 'faluss_identity_nonce' ); ?>
            <button type="submit" class="faluss-identity-login__link"><?php esc_html_e( 'Recevoir un nouveau code', 'faluss-identity' ); ?></button>
        </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_request_code() {
        self::redirect_with_notice( self::request_code_result(), self::posted_return() );
    }

    public static function handle_verify_code() {
        $redirect_to = self::posted_redirect();
        $user = self::verify_code_result();
        if ( ! $user instanceof WP_User ) {
            self::redirect_with_notice( 'invalid', self::posted_return() );
        }
        self::open_session( $user );
        self::redirect_with_notice( 'authenticated', self::post_authentication_redirect( $redirect_to ) );
    }

    public static function handle_request_code_ajax() {
        $notice = self::request_code_result();
        if ( 'sent' !== $notice ) {
            wp_send_json_error( array( 'notice' => self::notice_message( $notice ) ), 400 );
        }
        wp_send_json_success( array( 'notice' => self::notice_message( $notice ), 'otp_html' => self::render_otp_stage( self::posted_redirect(), self::posted_return(), self::posted_flow() ) ) );
    }

    public static function handle_verify_code_ajax() {
        $user = self::verify_code_result();
        if ( ! $user instanceof WP_User ) {
            $response = array( 'notice' => self::notice_message( 'invalid' ) );
            if ( ! self::has_active_challenge() ) {
                $response['reset_to_email'] = true;
                $response['email_html'] = self::render_email_stage( self::posted_redirect(), self::posted_return(), self::posted_flow() );
            }
            wp_send_json_error( $response, 400 );
        }
        self::open_session( $user );
        wp_send_json_success( array( 'redirect' => self::post_authentication_redirect( self::posted_redirect() ) ) );
    }

    private static function request_code_result() {
        if ( ! self::valid_nonce( 'faluss_identity_request_code' ) ) {
            return 'invalid';
        }
        $has_submitted_email = array_key_exists( 'email', $_POST );
        $email = $has_submitted_email && is_string( $_POST['email'] ) ? self::normalize_email( wp_unslash( $_POST['email'] ) ) : null;
        if ( ! $has_submitted_email ) { $email = self::email_for_current_challenge(); }
        if ( null === $email || ! Faluss_Identity_Schema::get_status()['ready'] ) {
            self::clear_cookie();
            return 'unavailable';
        }
        if ( ! self::issue_challenge( $email, self::client_ip() ) ) {
            self::clear_cookie();
            return 'unavailable';
        }
        return 'sent';
    }

    private static function verify_code_result() {
        if ( ! self::valid_nonce( 'faluss_identity_verify_code' ) ) { self::diagnostic( 'nonce' ); return null; }
        $otp = isset( $_POST['otp'] ) && is_string( $_POST['otp'] ) ? wp_unslash( $_POST['otp'] ) : '';
        $state = self::read_cookie_state();
        if ( ! self::is_valid_otp( $otp ) || null === $state ) { self::diagnostic( null === $state ? 'cookie' : 'format' ); return null; }
        $user = self::consume_valid_otp( $state, $otp );
        // A rolled-back internal failure leaves this browser's pending proof retryable.
        if ( ! $user instanceof WP_User && ! self::has_active_challenge() ) { self::clear_cookie(); }
        return $user;
    }

    private static function open_session( $user ) {
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, false, is_ssl() );
        do_action( 'wp_login', $user->user_login, $user );
        self::clear_cookie();
        self::record_audit( 'passwordless_session_opened' );
    }

    /**
     * Generates, stores and mails one passwordless ceremony. Delivery failures
     * are intentionally indistinguishable from all other request outcomes.
     */
    private static function issue_challenge( $email, $ip ) {
        try {
            $challenge = random_bytes( 32 );
            $browser_secret = random_bytes( 32 );
            $otp = sprintf( '%06d', random_int( 0, 999999 ) );
        } catch ( Exception $exception ) {
            return false;
        }

        $challenge_hash = hash( 'sha256', $challenge );
        $browser_hash = self::secret_hash( $browser_secret, 'browser' );
        $email_hash = self::secret_hash( $email, 'email' );
        $ip_hash = self::secret_hash( $ip, 'ip' );
        if ( null === $browser_hash || null === $email_hash || null === $ip_hash ) {
            return false;
        }

        if ( ! self::reserve_request_limits( $email_hash, $ip_hash ) ) {
            return false;
        }

        // A replacement invalidates the prior code before a new one is sent.
        self::invalidate_current_challenge();

        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['challenges'] ) ) {
            return false;
        }
        $now = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::COOKIE_TTL );
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::quote_identifier( $tables['challenges'] ) . ' (challenge_hash, otp_hash, browser_fingerprint_hash, email, email_hash, attempt_count, status, expires_at, created_at) VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s)',
                $challenge_hash,
                wp_hash_password( $otp ),
                $browser_hash,
                $email,
                $email_hash,
                0,
                'pending',
                $expires,
                $now
            )
        );
        if ( 1 !== $inserted ) {
            return false;
        }

        $sent = wp_mail(
            $email,
            __( 'Votre code de connexion Faluss', 'faluss-identity' ),
            sprintf( __( "Votre code Faluss est : %s\n\nIl expire dans 10 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.", 'faluss-identity' ), $otp )
        );
        if ( ! $sent ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['challenges'] ) . ' SET status = %s WHERE challenge_hash = %s AND status = %s', 'delivery_failed', $challenge_hash, 'pending' ) );
            return false;
        }

        self::write_cookie_state( $challenge, $browser_secret );
        self::record_audit( 'passwordless_code_requested' );
        return true;
    }

    /**
     * OTP consumption and local identity establishment commit together.
     * Invalid proofs still commit their attempt counter; an internal failure
     * rolls back the valid proof and all identity changes before any session.
     * @return WP_User|null
     */
    private static function consume_valid_otp( $state, $otp ) {
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        $browser_hash = self::secret_hash( $state['browser_secret'], 'browser' );
        if ( empty( $tables['challenges'] ) || null === $browser_hash ) { self::diagnostic( 'challenge_schema' ); return null; }
        $challenge_hash = hash( 'sha256', $state['challenge'] );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { self::diagnostic( 'transaction_start' ); return null; }
        $touched_user = null;
        $email = null;
        $stage = 'challenge_read';
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id, email, otp_hash, attempt_count, status, expires_at FROM ' . self::quote_identifier( $tables['challenges'] ) . ' WHERE challenge_hash = %s AND browser_fingerprint_hash = %s FOR UPDATE',
                    $challenge_hash, $browser_hash
                ), ARRAY_A
            );
            if ( ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'challenge_read' ); }
            $valid = is_array( $row ) && 'pending' === $row['status']
                && self::is_future_utc( $row['expires_at'] )
                && (int) $row['attempt_count'] < self::MAX_OTP_ATTEMPTS
                && wp_check_password( $otp, $row['otp_hash'] );
            if ( ! $valid ) {
                $stage = 'otp_attempt';
                if ( is_array( $row ) && 'pending' === $row['status'] && (int) $row['attempt_count'] < self::MAX_OTP_ATTEMPTS ) {
                    $attempts = (int) $row['attempt_count'] + 1;
                    $status = $attempts >= self::MAX_OTP_ATTEMPTS ? 'locked' : 'pending';
                    if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['challenges'] ) . ' SET attempt_count = %d, status = %s WHERE id = %d', $attempts, $status, (int) $row['id'] ) ) ) { throw new RuntimeException( 'otp_attempt' ); }
                }
                if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'otp_attempt' ); }
                self::record_audit( 'passwordless_code_rejected' );
                self::diagnostic( is_array( $row ) ? 'otp_rejected' : 'challenge_missing' );
                return null;
            }
            $stage = 'local_identity';
            $email = self::normalize_email( $row['email'] );
            $user = null === $email ? null : self::establish_local_identity( $email, $touched_user, $stage );
            if ( ! $user instanceof WP_User ) { throw new RuntimeException( 'local_identity' ); }
            $stage = 'otp_consume';
            $consumed = $wpdb->query( $wpdb->prepare(
                'UPDATE ' . self::quote_identifier( $tables['challenges'] ) . ' SET status = %s, consumed_at = %s WHERE id = %d AND status = %s AND consumed_at IS NULL',
                'consumed', current_time( 'mysql', true ), (int) $row['id'], 'pending'
            ) );
            if ( 1 !== $consumed ) { throw new RuntimeException( 'otp_consume' ); }
            $stage = 'transaction_commit';
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'transaction_commit' ); }
            return $user;
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            // WP user/meta caches can have been filled before a rolled-back insert.
            // A user_register hook may throw before the local insert returns its ID.
            if ( ! $touched_user instanceof WP_User && null !== $email ) { $touched_user = get_user_by( 'email', $email ); }
            if ( $touched_user instanceof WP_User ) {
                clean_user_cache( $touched_user );
                wp_cache_delete( $touched_user->ID, 'user_meta' );
            }
            self::diagnostic( $stage );
            return null;
        }
    }

    /** Rate limits email and IP in a single InnoDB transaction. */
    private static function reserve_request_limits( $email_hash, $ip_hash ) {
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['rate_limits'] ) ) {
            return false;
        }
        for ( $retry = 0; $retry < 2; ++$retry ) {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                return false;
            }
            try {
                $email_ok = self::reserve_rate_limit( $tables['rate_limits'], 'email', $email_hash );
                $ip_ok = $email_ok && self::reserve_rate_limit( $tables['rate_limits'], 'ip', $ip_hash );
                if ( $email_ok && $ip_ok && false !== $wpdb->query( 'COMMIT' ) ) {
                    return true;
                }
                $wpdb->query( 'ROLLBACK' );
                if ( ! $email_ok || ! $ip_ok ) {
                    return false;
                }
            } catch ( Exception $exception ) {
                $wpdb->query( 'ROLLBACK' );
            }
        }
        return false;
    }

    private static function reserve_rate_limit( $table, $bucket_type, $bucket_hash ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, attempt_count, expires_at FROM ' . self::quote_identifier( $table ) . ' WHERE bucket_type = %s AND bucket_hash = %s FOR UPDATE',
                $bucket_type,
                $bucket_hash
            ),
            ARRAY_A
        );
        $now = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::REQUEST_WINDOW );
        if ( ! is_array( $row ) ) {
            return 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $table ) . ' (bucket_type, bucket_hash, attempt_count, window_started_at, expires_at, updated_at) VALUES (%s, %s, %d, %s, %s, %s)', $bucket_type, $bucket_hash, 1, $now, $expires, $now ) );
        }
        if ( ! self::is_future_utc( $row['expires_at'] ) ) {
            return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET attempt_count = %d, window_started_at = %s, expires_at = %s, updated_at = %s WHERE id = %d', 1, $now, $expires, $now, (int) $row['id'] ) );
        }
        if ( (int) $row['attempt_count'] >= self::REQUEST_LIMIT ) {
            return false;
        }
        return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET attempt_count = attempt_count + 1, updated_at = %s WHERE id = %d AND attempt_count < %d', $now, (int) $row['id'], self::REQUEST_LIMIT ) );
    }

    private static function find_or_create_safe_user( $email ) {
        $existing = get_user_by( 'email', $email );
        if ( $existing instanceof WP_User ) {
            return $existing;
        }

        if ( ! get_role( 'subscriber' ) instanceof WP_Role ) {
            return null;
        }
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            try {
                $login = 'faluss_' . bin2hex( random_bytes( 10 ) );
                $password = bin2hex( random_bytes( 32 ) );
            } catch ( Exception $exception ) {
                return null;
            }
            $user_id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $email, 'role' => 'subscriber' ) );
            if ( ! is_wp_error( $user_id ) ) {
                return get_user_by( 'id', (int) $user_id );
            }
            $existing = get_user_by( 'email', $email );
            if ( $existing instanceof WP_User ) {
                return $existing;
            }
        }
        return null;
    }

    /** Participates in the OTP owner's transaction; never starts or commits one. */
    private static function establish_local_identity( $email, &$touched_user, &$stage ) {
        $stage = 'wp_user';
        $user = self::find_or_create_safe_user( $email );
        $touched_user = $user;
        if ( ! $user instanceof WP_User ) { return null; }
        $stage = 'privileged_user';
        if ( self::is_privileged_user( $user ) ) { return null; }
        $stage = 'registry_activation';
        // Registry promotes pending identities only; suspended identities remain denied.
        if ( null === Faluss_Identity_Registry::activate_for_wp_user( $user->ID ) ) { return null; }
        $stage = 'front_preferences';
        Faluss_Identity_Front_Preferences::enforce_for_user( $user );
        return $user;
    }

    /** Bounded server-only stages. Never include request values or exception text. */
    private static function diagnostic( $stage ) {
        $allowed = array( 'nonce', 'cookie', 'format', 'challenge_schema', 'transaction_start', 'challenge_read', 'challenge_missing', 'otp_attempt', 'otp_rejected', 'local_identity', 'wp_user', 'privileged_user', 'registry_activation', 'front_preferences', 'otp_consume', 'transaction_commit' );
        if ( ! in_array( $stage, $allowed, true ) ) { $stage = 'local_identity'; }
        // Unbound anonymous input must not create unbounded audit rows.
        if ( in_array( $stage, array( 'local_identity', 'wp_user', 'privileged_user', 'registry_activation', 'front_preferences', 'otp_consume', 'transaction_commit' ), true ) ) {
            self::record_audit( 'passwordless_failed_' . $stage );
        }
        do_action( 'faluss_identity_passwordless_diagnostic', $stage );
    }

    private static function is_privileged_user( $user ) {
        foreach ( array( 'manage_options', 'edit_users', 'promote_users', 'delete_users' ) as $capability ) {
            if ( user_can( $user, $capability ) ) {
                return true;
            }
        }
        return false;
    }

    private static function write_cookie_state( $challenge, $browser_secret ) {
        $value = self::base64url_encode( $challenge . $browser_secret );
        $options = self::cookie_options( time() + self::COOKIE_TTL );
        setcookie( self::COOKIE_NAME, $value, $options );
        $_COOKIE[ self::COOKIE_NAME ] = $value;
    }

    private static function read_cookie_state() {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return null;
        }
        $decoded = self::base64url_decode( $_COOKIE[ self::COOKIE_NAME ] );
        if ( ! is_string( $decoded ) || 64 !== strlen( $decoded ) ) {
            return null;
        }
        return array( 'challenge' => substr( $decoded, 0, 32 ), 'browser_secret' => substr( $decoded, 32, 32 ) );
    }

    /**
     * The OTP stage is server-confirmed only. A stale, incomplete, replaced,
     * failed, or expired browser cookie is discarded instead of selecting it.
     */
    private static function has_active_challenge() {
        if ( null === self::read_cookie_state() ) {
            return false;
        }
        if ( 'otp' === self::login_stage_for_challenge_email( self::email_for_current_challenge() ) ) {
            return true;
        }
        self::clear_cookie();
        return false;
    }

    /** @return 'email'|'otp' */
    private static function login_stage_for_challenge_email( $email ) {
        return null === self::normalize_email( $email ) ? 'email' : 'otp';
    }

    /** Returns no data to the browser; used only to rotate a live ceremony. */
    private static function email_for_current_challenge() {
        global $wpdb;
        $state = self::read_cookie_state();
        $tables = Faluss_Identity_Schema::get_table_names();
        $browser_hash = null === $state ? null : self::secret_hash( $state['browser_secret'], 'browser' );
        if ( null === $state || null === $browser_hash || empty( $tables['challenges'] ) ) {
            return null;
        }
        $email = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT email FROM ' . self::quote_identifier( $tables['challenges'] ) . ' WHERE challenge_hash = %s AND browser_fingerprint_hash = %s AND status = %s AND expires_at > %s',
                hash( 'sha256', $state['challenge'] ),
                $browser_hash,
                'pending',
                gmdate( 'Y-m-d H:i:s' )
            )
        );
        return self::normalize_email( $email );
    }

    private static function invalidate_current_challenge() {
        global $wpdb;
        $state = self::read_cookie_state();
        $tables = Faluss_Identity_Schema::get_table_names();
        $browser_hash = null === $state ? null : self::secret_hash( $state['browser_secret'], 'browser' );
        if ( null === $state || null === $browser_hash || empty( $tables['challenges'] ) ) {
            return;
        }
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::quote_identifier( $tables['challenges'] ) . ' SET status = %s WHERE challenge_hash = %s AND browser_fingerprint_hash = %s AND status = %s',
                'replaced',
                hash( 'sha256', $state['challenge'] ),
                $browser_hash,
                'pending'
            )
        );
    }

    private static function clear_cookie() {
        setcookie( self::COOKIE_NAME, '', self::cookie_options( time() - 3600 ) );
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    private static function cookie_options( $expires ) {
        return array(
            'expires' => $expires,
            'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        );
    }

    private static function is_login_request( $request = null ) {
        if ( is_object( $request ) && isset( $request->request ) && is_string( $request->request ) ) {
            return 'login' === trim( rawurldecode( $request->request ), '/' );
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
        $login_path = wp_parse_url( home_url( '/login/' ), PHP_URL_PATH );
        if ( ! is_string( $request_path ) || ! is_string( $login_path ) ) {
            return false;
        }
        return untrailingslashit( $request_path ) === untrailingslashit( $login_path );
    }

    private static function valid_nonce( $action ) {
        return isset( $_POST['faluss_identity_nonce'] ) && is_string( $_POST['faluss_identity_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['faluss_identity_nonce'] ) ), $action );
    }

    private static function normalize_email( $email ) {
        $email = strtolower( trim( (string) $email ) );
        return is_email( $email ) ? $email : null;
    }

    private static function is_valid_otp( $otp ) {
        return is_string( $otp ) && 1 === preg_match( '/^[0-9]{6}$/D', $otp );
    }

    private static function secret_hash( $value, $context ) {
        if ( ! function_exists( 'wp_salt' ) ) {
            return null;
        }
        $salt = wp_salt( 'faluss_identity_' . $context );
        return is_string( $salt ) && '' !== $salt ? hash_hmac( 'sha256', $value, $salt ) : null;
    }

    private static function client_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    }

    private static function is_future_utc( $datetime ) {
        return is_string( $datetime ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $datetime ) && $datetime > gmdate( 'Y-m-d H:i:s' );
    }

    private static function base64url_encode( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
            return null;
        }
        $decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
        return false === $decoded ? null : $decoded;
    }

    private static function sanitize_hex_color( $color, $fallback ) {
        $color = is_string( $color ) ? $color : '';
        return preg_match( '/^#[a-fA-F0-9]{6}$/D', $color ) ? $color : $fallback;
    }

    private static function posted_redirect() {
        $flow_context = self::onboarding_flow_context( self::posted_flow() );
        if ( null !== $flow_context ) {
            return $flow_context['return_to'];
        }
        $redirect_to = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : null;
        return self::local_redirect( $redirect_to );
    }

    private static function posted_return() {
        return self::login_return_url( self::posted_redirect(), self::posted_flow() );
    }

    private static function login_return_url( $redirect_to, $flow = '' ) {
        $login = home_url( '/login/' );
        $default = home_url( '/mon-faluss/' );
        $return = self::local_redirect( $redirect_to ) === $default ? $login : add_query_arg( 'redirect_to', self::local_redirect( $redirect_to ), $login );
        return '' !== $flow && class_exists( 'Faluss_Identity_Onboarding' ) ? add_query_arg( Faluss_Identity_Onboarding::FLOW_FIELD, $flow, $return ) : $return;
    }

    private static function posted_flow() {
        return class_exists( 'Faluss_Identity_Onboarding' ) ? Faluss_Identity_Onboarding::request_flow() : '';
    }

    /** @return array<string, string>|null */
    private static function onboarding_flow_context( $flow ) {
        return class_exists( 'Faluss_Identity_Onboarding' ) ? Faluss_Identity_Onboarding::login_flow_context( $flow ) : null;
    }

    private static function render_flow_field( $flow ) {
        if ( is_string( $flow ) && '' !== $flow && class_exists( 'Faluss_Identity_Onboarding' ) ) {
            echo '<input type="hidden" name="' . esc_attr( Faluss_Identity_Onboarding::FLOW_FIELD ) . '" value="' . esc_attr( $flow ) . '">';
        }
    }

    /**
     * SSO's local authorize route is deliberately resumed before a generic
     * onboarding intent. Authorization then applies Faluss.me's canonical
     * onboarding gate; no external redirect is accepted here.
     */
    private static function post_authentication_redirect( $fallback ) {
        $fallback = self::local_redirect( $fallback );
        if ( self::is_authorization_return( $fallback ) ) {
            return $fallback;
        }
        return class_exists( 'Faluss_Identity_Onboarding' ) ? Faluss_Identity_Onboarding::after_passwordless_authentication( self::posted_flow(), $fallback ) : $fallback;
    }

    private static function is_authorization_return( $url ) {
        $path = wp_parse_url( self::local_redirect( $url ), PHP_URL_PATH );
        $authorize_path = wp_parse_url( home_url( '/oauth/authorize/' ), PHP_URL_PATH );
        return is_string( $path ) && is_string( $authorize_path ) && untrailingslashit( $path ) === untrailingslashit( $authorize_path );
    }

    /**
     * Accepts only a URL on this Identity installation, without delegating the
     * host decision to any global redirect allow-list.
     */
    private static function local_redirect( $candidate ) {
        $home = home_url( '/' );
        $home_parts = wp_parse_url( $home );
        if ( ! is_array( $home_parts ) || empty( $home_parts['scheme'] ) || empty( $home_parts['host'] ) || ! is_string( $candidate ) ) {
            return $home;
        }

        $candidate = trim( $candidate );
        if ( '' === $candidate ) {
            return $home;
        }
        if ( 0 === strpos( $candidate, '/' ) && 0 !== strpos( $candidate, '//' ) ) {
            $candidate = home_url( $candidate );
        } elseif ( 0 === strpos( $candidate, '?' ) ) {
            $candidate = $home . $candidate;
        }

        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) || isset( $parts['user'], $parts['pass'] ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return $home;
        }
        if ( 0 !== strcasecmp( $parts['scheme'], $home_parts['scheme'] ) || 0 !== strcasecmp( $parts['host'], $home_parts['host'] ) || self::url_port( $parts ) !== self::url_port( $home_parts ) ) {
            return $home;
        }

        return $candidate;
    }

    private static function url_port( $parts ) {
        if ( isset( $parts['port'] ) ) {
            return (int) $parts['port'];
        }
        return 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
    }

    private static function redirect_with_notice( $notice, $redirect_to = null ) {
        $url = self::local_redirect( $redirect_to );
        wp_safe_redirect( add_query_arg( self::NOTICE_KEY, sanitize_key( $notice ), $url ) );
        exit;
    }

    private static function render_notice( $notice ) {
        $message = self::notice_message( $notice );
        if ( '' !== $message ) {
            $modifier = 'authenticated' === $notice ? ' faluss-identity-login__notice--success' : '';
            echo '<p class="faluss-identity-login__notice' . esc_attr( $modifier ) . '">' . esc_html( $message ) . '</p>';
        }
    }

    private static function notice_message( $notice ) {
        $messages = array(
            'sent'          => __( 'Si cette adresse peut recevoir un code, celui-ci vient d’être envoyé. Vérifiez aussi vos indésirables.', 'faluss-identity' ),
            'invalid'       => __( 'Nous ne pouvons pas valider ce code. Demandez-en un nouveau et réessayez.', 'faluss-identity' ),
            'unavailable'   => __( 'Nous ne pouvons pas envoyer de code pour le moment. Vérifiez votre adresse et réessayez.', 'faluss-identity' ),
            'authenticated' => __( 'Votre identité a été vérifiée.', 'faluss-identity' ),
        );
        return isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
    }

    private static function record_audit( $event_type ) {
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['audit'] ) ) {
            return;
        }
        try {
            $bytes = random_bytes( 16 );
        } catch ( Exception $exception ) {
            return;
        }
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        $hex = bin2hex( $bytes );
        $event_id = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
        $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $tables['audit'] ) . ' (event_id, event_type, occurred_at, expires_at) VALUES (%s, %s, %s, %s)', $event_id, $event_type, current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) ) );
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }
}
