<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

function sub01a_persistence_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function __( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_time( $type, $gmt = false ) { return time(); }
function get_option( $key, $default = false ) { return 'faluss_subscriptions_schema_version' === $key ? '2' : $default; }
function wp_generate_uuid4() { static $counter = 0; ++$counter; return sprintf( '00000000-0000-4000-8000-%012d', $counter ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_cache_delete( $key, $group ) { global $sub01a_cache_deletes; $sub01a_cache_deletes[] = array( $group, $key ); return true; }
function set_transient( $key, $value, $ttl ) { global $sub01a_transients; $sub01a_transients[ $key ] = $value; return $ttl > 0; }
function get_transient( $key ) { global $sub01a_transients; return $sub01a_transients[ $key ] ?? false; }
function delete_transient( $key ) { global $sub01a_transients; unset( $sub01a_transients[ $key] ); return true; }

final class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

final class Faluss_Subscriptions_Test_Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $schema = array();
    public $rows = array( 'subscriptions' => array(), 'entitlements' => array(), 'trials' => array(), 'events' => array(), 'audit' => array(), 'customers' => array(), 'checkout_sessions' => array(), 'notifications' => array() );
    public $queries = array();
    private $next_id = array( 'subscriptions' => 1, 'entitlements' => 1, 'trials' => 1, 'events' => 1, 'audit' => 1, 'customers' => 1, 'checkout_sessions' => 1, 'notifications' => 1 );

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare( $query, ...$args ) {
        foreach ( $args as $argument ) {
            $replacement = is_int( $argument ) ? (string) $argument : "'" . str_replace( "'", "\\'", (string) $argument ) . "'";
            $query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }
        return $query;
    }
    public function query( $query ) { $this->queries[] = $query; return 0; }
    public function get_var( $query ) {
        if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) { return 1; }
        if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) { return $this->table_key( $matches[1] ) ? $matches[1] : null; }
        return null;
    }
    public function get_row( $query, $output = ARRAY_A ) {
        if ( preg_match( "/SHOW TABLE STATUS LIKE '([^']+)'/", $query, $matches ) ) { return $this->table_key( $matches[1] ) ? array( 'Engine' => 'InnoDB' ) : null; }
        $key = $this->query_table_key( $query );
        if ( ! $key || ! isset( $this->rows[ $key ] ) ) { return null; }
        $row = null;
        if ( preg_match( "/source_reference='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'source_reference', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/admin_override_reference='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'admin_override_reference', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/return_state_hash='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'return_state_hash', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/checkout_uuid='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'checkout_uuid', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/provider_session_reference='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'provider_session_reference', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/provider_subscription_reference='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'provider_subscription_reference', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/provider_customer_reference='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'provider_customer_reference', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/payment_fingerprint_hash='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'payment_fingerprint_hash', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( "/faluss_id='([^']+)'/", $query, $matches ) ) { $row = $this->first( $key, 'faluss_id', stripslashes( $matches[1] ) ); }
        elseif ( preg_match( '/id=(\\d+)/', $query, $matches ) ) { $row = $this->first( $key, 'id', (int) $matches[1] ); }
        if ( $row && false !== strpos( $query, "source='admin_grant'" ) && 'admin_grant' !== ( $row['source'] ?? '' ) ) { return null; }
        return $row;
    }
    public function get_results( $query, $output = ARRAY_A ) {
        if ( preg_match( '/SHOW FULL COLUMNS FROM `([^`]+)`/', $query, $matches ) ) {
            $key = $this->table_key( $matches[1] );
            $result = array();
            foreach ( $this->schema[ $key ]['columns'] as $name => $definition ) {
                $result[] = array( 'Field' => $name, 'Type' => preg_replace( '/\\b(tinyint|smallint|mediumint|int|bigint)\\(\\d+\\)/', '$1', $definition[0] ), 'Null' => $definition[1] );
            }
            return $result;
        }
        if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $query, $matches ) ) {
            $key = $this->table_key( $matches[1] );
            $result = array();
            foreach ( $this->schema[ $key ]['indexes'] as $name => $definition ) {
                foreach ( $definition['columns'] as $position => $column ) {
                    $result[] = array( 'Key_name' => $name, 'Seq_in_index' => $position + 1, 'Non_unique' => $definition['unique'] ? 0 : 1, 'Column_name' => $column );
                }
            }
            return $result;
        }
        $key = $this->query_table_key( $query );
        if ( ! $key || ! isset( $this->rows[ $key ] ) ) { return array(); }
        $rows = $this->rows[ $key ];
        if ( preg_match( "/faluss_id='([^']+)'/", $query, $matches ) ) { $rows = array_values( array_filter( $rows, static function( $row ) use ( $matches ) { return ( $row['faluss_id'] ?? '' ) === stripslashes( $matches[1] ); } ) ); }
        return $rows;
    }
    public function insert( $table, $data, $formats = null ) {
        $key = $this->table_key( $table );
        if ( ! $key || ! isset( $this->rows[ $key ] ) ) { return false; }
        foreach ( $this->rows[ $key ] as $row ) {
            if ( 'entitlements' === $key && ( $row['source_reference'] ?? '' ) === ( $data['source_reference'] ?? '' ) ) { return false; }
            if ( 'trials' === $key && ( ( $row['faluss_id'] ?? '' ) === ( $data['faluss_id'] ?? '' ) || ( ! empty( $data['admin_override_reference'] ) && ( $row['admin_override_reference'] ?? '' ) === $data['admin_override_reference'] ) ) ) { return false; }
            if ( 'subscriptions' === $key && ( ( $row['provider'] ?? '' ) === ( $data['provider'] ?? '' ) && ( $row['provider_subscription_reference'] ?? '' ) === ( $data['provider_subscription_reference'] ?? '' ) ) ) { return false; }
        }
        $data['id'] = $this->next_id[ $key ]++;
        $this->insert_id = $data['id'];
        $this->rows[ $key ][] = $data;
        return 1;
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $key = $this->table_key( $table );
        if ( ! $key || ! isset( $this->rows[ $key ] ) ) { return false; }
        foreach ( $this->rows[ $key ] as $index => $row ) {
            foreach ( $where as $column => $value ) { if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) { continue 2; } }
            $this->rows[ $key ][ $index ] = array_merge( $row, $data );
            return 1;
        }
        return 0;
    }
    private function table_key( $table ) {
        foreach ( array( 'checkout_sessions' => 'faluss_billing_checkout_sessions', 'customers' => 'faluss_billing_customers', 'notifications' => 'faluss_subscription_notifications', 'subscriptions' => 'faluss_subscriptions', 'trials' => 'faluss_subscription_trials', 'entitlements' => 'faluss_entitlements', 'events' => 'faluss_subscription_events', 'audit' => 'faluss_subscription_audit' ) as $key => $suffix ) {
            if ( false !== strpos( $table, $suffix ) ) { return $key; }
        }
        return null;
    }
    private function query_table_key( $query ) { return $this->table_key( $query ); }
    private function first( $key, $column, $value ) { foreach ( $this->rows[ $key ] as $row ) { if ( (string) ( $row[ $column ] ?? '' ) === (string) $value ) { return $row; } } return null; }
}

