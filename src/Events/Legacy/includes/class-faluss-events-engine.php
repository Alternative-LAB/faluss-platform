<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Persistent append-only Faluss Events core and closed route/consumer registry. */
final class Faluss_Events_Engine {
    private const DESTINATIONS = array( 'analytics.events', 'quests.events', 'progression.events' );
    private static $routes = array();
    private static $consumers = array();
    private static $route_conflict = false;
    private static $consumer_conflict = false;

    /** Trusted PHP registers one exact route per source and destination. */
    public static function register_delivery_route( $descriptor ) {
        $keys = array( 'source_node_id', 'source_app_key', 'source_capability_key', 'catalog_version', 'destination', 'mode', 'target_node_id', 'target_app_key' );
        if ( ! self::exact_keys( $descriptor, $keys ) || ! self::is_node( $descriptor['source_node_id'] ) || ! self::is_app_key( $descriptor['source_app_key'] ) || ! self::is_capability( $descriptor['source_capability_key'], $descriptor['source_app_key'] ) || ! self::is_semver( $descriptor['catalog_version'] ) || ! in_array( $descriptor['destination'], self::DESTINATIONS, true ) || ! in_array( $descriptor['mode'], array( 'local', 'federation' ), true ) || ! self::is_node( $descriptor['target_node_id'] ) || ! self::is_app_key( $descriptor['target_app_key'] ) ) {
            return self::registry_failure();
        }
        $key = self::route_key( $descriptor );
        if ( isset( self::$routes[ $key ] ) ) {
            self::$route_conflict = true;
            return self::registry_failure();
        }
        self::$routes[ $key ] = $descriptor;
        return true;
    }

    /** Trusted PHP registers one exact target, source filters and its callback. */
    public static function register_consumer( $descriptor ) {
        $keys = array( 'consumer_key', 'destination', 'target_node_id', 'target_app_key', 'sources', 'callback' );
        if ( ! self::exact_keys( $descriptor, $keys ) || ! self::is_consumer_key( $descriptor['consumer_key'] ) || ! in_array( $descriptor['destination'], self::DESTINATIONS, true ) || ! self::is_node( $descriptor['target_node_id'] ) || ! self::is_app_key( $descriptor['target_app_key'] ) || ! is_callable( $descriptor['callback'] ) || ! self::is_list( $descriptor['sources'] ) || empty( $descriptor['sources'] ) || count( $descriptor['sources'] ) > 64 ) {
            return self::registry_failure();
        }
        $source_keys = array();
        foreach ( $descriptor['sources'] as $source ) {
            if ( ! self::valid_consumer_source( $source ) ) {
                return self::registry_failure();
            }
            $source_key = self::source_key( $source['node_id'], $source['app_key'], $source['capability_key'], $source['catalog_version'] );
            if ( isset( $source_keys[ $source_key ] ) ) {
                return self::registry_failure();
            }
            $source_keys[ $source_key ] = true;
        }
        $key = self::consumer_key( $descriptor['target_node_id'], $descriptor['target_app_key'], $descriptor['destination'], $descriptor['consumer_key'] );
        if ( isset( self::$consumers[ $key ] ) ) {
            self::$consumer_conflict = true;
            return self::registry_failure();
        }
        self::$consumers[ $key ] = $descriptor;
        return true;
    }

    /** Explicit only: signed remote read, validation, then private persistence. */
    public static function refresh_remote_catalog( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $catalog_version = '1.0.0' ) {
        if ( ! class_exists( 'Faluss_Events' ) || ! method_exists( 'Faluss_Events', 'read_remote_catalog' ) ) {
            return self::unavailable();
        }
        $catalog = Faluss_Events::read_remote_catalog( $peer_node_id, $peer_app_key, $owner_app_key, $capability_key, $catalog_version );
        return is_wp_error( $catalog ) ? self::unavailable() : self::persist_validated_catalog( $catalog );
    }

    /** Explicit only: trusted local provider resolution, validation, then private persistence. */
    public static function accept_registered_local_catalog( $node_id, $app_key, $capability_key, $catalog_version = '1.0.0' ) {
        if ( ! class_exists( 'Faluss_Events' ) || ! method_exists( 'Faluss_Events', 'resolve_local_catalog' ) ) {
            return self::unavailable();
        }
        $catalog = Faluss_Events::resolve_local_catalog( $node_id, $app_key, $capability_key, $catalog_version );
        return is_wp_error( $catalog ) ? self::unavailable() : self::persist_validated_catalog( $catalog );
    }

