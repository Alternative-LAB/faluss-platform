<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Identity-owned, explicit third-party grants. Never changes first_party or a local role. */
final class Faluss_Identity_Consent {
    const OPTION = 'faluss_identity_consent_schema';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'upgrade_after_update' ), 0 );
        add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
        add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 0 );
    }

    public static function upgrade() {
        if ( current_user_can( 'manage_options' ) ) { self::install(); }
    }

    /** Plugin updates do not invoke activation; prepare before any authorization transaction. */
    public static function upgrade_after_update() {
        if ( '' === (string) get_option( self::OPTION, '' ) ) { self::install(); }
    }

    public static function table() {
        global $wpdb;
        return isset( $wpdb->prefix ) && preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->prefix ) ? $wpdb->prefix . 'faluss_identity_consents' : '';
    }

    public static function ready() {
        return '1' === (string) get_option( self::OPTION, '' ) && self::verify_tables();
    }

    private static function verify_tables() {
        global $wpdb;
        if ( '' === self::table() ) { return false; }
        foreach ( array( '' => array( 'faluss_id', 'client_id', 'configuration_hash', 'scopes', 'granted_at' ), '_clients' => array( 'client_id', 'revision' ) ) as $suffix => $expected ) {
            $table = self::table() . $suffix;
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
            if ( 'INNODB' !== strtoupper( (string) $engine ) ) { return false; }
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
            if ( $expected !== $columns ) { return false; }
            $keys = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name='PRIMARY'", ARRAY_A );
            if ( is_array( $keys ) ) { usort( $keys, static function ( $a, $b ) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; } ); }
            if ( ! is_array( $keys ) || array_column( $keys, 'Column_name' ) !== ( '' === $suffix ? array( 'faluss_id', 'client_id' ) : array( 'client_id' ) ) ) { return false; }
        }
        return true;
    }

    /** Additive opt-in module upgrade, outside authorization transactions. */
    public static function install() {
        if ( self::ready() ) { return true; }
        if ( ! Faluss_Identity_Schema::get_status()['ready'] || '' === self::table() ) { return false; }
        global $wpdb;
        if ( false === $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . self::table() . '_clients` (client_id VARCHAR(191) NOT NULL PRIMARY KEY, revision CHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ) ) { return false; }
        if ( false === $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            faluss_id CHAR(36) NOT NULL, client_id VARCHAR(191) NOT NULL,
            configuration_hash CHAR(64) NOT NULL, scopes VARCHAR(255) NOT NULL,
            granted_at DATETIME NOT NULL,
            PRIMARY KEY (faluss_id, client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' ) ) { return false; }
        if ( ! self::verify_tables() ) { return false; }
        update_option( self::OPTION, '1', false );
        return self::ready();
    }

    /** Bump on every administrator save, even two changes within the same second. */
    public static function revise( $client_id ) {
        global $wpdb;
        return false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO `' . self::table() . '_clients` (client_id,revision) VALUES (%s,%s) ON DUPLICATE KEY UPDATE revision=VALUES(revision)', $client_id, bin2hex( random_bytes( 16 ) ) ) );
    }

    public static function revision( $client_id, $lock = false ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT revision FROM `' . self::table() . '_clients` WHERE client_id=%s' . ( $lock ? ' FOR UPDATE' : '' ), $client_id ) );
        return $wpdb->last_error !== '' ? null : (string) $value;
    }

    public static function fingerprint( $client ) {
        $fields = array();
        foreach ( array( 'client_id', 'client_name', 'client_secret_hash', 'allowed_scopes', 'redirect_uris', 'first_party', 'updated_at', 'consent_version' ) as $key ) {
            $fields[ $key ] = $client[ $key ] ?? null;
        }
        // Permission semantics are part of the grant, not only the OAuth scope names.
        return hash( 'sha256', 'identity-consent-v1:' . wp_json_encode( $fields ) );
    }

    public static function matches( $faluss_id, $request, $lock = false ) {
        if ( ! self::ready() ) { return false; }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT configuration_hash, scopes FROM `' . self::table() . '` WHERE faluss_id=%s AND client_id=%s' . ( $lock ? ' FOR UPDATE' : '' ), $faluss_id, $request['client_id'] ), ARRAY_A );
        return is_array( $row ) && hash_equals( self::fingerprint( $request['client'] ), $row['configuration_hash'] ) && implode( ' ', $request['scopes'] ) === $row['scopes'];
    }

    /** Called only while holding the client row lock in the code-issuance transaction. */
    public static function grant( $faluss_id, $request ) {
        if ( ! self::ready() ) { return false; }
        global $wpdb;
        return false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO `' . self::table() . '` (faluss_id, client_id, configuration_hash, scopes, granted_at) VALUES (%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE configuration_hash=VALUES(configuration_hash), scopes=VALUES(scopes), granted_at=VALUES(granted_at)', $faluss_id, $request['client_id'], self::fingerprint( $request['client'] ), implode( ' ', $request['scopes'] ), gmdate( 'Y-m-d H:i:s' ) ) );
    }

    public static function revoke( $faluss_id, $client_id ) {
        if ( ! self::ready() ) { return false; }
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        try {
            // Same lock order as code issuance; a concurrent reuse cannot resurrect a grant.
            $client = $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM `' . $tables['clients'] . '` WHERE client_id=%s FOR UPDATE', $client_id ) );
            if ( null === $client ) { $wpdb->query( 'ROLLBACK' ); return false; }
            $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . self::table() . '` WHERE faluss_id=%s AND client_id=%s', $faluss_id, $client_id ) );
            $codes = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $tables['auth_codes'] . '` WHERE faluss_id=%s AND client_id=%s AND consumed_at IS NULL', $faluss_id, $client_id ) );
            if ( false === $deleted || false === $codes || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            return true;
        } catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return false; }
    }

    public static function url() { return home_url( '/?faluss_identity_apps=1' ); }

    public static function link() {
        return '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Gérer mes autorisations d’applications', 'faluss-identity' ) . '</a></p>';
    }

    public static function dispatch() {
        if ( ! isset( $_GET['faluss_identity_apps'] ) || '1' !== $_GET['faluss_identity_apps'] ) { return; }
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( add_query_arg( 'redirect_to', self::url(), home_url( '/login' ) ) ); exit;
        }
        $id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $id ) { wp_die( esc_html__( 'Session Identity requise.', 'faluss-identity' ), '', array( 'response' => 403 ) ); }
        $notice = '';
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
            $client = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? wp_unslash( $_POST['client_id'] ) : '';
            $nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
            if ( '' === $client || ! wp_verify_nonce( $nonce, 'faluss_identity_revoke_' . $client ) ) { wp_die( esc_html__( 'Confirmation invalide.', 'faluss-identity' ), '', array( 'response' => 403 ) ); }
            if ( ! self::revoke( $id, $client ) ) { wp_die( esc_html__( 'Révocation indisponible. Réessayez.', 'faluss-identity' ), '', array( 'response' => 503 ) ); }
            $notice = __( 'Autorisation révoquée. Un nouvel accord sera demandé.', 'faluss-identity' );
        }
        global $wpdb;
        $tables = Faluss_Identity_Schema::get_table_names();
        $rows = self::ready() ? $wpdb->get_results( $wpdb->prepare( 'SELECT g.client_id, g.scopes, g.granted_at, c.client_name FROM `' . self::table() . '` g LEFT JOIN `' . $tables['clients'] . '` c ON BINARY c.client_id=BINARY g.client_id WHERE g.faluss_id=%s ORDER BY g.granted_at DESC', $id ), ARRAY_A ) : null;
        if ( $wpdb->last_error !== '' ) { $rows = null; }
        Faluss_Identity_Authorization::register_assets();
        wp_enqueue_style( Faluss_Identity_Authorization::STYLE_HANDLE );
        status_header( is_array( $rows ) ? 200 : 503 );
        get_header();
        ?>
        <main class="faluss-identity-authorization"><section class="faluss-identity-authorization__card" aria-labelledby="identity-apps-title">
        <p class="faluss-identity-authorization__eyebrow">FALUSS IDENTITY</p>
        <h1 id="identity-apps-title"><?php esc_html_e( 'Mes autorisations', 'faluss-identity' ); ?></h1>
        <p><?php esc_html_e( 'Révoquer arrête les prochaines connexions automatiques et invalide les codes non échangés. Les sessions déjà ouvertes dans les applications ne sont pas déconnectées ; leurs données ne sont pas supprimées.', 'faluss-identity' ); ?></p>
        <?php if ( $notice ) : ?><p role="status"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
        <?php if ( ! is_array( $rows ) ) : ?><p><?php esc_html_e( 'Gestion indisponible : la mise à niveau Identity doit être terminée par un administrateur.', 'faluss-identity' ); ?></p>
        <?php elseif ( empty( $rows ) ) : ?><p><?php esc_html_e( 'Aucune autorisation mémorisée.', 'faluss-identity' ); ?></p><?php endif; ?>
        <?php foreach ( is_array( $rows ) ? $rows : array() as $row ) : ?>
            <section><h2><?php echo esc_html( $row['client_name'] ?: __( 'Application indisponible', 'faluss-identity' ) ); ?></h2>
            <p><?php esc_html_e( 'Accord enregistré le', 'faluss-identity' ); ?> <?php echo esc_html( $row['granted_at'] ); ?> UTC.</p>
            <ul><li><?php esc_html_e( 'Votre Faluss ID et l’état publié de votre carte Me', 'faluss-identity' ); ?></li>
            <?php if ( in_array( 'identity.email', explode( ' ', $row['scopes'] ), true ) ) : ?><li><?php esc_html_e( 'Votre adresse e-mail vérifiée', 'faluss-identity' ); ?></li><?php endif; ?></ul>
            <form method="post" action="<?php echo esc_url( self::url() ); ?>"><input type="hidden" name="client_id" value="<?php echo esc_attr( $row['client_id'] ); ?>"><?php wp_nonce_field( 'faluss_identity_revoke_' . $row['client_id'] ); ?><button type="submit"><?php esc_html_e( 'Révoquer cet accord', 'faluss-identity' ); ?></button></form></section>
        <?php endforeach; ?>
        </section></main>
        <?php get_footer(); exit;
    }
}
