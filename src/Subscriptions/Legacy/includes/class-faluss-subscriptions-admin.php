<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Server-rendered, capability-gated billing administration. No public sales surface is mounted here. */
final class Faluss_Subscriptions_Admin {
    const CAPABILITY = 'manage_faluss_subscriptions';
    const PAGE = 'faluss-subscriptions';
    const NONCE = 'faluss_subscriptions_admin';
    const POST_ACTION = 'faluss_subscriptions_admin';
    const SANDBOX_CHECKOUT_POST_ACTION = 'faluss_subscriptions_sandbox_checkout';
    const SANDBOX_CHECKOUT_INTENT = 'sandbox_checkout';
    const SANDBOX_RECONCILE_INTENT = 'sandbox_reconcile_checkout';
    const SANDBOX_TAB = 'sandbox-test';

    public static function boot() {
        add_action( 'admin_init', array( __CLASS__, 'ensure_capability' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_' . self::POST_ACTION, array( __CLASS__, 'handle_post' ) );
        add_action( 'admin_post_' . self::SANDBOX_CHECKOUT_POST_ACTION, array( __CLASS__, 'handle_sandbox_checkout_post' ) );
    }

    /** Repair the administrator capability after an upgrade without granting it to another role. */
    public static function ensure_capability() {
        self::grant_capability();
    }

    /** New capability is deliberately given only to the built-in administrator role. */
    public static function grant_capability() {
        $role = get_role( 'administrator' );
        if ( $role ) { $role->add_cap( self::CAPABILITY ); }
    }

    public static function menu() {
        add_menu_page( __( 'Faluss Subscriptions', 'faluss-subscriptions' ), __( 'Abonnements Faluss', 'faluss-subscriptions' ), self::CAPABILITY, self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-awards', 59 );
    }

    public static function assets( $hook ) {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) { return; }
        wp_enqueue_style( 'faluss-subscriptions-admin', FALUSS_SUBSCRIPTIONS_URL . 'assets/css/faluss-subscriptions-admin.css', array(), FALUSS_SUBSCRIPTIONS_VERSION );
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Accès refusé.', 'faluss-subscriptions' ) ); }
        nocache_headers();
        $notice = Faluss_Subscriptions_Admin_Notices::consume( get_current_user_id() );
        $tab = self::tab();
        echo '<div class="wrap faluss-subscriptions-admin">';
        echo '<h1>' . esc_html__( 'Abonnements Faluss', 'faluss-subscriptions' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Autorité centrale des droits Gratuit/Faluss Max. Stripe reste limité au mode test et aux outils administrateur dans SUB-01B.', 'faluss-subscriptions' ) . '</p>';
        self::notice( $notice );
        self::tabs( $tab );
        if ( 'configuration' === $tab ) { self::configuration(); }
        elseif ( 'catalogue' === $tab ) { self::catalogue(); }
        elseif ( 'member' === $tab ) { self::member( self::notice_faluss_id( $notice ) ); }
        elseif ( 'subscriptions' === $tab ) { self::subscriptions(); }
        elseif ( 'events' === $tab ) { self::events(); }
        elseif ( 'audit' === $tab ) { self::audit(); }
        elseif ( self::SANDBOX_TAB === $tab ) { self::sandbox(); }
        else { self::diagnostics(); }
        echo '</div>';
    }

    /** All mutations require authenticated POST, capability, nonce and server-side validation. */
    public static function handle_post() {
        $action = self::post_value( 'faluss_subscriptions_action', 64 );
        $tab = self::post_value( 'return_tab', 32 );
        $actor_user_id = get_current_user_id();
        $member_faluss_id = self::post_value( 'faluss_id', 36 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            self::record_failed_action( $actor_user_id, $action, $member_faluss_id, 'admin_grant_failed' );
            wp_die( esc_html__( 'Accès refusé.', 'faluss-subscriptions' ), 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            self::record_failed_action( $actor_user_id, $action, $member_faluss_id, 'admin_grant_forbidden' );
            wp_die( esc_html__( 'Accès refusé.', 'faluss-subscriptions' ), 403 );
        }
        if ( ! self::valid_nonce() ) {
            self::queue_result_notice( $actor_user_id, $action, new WP_Error( 'admin_grant_invalid_nonce' ), $member_faluss_id );
            self::redirect_after_post( $tab );
            return;
        }
        check_admin_referer( self::NONCE );
        nocache_headers();
        $result = null;
        if ( 'grant_pro' === $action ) {
            $expires_at = self::post_value( 'expires_at', 32 );
            $reason = self::post_value( 'reason', 191 );
            $result = self::validate_grant_request( $member_faluss_id, $expires_at, $reason );
            if ( ! is_wp_error( $result ) ) {
                $result = Faluss_Subscriptions_Entitlements::grant_temporary_pro( $member_faluss_id, $expires_at, $reason, $actor_user_id, self::post_value( 'operation_reference', 191 ) );
            }
            if ( is_array( $result ) ) {
                $result = self::verify_grant_after_write( $result, $member_faluss_id );
            }
            $tab = 'member';
        } elseif ( 'revoke_grant' === $action ) {
            $result = Faluss_Subscriptions_Entitlements::revoke_admin_grant( absint( $_POST['grant_id'] ?? 0 ), self::post_value( 'reason', 191 ), $actor_user_id );
            $tab = 'member';
        } elseif ( 'override_trial_eligibility' === $action ) {
            $result = Faluss_Subscriptions_Trials::set_eligibility_override( $member_faluss_id, self::post_value( 'reason', 191 ), $actor_user_id, self::post_value( 'operation_reference', 191 ) );
            $tab = 'member';
        } elseif ( 'run_migration' === $action ) {
            $result = Faluss_Subscriptions_Schema::install();
            if ( $result ) { Faluss_Subscriptions_Audit::record( $actor_user_id, 'schema_diagnostic_run', null, 'admin', array(), array( 'ready' => true ), null ); }
            $tab = 'diagnostics';
        } elseif ( self::SANDBOX_CHECKOUT_INTENT === $action ) {
            // A stale sandbox form must never fall through the general mutation route.
            $result = new WP_Error( 'sandbox_checkout_legacy_route' );
            $tab = self::SANDBOX_TAB;
        } elseif ( 'sandbox_portal' === $action ) {
            if ( 'test' !== Faluss_Subscriptions_Stripe_Config::mode() ) { $result = new WP_Error( 'sandbox_test_only' ); }
            else { $result = Faluss_Subscriptions_Billing::create_portal( $member_faluss_id ); }
            $tab = self::SANDBOX_TAB;
            if ( is_array( $result ) && ! empty( $result['url'] ) ) { wp_redirect( esc_url_raw( $result['url'] ) ); exit; }
        } elseif ( self::SANDBOX_RECONCILE_INTENT === $action ) {
            if ( 'test' !== Faluss_Subscriptions_Stripe_Config::mode() ) { $result = new WP_Error( 'sandbox_test_only' ); }
            else { $result = Faluss_Subscriptions_Billing::reconcile_checkout( self::post_value( 'checkout_session_reference', 191 ) ); }
            $tab = self::SANDBOX_TAB;
        } elseif ( 'resync_subscription' === $action ) {
            $result = Faluss_Subscriptions_Billing::resync( self::post_value( 'subscription_reference', 191 ) );
            $tab = 'subscriptions';
        } elseif ( 'cancel_at_period_end' === $action || 'reactivate_subscription' === $action ) {
            $result = Faluss_Subscriptions_Billing::request_cancellation( $member_faluss_id, self::post_value( 'subscription_reference', 191 ), 'cancel_at_period_end' === $action );
            $tab = 'subscriptions';
        } elseif ( 'retry_event' === $action ) {
            $result = Faluss_Subscriptions_Webhooks::retry_event( absint( $_POST['event_id'] ?? 0 ) );
            $tab = 'events';
        } else {
            $result = new WP_Error( 'admin_grant_failed' );
        }
        self::queue_result_notice( $actor_user_id, $action, $result, $member_faluss_id );
        self::redirect_after_post( $tab );
    }

    /**
     * Dedicated admin-post route for the Stripe test Checkout. It is intentionally
     * outside the administrative entitlement router: opening Checkout must never
     * create an entitlement, trial or Pro decision.
     */
    public static function handle_sandbox_checkout_post() {
        $actor_user_id = get_current_user_id();
        $member_faluss_id = self::post_value( 'faluss_id', 36 );
        $intent = self::post_value( 'faluss_subscriptions_intent', 64 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            self::record_failed_action( $actor_user_id, self::SANDBOX_CHECKOUT_INTENT, $member_faluss_id, 'sandbox_checkout_invalid_request' );
            wp_die( esc_html__( 'Accès refusé.', 'faluss-subscriptions' ), 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            self::record_failed_action( $actor_user_id, self::SANDBOX_CHECKOUT_INTENT, $member_faluss_id, 'sandbox_checkout_forbidden' );
            wp_die( esc_html__( 'Accès refusé.', 'faluss-subscriptions' ), 403 );
        }
        if ( self::SANDBOX_CHECKOUT_INTENT !== $intent ) {
            $result = new WP_Error( 'sandbox_checkout_invalid_intent' );
        } elseif ( ! self::valid_nonce() ) {
            $result = new WP_Error( 'sandbox_checkout_invalid_nonce' );
        } elseif ( 'test' !== Faluss_Subscriptions_Stripe_Config::mode() ) {
            $result = new WP_Error( 'sandbox_test_only' );
        } else {
            check_admin_referer( self::NONCE );
            nocache_headers();
            $result = Faluss_Subscriptions_Billing::create_checkout( $member_faluss_id, self::post_value( 'period', 16 ) );
        }
        if ( is_array( $result ) && ! empty( $result['url'] ) ) {
            wp_redirect( esc_url_raw( $result['url'] ) );
            exit;
        }
        self::queue_result_notice( $actor_user_id, self::SANDBOX_CHECKOUT_INTENT, $result, $member_faluss_id );
        self::redirect_after_post( self::SANDBOX_TAB );
    }

    /** Public so diagnostics and the HTML/POST contract share the exact routed action. */
    public static function admin_post_url() {
        return admin_url( 'admin-post.php' );
    }

    /** Fixed internal destination for Stripe's sandbox browser return. */
    public static function sandbox_url() {
        return add_query_arg( array( 'page' => self::PAGE, 'tab' => self::SANDBOX_TAB ), admin_url( 'admin.php' ) );
    }

    private static function valid_nonce() {
        $nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
        return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE );
    }

    private static function validate_grant_request( $faluss_id, $expires_at, $reason ) {
        if ( ! self::valid_faluss_id( $faluss_id ) ) { return new WP_Error( 'admin_grant_invalid_subject' ); }
        if ( ! Faluss_Subscriptions_Entitlements::is_valid_future_expiration( $expires_at ) ) { return new WP_Error( 'admin_grant_invalid_expiration' ); }
        if ( '' === $reason ) { return new WP_Error( 'admin_grant_failed' ); }
        return true;
    }

    /** Verify the committed canonical row and a fresh central resolution independently of the write service. */
    private static function verify_grant_after_write( $grant, $faluss_id ) {
        if ( ! is_array( $grant ) || ! self::valid_faluss_id( $faluss_id ) ) { return new WP_Error( 'admin_grant_persistence_failed' ); }
        $persisted = false;
        foreach ( Faluss_Subscriptions_Repository::entitlements_for_faluss_id( $faluss_id ) as $candidate ) {
            if ( (int) ( $candidate['id'] ?? 0 ) === (int) ( $grant['id'] ?? 0 ) && 'admin_grant' === ( $candidate['source'] ?? '' ) && 'active' === ( $candidate['status'] ?? '' ) && (string) ( $candidate['expires_at'] ?? '' ) === (string) ( $grant['expires_at'] ?? '' ) ) {
                $persisted = true;
                break;
            }
        }
        if ( ! $persisted ) { return new WP_Error( 'admin_grant_persistence_failed' ); }
        $decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
        if ( 'pro' !== ( $decision['level'] ?? '' ) || 'comped' !== ( $decision['state'] ?? '' ) ) { return new WP_Error( 'admin_grant_resolution_failed' ); }
        return $grant;
    }

    private static function redirect_after_post( $tab ) {
        $redirect = add_query_arg( array( 'page' => self::PAGE, 'tab' => self::valid_tab( $tab ) ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    private static function queue_result_notice( $actor_user_id, $action, $result, $member_faluss_id ) {
        $context = array();
        if ( is_array( $result ) && self::valid_faluss_id( $result['faluss_id'] ?? '' ) ) { $context['faluss_id'] = strtolower( $result['faluss_id'] ); }
        elseif ( self::valid_faluss_id( $member_faluss_id ) ) { $context['faluss_id'] = strtolower( $member_faluss_id ); }
        if ( is_wp_error( $result ) || false === $result || null === $result ) {
            $code = is_wp_error( $result ) ? $result->get_error_code() : ( 'run_migration' === $action ? 'schema_migration_failed' : 'operation_failed' );
            self::record_failed_action( $actor_user_id, $action, $context['faluss_id'] ?? null, $code );
            if ( self::SANDBOX_CHECKOUT_INTENT === $action ) {
                $context['cause'] = self::safe_checkout_rejection_code( $code );
                Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'error', 'stripe_checkout_rejected', $context );
                return;
            }
            if ( self::SANDBOX_RECONCILE_INTENT === $action ) {
                Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'error', 'stripe_checkout_reconciliation_failed', array( 'cause' => self::safe_checkout_reconciliation_code( $code ) ) );
                return;
            }
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'error', $code, $context );
            return;
        }
        if ( 'grant_pro' === $action && is_array( $result ) ) {
            $context['expires_at'] = (string) ( $result['expires_at'] ?? '' );
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'success', 'pro_granted', $context );
        } elseif ( 'revoke_grant' === $action ) {
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'success', 'pro_revoked', $context );
        } elseif ( 'override_trial_eligibility' === $action ) {
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'warning', 'trial_override_recorded', $context );
        } elseif ( 'run_migration' === $action ) {
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'success', 'migration_verified' );
        } elseif ( self::SANDBOX_RECONCILE_INTENT === $action ) {
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'success', 'stripe_checkout_reconciled' );
        } else {
            Faluss_Subscriptions_Admin_Notices::add( $actor_user_id, 'success', 'saved', $context );
        }
    }

    private static function record_failed_action( $actor_user_id, $action, $faluss_id, $code ) {
        if ( Faluss_Subscriptions_Schema::is_ready() ) {
            if ( self::SANDBOX_CHECKOUT_INTENT === $action ) {
                Faluss_Subscriptions_Audit::record( $actor_user_id, 'stripe_checkout_rejected', self::valid_faluss_id( $faluss_id ) ? $faluss_id : null, 'sandbox', array(), array( 'operation' => self::SANDBOX_CHECKOUT_INTENT, 'cause' => self::safe_checkout_rejection_code( $code ) ), null );
                return;
            }
            if ( self::SANDBOX_RECONCILE_INTENT === $action ) {
                Faluss_Subscriptions_Audit::record( $actor_user_id, 'stripe_checkout_reconciliation_failed', null, 'sandbox', array(), array( 'operation' => self::SANDBOX_RECONCILE_INTENT, 'cause' => self::safe_checkout_reconciliation_code( $code ) ), null );
                return;
            }
            $traced_outcomes = array( 'admin_grant_failed', 'admin_grant_forbidden', 'admin_grant_invalid_nonce', 'admin_grant_invalid_subject', 'admin_grant_invalid_expiration', 'admin_grant_persistence_failed', 'admin_grant_resolution_failed' );
            $trace = in_array( $code, $traced_outcomes, true ) ? $code : 'admin_grant_failed';
            Faluss_Subscriptions_Audit::record( $actor_user_id, $trace, self::valid_faluss_id( $faluss_id ) ? $faluss_id : null, 'admin', array(), array( 'operation' => sanitize_key( $action ), 'outcome' => sanitize_key( $code ) ), null );
        }
    }

    /** Keep sandbox failures diagnosable without recording provider data or arbitrary error text. */
    private static function safe_checkout_rejection_code( $code ) {
        $allowed = array(
            'sandbox_checkout_invalid_request', 'sandbox_checkout_forbidden', 'sandbox_checkout_invalid_intent', 'sandbox_checkout_invalid_nonce', 'sandbox_checkout_legacy_route', 'sandbox_test_only',
            'checkout_invalid', 'checkout_busy', 'checkout_subscription_exists', 'checkout_trial_already_used', 'checkout_entropy_failed', 'checkout_session_invalid', 'checkout_conflict', 'checkout_record_failed',
            'billing_customer_invalid', 'billing_customer_conflict', 'stripe_customer_invalid', 'stripe_tax_not_enabled', 'stripe_price_not_configured', 'stripe_product_not_configured', 'stripe_price_unavailable', 'stripe_price_catalogue_mismatch',
            'stripe_secret_key_invalid', 'stripe_live_disabled', 'stripe_sdk_collision', 'stripe_sdk_missing', 'stripe_sdk_invalid', 'stripe_client_unavailable', 'stripe_customer_tax_location_invalid', 'stripe_transport_failed',
        );
        return in_array( $code, $allowed, true ) ? $code : 'stripe_checkout_unavailable';
    }

    private static function safe_checkout_reconciliation_code( $code ) {
        $allowed = array( 'sandbox_test_only', 'checkout_reconciliation_invalid', 'checkout_reconciliation_incomplete', 'checkout_subscription_link_invalid', 'checkout_subscription_link_conflict', 'checkout_subscription_link_failed', 'payment_proof_missing', 'payment_fingerprint_missing', 'payment_fingerprint_already_consumed', 'trial_window_invalid', 'trial_already_consumed', 'trial_activation_failed', 'stripe_subscription_identity_mismatch', 'stripe_subscription_price_mismatch', 'stripe_price_catalogue_mismatch', 'stripe_price_unavailable', 'stripe_transport_failed', 'stripe_customer_tax_location_invalid' );
        return in_array( $code, $allowed, true ) ? $code : 'checkout_reconciliation_unavailable';
    }

    private static function tabs( $active ) {
        $tabs = array( 'configuration' => __( 'Configuration Stripe', 'faluss-subscriptions' ), 'catalogue' => __( 'Catalogue', 'faluss-subscriptions' ), 'member' => __( 'Membre', 'faluss-subscriptions' ), 'subscriptions' => __( 'Abonnements', 'faluss-subscriptions' ), 'events' => __( 'Événements', 'faluss-subscriptions' ), self::SANDBOX_TAB => __( 'Sandbox test', 'faluss-subscriptions' ), 'audit' => __( 'Audit', 'faluss-subscriptions' ), 'diagnostics' => __( 'Diagnostics', 'faluss-subscriptions' ) );
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Sections des abonnements', 'faluss-subscriptions' ) . '">';
        foreach ( $tabs as $slug => $label ) {
            $url = add_query_arg( array( 'page' => self::PAGE, 'tab' => $slug ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $active === $slug ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private static function catalogue() {
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Catalogue canonique', 'faluss-subscriptions' ) . '</h2>';
        echo '<p>' . esc_html__( 'Les montants sont versionnés dans le plugin et ne peuvent pas être modifiés par cette interface.', 'faluss-subscriptions' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Offre', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Périodicité', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Montant TTC', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Essai', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État commercial', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>';
        foreach ( Faluss_Subscriptions_Catalog::plans() as $plan ) {
            if ( empty( $plan['periods'] ) ) {
                self::catalogue_row( $plan, '—', (int) $plan['amount_cents'] );
                continue;
            }
            foreach ( $plan['periods'] as $period => $price ) { self::catalogue_row( $plan, $period, (int) $price['amount_cents'] ); }
        }
        echo '</tbody></table><p class="description">' . esc_html__( 'Les identifiants Stripe sont configurés hors de WordPress. Cette interface ne permet ni de les saisir ni de modifier les montants.', 'faluss-subscriptions' ) . '</p></section>';
    }

    private static function configuration() {
        $config = Faluss_Subscriptions_Stripe_Config::diagnostics();
        $labels = array( 'secret_configured' => __( 'Clé serveur', 'faluss-subscriptions' ), 'webhook_configured' => __( 'Secret webhook', 'faluss-subscriptions' ), 'monthly_price_configured' => __( 'Price mensuel', 'faluss-subscriptions' ), 'annual_price_configured' => __( 'Price annuel', 'faluss-subscriptions' ), 'product_configured' => __( 'Produit Faluss Max', 'faluss-subscriptions' ), 'portal_configured' => __( 'Customer Portal', 'faluss-subscriptions' ), 'tax_enabled' => __( 'Stripe Tax explicite', 'faluss-subscriptions' ) );
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Configuration Stripe', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'Aucune valeur secrète ni identifiant de paiement n’est affiché ou modifiable ici.', 'faluss-subscriptions' ) . '</p><dl class="faluss-subscriptions-admin__definition"><dt>' . esc_html__( 'Mode', 'faluss-subscriptions' ) . '</dt><dd><code>' . esc_html( $config['mode'] ) . '</code></dd><dt>' . esc_html__( 'API Stripe épinglée', 'faluss-subscriptions' ) . '</dt><dd><code>' . esc_html( $config['api_version'] ) . '</code></dd><dt>' . esc_html__( 'Live explicitement autorisé', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $config['live_allowed'] ? __( 'Oui', 'faluss-subscriptions' ) : __( 'Non', 'faluss-subscriptions' ) ) . '</dd></dl><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Élément', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>';
        foreach ( $labels as $key => $label ) { echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( ! empty( $config[ $key ] ) ? __( 'Configuré', 'faluss-subscriptions' ) : __( 'Non configuré', 'faluss-subscriptions' ) ) . '</td></tr>'; }
        echo '</tbody></table><p class="description">' . esc_html__( 'La connectivité et la conformité effective des Price sont vérifiées côté serveur juste avant Checkout ; aucune requête Stripe n’est faite en chargeant cette page.', 'faluss-subscriptions' ) . '</p></section>';
    }

    private static function catalogue_row( $plan, $period, $amount ) {
        $amount_text = 0 === $amount ? __( 'Gratuit', 'faluss-subscriptions' ) : number_format_i18n( $amount / 100, 2 ) . ' ' . $plan['currency'];
        echo '<tr><td><strong>' . esc_html( $plan['public_name'] ) . '</strong><br><code>' . esc_html( $plan['key'] ) . '</code></td><td>' . esc_html( $period ) . '</td><td>' . esc_html( $amount_text ) . '</td><td>' . ( $plan['trial_days'] ? esc_html( sprintf( _n( '%d jour, carte requise', '%d jours, carte requise', (int) $plan['trial_days'], 'faluss-subscriptions' ), (int) $plan['trial_days'] ) ) : '—' ) . '</td><td>' . esc_html( ! empty( $plan['commercially_active'] ) ? __( 'Actif', 'faluss-subscriptions' ) : __( 'Préparé, non actif', 'faluss-subscriptions' ) ) . '</td></tr>';
    }

    private static function member( $notice_faluss_id = '' ) {
        $faluss_id = self::get_value( 'faluss_id', 36 );
        if ( '' === $faluss_id && self::valid_faluss_id( $notice_faluss_id ) ) { $faluss_id = strtolower( $notice_faluss_id ); }
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Rechercher un membre', 'faluss-subscriptions' ) . '</h2><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="faluss-subscriptions-admin__search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="member"><label for="faluss-subscriptions-faluss-id">' . esc_html__( 'Faluss ID', 'faluss-subscriptions' ) . '</label><input id="faluss-subscriptions-faluss-id" name="faluss_id" type="text" value="' . esc_attr( $faluss_id ) . '" placeholder="550e8400-e29b-41d4-a716-446655440000" pattern="[a-fA-F0-9-]{36}" autocomplete="off"><button type="submit" class="button button-primary">' . esc_html__( 'Rechercher', 'faluss-subscriptions' ) . '</button></form></section>';
        if ( '' === $faluss_id ) { return; }
        if ( ! self::valid_faluss_id( $faluss_id ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Faluss ID invalide.', 'faluss-subscriptions' ) . '</p></div>'; return; }
        $decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
        $trial = Faluss_Subscriptions_Repository::trial_for_faluss_id( $faluss_id );
        $entitlements = Faluss_Subscriptions_Repository::entitlements_for_faluss_id( $faluss_id );
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Droits calculés', 'faluss-subscriptions' ) . '</h2><dl class="faluss-subscriptions-admin__definition"><dt>' . esc_html__( 'Niveau effectif', 'faluss-subscriptions' ) . '</dt><dd><strong>' . esc_html( 'pro' === $decision['level'] ? 'Faluss Max' : 'Faluss Gratuit' ) . '</strong></dd><dt>' . esc_html__( 'État', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $decision['state'] ) . '</dd><dt>' . esc_html__( 'Raison', 'faluss-subscriptions' ) . '</dt><dd><code>' . esc_html( $decision['reason'] ) . '</code></dd><dt>' . esc_html__( 'Expiration retenue', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $decision['expires_at'] ?: '—' ) . '</dd></dl>';
        echo '<h3>' . esc_html__( 'Sources retenues', 'faluss-subscriptions' ) . '</h3><pre>' . esc_html( wp_json_encode( $decision['effective_sources'], JSON_PRETTY_PRINT ) ) . '</pre></section>';
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Essai', 'faluss-subscriptions' ) . '</h2>';
        if ( is_array( $trial ) ) { echo '<p><strong>' . esc_html( $trial['trial_state'] ) . '</strong> — ' . esc_html( $trial['eligibility_status'] ) . ( ! empty( $trial['expires_at'] ) ? ' — ' . esc_html( sprintf( __( 'expire : %s UTC', 'faluss-subscriptions' ), $trial['expires_at'] ) ) : '' ) . '</p>'; } else { echo '<p>' . esc_html__( 'Aucun essai enregistré.', 'faluss-subscriptions' ) . '</p>'; }
        self::form( 'override_trial_eligibility', $faluss_id, __( 'Dérogation d’éligibilité à l’essai', 'faluss-subscriptions' ), __( 'Justification obligatoire. Cette dérogation ne remplace jamais la preuve serveur d’un moyen de paiement.', 'faluss-subscriptions' ), false );
        echo '</section>';
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Attribution administrative', 'faluss-subscriptions' ) . '</h2>';
        self::form( 'grant_pro', $faluss_id, __( 'Attribuer Faluss Max temporairement', 'faluss-subscriptions' ), __( 'Une attribution est séparée d’un essai et d’un abonnement fournisseur.', 'faluss-subscriptions' ), true );
        if ( $entitlements ) { echo '<h3>' . esc_html__( 'Attributions enregistrées', 'faluss-subscriptions' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Expiration UTC', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Action', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>'; foreach ( $entitlements as $entitlement ) { echo '<tr><td>' . esc_html( $entitlement['source'] ) . '</td><td>' . esc_html( $entitlement['status'] ) . '</td><td>' . esc_html( $entitlement['expires_at'] ?: '—' ) . '</td><td>'; if ( 'admin_grant' === $entitlement['source'] && 'active' === $entitlement['status'] ) { self::revoke_form( $entitlement, $faluss_id ); } else { echo '—'; } echo '</td></tr>'; } echo '</tbody></table>'; }
        echo '</section>';
    }

    private static function form( $action, $faluss_id, $title, $description, $with_expiry ) {
        echo self::mutation_form_open( $action, 'faluss-subscriptions-admin__form' ) . '<h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $description ) . '</p><input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="return_tab" value="member"><input type="hidden" name="faluss_id" value="' . esc_attr( $faluss_id ) . '"><input type="hidden" name="operation_reference" value="' . esc_attr( wp_generate_uuid4() ) . '">'; wp_nonce_field( self::NONCE ); if ( $with_expiry ) { echo '<p><label>' . esc_html__( 'Expiration (heure du site)', 'faluss-subscriptions' ) . '<input type="datetime-local" name="expires_at" required></label><span class="description">' . esc_html__( 'Elle sera enregistrée et résolue en UTC.', 'faluss-subscriptions' ) . '</span></p>'; } echo '<p><label>' . esc_html__( 'Justification', 'faluss-subscriptions' ) . '<textarea name="reason" rows="3" maxlength="191" required></textarea></label></p><button type="submit" class="button button-primary">' . esc_html( $title ) . '</button></form>';
    }

    private static function revoke_form( $entitlement, $faluss_id ) {
        echo self::mutation_form_open( 'revoke_grant' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="revoke_grant"><input type="hidden" name="return_tab" value="member"><input type="hidden" name="faluss_id" value="' . esc_attr( $faluss_id ) . '"><input type="hidden" name="grant_id" value="' . esc_attr( (string) $entitlement['id'] ) . '">'; wp_nonce_field( self::NONCE ); echo '<label class="screen-reader-text" for="faluss-subscription-revoke-' . esc_attr( (string) $entitlement['id'] ) . '">' . esc_html__( 'Justification de révocation', 'faluss-subscriptions' ) . '</label><input id="faluss-subscription-revoke-' . esc_attr( (string) $entitlement['id'] ) . '" name="reason" type="text" maxlength="191" required placeholder="' . esc_attr__( 'Justification', 'faluss-subscriptions' ) . '"><button type="submit" class="button-link-delete">' . esc_html__( 'Révoquer', 'faluss-subscriptions' ) . '</button></form>';
    }

    private static function subscriptions() {
        $rows = Faluss_Subscriptions_Repository::all_subscriptions( 100 );
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Abonnements Stripe', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'Les références sont limitées à cette capacité. Les états proviennent d’une synchronisation Stripe côté serveur.', 'faluss-subscriptions' ) . '</p>';
        if ( ! $rows ) { echo '<p>' . esc_html__( 'Aucun abonnement synchronisé.', 'faluss-subscriptions' ) . '</p></section>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Faluss ID</th><th>Customer</th><th>Subscription</th><th>' . esc_html__( 'Période', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État Stripe', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État Faluss', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Action', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $customer_url = Faluss_Subscriptions_Stripe_Config::dashboard_url( 'customers', $row['provider_customer_reference'] );
            $subscription_url = Faluss_Subscriptions_Stripe_Config::dashboard_url( 'subscriptions', $row['provider_subscription_reference'] );
            echo '<tr><td><code>' . esc_html( $row['faluss_id'] ) . '</code></td><td><code>' . esc_html( $row['provider_customer_reference'] ) . '</code>' . ( $customer_url ? ' <a href="' . esc_url( $customer_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ouvrir', 'faluss-subscriptions' ) . '</a>' : '' ) . '</td><td><code>' . esc_html( $row['provider_subscription_reference'] ) . '</code>' . ( $subscription_url ? ' <a href="' . esc_url( $subscription_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ouvrir', 'faluss-subscriptions' ) . '</a>' : '' ) . '</td><td>' . esc_html( $row['billing_interval'] ) . '</td><td>' . esc_html( $row['provider_status'] ) . '</td><td>' . esc_html( $row['normalized_state'] ) . '</td><td>';
            echo self::mutation_form_open( 'resync_subscription' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="resync_subscription"><input type="hidden" name="return_tab" value="subscriptions"><input type="hidden" name="subscription_reference" value="' . esc_attr( $row['provider_subscription_reference'] ) . '">'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html__( 'Resynchroniser', 'faluss-subscriptions' ) . '</button></form>';
            $change = ! empty( $row['cancel_at_period_end'] ) ? 'reactivate_subscription' : 'cancel_at_period_end';
            $label = 'reactivate_subscription' === $change ? __( 'Réactiver', 'faluss-subscriptions' ) : __( 'Résilier à échéance', 'faluss-subscriptions' );
            echo self::mutation_form_open( $change ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="' . esc_attr( $change ) . '"><input type="hidden" name="return_tab" value="subscriptions"><input type="hidden" name="faluss_id" value="' . esc_attr( $row['faluss_id'] ) . '"><input type="hidden" name="subscription_reference" value="' . esc_attr( $row['provider_subscription_reference'] ) . '">'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function sandbox() {
        $test = 'test' === Faluss_Subscriptions_Stripe_Config::mode();
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Sandbox Stripe test', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'Cet outil crée uniquement une session Checkout Stripe test après validation serveur du catalogue. Il ne doit jamais être utilisé en live.', 'faluss-subscriptions' ) . '</p>';
        if ( ! $test ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'La sandbox est verrouillée hors du mode test.', 'faluss-subscriptions' ) . '</p></div></section>'; return; }
        echo self::sandbox_checkout_form_open() . '<input type="hidden" name="action" value="' . esc_attr( self::SANDBOX_CHECKOUT_POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_intent" value="' . esc_attr( self::SANDBOX_CHECKOUT_INTENT ) . '"><label>' . esc_html__( 'Faluss ID', 'faluss-subscriptions' ) . '<input name="faluss_id" type="text" required pattern="[a-fA-F0-9-]{36}" autocomplete="off"></label><p><label>' . esc_html__( 'Périodicité', 'faluss-subscriptions' ) . '<select name="period"><option value="monthly">' . esc_html__( 'Mensuel', 'faluss-subscriptions' ) . '</option><option value="annual">' . esc_html__( 'Annuel', 'faluss-subscriptions' ) . '</option></select></label></p>'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button button-primary">' . esc_html__( 'Ouvrir Checkout test', 'faluss-subscriptions' ) . '</button></form>';
        echo self::mutation_form_open( self::SANDBOX_RECONCILE_INTENT, 'faluss-subscriptions-admin__form' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="' . esc_attr( self::SANDBOX_RECONCILE_INTENT ) . '"><input type="hidden" name="return_tab" value="' . esc_attr( self::SANDBOX_TAB ) . '"><label>' . esc_html__( 'Session Checkout test existante', 'faluss-subscriptions' ) . '<input name="checkout_session_reference" type="text" required pattern="cs_[A-Za-z0-9_]+" autocomplete="off"></label>'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html__( 'Réconcilier le Checkout test', 'faluss-subscriptions' ) . '</button></form>';
        echo self::mutation_form_open( 'sandbox_portal', 'faluss-subscriptions-admin__form' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="sandbox_portal"><input type="hidden" name="return_tab" value="' . esc_attr( self::SANDBOX_TAB ) . '"><label>' . esc_html__( 'Faluss ID du Customer existant', 'faluss-subscriptions' ) . '<input name="faluss_id" type="text" required pattern="[a-fA-F0-9-]{36}" autocomplete="off"></label>'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html__( 'Ouvrir le Customer Portal test', 'faluss-subscriptions' ) . '</button></form></section>';
    }

    /** Every mutation has its own explicit admin-post target; it is never nested in the member search form. */
    private static function mutation_form_open( $mutation, $class = '' ) {
        return '<form method="post" action="' . esc_url( self::admin_post_url() ) . '" data-faluss-subscriptions-mutation="' . esc_attr( $mutation ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
    }

    private static function sandbox_checkout_form_open() {
        return '<form method="post" action="' . esc_url( self::admin_post_url() ) . '" data-faluss-subscriptions-mutation="' . esc_attr( self::SANDBOX_CHECKOUT_INTENT ) . '" class="faluss-subscriptions-admin__form">';
    }

    private static function events() {
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Événements fournisseur', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'Les webhooks Stripe signés sont dédoublonnés. Aucun payload brut, secret ou moyen de paiement n’est conservé.', 'faluss-subscriptions' ) . '</p>';
        self::event_table( Faluss_Subscriptions_Repository::events() );
        echo '</section>';
    }
    private static function event_table( $events ) {
        if ( ! $events ) { echo '<p>' . esc_html__( 'Aucun événement.', 'faluss-subscriptions' ) . '</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Fournisseur', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Type', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Tentatives', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Reçu UTC', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Action', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>'; foreach ( $events as $event ) { echo '<tr><td>' . esc_html( $event['provider'] ) . '</td><td>' . esc_html( $event['event_type'] ) . '</td><td>' . esc_html( $event['processing_status'] ) . '</td><td>' . esc_html( $event['attempt_count'] ) . '</td><td>' . esc_html( $event['received_at'] ) . '</td><td>'; if ( 'failed' === ( $event['processing_status'] ?? '' ) ) { echo self::mutation_form_open( 'retry_event' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="retry_event"><input type="hidden" name="return_tab" value="events"><input type="hidden" name="event_id" value="' . esc_attr( $event['id'] ) . '">'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html__( 'Retraiter', 'faluss-subscriptions' ) . '</button></form>'; } else { echo '—'; } echo '</td></tr>'; } echo '</tbody></table>';
    }
    private static function audit() {
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Journal d’audit', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'Les états sont nettoyés : ils n’incluent ni carte, ni payload fournisseur, ni secret.', 'faluss-subscriptions' ) . '</p>';
        $rows = Faluss_Subscriptions_Audit::recent(); if ( ! $rows ) { echo '<p>' . esc_html__( 'Aucune mutation auditée.', 'faluss-subscriptions' ) . '</p>'; } else { echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date UTC', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Action', 'faluss-subscriptions' ) . '</th><th>Faluss ID</th><th>' . esc_html__( 'Source', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Justification', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>'; foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td><code>' . esc_html( $row['action'] ) . '</code></td><td><code>' . esc_html( $row['faluss_id'] ?: '—' ) . '</code></td><td>' . esc_html( $row['source'] ) . '</td><td>' . esc_html( $row['justification'] ?: '—' ) . '</td></tr>'; } echo '</tbody></table>'; } echo '</section>';
    }
    private static function diagnostics() {
        $status = Faluss_Subscriptions_Diagnostics::status();
        $wiring = self::wiring_status( $status );
        echo '<section class="faluss-subscriptions-admin__panel"><h2>' . esc_html__( 'Diagnostics sûrs', 'faluss-subscriptions' ) . '</h2><p>' . esc_html__( 'La vérification ne supprime ni ne répare automatiquement des données existantes.', 'faluss-subscriptions' ) . '</p><dl class="faluss-subscriptions-admin__definition"><dt>' . esc_html__( 'Schéma prêt', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( ! empty( $status['schema']['ready'] ) ? __( 'Oui', 'faluss-subscriptions' ) : __( 'Non', 'faluss-subscriptions' ) ) . '</dd><dt>' . esc_html__( 'Version attendue', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $status['schema']['version'] ) . '</dd><dt>' . esc_html__( 'Version enregistrée', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $status['schema']['stored_version'] ?: '—' ) . '</dd></dl><h3>' . esc_html__( 'Tables vérifiées', 'faluss-subscriptions' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Table', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Moteur', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Colonnes', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'Index', 'faluss-subscriptions' ) . '</th><th>' . esc_html__( 'État', 'faluss-subscriptions' ) . '</th></tr></thead><tbody>';
        foreach ( (array) ( $status['schema']['inspection'] ?? array() ) as $inspection ) {
            echo '<tr><td><code>' . esc_html( $inspection['table'] ?? '' ) . '</code></td><td>' . esc_html( $inspection['engine'] ?: '—' ) . '</td><td>' . esc_html( (string) $inspection['columns_actual'] . '/' . (string) $inspection['columns_expected'] ) . '</td><td>' . esc_html( (string) $inspection['indexes_actual'] . '/' . (string) $inspection['indexes_expected'] ) . '</td><td>' . esc_html( ! empty( $inspection['ready'] ) ? __( 'Conforme', 'faluss-subscriptions' ) : __( 'À vérifier', 'faluss-subscriptions' ) ) . '</td></tr>';
        }
        echo '</tbody></table><h3>' . esc_html__( 'Volumes', 'faluss-subscriptions' ) . '</h3><ul>';
        foreach ( $status['counts'] as $key => $count ) { echo '<li><code>' . esc_html( $key ) . '</code> : ' . esc_html( null === $count ? '—' : (string) $count ) . '</li>'; }
        echo '</ul><h3>' . esc_html__( 'Câblage administratif', 'faluss-subscriptions' ) . '</h3><dl class="faluss-subscriptions-admin__definition"><dt>' . esc_html__( 'Hook de mutation', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $wiring['hook_registered'] ? __( 'Enregistré', 'faluss-subscriptions' ) : __( 'Absent', 'faluss-subscriptions' ) ) . '</dd><dt>' . esc_html__( 'Capacité courante', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $wiring['capability_current'] ? __( 'Présente', 'faluss-subscriptions' ) : __( 'Absente', 'faluss-subscriptions' ) ) . '</dd><dt>admin-post.php</dt><dd><code>' . esc_html( $wiring['endpoint'] ) . '</code></dd><dt>' . esc_html__( 'Formulaires de mutation', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $wiring['separate_forms'] ? __( 'Autonomes', 'faluss-subscriptions' ) : __( 'À vérifier', 'faluss-subscriptions' ) ) . '</dd><dt>' . esc_html__( 'Transaction disponible', 'faluss-subscriptions' ) . '</dt><dd>' . esc_html( $wiring['transactions_available'] ? __( 'Oui', 'faluss-subscriptions' ) : __( 'Non', 'faluss-subscriptions' ) ) . '</dd></dl>';
        echo self::mutation_form_open( 'run_migration' ) . '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '"><input type="hidden" name="faluss_subscriptions_action" value="run_migration"><input type="hidden" name="return_tab" value="diagnostics">'; wp_nonce_field( self::NONCE ); echo '<button type="submit" class="button">' . esc_html__( 'Relancer la vérification de migration', 'faluss-subscriptions' ) . '</button></form></section>';
    }

    private static function wiring_status( $status ) {
        return array(
            'hook_registered' => function_exists( 'has_action' ) && false !== has_action( 'admin_post_' . self::POST_ACTION, array( __CLASS__, 'handle_post' ) ),
            'capability_current' => current_user_can( self::CAPABILITY ),
            'endpoint' => self::admin_post_url(),
            'separate_forms' => true,
            'schema_ready' => ! empty( $status['schema']['ready'] ),
            'transactions_available' => ! empty( $status['transactions_available'] ),
        );
    }

    private static function notice( $notice ) {
        if ( ! is_array( $notice ) || empty( $notice['code'] ) ) { return; }
        $type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'error';
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( self::notice_message( $notice['code'], $notice['context'] ?? array() ) ) . '</p></div>';
    }

    private static function notice_message( $code, $context ) {
        if ( 'pro_granted' === $code ) { return sprintf( __( 'Faluss Max a été attribué jusqu’au %s.', 'faluss-subscriptions' ), self::notice_date( $context['expires_at'] ?? '' ) ); }
        if ( 'stripe_checkout_rejected' === $code ) { return sprintf( __( 'Checkout test a été refusé avant toute modification de droit. Cause sûre : %s. Consultez Audit.', 'faluss-subscriptions' ), self::safe_checkout_rejection_code( $context['cause'] ?? '' ) ); }
        $messages = array(
            'pro_revoked' => __( 'L’attribution Faluss Max a été révoquée.', 'faluss-subscriptions' ),
            'checkout_return_trialing' => __( 'Checkout test terminé. Faluss Max est actuellement en période d’essai.', 'faluss-subscriptions' ),
            'checkout_return_completed' => __( 'Checkout test terminé. L’état définitif sera confirmé par Stripe et apparaîtra dans Membre.', 'faluss-subscriptions' ),
            'checkout_return_cancelled' => __( 'Checkout test a été annulé. Aucun droit n’est déterminé par ce retour navigateur.', 'faluss-subscriptions' ),
            'checkout_return_invalid' => __( 'Le retour Checkout test ne peut pas être vérifié. Aucun droit n’a été modifié.', 'faluss-subscriptions' ),
            'checkout_return_expired' => __( 'Le retour Checkout test a expiré. Aucun droit n’a été modifié.', 'faluss-subscriptions' ),
            'checkout_return_session_invalid' => __( 'Le retour Checkout test ne correspond pas à une session locale valide. Aucun droit n’a été modifié.', 'faluss-subscriptions' ),
            'stripe_checkout_reconciled' => __( 'Le Checkout test a été relu côté Stripe. L’état affiché provient maintenant de la souscription canonique validée.', 'faluss-subscriptions' ),
            'stripe_checkout_reconciliation_failed' => sprintf( __( 'Le Checkout test n’a pas pu être réconcilié. Cause sûre : %s.', 'faluss-subscriptions' ), self::safe_checkout_reconciliation_code( $context['cause'] ?? '' ) ),
            'trial_override_recorded' => __( 'La dérogation d’éligibilité a été enregistrée. Elle n’active pas un essai.', 'faluss-subscriptions' ),
            'migration_verified' => __( 'La migration et les tables Faluss Subscriptions ont été vérifiées.', 'faluss-subscriptions' ),
            'schema_migration_failed' => __( 'La migration n’a pas été appliquée car un schéma incomplet ou divergent existe. Consultez Diagnostics.', 'faluss-subscriptions' ),
            'schema_not_ready' => __( 'La migration des abonnements n’est pas prête. Consultez Diagnostics avant de réessayer.', 'faluss-subscriptions' ),
            'admin_grant_failed' => __( 'L’attribution n’a pas été enregistrée : la justification et les données de la demande doivent être valides.', 'faluss-subscriptions' ),
            'admin_grant_forbidden' => __( 'L’attribution n’a pas été enregistrée : votre compte ne possède pas cette capacité.', 'faluss-subscriptions' ),
            'admin_grant_invalid_nonce' => __( 'L’attribution n’a pas été enregistrée : la confirmation de sécurité a expiré. Rechargez la page.', 'faluss-subscriptions' ),
            'admin_grant_invalid_subject' => __( 'L’attribution n’a pas été enregistrée : le Faluss ID est invalide.', 'faluss-subscriptions' ),
            'admin_grant_invalid_expiration' => __( 'L’attribution n’a pas été enregistrée : choisissez une expiration future valide.', 'faluss-subscriptions' ),
            'admin_grant_persistence_failed' => __( 'L’attribution n’a pas été enregistrée : sa persistance n’a pas pu être vérifiée.', 'faluss-subscriptions' ),
            'admin_grant_resolution_failed' => __( 'L’attribution n’a pas été enregistrée : le droit Faluss Max n’a pas pu être résolu.', 'faluss-subscriptions' ),
            'admin_grant_reference_conflict' => __( 'Cette demande est déjà associée à un autre membre. Rechargez la page avant de réessayer.', 'faluss-subscriptions' ),
            'admin_grant_audit_failed' => __( 'L’attribution n’a pas été enregistrée car son audit n’a pas pu être écrit.', 'faluss-subscriptions' ),
            'admin_grant_revoke_invalid' => __( 'La révocation n’a pas été enregistrée : vérifiez sa justification.', 'faluss-subscriptions' ),
            'admin_grant_missing' => __( 'Cette attribution n’existe plus. Rechargez la page avant de réessayer.', 'faluss-subscriptions' ),
            'admin_grant_revoke_conflict' => __( 'Cette attribution a changé entre-temps. Rechargez la page avant de réessayer.', 'faluss-subscriptions' ),
            'admin_grant_revoke_audit_failed' => __( 'La révocation n’a pas été enregistrée car son audit n’a pas pu être écrit.', 'faluss-subscriptions' ),
            'trial_override_invalid' => __( 'La dérogation n’a pas été enregistrée : vérifiez le Faluss ID et la justification.', 'faluss-subscriptions' ),
            'trial_override_reference_conflict' => __( 'Cette demande de dérogation est déjà associée à un autre membre.', 'faluss-subscriptions' ),
            'trial_override_audit_failed' => __( 'La dérogation n’a pas été enregistrée car son audit n’a pas pu être écrit.', 'faluss-subscriptions' ),
            'trial_already_used' => __( 'La dérogation ne peut pas modifier un essai déjà consommé.', 'faluss-subscriptions' ),
            'sandbox_test_only' => __( 'La sandbox Checkout est disponible uniquement en mode Stripe test.', 'faluss-subscriptions' ),
            'sandbox_checkout_invalid_request' => __( 'Checkout test a été refusé : la requête doit être envoyée depuis la sandbox.', 'faluss-subscriptions' ),
            'sandbox_checkout_forbidden' => __( 'Checkout test a été refusé : votre compte ne possède pas cette capacité.', 'faluss-subscriptions' ),
            'sandbox_checkout_invalid_intent' => __( 'Checkout test a été refusé : l’intention de la demande est invalide. Rechargez la sandbox.', 'faluss-subscriptions' ),
            'sandbox_checkout_invalid_nonce' => __( 'Checkout test a été refusé : la confirmation de sécurité a expiré. Rechargez la sandbox.', 'faluss-subscriptions' ),
            'sandbox_checkout_legacy_route' => __( 'Checkout test a été refusé : rechargez la sandbox pour utiliser son action dédiée.', 'faluss-subscriptions' ),
            'stripe_tax_not_enabled' => __( 'Checkout test reste verrouillé tant que Stripe Tax n’est pas explicitement activé côté serveur.', 'faluss-subscriptions' ),
            'stripe_price_catalogue_mismatch' => __( 'Checkout a été refusé : le Price Stripe ne correspond pas au catalogue Faluss Max TTC attendu.', 'faluss-subscriptions' ),
            'stripe_price_not_configured' => __( 'Checkout test a été refusé : le Price Stripe sélectionné n’est pas configuré côté serveur.', 'faluss-subscriptions' ),
            'stripe_product_not_configured' => __( 'Checkout test a été refusé : le produit Faluss Max n’est pas configuré côté serveur.', 'faluss-subscriptions' ),
            'stripe_price_unavailable' => __( 'Checkout test a été refusé : le Price Stripe ne peut pas être relu. Consultez la configuration et Stripe test.', 'faluss-subscriptions' ),
            'stripe_secret_key_invalid' => __( 'Checkout test a été refusé : la clé Stripe test n’est pas disponible côté serveur.', 'faluss-subscriptions' ),
            'stripe_sdk_collision' => __( 'Checkout test a été refusé : un autre SDK Stripe est déjà chargé.', 'faluss-subscriptions' ),
            'stripe_sdk_missing' => __( 'Checkout test a été refusé : le SDK Stripe livré est introuvable.', 'faluss-subscriptions' ),
            'stripe_sdk_invalid' => __( 'Checkout test a été refusé : le SDK Stripe livré est incomplet.', 'faluss-subscriptions' ),
            'stripe_client_unavailable' => __( 'Checkout test a été refusé : le client Stripe ne peut pas être initialisé.', 'faluss-subscriptions' ),
            'stripe_transport_failed' => __( 'Checkout test a été refusé : Stripe test n’a pas répondu de manière exploitable.', 'faluss-subscriptions' ),
            'checkout_invalid' => __( 'Checkout test a été refusé : le Faluss ID ou la périodicité est invalide.', 'faluss-subscriptions' ),
            'checkout_busy' => __( 'Checkout test est déjà en cours pour ce Faluss ID. Réessayez dans quelques instants.', 'faluss-subscriptions' ),
            'checkout_subscription_exists' => __( 'Checkout test a été refusé : un abonnement actif existe déjà pour ce Faluss ID.', 'faluss-subscriptions' ),
            'checkout_trial_already_used' => __( 'Checkout test a été refusé : l’essai de ce Faluss ID a déjà été consommé.', 'faluss-subscriptions' ),
            'checkout_conflict' => __( 'Checkout test a été refusé : une session concurrente existe déjà. Rechargez la sandbox.', 'faluss-subscriptions' ),
            'checkout_record_failed' => __( 'Checkout test a été refusé : la session locale n’a pas pu être enregistrée.', 'faluss-subscriptions' ),
            'checkout_session_invalid' => __( 'Checkout test a été refusé : Stripe n’a pas fourni de session Checkout valide.', 'faluss-subscriptions' ),
            'stripe_event_retry_requires_provider_delivery' => __( 'Le payload brut n’est pas conservé : demandez une nouvelle livraison Stripe plutôt que de retraiter des données non vérifiables.', 'faluss-subscriptions' ),
        );
        return $messages[ $code ] ?? __( 'L’action n’a pas pu être appliquée. Aucun droit n’a été modifié.', 'faluss-subscriptions' );
    }

    private static function notice_date( $value ) {
        try { return ( new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'd/m/Y H:i' ) . ' ' . __( '(heure du site)', 'faluss-subscriptions' ); }
        catch ( Exception $exception ) { return __( 'la date choisie', 'faluss-subscriptions' ); }
    }

    private static function notice_faluss_id( $notice ) { return is_array( $notice ) && is_array( $notice['context'] ?? null ) && self::valid_faluss_id( $notice['context']['faluss_id'] ?? '' ) ? $notice['context']['faluss_id'] : ''; }
    private static function tab() { return self::valid_tab( self::get_value( 'tab', 32 ) ); }
    private static function valid_tab( $tab ) { if ( 'sandbox' === $tab ) { $tab = self::SANDBOX_TAB; } return in_array( $tab, array( 'configuration', 'catalogue', 'member', 'subscriptions', 'events', self::SANDBOX_TAB, 'audit', 'diagnostics' ), true ) ? $tab : 'configuration'; }
    private static function get_value( $key, $length ) { return isset( $_GET[ $key ] ) ? self::bounded( $_GET[ $key ], $length ) : ''; }
    private static function post_value( $key, $length ) { return isset( $_POST[ $key ] ) ? self::bounded( $_POST[ $key ], $length ) : ''; }
    private static function bounded( $value, $length ) { $value = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : ''; return function_exists( 'mb_substr' ) ? mb_substr( trim( $value ), 0, $length ) : substr( trim( $value ), 0, $length ); }
    private static function valid_faluss_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value ); }
}
