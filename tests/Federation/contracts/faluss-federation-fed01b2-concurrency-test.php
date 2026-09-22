<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }

$fed01b2_events = array();
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        global $fed01b2_events;
        $fed01b2_events[] = 'purge-probe';
        return $default;
    }
}

$fed01b2_assertions = 0;
function fed01b2_assert( $condition, $message ) {
    global $fed01b2_assertions;
    $fed01b2_assertions++;
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fed01b2_private( $class, $method, $arguments ) {
    $reflection = new ReflectionMethod( $class, $method );
    $reflection->setAccessible( true );
    return $reflection->invokeArgs( null, $arguments );
}

function fed01b2_error_code( $value ) {
    return is_wp_error( $value ) ? $value->get_error_code() : null;
}

$root = getenv( 'FALUSS_FEDERATION_SOURCE_ROOT' );
$root = is_string( $root ) && '' !== $root ? rtrim( $root, '/\\' ) : dirname( __DIR__, 3 );
$includes = $root . '/src/Federation/Legacy/includes/';
require_once $includes . 'class-faluss-federation-schema.php';
fed01b2_assert( method_exists( 'Faluss_Federation_Schema', 'rate_lock_name' ), 'FED-01B.2 blocker: production rate-bucket lock is missing.' );
fed01b2_assert( method_exists( 'Faluss_Federation_Schema', 'consume_locked_transaction' ), 'FED-01B.2 blocker: production locked transaction core is missing.' );

if ( ! class_exists( 'Faluss_Federation_Providers' ) ) {
    final class Faluss_Federation_Providers {
        public static $dispatches = 0;
        public static function dispatch() {
            self::$dispatches++;
            return array( 'status' => 'empty', 'payload_contract' => null, 'payload' => array(), 'error' => null );
        }
    }
}
require_once $includes . 'class-faluss-federation-server.php';
fed01b2_assert( class_exists( 'Fiber' ), 'Concurrent harness requires PHP Fiber support.' );

/* Production lock-name derivation. */
$tuple = array( 'wp_', 'hub-node', 'hub-key-0001', 'diagnostic.read' );
$lock_name = fed01b2_private( 'Faluss_Federation_Schema', 'rate_lock_name', $tuple );
fed01b2_assert( is_string( $lock_name ) && $lock_name === fed01b2_private( 'Faluss_Federation_Schema', 'rate_lock_name', $tuple ), 'Same tuple must produce a deterministic lock name.' );
foreach ( array(
    array( 'wp_other_', 'hub-node', 'hub-key-0001', 'diagnostic.read' ),
    array( 'wp_', 'me-node', 'hub-key-0001', 'diagnostic.read' ),
    array( 'wp_', 'hub-node', 'hub-key-0002', 'diagnostic.read' ),
    array( 'wp_', 'hub-node', 'hub-key-0001', 'manifest.read' ),
) as $other_tuple ) {
    fed01b2_assert( $lock_name !== fed01b2_private( 'Faluss_Federation_Schema', 'rate_lock_name', $other_tuple ), 'Every prefix/sender/key/operation dimension must isolate its bucket.' );
}
foreach ( $tuple as $raw_component ) {
    fed01b2_assert( false === strpos( $lock_name, $raw_component ), 'Lock name must expose no raw tuple component.' );
}
fed01b2_assert( 64 === strlen( $lock_name ), 'Lock name must stay within the 64-character MariaDB limit.' );

final class FED01B2_Query {
    public $sql;
    public $args;
    public function __construct( $sql, $args ) { $this->sql = $sql; $this->args = $args; }
}

final class FED01B2_Lock_Manager {
    public $locks = array();
    public $acquire_attempts = 0;
    public $release_attempts = 0;
    public $waits = 0;
    public $max_active = 0;

    public function acquire( $name, $owner ) {
        $this->acquire_attempts++;
        if ( ! isset( $this->locks[ $name ] ) ) {
            $this->locks[ $name ] = $owner;
            $this->max_active = max( $this->max_active, count( $this->locks ) );
            return 1;
        }
        if ( $owner === $this->locks[ $name ] ) {
            return 1;
        }
        $this->waits++;
        return 0;
    }

    public function release( $name, $owner ) {
        $this->release_attempts++;
        if ( ! isset( $this->locks[ $name ] ) || $owner !== $this->locks[ $name ] ) {
            return null;
        }
        unset( $this->locks[ $name ] );
        return 1;
    }
}

final class FED01B2_Store {
    public $bindings = array();
    public $nonces = array();
    public $counts = array();
    public function bucket( $sender, $key_id, $operation ) { return $sender . '|' . $key_id . '|' . $operation; }
    public function binding( $sender, $request_id ) { return $sender . '|' . $request_id; }
    public function nonce( $sender, $key_id, $nonce_hash ) { return $sender . '|' . $key_id . '|' . $nonce_hash; }
}

final class FED01B2_WPDB {
    public $prefix;
    public $last_error = '';
    public $id;
    public $scenario;
    public $manager;
    public $store;
    public $wait_on_lock = false;
    public $yield_after_count = false;
    public $start_calls = 0;
    public $rollback_calls = 0;
    public $commit_calls = 0;
    public $insert_count = 0;
    public $release_selects = 0;
    public $get_lock_timeout = null;
    private $yielded_count = false;
    private $staged_bindings = array();
    private $staged_nonces = array();

    public function __construct( $id, $manager, $store, $scenario = 'success', $prefix = 'wp_' ) {
        $this->id = $id;
        $this->manager = $manager;
        $this->store = $store;
        $this->scenario = $scenario;
        $this->prefix = $prefix;
    }

    public function prepare( $sql ) {
        return new FED01B2_Query( $sql, array_slice( func_get_args(), 1 ) );
    }
    private function okay() { $this->last_error = ''; }
    private function fail( $message ) { $this->last_error = $message; return false; }
    private function query_parts( $query ) { return $query instanceof FED01B2_Query ? array( $query->sql, $query->args ) : array( (string) $query, array() ); }

    public function get_var( $query ) {
        $this->okay();
        list( $sql, $args ) = $this->query_parts( $query );
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) {
            $this->get_lock_timeout = $args[1] ?? null;
            if ( 'get_lock_zero' === $this->scenario ) { return '0'; }
            if ( 'get_lock_null' === $this->scenario ) { return null; }
            if ( 'get_lock_ambiguous' === $this->scenario ) { return '1.0'; }
            if ( 'get_lock_error' === $this->scenario ) { return $this->fail( 'get lock failed' ); }
            if ( 'get_lock_exception' === $this->scenario ) { throw new RuntimeException( 'get lock exception' ); }
            $acquired = $this->manager->acquire( $args[0], $this->id );
            if ( 0 === $acquired && $this->wait_on_lock ) {
                Fiber::suspend( 'waiting-lock' );
                $acquired = $this->manager->acquire( $args[0], $this->id );
            }
            return (string) $acquired;
        }
        if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) {
            $this->release_selects++;
            global $fed01b2_events;
            $fed01b2_events[] = 'release:' . $this->id;
            if ( 'release_zero' === $this->scenario ) { return '0'; }
            if ( 'release_null' === $this->scenario ) { return null; }
            if ( 'release_ambiguous' === $this->scenario ) { return '1.0'; }
            if ( 'release_error' === $this->scenario ) { return $this->fail( 'release failed' ); }
            if ( 'release_exception' === $this->scenario ) { throw new RuntimeException( 'release exception' ); }
            return (string) $this->manager->release( $args[0], $this->id );
        }
        if ( false !== strpos( $sql, 'request_bindings' ) ) {
            if ( in_array( $this->scenario, array( 'binding_read_fail', 'rollback_fail' ), true ) ) { return $this->fail( 'binding read failed' ); }
            return $this->store->bindings[ $this->store->binding( $args[0], $args[1] ) ] ?? null;
        }
        if ( false !== strpos( $sql, 'SELECT COUNT(*)' ) ) {
            if ( 'rate_read_fail' === $this->scenario ) { return $this->fail( 'rate read failed' ); }
            $count = $this->store->counts[ $this->store->bucket( $args[0], $args[1], $args[2] ) ] ?? 0;
            if ( $this->yield_after_count && ! $this->yielded_count ) {
                $this->yielded_count = true;
                Fiber::suspend( 'after-count' );
            }
            return (string) $count;
        }
        if ( false !== strpos( $sql, 'faluss_federation_nonces' ) ) {
            if ( 'nonce_read_fail' === $this->scenario ) { return $this->fail( 'nonce read failed' ); }
            return $this->store->nonces[ $this->store->nonce( $args[0], $args[1], $args[2] ) ] ?? null;
        }
        return null;
    }

    public function query( $sql ) {
        $this->okay();
        if ( 'START TRANSACTION' === $sql ) {
            $this->start_calls++;
            if ( 'start_exception' === $this->scenario ) { throw new RuntimeException( 'start exception' ); }
            if ( 'start_fail' === $this->scenario ) { return $this->fail( 'start failed' ); }
            return 1;
        }
        if ( 'ROLLBACK' === $sql ) {
            $this->rollback_calls++;
            $this->staged_bindings = array();
            $this->staged_nonces = array();
            if ( 'rollback_fail' === $this->scenario ) { return $this->fail( 'rollback failed' ); }
            return 1;
        }
        if ( 'COMMIT' === $sql ) {
            $this->commit_calls++;
            if ( 'commit_fail' === $this->scenario ) {
                $this->staged_bindings = array();
                $this->staged_nonces = array();
                return $this->fail( 'commit failed' );
            }
            foreach ( $this->staged_bindings as $key => $value ) { $this->store->bindings[ $key ] = $value; }
            foreach ( $this->staged_nonces as $key => $value ) {
                $this->store->nonces[ $key ] = true;
                $bucket = $this->store->bucket( $value['sender_node_id'], $value['sender_key_id'], $value['operation_name'] );
                $this->store->counts[ $bucket ] = ( $this->store->counts[ $bucket ] ?? 0 ) + 1;
            }
            $this->staged_bindings = array();
            $this->staged_nonces = array();
            return 1;
        }
        return 1;
    }

    public function insert( $table, $data ) {
        $this->okay();
        $this->insert_count++;
        if ( 1 === $this->insert_count && 'insert1_fail' === $this->scenario ) { return $this->fail( 'binding insert failed' ); }
        if ( 2 === $this->insert_count && 'insert2_fail' === $this->scenario ) { return $this->fail( 'nonce insert failed' ); }
        if ( false !== strpos( $table, 'request_bindings' ) ) {
            $key = $this->store->binding( $data['sender_node_id'], $data['request_id'] );
            if ( isset( $this->store->bindings[ $key ] ) ) { return $this->fail( 'duplicate binding' ); }
            $this->staged_bindings[ $key ] = $data['request_body_sha256'];
            return 1;
        }
        if ( false !== strpos( $table, 'faluss_federation_nonces' ) ) {
            $key = $this->store->nonce( $data['sender_node_id'], $data['sender_key_id'], $data['nonce_hash'] );
            if ( isset( $this->store->nonces[ $key ] ) ) { return $this->fail( 'duplicate nonce' ); }
            $this->staged_nonces[ $key ] = $data;
            return 1;
        }
        return 1;
    }
}

