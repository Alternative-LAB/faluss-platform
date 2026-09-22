<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Minimal administrator-only registry for FI-04 SSO clients. */
final class Faluss_Identity_SSO_Clients_Admin {

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_faluss_identity_save_sso_client', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_faluss_identity_rotate_sso_client_secret', array( __CLASS__, 'rotate_secret' ) );
    }

    public static function menu() {
        add_options_page( __( 'Clients SSO Faluss', 'faluss-identity' ), __( 'Clients SSO Faluss', 'faluss-identity' ), 'manage_options', 'faluss-identity-sso-clients', array( __CLASS__, 'render' ) );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'faluss_identity_save_sso_client' ) ) { wp_die( esc_html__( 'Accès refusé.', 'faluss-identity' ) ); }
        if ( ! Faluss_Identity_Schema::get_status()['ready'] ) { self::redirect( 'schema' ); }
        $client_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $name = isset( $_POST['client_name'] ) && is_string( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
        $status = isset( $_POST['status'] ) && 'inactive' === sanitize_key( wp_unslash( $_POST['status'] ) ) ? 'inactive' : 'active';
        $uris = self::parse_uris( isset( $_POST['redirect_uris'] ) && is_string( $_POST['redirect_uris'] ) ? wp_unslash( $_POST['redirect_uris'] ) : '' );
        $scopes = self::posted_scopes();
        if ( '' === $name || strlen( $name ) > 191 || empty( $uris ) || null === $scopes ) { self::redirect( 'invalid' ); }
        global $wpdb; $tables = Faluss_Identity_Schema::get_table_names();
        if ( empty( $tables['clients'] ) ) { self::redirect( 'schema' ); }
        $first_party = self::first_party_requested( $uris ) ? 1 : 0;
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( '' !== $client_id ) {
            $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['clients'] ) . ' SET client_name = %s, status = %s, allowed_scopes = %s, redirect_uris = %s, first_party = %d, updated_at = %s WHERE client_id = %s', $name, $status, implode( ' ', $scopes ), wp_json_encode( $uris ), $first_party, $now, $client_id ) );
            self::redirect( false === $updated ? 'error' : 'saved' );
        }
        try { $client_id = 'faluss_' . bin2hex( random_bytes( 16 ) ); } catch ( Exception $exception ) { self::redirect( 'error' ); }
        $confidential = ! empty( $_POST['confidential'] );
        $secret = $confidential ? self::new_secret() : null;
        $hash = null === $secret ? null : Faluss_Identity_Authorization::hash_client_secret( $secret );
        if ( $confidential && null === $hash ) { self::redirect( 'error' ); }
        $inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $tables['clients'] ) . ' (client_id, client_name, status, client_secret_hash, allowed_scopes, redirect_uris, first_party, created_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %s)', $client_id, $name, $status, $hash, implode( ' ', $scopes ), wp_json_encode( $uris ), $first_party, $now ) );
        if ( 1 !== $inserted ) { self::redirect( 'error' ); }
        // Render in this response: no option, transient, log, or redirect ever holds the secret.
        self::render_secret_confirmation( array( 'client_id' => $client_id, 'secret' => $secret ) );
    }

    public static function rotate_secret() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'faluss_identity_rotate_sso_client_secret' ) ) { wp_die( esc_html__( 'Accès refusé.', 'faluss-identity' ) ); }
        $client_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        if ( '' === $client_id ) { self::redirect( 'invalid' ); }
        $secret = self::new_secret(); $hash = Faluss_Identity_Authorization::hash_client_secret( $secret );
        global $wpdb; $tables = Faluss_Identity_Schema::get_table_names();
        $updated = null === $hash || empty( $tables['clients'] ) ? false : $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $tables['clients'] ) . ' SET client_secret_hash = %s, updated_at = %s WHERE client_id = %s', $hash, gmdate( 'Y-m-d H:i:s' ), $client_id ) );
        if ( 1 !== $updated ) { self::redirect( 'error' ); }
        self::render_secret_confirmation( array( 'client_id' => $client_id, 'secret' => $secret ) );
    }

    /** @param array<string, string>|null $secret_notice */
    public static function render( $secret_notice = null ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb; $tables = Faluss_Identity_Schema::get_table_names();
        $clients = empty( $tables['clients'] ) ? array() : $wpdb->get_results( 'SELECT client_id, client_name, status, client_secret_hash, allowed_scopes, redirect_uris, first_party FROM ' . self::quote_identifier( $tables['clients'] ) . ' ORDER BY client_name ASC', ARRAY_A );
        $notice = isset( $_GET['faluss_identity_sso_notice'] ) ? sanitize_key( wp_unslash( $_GET['faluss_identity_sso_notice'] ) ) : '';
        ?>
        <div class="wrap"><h1><?php esc_html_e( 'Clients SSO Faluss', 'faluss-identity' ); ?></h1>
        <p><?php esc_html_e( 'Déclarez uniquement des URI de retour HTTPS exactes. Le secret des clients confidentiels est affiché une seule fois.', 'faluss-identity' ); ?></p>
        <?php if ( is_array( $secret_notice ) && ! empty( $secret_notice['secret'] ) ) : ?><div class="notice notice-warning"><p><strong><?php esc_html_e( 'Copiez ce secret maintenant :', 'faluss-identity' ); ?></strong> <code><?php echo esc_html( $secret_notice['secret'] ); ?></code></p><p><?php esc_html_e( 'Il ne sera plus affiché et seule son empreinte est conservée.', 'faluss-identity' ); ?></p></div><?php elseif ( isset( array( 'saved' => true, 'invalid' => true, 'error' => true, 'schema' => true )[ $notice ] ) ) : ?><div class="notice <?php echo 'saved' === $notice ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( 'saved' === $notice ? __( 'Client enregistré.', 'faluss-identity' ) : __( 'Le client n’a pas pu être enregistré.', 'faluss-identity' ) ); ?></p></div><?php endif; ?>
        <h2><?php esc_html_e( 'Nouveau client', 'faluss-identity' ); ?></h2><?php self::form(); ?>
        <h2><?php esc_html_e( 'Clients déclarés', 'faluss-identity' ); ?></h2>
        <?php foreach ( is_array( $clients ) ? $clients : array() as $client ) : ?><div class="card" style="max-width:760px;margin:16px 0;padding:16px"><p><code><?php echo esc_html( $client['client_id'] ); ?></code></p><?php self::form( $client ); ?><?php if ( ! empty( $client['client_secret_hash'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="faluss_identity_rotate_sso_client_secret"><input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>"><?php wp_nonce_field( 'faluss_identity_rotate_sso_client_secret' ); ?><button class="button" type="submit"><?php esc_html_e( 'Générer un nouveau secret', 'faluss-identity' ); ?></button></form><?php endif; ?></div><?php endforeach; ?>
        </div><?php
    }

    /**
     * admin-post.php does not load the visual WordPress administration shell.
     * Keep the raw secret in this direct response only, but render that response
     * through the normal header/menu/styles/footer used by the Settings screen.
     *
     * @param array<string, string> $secret_notice
     */
    private static function render_secret_confirmation( $secret_notice ) {
        global $parent_file, $submenu_file, $title;
        $parent_file = 'options-general.php';
        $submenu_file = 'faluss-identity-sso-clients';
        $title = __( 'Clients SSO Faluss', 'faluss-identity' );
        require_once ABSPATH . 'wp-admin/admin-header.php';
        self::render( $secret_notice );
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }

    private static function form( $client = null ) {
        $is_existing = is_array( $client ); $scopes = $is_existing ? Faluss_Identity_Authorization::normalize_scopes( $client['allowed_scopes'] ) : array( Faluss_Identity_Authorization::SCOPE_BASIC ); $uris = $is_existing ? json_decode( $client['redirect_uris'], true ) : array();
        ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="faluss_identity_save_sso_client"><input type="hidden" name="client_id" value="<?php echo $is_existing ? esc_attr( $client['client_id'] ) : ''; ?>"><?php wp_nonce_field( 'faluss_identity_save_sso_client' ); ?><p><label><?php esc_html_e( 'Nom', 'faluss-identity' ); ?><br><input class="regular-text" required maxlength="191" name="client_name" value="<?php echo $is_existing ? esc_attr( $client['client_name'] ) : ''; ?>"></label></p><p><label><?php esc_html_e( 'État', 'faluss-identity' ); ?><br><select name="status"><option value="active" <?php selected( ! $is_existing || 'active' === $client['status'] ); ?>><?php esc_html_e( 'Actif', 'faluss-identity' ); ?></option><option value="inactive" <?php selected( $is_existing && 'inactive' === $client['status'] ); ?>><?php esc_html_e( 'Inactif', 'faluss-identity' ); ?></option></select></label></p><p><label><?php esc_html_e( 'URI de retour HTTPS, une par ligne', 'faluss-identity' ); ?><br><textarea class="large-text" rows="4" required name="redirect_uris"><?php echo esc_textarea( is_array( $uris ) ? implode( "\n", $uris ) : '' ); ?></textarea></label></p><p><label><input type="checkbox" checked disabled> identity.basic</label> <input type="hidden" name="scopes[]" value="identity.basic"><br><label><input type="checkbox" name="scopes[]" value="identity.email" <?php checked( in_array( Faluss_Identity_Authorization::SCOPE_EMAIL, (array) $scopes, true ) ); ?>> identity.email</label></p><p><label><input type="checkbox" name="first_party" value="1" <?php checked( $is_existing && ! empty( $client['first_party'] ) ); ?>> <?php esc_html_e( 'Client officiel Faluss.com : autoriser automatiquement une session Identity existante', 'faluss-identity' ); ?></label><br><span class="description"><?php esc_html_e( 'Disponible uniquement si l’URI exacte https://faluss.com/faluss-identity/callback est la seule URI déclarée. Tous les autres clients conservent le consentement.', 'faluss-identity' ); ?></span></p><?php if ( ! $is_existing ) : ?><p><label><input type="checkbox" name="confidential" value="1"> <?php esc_html_e( 'Client confidentiel : générer un secret', 'faluss-identity' ); ?></label></p><?php endif; ?><p><button class="button button-primary" type="submit"><?php echo esc_html( $is_existing ? __( 'Enregistrer', 'faluss-identity' ) : __( 'Créer le client', 'faluss-identity' ) ); ?></button></p></form><?php
    }

    private static function parse_uris( $value ) { $uris = preg_split( '/\r\n|\r|\n/', trim( $value ) ); if ( ! is_array( $uris ) || empty( $uris ) ) { return array(); } $out = array(); foreach ( $uris as $uri ) { $uri = trim( $uri ); if ( ! Faluss_Identity_Authorization::valid_redirect_uri( $uri ) || in_array( $uri, $out, true ) ) { return array(); } $out[] = $uri; } return $out; }
    private static function posted_scopes() {
        $raw = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? wp_unslash( $_POST['scopes'] ) : array();
        $scopes = array();
        foreach ( $raw as $scope ) {
            if ( ! is_string( $scope ) || ! in_array( $scope, array( Faluss_Identity_Authorization::SCOPE_BASIC, Faluss_Identity_Authorization::SCOPE_EMAIL ), true ) ) {
                return null;
            }
            $scopes[] = $scope;
        }
        return Faluss_Identity_Authorization::normalize_scopes( implode( ' ', $scopes ) );
    }
    private static function first_party_requested( $uris ) {
        return ! empty( $_POST['first_party'] )
            && array( Faluss_Identity_Authorization::FIRST_PARTY_FALUSS_COM_CALLBACK ) === array_values( $uris );
    }
    private static function new_secret() { try { return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); } catch ( Exception $exception ) { return null; } }
    private static function redirect( $notice ) { wp_safe_redirect( add_query_arg( 'faluss_identity_sso_notice', sanitize_key( $notice ), admin_url( 'options-general.php?page=faluss-identity-sso-clients' ) ) ); exit; }
    private static function quote_identifier( $identifier ) { return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 ); }
}
