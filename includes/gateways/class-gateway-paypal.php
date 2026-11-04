<?php
/**
 * PayPal Checkout via the Orders v2 REST API — no SDK required.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates an order, redirects to PayPal, captures on return.
 */
class OSB_Gateway_Paypal extends OSB_Gateway {

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = 'paypal';

	/**
	 * Admin name.
	 *
	 * @return string
	 */
	public function admin_name() {
		return __( 'PayPal', 'osam-bookings' );
	}

	/**
	 * Configured?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) OSB_Settings::get( 'paypal_client_id' ) ) && '' !== trim( (string) OSB_Settings::get( 'paypal_secret' ) );
	}

	/**
	 * API base.
	 *
	 * @return string
	 */
	private function base() {
		return 'live' === OSB_Settings::get( 'paypal_mode' ) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	/**
	 * OAuth access token (cached).
	 *
	 * @return string|WP_Error
	 */
	private function token() {
		$client = trim( (string) OSB_Settings::get( 'paypal_client_id' ) );
		$key    = 'osb_pp_token_' . md5( $client . OSB_Settings::get( 'paypal_mode' ) );
		$cached = get_transient( $key );
		if ( $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( $client . ':' . trim( (string) OSB_Settings::get( 'paypal_secret' ) ) ),
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$this->log( 'Auth failed', $data );
			return new WP_Error( 'osb_paypal', __( 'PayPal authentication failed. Check the client ID and secret.', 'osam-bookings' ) );
		}
		set_transient( $key, $data['access_token'], max( 60, (int) $data['expires_in'] - 300 ) );
		return $data['access_token'];
	}

	/**
	 * Call the API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error [ code, data ]
	 */
	private function request( $method, $path, $body = null ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'     => 'Bearer ' . $token,
				'Content-Type'      => 'application/json',
				'PayPal-Request-Id' => wp_generate_uuid4(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->base() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array( wp_remote_retrieve_response_code( $response ), json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Create the order and redirect to PayPal.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Page URL.
	 * @return array|WP_Error
	 */
	public function process( $booking, $return_url ) {
		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => 'booking-' . $booking->id,
					'custom_id'    => (string) $booking->id,
					'invoice_id'   => 'OSB-' . $booking->id . '-' . substr( $booking->token, 0, 6 ),
					'description'  => mb_substr( $this->item_description( $booking ), 0, 127 ),
					'amount'       => array(
						'currency_code' => strtoupper( $booking->currency ),
						'value'         => number_format( OSB_Repeat::charge_total( $booking ), 2, '.', '' ),
					),
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'brand_name'          => mb_substr( get_bloginfo( 'name' ), 0, 127 ),
						'shipping_preference' => 'NO_SHIPPING',
						'user_action'         => 'PAY_NOW',
						'return_url'          => $this->return_url( $return_url, $booking, 'success' ),
						'cancel_url'          => $this->return_url( $return_url, $booking, 'cancelled' ),
					),
				),
			),
		);
		$body = apply_filters( 'osb_paypal_order_body', $body, $booking );

		$result = $this->request( 'POST', '/v2/checkout/orders', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $code, $data ) = $result;
		if ( $code >= 400 || empty( $data['id'] ) ) {
			$this->log( 'Create order failed', $data );
			return new WP_Error( 'osb_paypal', isset( $data['message'] ) ? $data['message'] : __( 'Could not start PayPal checkout.', 'osam-bookings' ) );
		}
		$approve = '';
		foreach ( (array) $data['links'] as $link ) {
			if ( in_array( $link['rel'], array( 'payer-action', 'approve' ), true ) ) {
				$approve = $link['href'];
			}
		}
		if ( ! $approve ) {
			return new WP_Error( 'osb_paypal', __( 'PayPal did not return an approval link.', 'osam-bookings' ) );
		}
		OSB_DB::add_payment( $booking->id, $this->id, $data['id'], OSB_Repeat::charge_total( $booking ), $booking->currency, 'initiated' );
		return array( 'redirect' => $approve );
	}

	/**
	 * Capture after approval.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public function handle_return( $booking ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( ! $order_id ) {
			return 'pending';
		}

		// The order must be one we created for this booking.
		$known = false;
		foreach ( OSB_DB::payments( $booking->id ) as $p ) {
			if ( $p->gateway === $this->id && $p->transaction_id === $order_id ) {
				$known = true;
			}
		}
		if ( ! $known ) {
			return 'failed';
		}

		$result = $this->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', new stdClass() );
		if ( is_wp_error( $result ) ) {
			return 'pending';
		}
		list( $code, $data ) = $result;

		if ( 422 === (int) $code ) {
			// Already captured (e.g. page refresh) — read the order instead.
			$result = $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $order_id ) );
			if ( is_wp_error( $result ) ) {
				return 'pending';
			}
			list( $code, $data ) = $result;
		}

		if ( isset( $data['status'] ) && 'COMPLETED' === $data['status'] ) {
			$unit    = $data['purchase_units'][0];
			$capture = isset( $unit['payments']['captures'][0] ) ? $unit['payments']['captures'][0] : array();
			if ( isset( $unit['custom_id'] ) && (int) $unit['custom_id'] !== (int) $booking->id ) {
				return 'failed';
			}
			$txn    = isset( $capture['id'] ) ? $capture['id'] : $order_id;
			$amount = isset( $capture['amount']['value'] ) ? (float) $capture['amount']['value'] : (float) $booking->amount;
			OSB_Bookings::mark_paid( $booking, $this->id, $txn, $amount, array( 'order' => $order_id ) );
			return 'success';
		}

		$this->log( 'Capture not completed', $data );
		return 'failed';
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function settings_fields() {
		return array(
			'paypal_title'     => array( __( 'Title shown to customers', 'osam-bookings' ), 'text' ),
			'paypal_mode'      => array(
				__( 'Mode', 'osam-bookings' ),
				'select',
				array(
					'sandbox' => __( 'Sandbox', 'osam-bookings' ),
					'live'    => __( 'Live', 'osam-bookings' ),
				),
			),
			'paypal_client_id' => array( __( 'Client ID', 'osam-bookings' ), 'text', null, __( 'From developer.paypal.com → Apps & Credentials (REST API app).', 'osam-bookings' ) ),
			'paypal_secret'    => array( __( 'Secret', 'osam-bookings' ), 'password' ),
		);
	}
}
