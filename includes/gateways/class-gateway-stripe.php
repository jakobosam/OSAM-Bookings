<?php
/**
 * Stripe Checkout (hosted payment page) — no SDK required.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a Checkout Session, verifies on return and via webhook.
 */
class OSB_Gateway_Stripe extends OSB_Gateway {

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = 'stripe';

	/**
	 * Admin name.
	 *
	 * @return string
	 */
	public function admin_name() {
		return __( 'Stripe', 'osam-bookings' );
	}

	/**
	 * Secret key for the current mode.
	 *
	 * @return string
	 */
	private function secret() {
		return trim( (string) OSB_Settings::get( 'live' === OSB_Settings::get( 'stripe_mode' ) ? 'stripe_live_secret' : 'stripe_test_secret' ) );
	}

	/**
	 * Configured?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->secret();
	}

	/**
	 * Call the Stripe API.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after /v1/.
	 * @param array  $body   Form params.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array() ) {
		$args     = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
		);
		$url = 'https://api.stripe.com/v1/' . $path;
		if ( 'GET' === $method ) {
			$url = $body ? $url . '?' . http_build_query( $body ) : $url;
		} else {
			$args['body'] = http_build_query( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Stripe request failed.', 'osam-bookings' );
			$this->log( 'API error', $data );
			return new WP_Error( 'osb_stripe', $msg );
		}
		return $data;
	}

	/**
	 * Smallest currency unit.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	private function minor_units( $amount, $currency ) {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		return in_array( strtoupper( $currency ), $zero_decimal, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * Create the Checkout Session and redirect.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Page URL.
	 * @return array|WP_Error
	 */
	public function process( $booking, $return_url ) {
		if ( class_exists( 'OSB_Restaurant' ) && OSB_Restaurant::needs_card_hold( $booking ) ) {
			return $this->process_setup( $booking, $return_url );
		}
		// Stripe requires expiry between 30 minutes and 24 hours from now.
		$hold    = max( 31, (int) OSB_Settings::get( 'pending_payment_minutes' ) );
		$success = $this->return_url( $return_url, $booking, 'success' );
		$success = add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $success );
		// add_query_arg encodes braces; Stripe needs them literal.
		$success = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $success );

		$params = array(
			'mode'                                              => 'payment',
			'client_reference_id'                               => (string) $booking->id,
			'customer_email'                                    => $booking->customer_email,
			'success_url'                                       => $success,
			'cancel_url'                                        => $this->return_url( $return_url, $booking, 'cancelled' ),
			'expires_at'                                        => time() + min( 24 * 60, $hold ) * 60,
			'line_items[0][quantity]'                           => 1,
			'line_items[0][price_data][currency]'               => strtolower( $booking->currency ),
			'line_items[0][price_data][unit_amount]'            => $this->minor_units( OSB_Repeat::charge_total( $booking ), $booking->currency ),
			'line_items[0][price_data][product_data][name]'     => $this->item_description( $booking ),
			'metadata[osb_booking_id]'                          => (string) $booking->id,
			'metadata[osb_token]'                               => $booking->token,
			'payment_intent_data[metadata][osb_booking_id]'     => (string) $booking->id,
			'payment_intent_data[description]'                  => sprintf( 'Booking #%d', $booking->id ),
		);
		$params = apply_filters( 'osb_stripe_session_params', $params, $booking );

		$session = $this->request( 'POST', 'checkout/sessions', $params );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		OSB_DB::add_payment( $booking->id, $this->id, $session['id'], OSB_Repeat::charge_total( $booking ), $booking->currency, 'initiated' );
		return array( 'redirect' => $session['url'] );
	}

	/**
	 * Card hold: a Checkout Session in setup mode saves the customer's card for a later
	 * no-show charge without taking any money now.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Page URL.
	 * @return array|WP_Error
	 */
	private function process_setup( $booking, $return_url ) {
		$customer = $this->request(
			'POST',
			'customers',
			array(
				'email'                    => $booking->customer_email,
				'name'                     => $booking->customer_name,
				'metadata[osb_booking_id]' => (string) $booking->id,
			)
		);
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$hold    = max( 31, (int) OSB_Settings::get( 'pending_payment_minutes' ) );
		$success = $this->return_url( $return_url, $booking, 'success' );
		$success = add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', $success );
		$success = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $success );
		$params  = array(
			'mode'                                     => 'setup',
			'customer'                                 => $customer['id'],
			'payment_method_types[0]'                  => 'card',
			'client_reference_id'                      => (string) $booking->id,
			'success_url'                              => $success,
			'cancel_url'                               => $this->return_url( $return_url, $booking, 'cancelled' ),
			'expires_at'                               => time() + min( 24 * 60, $hold ) * 60,
			'metadata[osb_booking_id]'                 => (string) $booking->id,
			'metadata[osb_token]'                      => $booking->token,
			'metadata[osb_hold]'                       => '1',
			'setup_intent_data[metadata][osb_booking_id]' => (string) $booking->id,
			'setup_intent_data[description]'           => sprintf( 'Card guarantee for booking #%d — %s', $booking->id, $this->item_description( $booking ) ),
		);
		$params  = apply_filters( 'osb_stripe_setup_session_params', $params, $booking );
		$session = $this->request( 'POST', 'checkout/sessions', $params );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		OSB_DB::add_payment( $booking->id, $this->id, $session['id'], 0, $booking->currency, 'initiated' );
		return array( 'redirect' => $session['url'] );
	}

	/**
	 * Record a saved card from a completed setup-mode session.
	 *
	 * @param array  $session Session.
	 * @param object $booking Booking.
	 * @return bool
	 */
	private function apply_setup( array $session, $booking ) {
		if ( 'complete' !== ( isset( $session['status'] ) ? $session['status'] : '' ) || empty( $session['setup_intent'] ) ) {
			return false;
		}
		$intent = $session['setup_intent'];
		if ( ! is_array( $intent ) ) {
			$intent = $this->request( 'GET', 'setup_intents/' . rawurlencode( (string) $intent ) );
			if ( is_wp_error( $intent ) ) {
				return false;
			}
		}
		if ( 'succeeded' !== ( isset( $intent['status'] ) ? $intent['status'] : '' ) || empty( $intent['payment_method'] ) ) {
			return false;
		}
		global $wpdb;
		$t = OSB_Install::table( 'payments' );
		// Return page and webhook can both arrive: record the card once.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE booking_id = %d AND gateway = %s AND transaction_id = %s AND status = 'card_held' LIMIT 1", $booking->id, $this->id, $intent['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return true;
		}
		$pm       = is_array( $intent['payment_method'] ) ? $intent['payment_method']['id'] : $intent['payment_method'];
		$customer = ! empty( $intent['customer'] ) ? ( is_array( $intent['customer'] ) ? $intent['customer']['id'] : $intent['customer'] ) : ( isset( $session['customer'] ) ? $session['customer'] : '' );

		$fee = (float) $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM $t WHERE booking_id = %d AND status = 'hold_pending' ORDER BY id DESC LIMIT 1", $booking->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $t, array( 'booking_id' => (int) $booking->id, 'status' => 'hold_pending' ) );
		OSB_DB::add_payment( $booking->id, $this->id, $intent['id'], $fee, $booking->currency, 'card_held', array( 'customer' => $customer, 'payment_method' => $pm ) );
		OSB_DB::update(
			'bookings',
			$booking->id,
			array(
				'payment_status' => 'card_held',
				'updated_at'     => current_time( 'mysql' ),
			)
		);
		if ( in_array( $booking->status, array( 'pending_payment', 'cancelled', 'payment_failed' ), true ) ) {
			OSB_Bookings::set_status( $booking->id, 'approval' === OSB_Settings::get( 'rest_confirm' ) ? 'pending' : 'confirmed' );
		}
		do_action( 'osb_booking_card_saved', OSB_DB::get( 'bookings', $booking->id ) );
		return true;
	}

	/**
	 * Charge a card saved earlier (no-show fee), off-session.
	 *
	 * @param object $booking  Booking.
	 * @param string $customer Stripe customer id.
	 * @param string $pm       Payment method id.
	 * @param float  $amount   Amount.
	 * @return array|WP_Error PaymentIntent.
	 */
	public function charge_saved_card( $booking, $customer, $pm, $amount ) {
		$intent = $this->request(
			'POST',
			'payment_intents',
			array(
				'amount'                   => $this->minor_units( $amount, $booking->currency ),
				'currency'                 => strtolower( $booking->currency ),
				'customer'                 => $customer,
				'payment_method'           => $pm,
				'off_session'              => 'true',
				'confirm'                  => 'true',
				'description'              => sprintf( 'No-show fee — booking #%d', $booking->id ),
				'metadata[osb_booking_id]' => (string) $booking->id,
				'metadata[osb_no_show]'    => '1',
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}
		if ( 'succeeded' !== ( isset( $intent['status'] ) ? $intent['status'] : '' ) ) {
			return new WP_Error( 'osb_stripe', __( 'The card could not be charged without the customer (their bank asked them to approve it). Contact them to pay instead.', 'osam-bookings' ) );
		}
		return $intent;
	}

	/**
	 * Verify a completed session.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public function handle_return( $booking ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		if ( ! $session_id ) {
			return 'pending';
		}
		$session = $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return 'pending';
		}
		return $this->apply_session( $session ) ? 'success' : 'pending';
	}

	/**
	 * Mark the booking paid if a session is paid and belongs to it.
	 *
	 * @param array $session Session object.
	 * @return bool Paid.
	 */
	private function apply_session( array $session ) {
		$booking_id = isset( $session['metadata']['osb_booking_id'] ) ? (int) $session['metadata']['osb_booking_id'] : 0;
		$booking    = $booking_id ? OSB_DB::get( 'bookings', $booking_id ) : null;
		if ( ! $booking || ! isset( $session['metadata']['osb_token'] ) || ! hash_equals( $booking->token, (string) $session['metadata']['osb_token'] ) ) {
			return false;
		}
		if ( 'setup' === ( isset( $session['mode'] ) ? $session['mode'] : '' ) ) {
			return $this->apply_setup( $session, $booking );
		}
		if ( 'paid' !== ( isset( $session['payment_status'] ) ? $session['payment_status'] : '' ) ) {
			return false;
		}
		$txn    = ! empty( $session['payment_intent'] ) ? ( is_array( $session['payment_intent'] ) ? $session['payment_intent']['id'] : $session['payment_intent'] ) : $session['id'];
		$amount = isset( $session['amount_total'] ) ? $session['amount_total'] / 100 : $booking->amount;
		OSB_Bookings::mark_paid( $booking, $this->id, $txn, $amount, array( 'session' => $session['id'] ) );
		return true;
	}

	/**
	 * Webhook endpoint handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$secret  = trim( (string) OSB_Settings::get( 'stripe_webhook_secret' ) );
		$header  = (string) $request->get_header( 'stripe_signature' );

		if ( ! $secret || ! $this->verify_signature( $payload, $header, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		$type  = isset( $event['type'] ) ? $event['type'] : '';
		$obj   = isset( $event['data']['object'] ) ? $event['data']['object'] : array();

		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$this->apply_session( $obj );
				break;
			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				$booking_id = isset( $obj['metadata']['osb_booking_id'] ) ? (int) $obj['metadata']['osb_booking_id'] : 0;
				$booking    = $booking_id ? OSB_DB::get( 'bookings', $booking_id ) : null;
				if ( $booking ) {
					OSB_Bookings::payment_failed( $booking->id );
				}
				break;
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Verify Stripe-Signature header (v1 scheme).
	 *
	 * @param string $payload Raw body.
	 * @param string $header  Header value.
	 * @param string $secret  Endpoint secret.
	 * @return bool
	 */
	public function verify_signature( $payload, $header, $secret ) {
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function settings_fields() {
		return array(
			'stripe_title'          => array( __( 'Title shown to customers', 'osam-bookings' ), 'text' ),
			'stripe_mode'           => array(
				__( 'Mode', 'osam-bookings' ),
				'select',
				array(
					'test' => __( 'Test', 'osam-bookings' ),
					'live' => __( 'Live', 'osam-bookings' ),
				),
			),
			'stripe_test_secret'    => array( __( 'Test secret key', 'osam-bookings' ), 'password', null, 'sk_test_… or rk_test_… (restricted key with Checkout Sessions write access)' ),
			'stripe_live_secret'    => array( __( 'Live secret key', 'osam-bookings' ), 'password', null, 'sk_live_… or rk_live_…' ),
			'stripe_webhook_secret' => array(
				__( 'Webhook signing secret', 'osam-bookings' ),
				'password',
				null,
				sprintf(
					/* translators: %s URL */
					__( 'Add a webhook endpoint in Stripe pointing to %s with events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired. Bookings still confirm on return without it, but the webhook covers customers who close the tab.', 'osam-bookings' ),
					'<code>' . esc_html( rest_url( 'osb/v1/webhooks/stripe' ) ) . '</code>'
				),
			),
		);
	}
}
