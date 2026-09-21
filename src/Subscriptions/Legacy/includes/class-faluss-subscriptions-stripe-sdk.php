<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Narrow Stripe adapter boundary. Production only loads the bundled, pinned SDK;
 * contracts inject a deterministic adapter through a PHP filter and never call
 * Stripe’s network API.
 */
final class Faluss_Subscriptions_Stripe_Sdk {
    const SDK_VERSION = '21.3.0';
    private static $loaded_by_faluss = false;

    /** @return Faluss_Subscriptions_Stripe_Adapter|WP_Error */
    public static function adapter() {
        $injected = function_exists( 'apply_filters' ) ? apply_filters( 'faluss_subscriptions_stripe_adapter', null ) : null;
        if ( $injected instanceof Faluss_Subscriptions_Stripe_Adapter ) {
            return $injected;
        }
        $key = Faluss_Subscriptions_Stripe_Config::secret_key();
        if ( is_wp_error( $key ) ) {
            return $key;
        }
        $loaded = self::load();
        if ( is_wp_error( $loaded ) ) {
            return $loaded;
        }
        try {
            return new Faluss_Subscriptions_Stripe_Adapter( new \Stripe\StripeClient( array(
                'api_key' => $key,
                'stripe_version' => Faluss_Subscriptions_Stripe_Config::API_VERSION,
            ) ) );
        } catch ( Exception $exception ) {
            return self::error( 'stripe_client_unavailable' );
        }
    }

    /** @return true|WP_Error */
    public static function load() {
        if ( class_exists( '\\Stripe\\StripeClient', false ) && ! self::$loaded_by_faluss ) {
            // A previously loaded global Stripe SDK can have an incompatible API
            // surface. Do not silently share it between WordPress extensions.
            return self::error( 'stripe_sdk_collision' );
        }
        $autoload = FALUSS_SUBSCRIPTIONS_DIR . 'vendor/autoload.php';
        if ( ! is_readable( $autoload ) ) {
            return self::error( 'stripe_sdk_missing' );
        }
        require_once $autoload;
        if ( ! class_exists( '\\Stripe\\StripeClient' ) || ! class_exists( '\\Stripe\\Webhook' ) ) {
            return self::error( 'stripe_sdk_invalid' );
        }
        self::$loaded_by_faluss = true;
        return true;
    }

    /** @return array<string,mixed>|WP_Error */
    public static function verify_webhook( $payload, $signature, $secret ) {
        $loaded = self::load();
        if ( is_wp_error( $loaded ) ) {
            return $loaded;
        }
        try {
            $event = \Stripe\Webhook::constructEvent( $payload, $signature, $secret, 300 );
            return Faluss_Subscriptions_Stripe_Adapter::normalise( $event );
        } catch ( Exception $exception ) {
            return self::error( 'stripe_webhook_signature_invalid' );
        }
    }

    /** @return WP_Error */
    private static function error( $code ) {
        return new WP_Error( $code, __( 'Le service Stripe n’est pas disponible.', 'faluss-subscriptions' ) );
    }
}

/** Adapter class kept deliberately small to make every Stripe network operation explicit. */
class Faluss_Subscriptions_Stripe_Adapter {
    protected $client;

    public function __construct( $client ) {
        $this->client = $client;
    }

    /** @return array<string,mixed>|WP_Error */
    public function verify_webhook( $payload, $signature, $secret ) {
        return Faluss_Subscriptions_Stripe_Sdk::verify_webhook( $payload, $signature, $secret );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_price( $price_id ) {
        return $this->call( static function( $client ) use ( $price_id ) { return $client->prices->retrieve( $price_id, array() ); } );
    }

    /**
     * Administrative retries deliberately fetch a canonical event from Stripe.
     * The signed delivery body is never stored locally.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_event( $event_id ) {
        return $this->call( static function( $client ) use ( $event_id ) { return $client->events->retrieve( $event_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function create_customer( $faluss_id ) {
        return $this->call( static function( $client ) use ( $faluss_id ) {
            return $client->customers->create( array( 'metadata' => array( 'faluss_id' => $faluss_id ) ) );
        } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function create_checkout_session( $parameters, $idempotency_key ) {
        return $this->call( static function( $client ) use ( $parameters, $idempotency_key ) {
            return $client->checkout->sessions->create( $parameters, array( 'idempotency_key' => $idempotency_key ) );
        } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_checkout_session( $session_id ) {
        return $this->call( static function( $client ) use ( $session_id ) { return $client->checkout->sessions->retrieve( $session_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_subscription( $subscription_id ) {
        return $this->call( static function( $client ) use ( $subscription_id ) { return $client->subscriptions->retrieve( $subscription_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_invoice( $invoice_id ) {
        return $this->call( static function( $client ) use ( $invoice_id ) { return $client->invoices->retrieve( $invoice_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_charge( $charge_id ) {
        return $this->call( static function( $client ) use ( $charge_id ) { return $client->charges->retrieve( $charge_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_payment_method( $payment_method_id ) {
        return $this->call( static function( $client ) use ( $payment_method_id ) { return $client->paymentMethods->retrieve( $payment_method_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function retrieve_customer( $customer_id ) {
        return $this->call( static function( $client ) use ( $customer_id ) { return $client->customers->retrieve( $customer_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function create_portal_session( $parameters ) {
        return $this->call( static function( $client ) use ( $parameters ) { return $client->billingPortal->sessions->create( $parameters ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function cancel_at_period_end( $subscription_id, $cancel ) {
        return $this->call( static function( $client ) use ( $subscription_id, $cancel ) {
            return $client->subscriptions->update( $subscription_id, array( 'cancel_at_period_end' => (bool) $cancel ) );
        } );
    }

    /** @return array<string,mixed>|WP_Error */
    public function cancel_now( $subscription_id ) {
        return $this->call( static function( $client ) use ( $subscription_id ) { return $client->subscriptions->cancel( $subscription_id, array() ); } );
    }

    /** @return array<string,mixed>|WP_Error */
    protected function call( $callback ) {
        try {
            return self::normalise( $callback( $this->client ) );
        } catch ( Exception $exception ) {
            return new WP_Error( self::safe_exception_code( $exception ), __( 'Stripe n’a pas répondu de manière exploitable.', 'faluss-subscriptions' ) );
        }
    }

    /**
     * Convert only known provider errors to audited, non-sensitive outcomes.
     * Provider messages, parameters and response bodies are deliberately never
     * passed across this boundary.
     */
    private static function safe_exception_code( $exception ) {
        if ( $exception instanceof \Stripe\Exception\InvalidRequestException && 'customer_tax_location_invalid' === $exception->getStripeCode() ) {
            return 'stripe_customer_tax_location_invalid';
        }
        if ( $exception instanceof \Stripe\Exception\InvalidRequestException
            && ( 'resource_missing' === $exception->getStripeCode() || ( method_exists( $exception, 'getHttpStatus' ) && 404 === (int) $exception->getHttpStatus() ) ) ) {
            // A claimed cancellation can be resumed after Stripe has already
            // removed the subscription. Keep that terminal provider result
            // distinguishable from a transport failure without exposing it.
            return 'stripe_subscription_not_found';
        }
        return 'stripe_transport_failed';
    }

    /** @return array<string,mixed> */
    public static function normalise( $value ) {
        if ( is_object( $value ) && method_exists( $value, 'toArray' ) ) {
            $value = $value->toArray();
        }
        return is_array( $value ) ? $value : array();
    }
}