function fed01b2_request( $number, $operation = 'diagnostic.read', $sender = 'hub-node', $key_id = 'hub-key-0001' ) {
    return array(
        'sender' => array( 'node_id' => $sender, 'key_id' => $key_id ),
        'request_id' => sprintf( '11111111-1111-4111-8111-%012x', $number ),
        'nonce' => rtrim( strtr( base64_encode( str_pad( (string) $number, 32, 'n' ) ), '+/', '-_' ), '=' ),
        'operation' => $operation,
    );
}

function fed01b2_consume( $connection, $request, $limit = 30 ) {
    global $wpdb;
    $wpdb = $connection;
    return fed01b2_private( 'Faluss_Federation_Schema', 'consume_transaction', array( $request, hash( 'sha256', $request['request_id'] ), $limit ) );
}

function fed01b2_gate( $consumed, $request ) {
    return fed01b2_private( 'Faluss_Federation_Server', 'dispatch_after_consumption', array( $consumed, $request, array() ) );
}

function fed01b2_connection_result( $connection, $request, $limit = 30 ) {
    $consumed = fed01b2_consume( $connection, $request, $limit );
    $dispatched = fed01b2_gate( $consumed, $request );
    return array( $consumed, $dispatched );
}

/* Lock acquisition failures never enter a transaction or dispatch. */
foreach ( array( 'get_lock_zero', 'get_lock_null', 'get_lock_ambiguous', 'get_lock_error', 'get_lock_exception' ) as $scenario ) {
    $manager = new FED01B2_Lock_Manager();
    $store = new FED01B2_Store();
    $connection = new FED01B2_WPDB( $scenario, $manager, $store, $scenario );
    Faluss_Federation_Providers::$dispatches = 0;
    list( $result ) = fed01b2_connection_result( $connection, fed01b2_request( 1 ) );
    fed01b2_assert( 'faluss_federation_fail_closed' === fed01b2_error_code( $result ), 'Unavailable or ambiguous GET_LOCK must fail closed: ' . $scenario );
    fed01b2_assert( 0 === $connection->start_calls && 0 === $connection->insert_count && 0 === Faluss_Federation_Providers::$dispatches, 'GET_LOCK failure must precede transaction, inserts and dispatch: ' . $scenario );
    fed01b2_assert( 0 === $connection->release_selects, 'Unacquired lock must never be released: ' . $scenario );
    fed01b2_assert( 1 === $connection->get_lock_timeout, 'GET_LOCK wait must be exactly one second.' );
}