    public static function accept_local_event( $event ) {
        return self::accept_event( $event, 'local', null );
    }

    /** Federation invokes this primitive only after authentication and policy. */
    public static function accept_inbound_event( $event, $authenticated_sender, $authenticated_recipient ) {
        return self::accept_event( $event, 'inbound', $authenticated_sender, $authenticated_recipient );
    }

    /** Private server-side read with canonical integrity revalidation. */
    public static function event_by_id( $event_id ) {
        $record = self::event_record_by_id( $event_id );
        return is_wp_error( $record ) ? $record : $record['event'];
    }

    /** Canonical event and persisted digest used by both workers. */
    public static function event_record_by_id( $event_id ) {
        global $wpdb;
        if ( ! Faluss_Events_Schema::is_ready() || ! self::is_uuid( $event_id ) ) {
            return self::unavailable();
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id,event_sha256,retention_until,envelope_json FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::events_table() ) . ' WHERE event_id = %s LIMIT 1', $event_id ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $row ) ) {
            return self::unavailable();
        }
        try {
            $event = json_decode( $row['envelope_json'], true, 16, JSON_THROW_ON_ERROR );
        } catch ( Throwable $throwable ) {
            return self::unavailable();
        }
        $canonical = Faluss_Events_Canonicalizer::canonicalize_event( $event );
        if ( is_wp_error( $canonical ) || ! is_string( $row['event_sha256'] ?? null ) || ! self::is_sql_utc( $row['retention_until'] ?? null ) || ! is_string( $row['envelope_json'] ?? null ) || $canonical !== $row['envelope_json'] || ! hash_equals( $row['event_sha256'], hash( 'sha256', $canonical ) ) || $event_id !== ( $event['event_id'] ?? null ) ) {
            return self::unavailable();
        }
        return array( 'event' => $event, 'event_sha256' => $row['event_sha256'], 'retention_until' => $row['retention_until'] );
    }

    /** Resolve an outbox row against the exact immutable PHP route descriptor. */
    public static function delivery_route( $event, $row ) {
        if ( self::$route_conflict || ! is_array( $event ) || ! is_array( $row ) ) {
            return self::registry_failure();
        }
        $key = self::source_key( $event['source']['node_id'] ?? '', $event['source']['app_key'] ?? '', $event['source']['capability_key'] ?? '', $event['source']['catalog_version'] ?? '' ) . "\x1F" . ( $row['destination'] ?? '' );
        $route = self::$routes[ $key ] ?? null;
        return is_array( $route ) && ( $row['target_node_id'] ?? null ) === $route['target_node_id'] && ( $row['target_app_key'] ?? null ) === $route['target_app_key'] ? $route : self::registry_failure();
    }

    /** Resolve exactly one registered consumer for an already persisted delivery. */
    public static function consumer_descriptor( $event, $row, $target_node_id, $target_app_key ) {
        if ( self::$consumer_conflict || ! is_array( $event ) || ! is_array( $row ) ) {
            return self::registry_failure();
        }
        foreach ( self::$consumers as $consumer ) {
            if ( ( $row['destination'] ?? null ) !== $consumer['destination'] || ( $row['consumer_key'] ?? null ) !== $consumer['consumer_key'] || $target_node_id !== $consumer['target_node_id'] || $target_app_key !== $consumer['target_app_key'] || ! self::consumer_accepts_source( $consumer, $event ) ) {
                continue;
            }
            return $consumer;
        }
        return self::registry_failure();
    }

    public static function consumers_for_local_route( $event, $destination, $target_node_id, $target_app_key ) {
        if ( ! is_array( $event ) || ! in_array( $destination, self::DESTINATIONS, true ) ) {
            return self::registry_failure();
        }
        $single = $event;
        $single['destinations'] = array( $destination );
        return self::consumers_for_event( $single, array( 'node_id' => $target_node_id, 'app_key' => $target_app_key ) );
    }

    public static function route_registry_ready() { return ! self::$route_conflict && ! empty( self::$routes ); }
    public static function consumer_registry_ready() { return ! self::$consumer_conflict && ! empty( self::$consumers ); }

    /** Bounded non-sensitive counters only. */
    public static function technical_counts() {
        global $wpdb;
        if ( ! Faluss_Events_Schema::is_ready() ) {
            return array();
        }
        $tables = array( 'catalogs' => Faluss_Events_Schema::catalogs_table(), 'events' => Faluss_Events_Schema::events_table(), 'outbox' => Faluss_Events_Schema::outbox_table(), 'inbox' => Faluss_Events_Schema::inbox_table(), 'consumer_deliveries' => Faluss_Events_Schema::consumer_deliveries_table(), 'tombstones' => Faluss_Events_Schema::tombstones_table() );
        $counts = array();
        foreach ( $tables as $name => $table ) {
            $value = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Faluss_Events_Schema::quote_identifier( $table ) );
            if ( self::database_has_error() || ! is_numeric( $value ) ) {
                return array();
            }
            $counts[ $name ] = (int) $value;
        }
        return $counts;
    }

    /** Accepts no browser input: callers can reach this only through validated local/remote resolver methods. */
    private static function persist_validated_catalog( $catalog ) {
        if ( ! Faluss_Events_Schema::is_ready() || ! Faluss_Events_Catalog_Validator::validate( $catalog ) ) {
            return self::unavailable();
        }
        $canonical = Faluss_Events_Canonicalizer::canonicalize_catalog( $catalog );
        if ( is_wp_error( $canonical ) ) {
            return self::unavailable();
        }
        $hash = hash( 'sha256', $canonical );
        $lock = self::lock_name( 'catalog', self::source_key( $catalog['node_id'], $catalog['app_key'], $catalog['capability_key'], $catalog['catalog_version'] ) );
        if ( is_wp_error( $lock ) || ! self::acquire_lock( $lock ) ) {
            return self::unavailable();
        }
        try {
            $result = self::persist_catalog_transaction( $catalog, $canonical, $hash );
        } catch ( Throwable $throwable ) {
            self::rollback();
            $result = self::unavailable();
        }
        return self::release_lock( $lock ) ? $result : self::unavailable();
    }

    private static function persist_catalog_transaction( $catalog, $canonical, $hash ) {
        global $wpdb;
        if ( ! self::start_transaction() ) {
            return self::unavailable();
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,catalog_uuid,catalog_sha256,catalog_json FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::catalogs_table() ) . ' WHERE source_node_id = %s AND source_app_key = %s AND capability_key = %s AND catalog_version = %s FOR UPDATE', $catalog['node_id'], $catalog['app_key'], $catalog['capability_key'], $catalog['catalog_version'] ), ARRAY_A );
        if ( self::database_has_error() ) {
            self::rollback();
            return self::unavailable();
        }
        if ( is_array( $row ) ) {
            if ( ! is_string( $row['catalog_sha256'] ?? null ) || ! is_string( $row['catalog_json'] ?? null ) || ! hash_equals( $row['catalog_sha256'], $hash ) || $row['catalog_json'] !== $canonical ) {
                self::rollback();
                return self::conflict();
            }
            if ( ! self::commit() ) {
                self::rollback();
                return self::unavailable();
            }
            return array( 'catalog_uuid' => $row['catalog_uuid'], 'existing' => true );
        }
        $catalog_uuid = self::uuid();
        if ( is_wp_error( $catalog_uuid ) ) {
            self::rollback();
            return self::unavailable();
        }
        $inserted = $wpdb->insert( Faluss_Events_Schema::catalogs_table(), array( 'catalog_uuid' => $catalog_uuid, 'source_node_id' => $catalog['node_id'], 'source_app_key' => $catalog['app_key'], 'capability_key' => $catalog['capability_key'], 'catalog_version' => $catalog['catalog_version'], 'catalog_sha256' => $hash, 'catalog_json' => $canonical, 'accepted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        if ( false === $inserted || self::database_has_error() || ! self::commit() ) {
            self::rollback();
            return self::unavailable();
        }
        return array( 'catalog_uuid' => $catalog_uuid, 'existing' => false );
    }

    private static function accept_event( $event, $direction, $authenticated_sender, $authenticated_recipient = null ) {
        if ( ! Faluss_Events_Schema::is_ready() || ! is_array( $event ) || ! in_array( $direction, array( 'local', 'inbound' ), true ) ) {
            return self::unavailable();
        }
        if ( 'inbound' === $direction && ( ! self::valid_sender( $authenticated_sender ) || ! self::valid_sender( $authenticated_recipient ) || ( $event['source']['node_id'] ?? null ) !== $authenticated_sender['node_id'] || ( $event['source']['app_key'] ?? null ) !== $authenticated_sender['app_key'] || ( $event['source']['owner'] ?? null ) !== $authenticated_sender['app_key'] ) ) {
            return self::incompatible();
        }
        $catalog = self::accepted_catalog_for_event( $event );
        if ( is_wp_error( $catalog ) ) {
            return $catalog;
        }
        $validator_state = Faluss_Events::payload_validator_state( $event );
        if ( 'unavailable' === $validator_state ) {
            return self::registry_failure();
        }
        if ( 'registered' !== $validator_state ) {
            return self::incompatible();
        }
        if ( ! Faluss_Events::validate_event( $event, $catalog ) ) {
            return self::incompatible();
        }
        $canonical = Faluss_Events_Canonicalizer::canonicalize_event( $event );
        $identity_canonical = self::source_identity_canonical( $event );
        if ( is_wp_error( $canonical ) || is_wp_error( $identity_canonical ) ) {
            return self::unavailable();
        }
        $event_hash = hash( 'sha256', $canonical );
        $identity_hash = hash( 'sha256', $identity_canonical );
        $definition = self::definition( $catalog, $event['event_type'], $event['event_version'] );
        if ( ! is_array( $definition ) ) {
            return self::unavailable();
        }
        $operations = 'local' === $direction ? self::routes_for_event( $event ) : self::consumers_for_event( $event, $authenticated_recipient );
        if ( is_wp_error( $operations ) ) {
            return $operations;
        }
        $uuids = self::operation_uuids( $direction, count( $operations ) );
        if ( is_wp_error( $uuids ) ) {
            return self::unavailable();
        }
        $locks = self::event_lock_names( $identity_hash, $event['event_id'] );
        if ( is_wp_error( $locks ) || ! self::acquire_locks( $locks ) ) {
            return self::unavailable();
        }
        $result = self::unavailable();
        try {
            $result = self::persist_event_transaction( $event, $canonical, $identity_hash, $event_hash, $direction, $authenticated_sender, $definition, $operations, $uuids );
        } catch ( Throwable $throwable ) {
            self::rollback();
            $result = self::unavailable();
        }
        if ( ! self::release_locks( $locks ) ) {
            return self::unavailable();
        }
        return $result;
    }

    private static function persist_event_transaction( $event, $canonical, $identity_hash, $event_hash, $direction, $sender, $definition, $operations, $uuids ) {
        global $wpdb;
        if ( ! self::start_transaction() ) {
            return self::unavailable();
        }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::events_table() ) . ' WHERE source_identity_sha256 = %s OR event_id = %s FOR UPDATE', $identity_hash, $event['event_id'] ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $rows ) ) {
            self::rollback();
            return self::unavailable();
        }
        $tombstones = $wpdb->get_results( $wpdb->prepare( 'SELECT tombstone_uuid,event_id,source_identity_sha256,event_sha256,expires_at FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::tombstones_table() ) . ' WHERE source_identity_sha256 = %s OR event_id = %s FOR UPDATE', $identity_hash, $event['event_id'] ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $tombstones ) ) {
            self::rollback();
            return self::unavailable();
        }
        if ( count( $rows ) > 1 || count( $tombstones ) > 1 || ( ! empty( $rows ) && ! empty( $tombstones ) ) ) {
            self::rollback();
            return self::conflict();
        }
        if ( ! empty( $rows ) ) {
            if ( ! self::existing_event_matches( $rows[0], $event, $canonical, $identity_hash, $event_hash, $direction ) || ! self::existing_operations_match( $event, $event_hash, $direction, $sender, $operations ) ) {
                self::rollback();
                return self::conflict();
            }
            if ( ! self::commit() ) {
                self::rollback();
                return self::unavailable();
            }
            return array( 'event_id' => $event['event_id'], 'event_sha256' => $event_hash, 'existing' => true );
        }
        if ( ! empty( $tombstones ) ) {
            $receipt = $tombstones[0];
            if ( ! self::is_uuid( $receipt['tombstone_uuid'] ?? null ) || ( $receipt['event_id'] ?? null ) !== $event['event_id'] || ( $receipt['source_identity_sha256'] ?? null ) !== $identity_hash || ( $receipt['event_sha256'] ?? null ) !== $event_hash || ! self::is_sql_utc( $receipt['expires_at'] ?? null ) ) {
                self::rollback();
                return self::conflict();
            }
            if ( $receipt['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) || ! self::commit() ) {
                self::rollback();
                return self::unavailable();
            }
            return array( 'event_id' => $event['event_id'], 'event_sha256' => $event_hash, 'existing' => true );
        }
        $accepted_timestamp = time();
        $accepted_at = gmdate( 'Y-m-d H:i:s', $accepted_timestamp );
        $retention_until = gmdate( 'Y-m-d H:i:s', $accepted_timestamp + $definition['max_retention_seconds'] );
        $source = $event['source'];
        $inserted = $wpdb->insert( Faluss_Events_Schema::events_table(), array( 'event_id' => $event['event_id'], 'source_identity_sha256' => $identity_hash, 'event_sha256' => $event_hash, 'source_node_id' => $source['node_id'], 'source_app_key' => $source['app_key'], 'source_owner' => $source['owner'], 'source_capability_key' => $source['capability_key'], 'catalog_version' => $source['catalog_version'], 'event_type' => $event['event_type'], 'event_version' => $event['event_version'], 'source_event_reference' => $event['source_event_reference'], 'direction' => $direction, 'occurred_at' => self::utc_to_sql( $event['occurred_at'] ), 'produced_at' => self::utc_to_sql( $event['produced_at'] ), 'accepted_at' => $accepted_at, 'retention_until' => $retention_until, 'envelope_json' => $canonical ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        if ( false === $inserted || self::database_has_error() ) {
            self::rollback();
            return self::unavailable();
        }
        if ( 'local' === $direction ) {
            foreach ( $operations as $index => $route ) {
                $inserted = $wpdb->insert( Faluss_Events_Schema::outbox_table(), array( 'delivery_uuid' => $uuids[ $index ], 'event_id' => $event['event_id'], 'destination' => $route['destination'], 'target_node_id' => $route['target_node_id'], 'target_app_key' => $route['target_app_key'], 'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => $accepted_at, 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => null, 'created_at' => $accepted_at, 'delivered_at' => null ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
                if ( false === $inserted || self::database_has_error() ) {
                    self::rollback();
                    return self::unavailable();
                }
            }
        } else {
            $inserted = $wpdb->insert( Faluss_Events_Schema::inbox_table(), array( 'receipt_uuid' => $uuids['receipt'], 'sender_node_id' => $sender['node_id'], 'sender_app_key' => $sender['app_key'], 'event_id' => $event['event_id'], 'event_sha256' => $event_hash, 'received_at' => $accepted_at ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
            if ( false === $inserted || self::database_has_error() ) {
                self::rollback();
                return self::unavailable();
            }
            foreach ( $operations as $index => $consumer ) {
                $inserted = $wpdb->insert( Faluss_Events_Schema::consumer_deliveries_table(), array( 'delivery_uuid' => $uuids['deliveries'][ $index ], 'event_id' => $event['event_id'], 'destination' => $consumer['destination'], 'consumer_key' => $consumer['consumer_key'], 'status' => 'pending', 'attempt_count' => 0, 'lease_token' => null, 'lease_expires_at' => null, 'last_result_code' => null, 'created_at' => $accepted_at, 'processed_at' => null ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
                if ( false === $inserted || self::database_has_error() ) {
                    self::rollback();
                    return self::unavailable();
                }
            }
        }
        if ( ! self::commit() ) {
            self::rollback();
            return self::unavailable();
        }
        return array( 'event_id' => $event['event_id'], 'event_sha256' => $event_hash, 'existing' => false );
    }

    private static function accepted_catalog_for_event( $event ) {
        global $wpdb;
        if ( ! is_array( $event['source'] ?? null ) ) {
            return self::unavailable();
        }
        $source = $event['source'];
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT catalog_sha256,catalog_json FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::catalogs_table() ) . ' WHERE source_node_id = %s AND source_app_key = %s AND capability_key = %s AND catalog_version = %s LIMIT 1', $source['node_id'] ?? '', $source['app_key'] ?? '', $source['capability_key'] ?? '', $source['catalog_version'] ?? '' ), ARRAY_A );
        if ( self::database_has_error() ) {
            return self::unavailable();
        }
        if ( ! is_array( $row ) ) {
            return self::not_available();
        }
        try {
            $catalog = json_decode( $row['catalog_json'], true, 32, JSON_THROW_ON_ERROR );
        } catch ( Throwable $throwable ) {
            return self::unavailable();
        }
        $canonical = Faluss_Events_Canonicalizer::canonicalize_catalog( $catalog );
        if ( is_wp_error( $canonical ) || ! is_string( $row['catalog_json'] ?? null ) || ! is_string( $row['catalog_sha256'] ?? null ) || $canonical !== $row['catalog_json'] || ! hash_equals( $row['catalog_sha256'], hash( 'sha256', $canonical ) ) || ( $catalog['node_id'] ?? null ) !== ( $source['node_id'] ?? null ) || ( $catalog['app_key'] ?? null ) !== ( $source['app_key'] ?? null ) || ( $catalog['capability_key'] ?? null ) !== ( $source['capability_key'] ?? null ) || ( $catalog['catalog_version'] ?? null ) !== ( $source['catalog_version'] ?? null ) ) {
            return self::unavailable();
        }
        return $catalog;
    }

    private static function source_identity_canonical( $event ) {
        $source = $event['source'];
        return Faluss_Events_Canonicalizer::canonicalize( array( 'source_node_id' => $source['node_id'], 'source_app_key' => $source['app_key'], 'source_owner' => $source['owner'], 'source_capability_key' => $source['capability_key'], 'source_catalog_version' => $source['catalog_version'], 'event_type' => $event['event_type'], 'event_version' => $event['event_version'], 'source_event_reference' => $event['source_event_reference'] ) );
    }

    private static function routes_for_event( $event ) {
        if ( self::$route_conflict ) {
            return self::registry_failure();
        }
        $routes = array();
        foreach ( $event['destinations'] as $destination ) {
            $key = self::source_key( $event['source']['node_id'], $event['source']['app_key'], $event['source']['capability_key'], $event['source']['catalog_version'] ) . "\x1F" . $destination;
            if ( ! isset( self::$routes[ $key ] ) ) {
                return self::registry_failure();
            }
            $routes[] = self::$routes[ $key ];
        }
        return $routes;
    }

    private static function consumers_for_event( $event, $recipient ) {
        if ( self::$consumer_conflict ) {
            return self::registry_failure();
        }
        if ( ! self::valid_sender( $recipient ) ) {
            return self::registry_failure();
        }
        $resolved = array();
        $source_key = self::source_key( $event['source']['node_id'], $event['source']['app_key'], $event['source']['capability_key'], $event['source']['catalog_version'] );
        foreach ( $event['destinations'] as $destination ) {
            $found = false;
            foreach ( self::$consumers as $consumer ) {
                if ( $destination !== $consumer['destination'] || $recipient['node_id'] !== $consumer['target_node_id'] || $recipient['app_key'] !== $consumer['target_app_key'] ) {
                    continue;
                }
                foreach ( $consumer['sources'] as $source ) {
                    if ( $source_key === self::source_key( $source['node_id'], $source['app_key'], $source['capability_key'], $source['catalog_version'] ) ) {
                        $resolved[] = array( 'consumer_key' => $consumer['consumer_key'], 'destination' => $destination );
                        $found = true;
                        break;
                    }
                }
            }
            if ( ! $found ) {
                return self::registry_failure();
            }
        }
        return $resolved;
    }

    private static function existing_event_matches( $row, $event, $canonical, $identity_hash, $event_hash, $direction ) {
        return ( $row['source_identity_sha256'] ?? null ) === $identity_hash && ( $row['event_id'] ?? null ) === $event['event_id'] && ( $row['event_sha256'] ?? null ) === $event_hash && ( $row['envelope_json'] ?? null ) === $canonical && ( $row['direction'] ?? null ) === $direction;
    }

    private static function existing_operations_match( $event, $event_hash, $direction, $sender, $operations ) {
        global $wpdb;
        if ( 'local' === $direction ) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT destination,target_node_id,target_app_key FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::outbox_table() ) . ' WHERE event_id = %s FOR UPDATE', $event['event_id'] ), ARRAY_A );
            $expected = array();
            foreach ( $operations as $route ) { $expected[] = $route['destination'] . "\x1F" . $route['target_node_id'] . "\x1F" . $route['target_app_key']; }
            $actual = array();
            foreach ( is_array( $rows ) ? $rows : array() as $row ) { $actual[] = $row['destination'] . "\x1F" . $row['target_node_id'] . "\x1F" . $row['target_app_key']; }
            sort( $expected, SORT_STRING ); sort( $actual, SORT_STRING );
            return ! self::database_has_error() && $expected === $actual;
        }
        $inbox = $wpdb->get_row( $wpdb->prepare( 'SELECT sender_node_id,sender_app_key,event_sha256 FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::inbox_table() ) . ' WHERE event_id = %s FOR UPDATE', $event['event_id'] ), ARRAY_A );
        if ( self::database_has_error() || ! is_array( $inbox ) || ( $inbox['sender_node_id'] ?? null ) !== $sender['node_id'] || ( $inbox['sender_app_key'] ?? null ) !== $sender['app_key'] || ! is_string( $inbox['event_sha256'] ?? null ) || ! hash_equals( $inbox['event_sha256'], $event_hash ) ) {
            return false;
        }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT destination,consumer_key FROM ' . Faluss_Events_Schema::quote_identifier( Faluss_Events_Schema::consumer_deliveries_table() ) . ' WHERE event_id = %s FOR UPDATE', $event['event_id'] ), ARRAY_A );
        $expected = array(); foreach ( $operations as $consumer ) { $expected[] = $consumer['destination'] . "\x1F" . $consumer['consumer_key']; }
        $actual = array(); foreach ( is_array( $rows ) ? $rows : array() as $row ) { $actual[] = $row['destination'] . "\x1F" . $row['consumer_key']; }
        sort( $expected, SORT_STRING ); sort( $actual, SORT_STRING );
        return ! self::database_has_error() && $expected === $actual;
    }

    private static function operation_uuids( $direction, $count ) {
        if ( 'local' === $direction ) {
            $uuids = array();
            for ( $index = 0; $index < $count; $index++ ) { $uuid = self::uuid(); if ( is_wp_error( $uuid ) ) { return $uuid; } $uuids[] = $uuid; }
            return $uuids;
        }
        $receipt = self::uuid();
        if ( is_wp_error( $receipt ) ) { return $receipt; }
        $deliveries = array();
        for ( $index = 0; $index < $count; $index++ ) { $uuid = self::uuid(); if ( is_wp_error( $uuid ) ) { return $uuid; } $deliveries[] = $uuid; }
        return array( 'receipt' => $receipt, 'deliveries' => $deliveries );
    }

    private static function definition( $catalog, $type, $version ) {
        foreach ( $catalog['event_types'] as $definition ) {
            if ( $type === $definition['event_type'] && $version === $definition['event_version'] ) { return $definition; }
        }
        return null;
    }

    private static function route_key( $descriptor ) { return self::source_key( $descriptor['source_node_id'], $descriptor['source_app_key'], $descriptor['source_capability_key'], $descriptor['catalog_version'] ) . "\x1F" . $descriptor['destination']; }
    private static function consumer_key( $node, $app, $destination, $consumer ) { return implode( "\x1F", array( $node, $app, $destination, $consumer ) ); }
    private static function source_key( $node, $app, $capability, $version ) { return implode( "\x1F", array( $node, $app, $capability, $version ) ); }
    private static function consumer_accepts_source( $consumer, $event ) {
        $wanted = self::source_key( $event['source']['node_id'] ?? '', $event['source']['app_key'] ?? '', $event['source']['capability_key'] ?? '', $event['source']['catalog_version'] ?? '' );
        foreach ( $consumer['sources'] as $source ) {
            if ( $wanted === self::source_key( $source['node_id'], $source['app_key'], $source['capability_key'], $source['catalog_version'] ) ) { return true; }
        }
        return false;
    }
    private static function valid_consumer_source( $source ) { return self::exact_keys( $source, array( 'node_id', 'app_key', 'capability_key', 'catalog_version' ) ) && self::is_node( $source['node_id'] ) && self::is_app_key( $source['app_key'] ) && self::is_capability( $source['capability_key'], $source['app_key'] ) && self::is_semver( $source['catalog_version'] ); }
    private static function valid_sender( $sender ) { return self::exact_keys( $sender, array( 'node_id', 'app_key' ) ) && self::is_node( $sender['node_id'] ) && self::is_app_key( $sender['app_key'] ); }
    private static function is_node( $value ) { return self::is_app_key( $value ); }
    private static function is_app_key( $value ) { return is_string( $value ) && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}$/D', $value ); }
    private static function is_capability( $value, $app ) { return is_string( $value ) && strlen( $value ) <= 512 && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,7}$/D', $value ) && 0 === strpos( $value, $app . '.' ); }
    private static function is_semver( $value ) { return is_string( $value ) && strlen( $value ) <= 32 && 1 === preg_match( '/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value ); }
    private static function is_consumer_key( $value ) { return is_string( $value ) && strlen( $value ) <= 128 && '*' !== $value && 1 === preg_match( '/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63}){1,3}$/D', $value ); }
    private static function is_uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value ); }
    private static function is_sql_utc( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) && false !== strtotime( $value . ' UTC' ); }
    private static function exact_keys( $value, $expected ) { if ( ! is_array( $value ) || self::is_list( $value ) ) { return false; } $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $expected, SORT_STRING ); return $actual === $expected; }
    private static function is_list( $value ) { return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private static function utc_to_sql( $value ) { $time = is_string( $value ) ? strtotime( $value ) : false; return false === $time ? '' : gmdate( 'Y-m-d H:i:s', $time ); }

    private static function lock_name( $scope, $material ) {
        return is_string( $material ) && '' !== $material && in_array( $scope, array( 'catalog', 'identity', 'event_id' ), true ) ? 'faluss_evt_' . $scope . '_' . substr( hash( 'sha256', $material ), 0, 40 ) : self::unavailable();
    }
    private static function event_lock_names( $identity_hash, $event_id ) {
        $locks = array( self::lock_name( 'identity', $identity_hash ), self::lock_name( 'event_id', $event_id ) );
        if ( is_wp_error( $locks[0] ) || is_wp_error( $locks[1] ) || $locks[0] === $locks[1] ) { return self::unavailable(); }
        sort( $locks, SORT_STRING );
        return $locks;
    }
    private static function acquire_lock( $lock ) { global $wpdb; $result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock, 2 ) ); return ( 1 === $result || '1' === $result ) && ! self::database_has_error(); }
    private static function release_lock( $lock ) { global $wpdb; $result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); return ( 1 === $result || '1' === $result ) && ! self::database_has_error(); }
    private static function acquire_locks( $locks ) {
        $acquired = array();
        foreach ( $locks as $lock ) {
            if ( ! self::acquire_lock( $lock ) ) {
                self::release_locks( $acquired );
                return false;
            }
            $acquired[] = $lock;
        }
        return true;
    }
    private static function release_locks( $locks ) {
        $released = true;
        foreach ( array_reverse( $locks ) as $lock ) {
            if ( ! self::release_lock( $lock ) ) { $released = false; }
        }
        return $released;
    }
    private static function start_transaction() { global $wpdb; return false !== $wpdb->query( 'START TRANSACTION' ) && ! self::database_has_error(); }
    private static function commit() { global $wpdb; return false !== $wpdb->query( 'COMMIT' ) && ! self::database_has_error(); }
    private static function rollback() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ) && ! self::database_has_error(); }
    private static function database_has_error() { global $wpdb; return ! isset( $wpdb->last_error ) || ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error; }
    private static function uuid() { if ( ! function_exists( 'wp_generate_uuid4' ) ) { return self::unavailable(); } $uuid = wp_generate_uuid4(); return self::is_uuid( $uuid ) ? $uuid : self::unavailable(); }
    private static function unavailable() { return new WP_Error( 'faluss_events_unavailable' ); }
    private static function conflict() { return new WP_Error( 'faluss_events_conflict' ); }
    private static function incompatible() { return new WP_Error( 'faluss_events_incompatible' ); }
    private static function not_available() { return new WP_Error( 'faluss_events_not_available' ); }
    private static function registry_failure() { return new WP_Error( 'faluss_events_registry_unavailable' ); }
}
