<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Closed AN-01 payload and envelope semantics for the Hub consumer. */
final class Faluss_Analytics_Event_Validator {
    public static function payload_contracts() {
        return array_values( array_map( function ( $definition ) { return $definition['document_type']; }, self::definitions() ) );
    }

    public static function validate_payload( $payload, $contract, $event ) {
        if ( ! self::exact_keys( $contract, array( 'document_type', 'contract_version' ) ) || '1.0.0' !== $contract['contract_version'] || ! is_array( $event ) ) {
            return false;
        }
        $definition = self::definition( $event['event_type'] ?? '' );
        if ( ! is_array( $definition ) || $definition['document_type'] !== $contract['document_type'] ) {
            return false;
        }
        if ( 'faluss-hub.portal.viewed' === $event['event_type'] ) {
            return self::exact_keys( $payload, array( 'surface_key' ) ) && in_array( $payload['surface_key'], array( 'hub', 'apps', 'explore' ), true );
        }
        return array() === $payload;
    }

    public static function validate_event( $event ) {
        if ( ! class_exists( 'Faluss_Events_Envelope_Validator' ) || ! Faluss_Events_Envelope_Validator::validate_transport( $event ) ) {
            return false;
        }
        $definition = self::definition( $event['event_type'] ?? '' );
        if ( ! is_array( $definition ) || '1.0.0' !== $event['event_version'] || array( 'analytics.events' ) !== $event['destinations'] || 1 !== preg_match( '/^an01_event_[a-f0-9]{64}$/D', $event['source_event_reference'] ) ) {
            return false;
        }
        $occurred = strtotime( $event['occurred_at'] );
        $produced = strtotime( $event['produced_at'] );
        if ( false === $occurred || false === $produced || $produced < $occurred || $produced - $occurred > 3600 ) {
            return false;
        }
        $source = $event['source'];
        if ( $definition['node_id'] !== $source['node_id'] || $definition['app_key'] !== $source['app_key'] || $definition['app_key'] !== $source['owner'] || $definition['capability_key'] !== $source['capability_key'] || '1.0.0' !== $source['catalog_version'] ) {
            return false;
        }
        if ( ! self::exact_keys( $event['subject_context'], array( 'subject_type', 'subject_faluss_id' ) ) || 'faluss_member' !== $event['subject_context']['subject_type'] || ! self::uuid( $event['subject_context']['subject_faluss_id'] ) ) {
            return false;
        }
        $actor = $event['actor_context'];
        if ( ! self::exact_keys( $actor, array( 'actor_type', 'actor_faluss_id', 'anonymous_reference', 'anonymous_scope' ) ) ) {
            return false;
        }
        if ( 'member' === $definition['actor_type'] ) {
            if ( 'member' !== $actor['actor_type'] || $event['subject_context']['subject_faluss_id'] !== $actor['actor_faluss_id'] || null !== $actor['anonymous_reference'] || null !== $actor['anonymous_scope'] ) {
                return false;
            }
        } elseif ( 'anonymous' !== $actor['actor_type']
            || null !== $actor['actor_faluss_id']
            || null !== $actor['anonymous_reference']
            || null !== $actor['anonymous_scope'] ) {
            return false;
        }
        if ( null === $definition['object_type'] ) {
            if ( null !== $event['object_context'] ) {
                return false;
            }
        } else {
            $object = $event['object_context'];
            if ( ! self::exact_keys( $object, array( 'object_type', 'object_reference' ) ) || $definition['object_type'] !== $object['object_type'] || 1 !== preg_match( '/^an01_' . preg_quote( $definition['object_type'], '/' ) . '_[a-f0-9]{64}$/D', $object['object_reference'] ) ) {
                return false;
            }
        }
        return self::validate_payload( $event['payload'], $event['payload_contract'], $event );
    }

    public static function definition( $event_type ) {
        $definitions = self::definitions();
        return $definitions[ $event_type ] ?? null;
    }

    private static function definitions() {
        return array(
            'faluss-hub.portal.viewed' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'document_type' => 'faluss-hub.portal-viewed', 'actor_type' => 'member', 'object_type' => null, 'metric_key' => 'hub.portal.raw_views' ),
            'faluss-hub.app.opened' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'document_type' => 'faluss-hub.app-opened', 'actor_type' => 'member', 'object_type' => 'app', 'metric_key' => 'hub.app.opens' ),
            'faluss-hub.daily-reward.claimed' => array( 'node_id' => 'hub-node', 'app_key' => 'faluss-hub', 'capability_key' => 'faluss-hub.events', 'document_type' => 'faluss-hub.daily-reward-claimed', 'actor_type' => 'member', 'object_type' => null, 'metric_key' => 'hub.daily_reward.claims' ),
            'faluss-me.card.viewed' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'capability_key' => 'faluss-me.events', 'document_type' => 'faluss-me.card-viewed', 'actor_type' => 'anonymous', 'object_type' => null, 'metric_key' => 'me.card.raw_views' ),
            'faluss-me.link.clicked' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'capability_key' => 'faluss-me.events', 'document_type' => 'faluss-me.link-clicked', 'actor_type' => 'anonymous', 'object_type' => 'link', 'metric_key' => 'me.link.clicks' ),
            'faluss-me.collection.opened' => array( 'node_id' => 'me-node', 'app_key' => 'faluss-me', 'capability_key' => 'faluss-me.events', 'document_type' => 'faluss-me.collection-opened', 'actor_type' => 'anonymous', 'object_type' => 'collection', 'metric_key' => 'me.collection.opens' ),
        );
    }

    private static function exact_keys( $value, $expected ) {
        if ( ! is_array( $value ) || self::is_list( $value ) ) { return false; }
        $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $expected, SORT_STRING );
        return $actual === $expected;
    }
    private static function is_list( $value ) { return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ); }
}