/* Every exit after acquisition makes exactly one release attempt. */
$acquired_scenarios = array( 'success', 'rate_limited', 'start_fail', 'start_exception', 'binding_read_fail', 'nonce_read_fail', 'rate_read_fail', 'insert1_fail', 'insert2_fail', 'rollback_fail', 'commit_fail', 'release_zero', 'release_null', 'release_ambiguous', 'release_error', 'release_exception' );
foreach ( $acquired_scenarios as $index => $scenario ) {
    global $fed01b2_events;
    $fed01b2_events = array();
    $manager = new FED01B2_Lock_Manager();
    $store = new FED01B2_Store();
    if ( 'rate_limited' === $scenario ) {
        $store->counts[ $store->bucket( 'hub-node', 'hub-key-0001', 'diagnostic.read' ) ] = 30;
    }
    $connection = new FED01B2_WPDB( 'exit-' . $index, $manager, $store, $scenario );
    Faluss_Federation_Providers::$dispatches = 0;
    list( $result ) = fed01b2_connection_result( $connection, fed01b2_request( 100 + $index ) );
    fed01b2_assert( 1 === $connection->release_selects, 'Acquired lock must have one RELEASE_LOCK attempt: ' . $scenario );
    if ( 'success' === $scenario ) {
        fed01b2_assert( true === $result && 1 === Faluss_Federation_Providers::$dispatches, 'GET_LOCK=1 plus commit/release success must allow one dispatch.' );
        fed01b2_assert( array( 'release:exit-0', 'purge-probe' ) === $fed01b2_events, 'Purge must occur only after confirmed release.' );
    } elseif ( 'rate_limited' === $scenario ) {
        fed01b2_assert( 'faluss_federation_rate_limited' === fed01b2_error_code( $result ) && 0 === Faluss_Federation_Providers::$dispatches, 'Committed limited request must release without dispatch.' );
        fed01b2_assert( 31 === $store->counts[ $store->bucket( 'hub-node', 'hub-key-0001', 'diagnostic.read' ) ], 'Limited request must remain committed.' );
    } else {
        fed01b2_assert( 'faluss_federation_fail_closed' === fed01b2_error_code( $result ) && 0 === Faluss_Federation_Providers::$dispatches, 'Acquired-path fault must fail closed without dispatch: ' . $scenario );
        if ( 0 === strpos( $scenario, 'release_' ) ) {
            fed01b2_assert( 1 === $connection->commit_calls && 1 === $store->counts[ $store->bucket( 'hub-node', 'hub-key-0001', 'diagnostic.read' ) ], 'Release fault must be tested after durable commit: ' . $scenario );
            fed01b2_assert( false === in_array( 'purge-probe', $fed01b2_events, true ), 'Ambiguous release must prevent opportunistic purge: ' . $scenario );
        }
    }
}