$root = dirname( __DIR__, 2 );
$plugin = $root . '/src/Subscriptions/Legacy';
$wpdb = new Faluss_Subscriptions_Test_Wpdb();
$sub01a_cache_deletes = array();
$sub01a_transients = array();
require_once $plugin . '/includes/class-faluss-subscriptions-schema.php';
require_once $plugin . '/includes/class-faluss-subscriptions-catalog.php';
require_once $plugin . '/includes/class-faluss-subscriptions-audit.php';
require_once $plugin . '/includes/class-faluss-subscriptions-repository.php';
require_once $plugin . '/includes/class-faluss-subscriptions-trials.php';
require_once $plugin . '/includes/class-faluss-subscriptions-entitlements.php';
require_once $plugin . '/includes/class-faluss-subscriptions-resolver.php';
require_once $plugin . '/includes/class-faluss-subscriptions-admin-notices.php';
$wpdb->schema = Faluss_Subscriptions_Schema::get_expected_schema();

sub01a_persistence_assert( Faluss_Subscriptions_Schema::is_ready(), 'A valid MySQL 8-style schema without integer display widths must be accepted.' );
$faluss_id = '11111111-1111-4111-8111-111111111111';
$expires_local = gmdate( 'Y-m-d\\TH:i', time() + DAY_IN_SECONDS );
$grant = Faluss_Subscriptions_Entitlements::grant_temporary_pro( $faluss_id, $expires_local, 'Recette de persistance', 77, '22222222-2222-4222-8222-222222222222' );
sub01a_persistence_assert( is_array( $grant ) && 'active' === $grant['status'] && 'admin_grant' === $grant['source'], 'A valid temporary Pro grant must persist an active admin entitlement.' );
sub01a_persistence_assert( 1 === count( $wpdb->rows['entitlements'] ) && 1 === count( $wpdb->rows['audit'] ), 'A grant must persist exactly one entitlement and one audit record in its transaction.' );
$decision = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
sub01a_persistence_assert( 'pro' === $decision['level'] && 'comped' === $decision['state'], 'The resolver must immediately reread the persisted grant as comped Pro.' );
sub01a_persistence_assert( in_array( array( 'faluss_subscriptions', 'decision:' . $faluss_id ), $sub01a_cache_deletes, true ), 'A changed entitlement must invalidate its central decision cache key.' );
$revoked = Faluss_Subscriptions_Entitlements::revoke_admin_grant( $grant['id'], 'Fin de recette', 77 );
sub01a_persistence_assert( is_array( $revoked ) && 'revoked' === $revoked['status'] && 2 === count( $wpdb->rows['audit'] ), 'A revocation must persist, reread and audit the revoked state.' );
$after_revoke = Faluss_Subscriptions_Resolver::resolve_for_faluss_id( $faluss_id );
sub01a_persistence_assert( 'free' === $after_revoke['level'], 'A revoked admin grant must resolve to Free immediately.' );
$invalid = Faluss_Subscriptions_Entitlements::grant_temporary_pro( $faluss_id, gmdate( 'Y-m-d\\TH:i', time() - HOUR_IN_SECONDS ), 'Date passée', 77, '33333333-3333-4333-8333-333333333333' );
sub01a_persistence_assert( is_wp_error( $invalid ) && 'admin_grant_invalid_expiration' === $invalid->get_error_code() && 1 === count( $wpdb->rows['entitlements'] ), 'An invalid expiration must fail without a partial entitlement.' );
$override = Faluss_Subscriptions_Trials::set_eligibility_override( $faluss_id, 'Exception validée', 77, '44444444-4444-4444-8444-444444444444' );
sub01a_persistence_assert( is_array( $override ) && 'admin_override' === $override['eligibility_status'] && 3 === count( $wpdb->rows['audit'] ), 'A trial override must persist and audit before it is reported as saved.' );
sub01a_persistence_assert( false !== strpos( implode( "\n", $wpdb->queries ), 'START TRANSACTION' ) && false !== strpos( implode( "\n", $wpdb->queries ), 'COMMIT' ), 'Administrative mutations must use transaction boundaries.' );
Faluss_Subscriptions_Admin_Notices::add( 77, 'success', 'pro_granted', array( 'faluss_id' => $faluss_id ) );
sub01a_persistence_assert( is_array( Faluss_Subscriptions_Admin_Notices::consume( 77 ) ) && null === Faluss_Subscriptions_Admin_Notices::consume( 77 ), 'PRG notices must be per-user and consumed exactly once.' );

echo "SUB-01A persistence contract: OK\n";
