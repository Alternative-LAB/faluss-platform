<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Tools-only administrator. It shows public technical metadata and never a private seed. */
final class Faluss_Federation_Admin {
    const PAGE_SLUG = 'faluss-federation';

    public static function boot() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_faluss_federation_manage', array( __CLASS__, 'handle_post' ) );
    }

    public static function menu() {
        add_management_page( __( 'Faluss Federation', 'faluss-federation' ), __( 'Faluss Federation', 'faluss-federation' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
    }

    public static function handle_post() {
        if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'Action refused.', 'faluss-federation' ), 403 );
        }
        $raw_action = $_POST['faluss_federation_action'] ?? '';
        $action = is_string( $raw_action ) ? sanitize_key( wp_unslash( $raw_action ) ) : '';
        if ( 'update_peer_policy' === $action ) {
            check_admin_referer( 'faluss_federation_update_policy' );
        } elseif ( 'test_manifest' === $action ) {
            check_admin_referer( 'faluss_federation_test_manifest' );
        } else {
            check_admin_referer( 'faluss_federation_manage' );
        }
        $result = new WP_Error( 'faluss_federation_action_refused' );
        $manifest_message = null;
        if ( 'register_peer' === $action && isset( $_POST['confirmation'] ) && hash_equals( 'ENREGISTRER LE PAIR FEDERATION', (string) wp_unslash( $_POST['confirmation'] ) ) ) {
            $result = Faluss_Federation_Policy::create_peer( self::peer_input_from_post() );
        } elseif ( 'revoke_peer' === $action && isset( $_POST['confirmation'] ) && hash_equals( 'REVOQUER LA CLE FEDERATION', (string) wp_unslash( $_POST['confirmation'] ) ) ) {
            $result = Faluss_Federation_Policy::revoke_peer( absint( $_POST['peer_id'] ?? 0 ) );
        } elseif ( 'update_peer_policy' === $action && self::update_policy_post_is_exact() && isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] ) && hash_equals( 'METTRE A JOUR LA POLITIQUE FEDERATION', wp_unslash( $_POST['confirmation'] ) ) ) {
            $revision = isset( $_POST['policy_revision'] ) && is_string( $_POST['policy_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['policy_revision'] ) ) : '';
            $result = Faluss_Federation_Policy::update_peer_policy( absint( $_POST['peer_id'] ?? 0 ), $revision, self::policy_input_from_post() );
        } elseif ( 'test_manifest' === $action && self::manifest_test_post_is_exact() ) {
            $peer = Faluss_Federation_Policy::find_outbound_peer_by_id( absint( $_POST['peer_id'] ) );
            if ( ! is_wp_error( $peer ) && in_array( 'manifest.read', $peer['operations'], true ) ) {
                $result = Faluss_Federation_Client::manifest_read( $peer['peer_node_id'], $peer['peer_app_key'], '1.0.0' );
                if ( ! is_wp_error( $result ) ) {
                    $manifest_message = self::manifest_test_message( $result, $peer );
                    if ( is_wp_error( $manifest_message ) ) {
                        $result = $manifest_message;
                    }
                }
            }
        } elseif ( 'diagnostic' === $action ) {
            $result = Faluss_Federation_Client::diagnostic_read( sanitize_key( wp_unslash( $_POST['peer_node_id'] ?? '' ) ), sanitize_key( wp_unslash( $_POST['peer_app_key'] ?? '' ) ) );
        }
        $state = is_wp_error( $result ) ? 'error' : 'ok';
        $message = is_wp_error( $result ) ? 'Action refusée ou indisponible.' : ( is_string( $manifest_message ) ? $manifest_message : ( 'diagnostic' === $action ? 'Diagnostic distant vérifié.' : ( 'update_peer_policy' === $action ? ( 'unchanged' === $result ? 'Politique déjà à jour.' : 'Politique mise à jour.' ) : 'Politique enregistrée.' ) ) );
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'faluss_federation_notice' => $state, 'faluss_federation_message' => rawurlencode( $message ) ), admin_url( 'tools.php' ) ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $identity = Faluss_Federation_Crypto::local_identity();
        $sodium = Faluss_Federation_Crypto::sodium_available();
        $auto_test = $sodium && Faluss_Federation_Crypto::auto_test();
        $schema = Faluss_Federation_Schema::status();
        $ready = Faluss_Federation_Crypto::transport_ready();
        $notice = isset( $_GET['faluss_federation_notice'] ) ? sanitize_key( wp_unslash( $_GET['faluss_federation_notice'] ) ) : '';
        $message = isset( $_GET['faluss_federation_message'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['faluss_federation_message'] ) ) ) : '';
        echo '<div class="wrap"><h1>' . esc_html__( 'Faluss Federation', 'faluss-federation' ) . '</h1>';
        if ( '' !== $notice ) {
            echo '<div class="notice notice-' . ( 'ok' === $notice ? 'success' : 'error' ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }
        echo '<table class="widefat striped"><tbody>';
        self::row( 'Plugin', FALUSS_FEDERATION_VERSION );
        self::row( 'Schéma', $schema['stored_version'] . ' / ' . FALUSS_FEDERATION_SCHEMA_VERSION );
        self::row( 'Schéma vérifié', $schema['ready'] ? 'oui' : 'non' );
        self::row( 'Sodium', $sodium ? 'présent' : 'absent' );
        self::row( 'Auto-test Ed25519', $auto_test ? 'réussi' : 'échoué' );
        self::row( 'État global', $ready ? 'ready' : 'fail_closed' );
        if ( ! $sodium || ! $auto_test ) {
            echo '<tr><td colspan="2"><strong>' . esc_html__( 'Transport indisponible : Sodium absent ou invalide', 'faluss-federation' ) . '</strong></td></tr>';
        }
        if ( is_wp_error( $identity ) ) {
            self::row( 'Configuration locale', 'invalide ou incomplète' );
        } else {
            self::row( 'Nœud local', $identity['node_id'] );
            self::row( 'Application locale', $identity['app_key'] );
            self::row( 'Origine locale', $identity['origin'] );
            self::row( 'key_id local', $identity['key_id'] );
            self::row( 'Clé publique locale', $identity['public_key'] );
            self::row( 'Bundle public', wp_json_encode( array( 'node_id' => $identity['node_id'], 'app_key' => $identity['app_key'], 'origin' => $identity['origin'], 'key_id' => $identity['key_id'], 'public_key' => $identity['public_key'], 'valid_from' => $identity['valid_from'], 'valid_until' => $identity['valid_until'] ), JSON_UNESCAPED_SLASHES ) );
        }
        self::row( 'Pairs', wp_json_encode( Faluss_Federation_Policy::state_counts() ) );
        self::row( 'Audit', wp_json_encode( Faluss_Federation_Schema::audit_counts() ) );
        self::row( 'Opérations disponibles', $ready ? wp_json_encode( Faluss_Federation_Providers::operation_availability() ) : 'aucune' );
        echo '</tbody></table>';
        self::render_peer_form();
        self::render_peer_list();
        echo '</div>';
    }

    private static function row( $name, $value ) {
        echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
    }

    private static function render_peer_form() {
        echo '<h2>Enregistrer ou faire tourner une clé de pair</h2><p>Un nouvel enregistrement actif fait passer l’ancienne clé active du même pair à l’état rotating.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'faluss_federation_manage' );
        echo '<input type="hidden" name="action" value="faluss_federation_manage"><input type="hidden" name="faluss_federation_action" value="register_peer"><table class="form-table"><tbody>';
        self::field( 'peer_node_id', 'Nœud' ); self::field( 'peer_app_key', 'Application' ); self::field( 'canonical_origin', 'Origine HTTPS canonique' ); self::field( 'key_id', 'key_id' ); self::field( 'public_key', 'Clé publique Ed25519 (base64url)' ); self::field( 'valid_from', 'Valide à partir de (UTC)', '', 'datetime-local' ); self::field( 'valid_until', 'Valide jusqu’à (UTC)', '', 'datetime-local' );
        echo '<tr><th>Opérations</th><td>';
        foreach ( Faluss_Federation_Policy::operations() as $operation ) { echo '<label><input type="checkbox" name="operations[]" value="' . esc_attr( $operation ) . '"> ' . esc_html( $operation ) . '</label><br>'; }
        echo '</td></tr><tr><th>Audiences</th><td>';
        foreach ( Faluss_Federation_Policy::audiences() as $audience ) { echo '<label><input type="checkbox" name="audiences[]" value="' . esc_attr( $audience ) . '"> ' . esc_html( $audience ) . '</label><br>'; }
        echo '</td></tr>';
        self::field( 'owner_apps', 'Applications propriétaires (liste séparée par virgules)' ); self::field( 'capabilities', 'Capacités (liste séparée par virgules)' ); self::field( 'confirmation', 'Confirmation exacte' );
        echo '</tbody></table><p><button type="submit" class="button button-primary">Enregistrer le pair</button></p></form>';
    }

    private static function render_peer_list() {
        $peers = Faluss_Federation_Policy::peer_summaries();
        echo '<h2>Pairs enregistrés</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Nœud</th><th>Application</th><th>Origine</th><th>key_id</th><th>État</th><th>Action</th></tr></thead><tbody>';
        foreach ( $peers as $peer ) {
            echo '<tr><td>' . esc_html( (string) $peer['id'] ) . '</td><td>' . esc_html( $peer['peer_node_id'] ) . '</td><td>' . esc_html( $peer['peer_app_key'] ) . '</td><td>' . esc_html( $peer['canonical_origin'] ) . '</td><td>' . esc_html( $peer['key_id'] ) . '</td><td>' . esc_html( $peer['key_state'] ) . '</td><td>';
            if ( in_array( $peer['key_state'], array( 'active', 'rotating' ), true ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'faluss_federation_manage' ); echo '<input type="hidden" name="action" value="faluss_federation_manage"><input type="hidden" name="faluss_federation_action" value="revoke_peer"><input type="hidden" name="peer_id" value="' . esc_attr( $peer['id'] ) . '"><input type="text" name="confirmation" aria-label="Confirmation de révocation"><button type="submit" class="button">Révoquer</button></form>';
                self::render_policy_form( $peer );
                if ( in_array( 'manifest.read', $peer['operations'], true ) ) {
                    self::render_manifest_test_form( $peer );
                }
            }
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'faluss_federation_manage' ); echo '<input type="hidden" name="action" value="faluss_federation_manage"><input type="hidden" name="faluss_federation_action" value="diagnostic"><input type="hidden" name="peer_node_id" value="' . esc_attr( $peer['peer_node_id'] ) . '"><input type="hidden" name="peer_app_key" value="' . esc_attr( $peer['peer_app_key'] ) . '"><button type="submit" class="button">Diagnostic</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_policy_form( $peer ) {
        if ( ! is_array( $peer['operations'] ?? null ) || ! is_array( $peer['owner_apps'] ?? null ) || ! is_array( $peer['capabilities'] ?? null ) || ! is_array( $peer['audiences'] ?? null ) || ! is_string( $peer['policy_revision'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $peer['policy_revision'] ) ) {
            return;
        }
        $peer_id = absint( $peer['id'] );
        echo '<details><summary>Modifier la politique</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'faluss_federation_update_policy' );
        echo '<input type="hidden" name="action" value="faluss_federation_manage"><input type="hidden" name="faluss_federation_action" value="update_peer_policy"><input type="hidden" name="peer_id" value="' . esc_attr( $peer_id ) . '"><input type="hidden" name="policy_revision" value="' . esc_attr( $peer['policy_revision'] ) . '"><fieldset><legend>Opérations</legend>';
        foreach ( Faluss_Federation_Policy::operations() as $operation ) {
            echo '<label><input type="checkbox" name="operations[]" value="' . esc_attr( $operation ) . '"' . ( in_array( $operation, $peer['operations'], true ) ? ' checked' : '' ) . '> ' . esc_html( $operation ) . '</label><br>';
        }
        echo '</fieldset>';
        self::policy_field( $peer_id, 'owner_apps', 'Applications propriétaires', implode( ',', $peer['owner_apps'] ) );
        self::policy_field( $peer_id, 'capabilities', 'Capacités', implode( ',', $peer['capabilities'] ) );
        echo '<fieldset><legend>Audiences</legend>';
        foreach ( Faluss_Federation_Policy::audiences() as $audience ) {
            echo '<label><input type="checkbox" name="audiences[]" value="' . esc_attr( $audience ) . '"' . ( in_array( $audience, $peer['audiences'], true ) ? ' checked' : '' ) . '> ' . esc_html( $audience ) . '</label><br>';
        }
        echo '</fieldset><p>Saisir exactement <code>METTRE A JOUR LA POLITIQUE FEDERATION</code>.</p>';
        self::policy_field( $peer_id, 'confirmation', 'Confirmation exacte', '' );
        echo '<p><button type="submit" class="button button-primary">Mettre à jour la politique</button></p></form></details>';
    }

    private static function policy_field( $peer_id, $name, $label, $value ) {
        $id = 'faluss_federation_' . $name . '_' . $peer_id;
        echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></p>';
    }

    private static function render_manifest_test_form( $peer ) {
        $peer_id = absint( $peer['id'] ?? 0 );
        if ( $peer_id < 1 ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'faluss_federation_test_manifest' );
        echo '<input type="hidden" name="action" value="faluss_federation_manage"><input type="hidden" name="faluss_federation_action" value="test_manifest"><input type="hidden" name="peer_id" value="' . esc_attr( $peer_id ) . '"><button type="submit" class="button">Tester le manifeste</button></form>';
    }

    private static function field( $name, $label, $value = '', $type = 'text' ) {
        echo '<tr><th scope="row"><label for="faluss_federation_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="' . esc_attr( $type ) . '" id="faluss_federation_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
    }

    private static function peer_input_from_post() {
        return array(
            'peer_node_id' => sanitize_key( wp_unslash( $_POST['peer_node_id'] ?? '' ) ),
            'peer_app_key' => sanitize_key( wp_unslash( $_POST['peer_app_key'] ?? '' ) ),
            'canonical_origin' => esc_url_raw( trim( (string) wp_unslash( $_POST['canonical_origin'] ?? '' ) ) ),
            'key_id' => sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) ),
            'public_key' => sanitize_text_field( wp_unslash( $_POST['public_key'] ?? '' ) ),
            'key_state' => 'active',
            'valid_from' => self::utc_datetime( $_POST['valid_from'] ?? '' ),
            'valid_until' => self::utc_datetime( $_POST['valid_until'] ?? '' ),
            'operations' => self::closed_values_from_post( 'operations', Faluss_Federation_Policy::operations() ),
            'owner_apps' => self::csv_values_from_post( 'owner_apps' ),
            'capabilities' => self::csv_values_from_post( 'capabilities' ),
            'audiences' => self::closed_values_from_post( 'audiences', Faluss_Federation_Policy::audiences() ),
        );
    }

    private static function update_policy_post_is_exact() {
        $allowed = array( 'action', '_wpnonce', '_wp_http_referer', 'faluss_federation_action', 'peer_id', 'policy_revision', 'operations', 'owner_apps', 'capabilities', 'audiences', 'confirmation' );
        $required = array( 'action', '_wpnonce', 'faluss_federation_action', 'peer_id', 'policy_revision', 'operations', 'owner_apps', 'capabilities', 'confirmation' );
        $keys = array_keys( $_POST );
        if ( array_diff( $keys, $allowed ) || array_diff( $required, $keys ) ) {
            return false;
        }
        foreach ( array( 'action', '_wpnonce', 'faluss_federation_action', 'peer_id', 'policy_revision', 'owner_apps', 'capabilities', 'confirmation' ) as $name ) {
            if ( ! is_string( $_POST[ $name ] ) ) {
                return false;
            }
        }
        if ( isset( $_POST['_wp_http_referer'] ) && ! is_string( $_POST['_wp_http_referer'] ) ) {
            return false;
        }
        return 'faluss_federation_manage' === wp_unslash( $_POST['action'] )
            && 'update_peer_policy' === wp_unslash( $_POST['faluss_federation_action'] )
            && 1 === preg_match( '/^[1-9][0-9]*$/D', wp_unslash( $_POST['peer_id'] ) )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', wp_unslash( $_POST['policy_revision'] ) )
            && is_array( $_POST['operations'] )
            && ( ! isset( $_POST['audiences'] ) || is_array( $_POST['audiences'] ) );
    }

    private static function manifest_test_post_is_exact() {
        $allowed = array( 'action', '_wpnonce', '_wp_http_referer', 'faluss_federation_action', 'peer_id' );
        $required = array( 'action', '_wpnonce', 'faluss_federation_action', 'peer_id' );
        $keys = array_keys( $_POST );
        if ( array_diff( $keys, $allowed ) || array_diff( $required, $keys ) ) {
            return false;
        }
        foreach ( $required as $name ) {
            if ( ! is_string( $_POST[ $name ] ) ) {
                return false;
            }
        }
        return ( ! isset( $_POST['_wp_http_referer'] ) || is_string( $_POST['_wp_http_referer'] ) )
            && 'faluss_federation_manage' === wp_unslash( $_POST['action'] )
            && 'test_manifest' === wp_unslash( $_POST['faluss_federation_action'] )
            && 1 === preg_match( '/^[1-9][0-9]*$/D', wp_unslash( $_POST['peer_id'] ) );
    }

    private static function manifest_test_message( $result, $peer ) {
        $contract = $result['payload_contract'] ?? null;
        $manifest = $result['payload'] ?? null;
        $contract_keys = array( 'document_type', 'contract_version' );
        if ( ! is_array( $result ) || 'success' !== ( $result['status'] ?? null ) || ! is_array( $contract ) || array_diff( $contract_keys, array_keys( $contract ) ) || array_diff( array_keys( $contract ), $contract_keys ) || 'faluss.app-capability-manifest' !== $contract['document_type'] || '1.0.0' !== $contract['contract_version'] || ! is_array( $manifest ) || ( $peer['peer_app_key'] ?? null ) !== ( $manifest['app_key'] ?? null ) || '1.0.0' !== ( $manifest['manifest_version'] ?? null ) || ! in_array( $manifest['product_state'] ?? null, array( 'planned', 'active', 'maintenance', 'retired' ), true ) || ! is_array( $manifest['capabilities'] ?? null ) || count( $manifest['capabilities'] ) > 64 ) {
            return new WP_Error( 'faluss_federation_manifest_test_refused' );
        }
        return sprintf( 'Manifeste distant vérifié. app_key : %s ; manifest_version : %s ; product_state : %s ; capacités : %d.', $manifest['app_key'], $manifest['manifest_version'], $manifest['product_state'], count( $manifest['capabilities'] ) );
    }

    private static function policy_input_from_post() {
        return array(
            'operations' => self::policy_values_from_post( 'operations', false ),
            'owner_apps' => self::policy_values_from_post( 'owner_apps', true ),
            'capabilities' => self::policy_values_from_post( 'capabilities', true ),
            'audiences' => self::policy_values_from_post( 'audiences', false ),
        );
    }

    private static function policy_values_from_post( $name, $csv ) {
        if ( ! array_key_exists( $name, $_POST ) ) {
            return array();
        }
        $raw = wp_unslash( $_POST[ $name ] );
        if ( $csv ) {
            if ( ! is_string( $raw ) ) {
                return array( $raw );
            }
            return '' === trim( $raw ) ? array() : array_map( 'trim', explode( ',', $raw ) );
        }
        if ( ! is_array( $raw ) ) {
            return $raw;
        }
        return array_map( static function ( $value ) { return is_string( $value ) ? trim( $value ) : $value; }, $raw );
    }

    private static function closed_values_from_post( $name, $allowed ) {
        $values = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $name ] ) ) : array();
        return array_values( array_intersect( $values, $allowed ) );
    }

    private static function csv_values_from_post( $name ) {
        $raw = isset( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : '';
        if ( '' === trim( $raw ) ) { return array(); }
        return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $raw ) ) ), 'strlen' ) );
    }

    private static function utc_datetime( $value ) {
        $timestamp = strtotime( (string) wp_unslash( $value ) );
        return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
    }
}