/* A confirmed replay is classified while the bucket lock is still held. */
$manager = new FED01B2_Lock_Manager();
$store = new FED01B2_Store();
$replay_request = fed01b2_request( 500 );
$store->bindings[ $store->binding( $replay_request['sender']['node_id'], $replay_request['request_id'] ) ] = str_repeat( 'a', 64 );
$connection = new FED01B2_WPDB( 'replay', $manager, $store );
Faluss_Federation_Providers::$dispatches = 0;
list( $replay ) = fed01b2_connection_result( $connection, $replay_request );
fed01b2_assert( 'faluss_federation_replay_rejected' === fed01b2_error_code( $replay ) && 0 === Faluss_Federation_Providers::$dispatches, 'Confirmed replay must remain replay_rejected without dispatch.' );
fed01b2_assert( 1 === $connection->rollback_calls && 1 === $connection->release_selects, 'Replay check must roll back and release its held bucket lock.' );

/* Two genuinely interleaved connections at 29/30 share one advisory-lock manager. */
$manager = new FED01B2_Lock_Manager();
$store = new FED01B2_Store();
$bucket = $store->bucket( 'hub-node', 'hub-key-0001', 'diagnostic.read' );
$store->counts[ $bucket ] = 29;
$request_a = fed01b2_request( 601 );
$request_b = fed01b2_request( 602 );
$connection_a = new FED01B2_WPDB( 'threshold-a', $manager, $store );
$connection_b = new FED01B2_WPDB( 'threshold-b', $manager, $store );
$connection_a->yield_after_count = true;
$connection_b->wait_on_lock = true;
Faluss_Federation_Providers::$dispatches = 0;
$fiber_a = new Fiber( function () use ( $connection_a, $request_a ) { return fed01b2_connection_result( $connection_a, $request_a ); } );
$fiber_b = new Fiber( function () use ( $connection_b, $request_b ) { return fed01b2_connection_result( $connection_b, $request_b ); } );
fed01b2_assert( 'after-count' === $fiber_a->start(), 'First connection must pause after observing 29 while retaining the lock.' );
fed01b2_assert( 'waiting-lock' === $fiber_b->start(), 'Second connection must block on the same bucket lock.' );
fed01b2_assert( 0 === $connection_b->start_calls, 'Blocked second connection must not start a transaction.' );
global $wpdb;
$wpdb = $connection_a;
$fiber_a->resume();
$wpdb = $connection_b;
$fiber_b->resume();
$result_a = $fiber_a->getReturn()[0];
$result_b = $fiber_b->getReturn()[0];
fed01b2_assert( true === $result_a && 'faluss_federation_rate_limited' === fed01b2_error_code( $result_b ), 'At 29/30 only the lock holder may become the thirtieth accepted request.' );
fed01b2_assert( 1 === Faluss_Federation_Providers::$dispatches, 'Threshold pair must dispatch exactly once.' );
fed01b2_assert( 31 === $store->counts[ $bucket ], 'Limited follower must be durably counted after observing committed 30.' );
fed01b2_assert( isset( $store->bindings[ $store->binding( 'hub-node', $request_b['request_id'] ) ] ) && 2 === count( $store->nonces ), 'Limited follower must retain committed binding and nonce.' );

