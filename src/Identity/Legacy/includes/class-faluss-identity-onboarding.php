<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ONB-01 creates a small, resumable public-card decision after identity proof.
 * Technical Faluss IDs remain server-side; the only public identifier handled
 * here is the FI-03 public slug owned by Faluss Identity.
 */
final class Faluss_Identity_Onboarding {

    const QUERY_VAR = 'faluss_identity_onboarding';
    const ROUTE = 'commencer';
    const OPTION_TEMPLATE_ID = 'faluss_identity_onboarding_template_id';
    const STYLE_HANDLE = 'faluss-identity-onboarding';
    const SCRIPT_HANDLE = 'faluss-identity-onboarding';
    const FLOW_FIELD = 'faluss_identity_flow';
    const FLOW_TRANSIENT_PREFIX = 'faluss_identity_onb_flow_';
    const FLOW_TTL = 900;
    const FLOW_VERSION = 1;

    /** ONB-02 persists only its resumable step in the existing ONB-01 state. */
    const CARD_WIZARD_STEPS = array( 'wizard_name', 'wizard_avatar', 'wizard_header', 'wizard_style', 'wizard_socials', 'wizard_links', 'wizard_finish', 'complete' );

    /** @var array<int, string> */
    const INTENTS = array( 'unlock_teaser', 'claim_reward', 'create_card', 'generic_login' );

    public static function register() {
        add_shortcode( 'faluss_identity_onboarding', array( __CLASS__, 'shortcode' ) );
        add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ), 20 );
        add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
        add_action( 'parse_request', array( __CLASS__, 'exclude_route_from_cache' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 1 );
        add_action( 'wp_ajax_faluss_identity_onboarding_choice', array( __CLASS__, 'handle_choice_ajax' ) );
        add_action( 'wp_ajax_faluss_identity_onboarding_availability', array( __CLASS__, 'handle_availability_ajax' ) );
        add_action( 'wp_ajax_faluss_identity_onboarding_reserve_slug', array( __CLASS__, 'handle_reserve_slug_ajax' ) );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
            add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        }
    }

    public static function register_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url( 'assets/css/faluss-identity-onboarding.css', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION
        );
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url( 'assets/js/faluss-identity-onboarding.js', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION,
            true
        );
    }

    public static function register_rewrite_rule() {
        add_rewrite_rule( '^' . self::ROUTE . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    public static function register_query_var( $query_vars ) {
        $query_vars[] = self::QUERY_VAR;
        return $query_vars;
    }

    public static function shortcode( $attributes = array() ) {
        return self::render( (array) $attributes );
    }

    /**
     * Returns the canonical member-facing onboarding surface. A selected
     * Elementor page is deliberately the source of truth; the legacy route is
     * only the reliable fallback when no such page is configured.
     *
     * @return string Local onboarding URL.
     */
    public static function onboarding_url() {
        $configured_url = self::configured_onboarding_url();
        if ( '' !== $configured_url ) {
            return $configured_url;
        }
        return home_url( '/' . self::ROUTE . '/' );
    }

    /**
     * Creates an opaque, server-backed login flow. The URL holds no identity,
     * e-mail or return data; its server record has an HMAC and short expiry.
     */
    public static function login_url( $intent, $return_to = null ) {
        $flow = self::create_login_flow( $intent, $return_to );
        if ( '' === $flow ) {
            return add_query_arg( 'redirect_to', self::safe_local_return( $return_to ), home_url( '/login/' ) );
        }
        return add_query_arg( self::FLOW_FIELD, $flow, home_url( '/login/' ) );
    }

    /** @return array<string, string>|null */
    public static function login_flow_context( $flow ) {
        if ( ! is_string( $flow ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/D', $flow ) || ! function_exists( 'get_transient' ) || ! function_exists( 'wp_salt' ) ) {
            return null;
        }
        $state = get_transient( self::FLOW_TRANSIENT_PREFIX . hash( 'sha256', $flow ) );
        if ( ! is_array( $state ) || ! isset( $state['intent'], $state['return_to'], $state['expires'], $state['signature'] ) || ! self::is_allowed_intent( $state['intent'] ) || ! is_string( $state['return_to'] ) || ! is_numeric( $state['expires'] ) || (int) $state['expires'] < time() ) {
            return null;
        }
        $expected = hash_hmac( 'sha256', $flow . '|' . $state['intent'] . '|' . $state['return_to'] . '|' . (int) $state['expires'], wp_salt( 'faluss_identity_onboarding_flow' ) );
        if ( ! is_string( $state['signature'] ) || ! hash_equals( $expected, $state['signature'] ) ) {
            return null;
        }
        return array( 'intent' => $state['intent'], 'return_to' => self::safe_local_return( $state['return_to'] ) );
    }

    /**
     * Resolves an authenticated flow after passwordless proof. The local SSO
     * authorize route resumes its opaque request ledger, which checks the
     * explicit onboarding state before it can issue a code.
     */
    public static function after_passwordless_authentication( $flow, $fallback ) {
        $fallback = self::safe_local_return( $fallback );
        $context = self::login_flow_context( $flow );
        if ( null !== $context ) {
            self::consume_login_flow( $flow );
        }
        return self::resolve_authenticated_destination( $context, $fallback, self::current_member_requires_onboarding() );
    }

    /** @return string Safe local URL only. */
    public static function safe_local_return( $candidate ) {
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
        }
        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) || isset( $parts['user'], $parts['pass'] ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 0 !== strcasecmp( $parts['scheme'], $home_parts['scheme'] ) || 0 !== strcasecmp( $parts['host'], $home_parts['host'] ) || self::url_port( $parts ) !== self::url_port( $home_parts ) ) {
            return $home;
        }
        return $candidate;
    }

    /** @return string Opaque flow from GET or POST only. */
    public static function request_flow() {
        $source = isset( $_POST[ self::FLOW_FIELD ] ) ? $_POST : $_GET;
        $flow = isset( $source[ self::FLOW_FIELD ] ) && is_string( $source[ self::FLOW_FIELD ] ) ? wp_unslash( $source[ self::FLOW_FIELD ] ) : '';
        return null === self::login_flow_context( $flow ) ? '' : $flow;
    }

    public static function register_settings_page() {
        add_options_page( __( 'Onboarding Faluss', 'faluss-identity' ), __( 'Onboarding Faluss', 'faluss-identity' ), 'manage_options', 'faluss-identity-onboarding', array( __CLASS__, 'render_settings_page' ) );
    }

    public static function register_settings() {
        register_setting( 'faluss_identity_onboarding', self::OPTION_TEMPLATE_ID, array( 'type' => 'integer', 'sanitize_callback' => array( __CLASS__, 'sanitize_template_id' ), 'default' => 0 ) );
    }

    public static function sanitize_template_id( $template_id ) {
        $template_id = (int) $template_id;
        return self::is_valid_elementor_template( $template_id ) ? $template_id : 0;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $selected = (int) get_option( self::OPTION_TEMPLATE_ID, 0 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Onboarding Faluss', 'faluss-identity' ); ?></h1>
            <p><?php esc_html_e( 'Choisissez une page Elementor publiée comme surface de /commencer. Ajoutez-y le widget « Onboarding Faluss ». Le plugin ne crée aucune page ; sans modèle valide, le rendu de secours reste disponible.', 'faluss-identity' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'faluss_identity_onboarding' ); ?>
                <table class="form-table" role="presentation"><tr><th scope="row"><label for="faluss-identity-onboarding-template"><?php esc_html_e( 'Page Elementor', 'faluss-identity' ); ?></label></th><td>
                    <select id="faluss-identity-onboarding-template" name="<?php echo esc_attr( self::OPTION_TEMPLATE_ID ); ?>">
                        <option value="0"><?php esc_html_e( 'Rendu de secours', 'faluss-identity' ); ?></option>
                        <?php foreach ( self::get_elementor_templates() as $template ) : ?><option value="<?php echo (int) $template->ID; ?>" <?php selected( $selected, $template->ID ); ?>><?php echo esc_html( $template->post_title ); ?></option><?php endforeach; ?>
                    </select>
                </td></tr></table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** @param mixed $request */
    public static function exclude_route_from_cache( $request ) {
        if ( is_admin() || ! self::is_onboarding_request( $request ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        add_filter( 'wp_headers', array( __CLASS__, 'no_cache_headers' ), 99 );
        do_action( 'litespeed_control_set_nocache' );
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function no_cache_headers( $headers ) {
        if ( ! self::is_onboarding_request() ) {
            return $headers;
        }
        return array_merge( (array) $headers, array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ) );
    }

    public static function render_route() {
        if ( ! self::is_onboarding_route() ) {
            return;
        }
        status_header( 200 );
        nocache_headers();
        do_action( 'litespeed_control_set_nocache' );
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?php wp_head(); ?></head>
        <body <?php body_class( 'faluss-identity-onboarding-route' ); ?>><?php wp_body_open(); ?>
        <?php if ( ! self::render_elementor_template( self::get_template_id() ) ) : ?><main class="faluss-identity-onboarding-page"><?php echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe server-rendered component. ?></main><?php endif; ?>
        <?php wp_footer(); ?></body></html>
        <?php
        exit;
    }

    /** @return string */
    public static function render( $settings = array() ) {
        self::enqueue_assets();
        $settings = wp_parse_args( (array) $settings, array(
            'heading' => __( 'Créez votre espace Faluss', 'faluss-identity' ),
            'intro' => __( 'Choisissez ce que vous souhaitez faire après votre connexion.', 'faluss-identity' ),
            'create_label' => __( 'Créer mon Faluss', 'faluss-identity' ),
            'continue_label' => __( 'Continuer sans carte', 'faluss-identity' ),
        ) );
        if ( ! is_user_logged_in() ) {
            return self::render_login_gate( $settings );
        }
        if ( null === self::active_faluss_id() ) {
            return self::render_unavailable( $settings );
        }
        if ( self::card_wizard_context()['required'] && class_exists( 'Faluss_Link' ) && method_exists( 'Faluss_Link', 'render_onboarding_wizard' ) ) {
            return Faluss_Link::render_onboarding_wizard();
        }
        // An explicit no-card completion remains resumable: this UI must keep
        // offering card creation later. The authorization gate below is
        // deliberately stricter and uses the canonical ONB-01 final state.
        if ( self::current_member_has_completed_public_profile() ) {
            return self::render_completed( $settings );
        }
        return self::render_choice( $settings, self::current_member_onboarding_state() );
    }

    public static function handle_choice_ajax() {
        $faluss_id = self::verify_ajax_member();
        $choice = isset( $_POST['choice'] ) && is_string( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
        if ( null === $faluss_id || ! in_array( $choice, array( 'create_card', 'no_card' ), true ) || ! self::record_state( $faluss_id, $choice, 'none', 'create_card' === $choice ? 'identifier' : 'complete' ) ) {
            self::send_ajax_error();
        }
        wp_send_json_success( array( 'choice' => $choice, 'redirect' => 'no_card' === $choice ? self::onboarding_completion_destination() : '' ) );
    }

    public static function handle_availability_ajax() {
        $faluss_id = self::verify_ajax_member();
        $slug = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
        if ( null === $faluss_id ) {
            self::send_ajax_error();
        }
        $state = Faluss_Identity_Public_Profile::public_slug_availability( $slug, $faluss_id );
        wp_send_json_success( array( 'state' => $state, 'message' => self::availability_message( $state ) ) );
    }

    public static function handle_reserve_slug_ajax() {
        $faluss_id = self::verify_ajax_member();
        $slug = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
        if ( null === $faluss_id ) {
            self::send_ajax_error();
        }
        $result = Faluss_Identity_Public_Profile::reserve_public_slug( $faluss_id, $slug );
        if ( 'claimed' !== $result || ! self::record_state( $faluss_id, 'create_card', 'claimed', 'wizard_name' ) ) {
            wp_send_json_error( array( 'message' => self::availability_message( $result ) ), 400 );
        }
        wp_send_json_success( array( 'redirect' => self::onboarding_url() ) );
    }

    private static function render_login_gate( $settings ) {
        ob_start();
        ?>
        <section class="faluss-identity-onboarding" data-faluss-identity-onboarding>
            <div class="faluss-identity-onboarding__card">
                <p class="faluss-identity-onboarding__eyebrow">FALUSS</p><h1><?php echo esc_html( $settings['heading'] ); ?></h1><p><?php echo esc_html( $settings['intro'] ); ?></p>
                <a class="faluss-identity-onboarding__button" href="<?php echo esc_url( self::login_url( 'create_card', self::onboarding_url() ) ); ?>"><?php esc_html_e( 'Se connecter pour commencer', 'faluss-identity' ); ?></a>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_unavailable( $settings ) {
        return '<section class="faluss-identity-onboarding" data-faluss-identity-onboarding><div class="faluss-identity-onboarding__card"><h1>' . esc_html( $settings['heading'] ) . '</h1><p role="status">' . esc_html__( 'Votre identité Faluss doit être active avant de continuer.', 'faluss-identity' ) . '</p></div></section>';
    }

    private static function render_completed( $settings ) {
        return '<section class="faluss-identity-onboarding" data-faluss-identity-onboarding><div class="faluss-identity-onboarding__card"><p class="faluss-identity-onboarding__eyebrow">FALUSS</p><h1>' . esc_html( $settings['heading'] ) . '</h1><p>' . esc_html__( 'Votre identifiant public est déjà réservé. Vous pouvez continuer à personnaliser votre Faluss.', 'faluss-identity' ) . '</p><a class="faluss-identity-onboarding__button" href="' . esc_url( self::onboarding_completion_destination() ) . '">' . esc_html__( 'Ouvrir mon Faluss', 'faluss-identity' ) . '</a></div></section>';
    }

    private static function render_choice( $settings, $state = array() ) {
        $resume = isset( $state['choice'] ) && 'no_card' === $state['choice'] ? __( 'Vous avez choisi de continuer sans carte. Vous pouvez toujours en créer une maintenant.', 'faluss-identity' ) : '';
        ob_start();
        ?>
        <section class="faluss-identity-onboarding" data-faluss-identity-onboarding data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'faluss_identity_onboarding' ) ); ?>">
            <div class="faluss-identity-onboarding__card">
                <p class="faluss-identity-onboarding__eyebrow">FALUSS</p><h1><?php echo esc_html( $settings['heading'] ); ?></h1><p><?php echo esc_html( $settings['intro'] ); ?></p>
                <?php if ( '' !== $resume ) : ?><p class="faluss-identity-onboarding__hint"><?php echo esc_html( $resume ); ?></p><?php endif; ?>
                <p class="faluss-identity-onboarding__notice" role="status" aria-live="polite" hidden></p>
                <div class="faluss-identity-onboarding__choices" data-onboarding-step="choice">
                    <button type="button" class="faluss-identity-onboarding__button" data-onboarding-choice="create_card"><?php echo esc_html( $settings['create_label'] ); ?></button>
                    <button type="button" class="faluss-identity-onboarding__button faluss-identity-onboarding__button--secondary" data-onboarding-choice="no_card"><?php echo esc_html( $settings['continue_label'] ); ?></button>
                </div>
                <form class="faluss-identity-onboarding__identifier" data-onboarding-step="identifier" hidden>
                    <label for="faluss-onboarding-slug"><?php esc_html_e( 'Votre identifiant public', 'faluss-identity' ); ?></label>
                    <input id="faluss-onboarding-slug" name="slug" type="text" autocomplete="username" pattern="[a-z0-9][a-z0-9-]{1,39}" maxlength="40" aria-describedby="faluss-onboarding-slug-help" required>
                    <p id="faluss-onboarding-slug-help" class="faluss-identity-onboarding__hint"><?php esc_html_e( 'Lettres minuscules, chiffres et tirets. Il restera stable après sa réservation.', 'faluss-identity' ); ?></p>
                    <p class="faluss-identity-onboarding__availability" aria-live="polite"></p>
                    <button type="submit" class="faluss-identity-onboarding__button"><?php esc_html_e( 'Réserver mon identifiant', 'faluss-identity' ); ?></button>
                    <button type="button" class="faluss-identity-onboarding__back" data-onboarding-back><?php esc_html_e( 'Retour', 'faluss-identity' ); ?></button>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private static function create_login_flow( $intent, $return_to ) {
        $intent = is_string( $intent ) ? sanitize_key( $intent ) : '';
        if ( ! self::is_allowed_intent( $intent ) || ! function_exists( 'set_transient' ) || ! function_exists( 'wp_salt' ) ) {
            return '';
        }
        try {
            $flow = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        } catch ( Exception $exception ) {
            return '';
        }
        $return_to = self::safe_local_return( $return_to );
        $expires = time() + self::FLOW_TTL;
        $state = array(
            'intent' => $intent,
            'return_to' => $return_to,
            'expires' => $expires,
            'signature' => hash_hmac( 'sha256', $flow . '|' . $intent . '|' . $return_to . '|' . $expires, wp_salt( 'faluss_identity_onboarding_flow' ) ),
        );
        return set_transient( self::FLOW_TRANSIENT_PREFIX . hash( 'sha256', $flow ), $state, self::FLOW_TTL ) ? $flow : '';
    }

    private static function consume_login_flow( $flow ) {
        if ( is_string( $flow ) && '' !== $flow && function_exists( 'delete_transient' ) ) {
            delete_transient( self::FLOW_TRANSIENT_PREFIX . hash( 'sha256', $flow ) );
        }
    }

    private static function is_allowed_intent( $intent ) {
        return is_string( $intent ) && in_array( $intent, self::INTENTS, true );
    }

    private static function active_faluss_id() {
        if ( ! is_user_logged_in() || ! class_exists( 'Faluss_Identity_Registry' ) ) {
            return null;
        }
        return Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
    }

    /** A reserved draft is intentionally not a completed public card. */
    private static function current_member_has_completed_public_profile() {
        $faluss_id = self::active_faluss_id();
        if ( null === $faluss_id || ! class_exists( 'Faluss_Identity_Public_Profile' ) ) {
            return false;
        }
        $profile = Faluss_Identity_Public_Profile::studio_profile( $faluss_id );
        return is_array( $profile ) && 'published' === ( $profile['publication_status'] ?? '' );
    }

    /** An explicit ONB-01 state, not card presence, determines completion. */
    private static function current_member_requires_onboarding() {
        return self::requires_onboarding( false, self::current_member_onboarding_state() );
    }

    /** @param array<string, mixed> $state */
    private static function requires_onboarding( $has_public_profile, $state ) {
        return ! is_array( $state )
            || ! in_array( $state['choice'] ?? '', array( 'create_card', 'no_card' ), true )
            || 'complete' !== ( $state['next_step'] ?? '' );
    }

    /**
     * Public only for the first-party authorization gate. The canonical
     * choice and final step are written after publication or after the
     * explicit no-card decision; a card's presence alone is never enough.
     */
    public static function current_member_has_completed_onboarding() {
        return ! self::current_member_requires_onboarding();
    }

    /**
     * The onboarding browser receives only a local route. The original OAuth
     * client, URI, state and PKCE challenge remain in the server-side ledger.
     */
    public static function onboarding_completion_destination() {
        if ( class_exists( 'Faluss_Identity_Authorization' ) && method_exists( 'Faluss_Identity_Authorization', 'pending_onboarding_resume_url' ) ) {
            $resume = Faluss_Identity_Authorization::pending_onboarding_resume_url();
            if ( is_string( $resume ) && '' !== $resume ) {
                return self::safe_local_return( $resume );
            }
        }
        return home_url( '/mon-faluss/' );
    }

    /**
     * Resolves only already-validated local values. Passwordless resolves the
     * SSO authorization callback before calling this method. Typed business
     * intents therefore remain ahead of the generic Navigation return.
     *
     * @param array<string, string>|null $context
     * @return string
     */
    private static function resolve_authenticated_destination( $context, $fallback, $requires_onboarding ) {
        if ( is_array( $context ) ) {
            if ( in_array( $context['intent'], array( 'unlock_teaser', 'claim_reward' ), true ) ) {
                return $context['return_to'];
            }
            if ( 'create_card' === $context['intent'] ) {
                return self::onboarding_url();
            }
        }
        return $requires_onboarding ? self::onboarding_url() : $fallback;
    }

    /** @return array{choice: string, slug_status: string, next_step: string, flow_version: int} */
    private static function current_member_onboarding_state() {
        $state = array( 'choice' => 'unknown', 'slug_status' => 'none', 'next_step' => 'choice', 'flow_version' => self::FLOW_VERSION );
        $faluss_id = self::active_faluss_id();
        if ( null === $faluss_id ) {
            return $state;
        }
        if ( ! self::state_schema_ready() ) {
            return $state;
        }
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['profiles'] ) ) {
            return $state;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT onboarding_choice, onboarding_slug_status, onboarding_next_step, onboarding_flow_version FROM ' . self::quote_identifier( $tables['profiles'] ) . ' WHERE faluss_id = %s AND status = %s', $faluss_id, 'active' ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return $state;
        }
        $state['choice'] = in_array( $row['onboarding_choice'] ?? '', array( 'create_card', 'no_card' ), true ) ? $row['onboarding_choice'] : 'unknown';
        $state['slug_status'] = in_array( $row['onboarding_slug_status'] ?? '', array( 'none', 'claimed' ), true ) ? $row['onboarding_slug_status'] : 'none';
        $allowed_steps = array_merge( array( 'identifier', 'studio' ), self::CARD_WIZARD_STEPS );
        $state['next_step'] = in_array( $row['onboarding_next_step'] ?? '', $allowed_steps, true ) ? $row['onboarding_next_step'] : 'choice';
        $state['flow_version'] = max( self::FLOW_VERSION, (int) ( $row['onboarding_flow_version'] ?? self::FLOW_VERSION ) );
        return $state;
    }

    /**
     * Minimal ONB-02 adapter. It exposes no identity value to the browser and
     * leaves every card field to the canonical Identity or Link owner.
     *
     * @return array{required: bool, step: string}
     */
    public static function card_wizard_context() {
        $state = self::current_member_onboarding_state();
        $faluss_id = self::active_faluss_id();
        $profile = null !== $faluss_id && class_exists( 'Faluss_Identity_Public_Profile' ) ? Faluss_Identity_Public_Profile::studio_profile( $faluss_id ) : array();
        $reserved = is_array( $profile ) && '' !== (string) ( $profile['public_slug'] ?? '' ) && 'draft' === ( $profile['publication_status'] ?? '' );
        $step = in_array( $state['next_step'], self::CARD_WIZARD_STEPS, true ) ? $state['next_step'] : 'wizard_name';
        return array(
            'required' => null !== $faluss_id && $reserved && 'create_card' === $state['choice'] && 'claimed' === $state['slug_status'] && 'complete' !== $step,
            'step' => $step,
        );
    }

    /** Advances only the existing ONB-01 row after Link saved canonical data. */
    public static function advance_card_wizard( $step ) {
        $context = self::card_wizard_context();
        $step = is_string( $step ) ? sanitize_key( $step ) : '';
        if ( empty( $context['required'] ) || ! in_array( $step, self::CARD_WIZARD_STEPS, true ) || 'complete' === $step ) {
            return false;
        }
        $faluss_id = self::active_faluss_id();
        return null !== $faluss_id && self::record_state( $faluss_id, 'create_card', 'claimed', $step );
    }

    /** Marks the existing state complete only after the canonical profile published. */
    public static function complete_card_wizard() {
        $faluss_id = self::active_faluss_id();
        if ( null === $faluss_id || ! self::current_member_has_completed_public_profile() ) {
            return false;
        }
        return self::record_state( $faluss_id, 'create_card', 'claimed', 'complete' );
    }

    private static function verify_ajax_member() {
        $nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        return wp_verify_nonce( $nonce, 'faluss_identity_onboarding' ) ? self::active_faluss_id() : null;
    }

    private static function record_state( $faluss_id, $choice, $slug_status, $next_step ) {
        global $wpdb;
        $allowed_steps = array_merge( array( 'identifier', 'studio' ), self::CARD_WIZARD_STEPS );
        if ( ! self::state_schema_ready() || ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || ! in_array( $choice, array( 'create_card', 'no_card' ), true ) || ! in_array( $slug_status, array( 'none', 'claimed' ), true ) || ! in_array( $next_step, $allowed_steps, true ) ) {
            return false;
        }
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['profiles'] ) ) {
            return false;
        }
        return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['profiles'] ) . ' SET onboarding_choice = %s, onboarding_slug_status = %s, onboarding_next_step = %s, onboarding_flow_version = %d, onboarding_updated_at = %s WHERE faluss_id = %s AND status = %s', $choice, $slug_status, $next_step, self::FLOW_VERSION, current_time( 'mysql', true ), $faluss_id, 'active' ) );
    }

    private static function state_schema_ready() {
        return (int) get_option( Faluss_Identity_Schema::OPTION_VERSION, 0 ) >= (int) Faluss_Identity_Schema::ONB01_VERSION && ! empty( Faluss_Identity_Schema::get_status()['ready'] );
    }

    private static function availability_message( $state ) {
        $messages = array(
            'available' => __( 'Cet identifiant est disponible.', 'faluss-identity' ),
            'claimed' => __( 'Cet identifiant est déjà réservé pour votre Faluss.', 'faluss-identity' ),
            'taken' => __( 'Cet identifiant n’est pas disponible.', 'faluss-identity' ),
            'immutable' => __( 'Votre identifiant public est déjà réservé.', 'faluss-identity' ),
            'invalid' => __( 'Choisissez un identifiant public valide.', 'faluss-identity' ),
        );
        return isset( $messages[ $state ] ) ? $messages[ $state ] : $messages['invalid'];
    }

    private static function send_ajax_error() {
        wp_send_json_error( array( 'message' => __( 'Nous ne pouvons pas poursuivre pour le moment.', 'faluss-identity' ) ), 400 );
    }

    /** @param mixed $request */
    private static function is_onboarding_request( $request = null ) {
        return self::is_onboarding_route( $request ) || self::is_selected_onboarding_request();
    }

    /** @param mixed $request */
    private static function is_onboarding_route( $request = null ) {
        if ( is_object( $request ) && isset( $request->query_vars[ self::QUERY_VAR ] ) ) {
            return '1' === (string) $request->query_vars[ self::QUERY_VAR ];
        }
        if ( '1' === (string) get_query_var( self::QUERY_VAR ) ) {
            return true;
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        return self::request_matches_url( $request_uri, home_url( '/' . self::ROUTE . '/' ) );
    }

    private static function is_selected_onboarding_request() {
        $configured_url = self::configured_onboarding_url();
        if ( '' === $configured_url ) {
            return false;
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        return self::request_matches_url( $request_uri, $configured_url );
    }

    private static function request_matches_url( $request_uri, $url ) {
        $path = wp_parse_url( $request_uri, PHP_URL_PATH );
        $route = wp_parse_url( $url, PHP_URL_PATH );
        return is_string( $path ) && is_string( $route ) && untrailingslashit( $path ) === untrailingslashit( $route );
    }

    /** @return string Empty when no valid selected page is available. */
    private static function configured_onboarding_url() {
        $template_id = self::get_template_id();
        if ( $template_id < 1 || ! function_exists( 'get_permalink' ) ) {
            return '';
        }
        $url = get_permalink( $template_id );
        if ( ! is_string( $url ) || '' === trim( $url ) ) {
            return '';
        }
        return self::safe_local_return( $url ) === $url ? $url : '';
    }

    private static function get_template_id() {
        return self::sanitize_template_id( get_option( self::OPTION_TEMPLATE_ID, 0 ) );
    }

    private static function is_valid_elementor_template( $template_id ) {
        if ( $template_id < 1 || ! class_exists( 'Elementor\\Plugin' ) ) {
            return false;
        }
        $post = get_post( $template_id );
        return $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status && '' !== (string) get_post_meta( $template_id, '_elementor_data', true );
    }

    /** @return array<int, WP_Post> */
    private static function get_elementor_templates() {
        if ( ! class_exists( 'Elementor\\Plugin' ) ) {
            return array();
        }
        return get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_elementor_data', 'meta_compare' => 'EXISTS' ) );
    }

    private static function render_elementor_template( $template_id ) {
        if ( ! self::is_valid_elementor_template( $template_id ) ) {
            return false;
        }
        $content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return false;
        }
        echo '<main class="faluss-identity-onboarding-page faluss-identity-onboarding-page--elementor">' . $content . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor builder markup.
        return true;
    }

    private static function enqueue_assets() {
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
            self::register_assets();
        }
        wp_enqueue_style( self::STYLE_HANDLE );
        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    private static function url_port( $parts ) {
        if ( isset( $parts['port'] ) ) {
            return (int) $parts['port'];
        }
        return 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }
}