/* Interleaved burst: all waiters start while the first connection owns the bucket. */
$manager = new FED01B2_Lock_Manager();
$store = new FED01B2_Store();
$connections = array();
$requests = array();
$fibers = array();
Faluss_Federation_Providers::$dispatches = 0;
for ( $i = 1; $i <= 31; $i++ ) {
    $requests[ $i ] = fed01b2_request( 700 + $i );
    $connections[ $i ] = new FED01B2_WPDB( 'burst-' . $i, $manager, $store );
    $connections[ $i ]->wait_on_lock = 1 !== $i;
    $connections[ $i ]->yield_after_count = 1 === $i;
    $connection = $connections[ $i ];
    $request = $requests[ $i ];
    $fibers[ $i ] = new Fiber( function () use ( $connection, $request ) { return fed01b2_connection_result( $connection, $request ); } );
}
fed01b2_assert( 'after-count' === $fibers[1]->start(), 'Burst leader must hold the lock before waiters start.' );
for ( $i = 2; $i <= 31; $i++ ) {
    fed01b2_assert( 'waiting-lock' === $fibers[ $i ]->start(), 'Burst request must wait on the shared bucket: ' . $i );
}
$wpdb = $connections[1];
$fibers[1]->resume();
for ( $i = 2; $i <= 31; $i++ ) {
    $wpdb = $connections[ $i ];
    $fibers[ $i ]->resume();
}
$limited_count = 0;
for ( $i = 1; $i <= 31; $i++ ) {
    if ( 'faluss_federation_rate_limited' === fed01b2_error_code( $fibers[ $i ]->getReturn()[0] ) ) { $limited_count++; }
}
$burst_bucket = $store->bucket( 'hub-node', 'hub-key-0001', 'diagnostic.read' );
fed01b2_assert( 30 === Faluss_Federation_Providers::$dispatches && 1 === $limited_count, 'Concurrent burst must never allow more than the limit of provider calls.' );
fed01b2_assert( 31 === $store->counts[ $burst_bucket ] && 31 === count( $store->bindings ) && 31 === count( $store->nonces ), 'Burst including limited request must commit every unique binding and nonce.' );
fed01b2_assert( $manager->waits >= 30 && empty( $manager->locks ), 'Burst must exercise shared-lock contention and release every lock.' );

/* EVT-01B.2B exact publish threshold: observed counts 599, 600 and 601. */
$manager = new FED01B2_Lock_Manager();
$store = new FED01B2_Store();
$publish_bucket = $store->bucket( 'hub-node', 'hub-key-0001', 'event.publish' );
$store->counts[ $publish_bucket ] = 599;
Faluss_Federation_Providers::$dispatches = 0;
$publish_results = array();
for ( $i = 0; $i < 3; $i++ ) {
    $connection = new FED01B2_WPDB( 'publish-' . $i, $manager, $store );
    $publish_results[] = fed01b2_connection_result( $connection, fed01b2_request( 850 + $i, 'event.publish' ), 600 )[0];
}
fed01b2_assert( true === $publish_results[0] && 'faluss_federation_rate_limited' === fed01b2_error_code( $publish_results[1] ) && 'faluss_federation_rate_limited' === fed01b2_error_code( $publish_results[2] ), 'Publish counts 599/600/601 must dispatch only the request observing 599.' );
fed01b2_assert( 1 === Faluss_Federation_Providers::$dispatches && 602 === $store->counts[ $publish_bucket ], 'Limited publish requests must still durably consume their unique nonce and binding.' );

/* Different buckets can execute while the first bucket remains locked. */
$manager = new FED01B2_Lock_Manager();
$store = new FED01B2_Store();
$connection_a = new FED01B2_WPDB( 'bucket-a', $manager, $store );
$connection_b = new FED01B2_WPDB( 'bucket-b', $manager, $store );
$connection_a->yield_after_count = true;
$request_a = fed01b2_request( 900, 'diagnostic.read' );
$request_b = fed01b2_request( 901, 'manifest.read' );
Faluss_Federation_Providers::$dispatches = 0;
$fiber_a = new Fiber( function () use ( $connection_a, $request_a ) { return fed01b2_connection_result( $connection_a, $request_a, 30 ); } );
$fiber_b = new Fiber( function () use ( $connection_b, $request_b ) { return fed01b2_connection_result( $connection_b, $request_b, 60 ); } );
fed01b2_assert( 'after-count' === $fiber_a->start(), 'First bucket must remain locked for independence proof.' );
$fiber_b->start();
fed01b2_assert( $fiber_b->isTerminated() && 1 === $connection_b->start_calls, 'Different bucket must not block behind the first bucket.' );
fed01b2_assert( 2 === $manager->max_active, 'Two distinct bucket locks must coexist.' );
$wpdb = $connection_a;
$fiber_a->resume();
fed01b2_assert( 2 === Faluss_Federation_Providers::$dispatches && empty( $manager->locks ), 'Independent buckets must both complete and release.' );

echo 'FED-01B.2 Federation concurrency: OK (' . $fed01b2_assertions . ' assertions; two-connection shared-lock simulation)' . PHP_EOL;
