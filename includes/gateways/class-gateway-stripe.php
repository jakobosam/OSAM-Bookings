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
	 * Events the webhook listens for.
	 */
	const EVENTS = array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired' );

	/**
	 * Mode the site takes payments in.
	 *
	 * @return string live|test
	 */
	public function mode() {
		return 'live' === OSB_Settings::get( 'stripe_mode' ) ? 'live' : 'test';
	}

	/**
	 * Secret key for a mode (the current one by default).
	 *
	 * @param string $mode live|test.
	 * @return string
	 */
	private function secret( $mode = '' ) {
		$mode = $mode ? $mode : $this->mode();
		return trim( (string) OSB_Settings::get( 'live' === $mode ? 'stripe_live_secret' : 'stripe_test_secret' ) );
	}

	/**
	 * Which mode a secret key belongs to, read from its prefix.
	 *
	 * @param string $key Key.
	 * @return string live|test, or '' when it isn't a secret key.
	 */
	public static function key_mode( $key ) {
		return preg_match( '/^(?:sk|rk)_(live|test)_[A-Za-z0-9]{8,}$/', (string) $key, $m ) ? $m[1] : '';
	}

	/**
	 * A key with the middle hidden, safe to show on screen.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function mask( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 14 ? substr( $key, 0, 8 ) . '••••••' . substr( $key, -4 ) : ( '' === $key ? '' : '••••••' );
	}

	/**
	 * Check a pasted secret key with Stripe, store it, switch to its mode and set up the webhook.
	 *
	 * @param string $key What the admin pasted.
	 * @return array|WP_Error [ mode, name, warning ].
	 */
	public function connect( $key ) {
		$key = preg_replace( '/\s+/', '', (string) $key );
		if ( 0 === strpos( $key, 'pk_' ) ) {
			return new WP_Error( 'osb_stripe_key', __( 'That’s the publishable key (it starts pk_). The one needed is the Secret key, just underneath it in Stripe — click “Reveal”, then copy that one.', 'osam-bookings' ) );
		}
		if ( 0 === strpos( $key, 'whsec_' ) ) {
			return new WP_Error( 'osb_stripe_key', __( 'That’s a webhook signing secret (it starts whsec_). Paste your Secret key instead — the webhook is set up for you.', 'osam-bookings' ) );
		}
		$mode = self::key_mode( $key );
		if ( '' === $mode ) {
			return new WP_Error( 'osb_stripe_key', __( 'That doesn’t look like a Stripe Secret key. It starts with sk_live_ (or sk_test_ for testing). Copy it again from Stripe and paste the whole thing.', 'osam-bookings' ) );
		}
		$name    = '';
		$warning = '';
		$account = $this->request( 'GET', 'account', array(), $key );
		if ( is_wp_error( $account ) ) {
			// Restricted keys often can't read the account; they still work if they can use Checkout.
			$probe = 0 === strpos( $key, 'rk_' ) ? $this->request( 'GET', 'checkout/sessions', array( 'limit' => 1 ), $key ) : $account;
			if ( is_wp_error( $probe ) ) {
				/* translators: %s Stripe's message */
				return new WP_Error( 'osb_stripe_key', sprintf( __( 'Stripe didn’t accept that key: %s', 'osam-bookings' ), $probe->get_error_message() ) );
			}
		} else {
			foreach ( array( $account['settings']['dashboard']['display_name'] ?? '', $account['business_profile']['name'] ?? '', $account['email'] ?? '', $account['id'] ?? '' ) as $candidate ) {
				if ( '' !== trim( (string) $candidate ) ) {
					$name = sanitize_text_field( $candidate );
					break;
				}
			}
			if ( 'live' === $mode && isset( $account['charges_enabled'] ) && ! $account['charges_enabled'] ) {
				$warning = __( 'Stripe hasn’t finished setting up this account yet, so it can’t take payments. Open Stripe and complete the steps it’s asking for.', 'osam-bookings' );
			}
		}
		$changed = $key !== $this->secret( $mode );
		$values  = array(
			'stripe_' . $mode . '_secret'  => $key,
			'stripe_' . $mode . '_account' => $name,
			'stripe_mode'                  => $mode,
			'stripe_enabled'               => 1,
		);
		if ( $changed ) {
			// A different Stripe account: its webhook is a new one.
			$values[ 'stripe_' . $mode . '_webhook_id' ]     = '';
			$values[ 'stripe_' . $mode . '_webhook_secret' ] = '';
		}
		OSB_Settings::update( $values );
		$this->ensure_webhook( $mode );
		return array(
			'mode'    => $mode,
			'name'    => $name,
			'warning' => $warning,
		);
	}

	/**
	 * Address Stripe sends payment confirmations to.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'osb/v1/webhooks/stripe' );
	}

	/**
	 * Make sure Stripe has a webhook pointing at this site, creating or repairing it as needed.
	 * The outcome is remembered for the settings screen.
	 *
	 * @param string $mode live|test.
	 * @return true|WP_Error
	 */
	public function ensure_webhook( $mode = '' ) {
		$mode   = $mode ? $mode : $this->mode();
		$key    = $this->secret( $mode );
		$prefix = 'stripe_' . $mode . '_webhook_';
		if ( '' === $key ) {
			return new WP_Error( 'osb_stripe_setup', __( 'Stripe isn’t connected.', 'osam-bookings' ) );
		}
		$done = static function ( $result, array $extra = array() ) use ( $prefix ) {
			OSB_Settings::update( array_merge( $extra, array( $prefix . 'error' => is_wp_error( $result ) ? $result->get_error_message() : '' ) ) );
			return $result;
		};
		$url  = self::webhook_url();
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'localhost' === $host || preg_match( '/(\.local|\.localhost|\.test|\.invalid)$|^\d+\.\d+\.\d+\.\d+$|^\[/', $host ) ) {
			return $done( new WP_Error( 'osb_stripe_local', __( 'This site can’t be reached from the internet over https, so Stripe can’t send it payment confirmations. That’s normal for a test copy of a site. Bookings still confirm when the customer comes back from paying.', 'osam-bookings' ) ) );
		}
		$fields = array(
			'url'            => $url,
			'enabled_events' => self::EVENTS,
		);
		$id     = (string) OSB_Settings::get( $prefix . 'id' );
		if ( '' !== $id && '' !== (string) OSB_Settings::get( $prefix . 'secret' ) ) {
			$ep = $this->request( 'GET', 'webhook_endpoints/' . rawurlencode( $id ), array(), $key );
			if ( ! is_wp_error( $ep ) && empty( $ep['deleted'] ) ) {
				$events = isset( $ep['enabled_events'] ) ? (array) $ep['enabled_events'] : array();
				$ok     = $url === ( $ep['url'] ?? '' ) && 'enabled' === ( $ep['status'] ?? '' ) && ( in_array( '*', $events, true ) || ! array_diff( self::EVENTS, $events ) );
				if ( $ok ) {
					return $done( true );
				}
				// Site address changed, or the endpoint was switched off or edited: put it right.
				$fixed = $this->request( 'POST', 'webhook_endpoints/' . rawurlencode( $id ), $fields + array( 'disabled' => 'false' ), $key );
				if ( ! is_wp_error( $fixed ) ) {
					return $done( true );
				}
			}
		}
		// Clear out an earlier automatic endpoint for this address whose signing secret is gone
		// (Stripe only shows a secret once). Endpoints added by hand are left alone.
		$list = $this->request( 'GET', 'webhook_endpoints', array( 'limit' => 100 ), $key );
		if ( ! is_wp_error( $list ) && ! empty( $list['data'] ) ) {
			foreach ( $list['data'] as $ep ) {
				if ( $url === ( $ep['url'] ?? '' ) && '1' === (string) ( $ep['metadata']['osb'] ?? '' ) ) {
					$this->request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $ep['id'] ), array(), $key );
				}
			}
		}
		$made = $this->request(
			'POST',
			'webhook_endpoints',
			$fields + array(
				'description' => 'OSAM Bookings — ' . $host,
				'metadata'    => array( 'osb' => '1' ),
			),
			$key
		);
		if ( is_wp_error( $made ) || empty( $made['secret'] ) ) {
			$why = is_wp_error( $made ) ? $made->get_error_message() : __( 'Stripe didn’t return a signing secret.', 'osam-bookings' );
			/* translators: %s Stripe's message */
			return $done( new WP_Error( 'osb_stripe_webhook', sprintf( __( 'Stripe wouldn’t let this key set up payment confirmations (%s). Bookings still confirm when the customer comes back from paying. If you used a restricted key, give it “Webhook Endpoints: Write” or use the standard Secret key.', 'osam-bookings' ), $why ) ) );
		}
		return $done(
			true,
			array(
				$prefix . 'id'     => sanitize_text_field( $made['id'] ),
				$prefix . 'secret' => sanitize_text_field( $made['secret'] ),
			)
		);
	}

	/**
	 * Forget the key for a mode and remove the webhook this plugin created for it.
	 *
	 * @param string $mode live|test.
	 */
	public function disconnect( $mode ) {
		$mode   = 'live' === $mode ? 'live' : 'test';
		$prefix = 'stripe_' . $mode . '_';
		$id     = (string) OSB_Settings::get( $prefix . 'webhook_id' );
		if ( '' !== $id && '' !== $this->secret( $mode ) ) {
			$this->request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $id ), array(), $this->secret( $mode ) );
		}
		$values = array(
			$prefix . 'secret'         => '',
			$prefix . 'account'        => '',
			$prefix . 'webhook_id'     => '',
			$prefix . 'webhook_secret' => '',
			$prefix . 'webhook_error'  => '',
		);
		$other  = 'live' === $mode ? 'test' : 'live';
		if ( '' !== $this->secret( $other ) ) {
			$values['stripe_mode'] = $other;
		} else {
			$values['stripe_enabled'] = 0;
		}
		OSB_Settings::update( $values );
	}

	/**
	 * Signing secrets a webhook call may be signed with: the automatic one for the current mode,
	 * then one pasted by hand (sites set up before 2.17).
	 *
	 * @return string[]
	 */
	private function webhook_secrets() {
		return array_values(
			array_unique(
				array_filter(
					array(
						trim( (string) OSB_Settings::get( 'stripe_' . $this->mode() . '_webhook_secret' ) ),
						trim( (string) OSB_Settings::get( 'stripe_webhook_secret' ) ),
					)
				)
			)
		);
	}

	/**
	 * Are payment confirmations (the webhook) set up for the current mode?
	 *
	 * @return bool
	 */
	public function webhook_ready() {
		return (bool) $this->webhook_secrets();
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
	 * @param string $key    Secret key to use instead of the saved one (when checking a new key).
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array(), $key = '' ) {
		$args     = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . ( '' !== $key ? $key : $this->secret() ),
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
		$header  = (string) $request->get_header( 'stripe_signature' );
		$signed  = false;
		foreach ( $this->webhook_secrets() as $secret ) {
			if ( $this->verify_signature( $payload, $header, $secret ) ) {
				$signed = true;
				break;
			}
		}
		if ( ! $signed ) {
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
				// A "Pay now" link that wasn't finished leaves the booking as it was.
				if ( $booking && empty( $obj['metadata']['osb_pay'] ) ) {
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
			'stripe_title' => array( __( 'Name customers see at checkout', 'osam-bookings' ), 'text' ),
		);
	}

	/**
	 * The "paste your key" box, shared by Settings → Payments and the setup wizard.
	 *
	 * @param bool $connected Is a key already saved?
	 */
	public function key_field( $connected = false ) {
		?>
		<div class="osb-field">
			<label for="osb_stripe_key"><?php echo $connected ? esc_html__( 'New Secret key', 'osam-bookings' ) : esc_html__( 'Stripe Secret key', 'osam-bookings' ); ?></label>
			<input type="password" id="osb_stripe_key" name="stripe_key" class="large-text" value="" autocomplete="new-password" spellcheck="false" placeholder="sk_live_…">
			<p class="osb-help"><?php esc_html_e( 'Paste it and save. It’s checked with Stripe straight away, live or test is worked out from the key, and payment confirmations are set up for you — there’s nothing else to copy.', 'osam-bookings' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Step-by-step instructions for finding the key.
	 *
	 * @param bool $open Show expanded.
	 */
	public function key_steps( $open = true ) {
		?>
		<details class="osb-steps" <?php echo $open ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Where do I find my key?', 'osam-bookings' ); ?></summary>
			<ol>
				<li><?php echo wp_kses_post( __( 'Open your <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">Stripe API keys page ↗</a> and sign in. No Stripe account yet? <a href="https://dashboard.stripe.com/register" target="_blank" rel="noopener">Create one free ↗</a> first.', 'osam-bookings' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Under <strong>Standard keys</strong>, find <strong>Secret key</strong>, click <strong>Reveal</strong> and copy it. It starts with <code>sk_live_</code>.', 'osam-bookings' ) ); ?></li>
				<li><?php esc_html_e( 'Paste it in the box below and save.', 'osam-bookings' ); ?></li>
			</ol>
			<p class="osb-help"><?php echo wp_kses_post( __( 'Want to try it without real money first? Switch Stripe to <strong>Test mode</strong> (or a sandbox), copy the key that starts <code>sk_test_</code> and paste that instead. Swap to your live key when you’re ready.', 'osam-bookings' ) ); ?></p>
		</details>
		<?php
	}

	/**
	 * Settings → Payments: connection status and the key box (shown above settings_fields()).
	 *
	 * @param array $s Settings.
	 */
	public function settings_panel( array $s ) {
		$mode      = $this->mode();
		$connected = $this->is_configured();
		$other     = 'live' === $mode ? 'test' : 'live';
		$both      = $connected && '' !== $this->secret( $other );
		$action   = static function ( $name, $m ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=' . $name . '&mode=' . $m ), $name );
		};
		if ( ! $connected ) {
			$this->key_steps( true );
			echo '<div class="osb-gateway__full">';
			$this->key_field( false );
			echo '</div>';
			return;
		}
		$name   = (string) ( $s[ 'stripe_' . $mode . '_account' ] ?? '' );
		$error  = (string) ( $s[ 'stripe_' . $mode . '_webhook_error' ] ?? '' );
		$manual = '' !== trim( (string) $s['stripe_webhook_secret'] );
		?>
		<div class="osb-gateway__full">
			<div class="osb-alert osb-alert--<?php echo 'live' === $mode ? 'ok' : 'info'; ?>">
				<span class="dashicons dashicons-<?php echo 'live' === $mode ? 'yes-alt' : 'info'; ?>"></span>
				<div>
					<strong>
					<?php
					if ( 'live' === $mode ) {
						/* translators: %s Stripe account name */
						echo esc_html( $name ? sprintf( __( 'Connected to %s — taking real payments', 'osam-bookings' ), $name ) : __( 'Connected — taking real payments', 'osam-bookings' ) );
					} else {
						/* translators: %s Stripe account name */
						echo esc_html( $name ? sprintf( __( 'Connected to %s in test mode — no real money is taken', 'osam-bookings' ), $name ) : __( 'Connected in test mode — no real money is taken', 'osam-bookings' ) );
					}
					?>
					</strong>
					<br><?php /* translators: %s masked key */ echo esc_html( sprintf( __( 'Key: %s', 'osam-bookings' ), self::mask( $this->secret( $mode ) ) ) ); ?>
					<?php if ( 'test' === $mode ) : ?>
						<br><?php echo wp_kses_post( __( 'Pay with Stripe’s test card <code>4242 4242 4242 4242</code>, any future expiry date and any 3 digits. When you’re ready for real payments, paste your live key under “Use a different key”.', 'osam-bookings' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $this->webhook_ready() && '' === $error ) : ?>
				<p class="osb-help"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php echo esc_html( $manual && '' === (string) ( $s[ 'stripe_' . $mode . '_webhook_secret' ] ?? '' ) ? __( 'Payment confirmations: set up by hand (webhook signing secret saved).', 'osam-bookings' ) : __( 'Payment confirmations: set up automatically. Bookings are confirmed even if the customer closes the page after paying.', 'osam-bookings' ) ); ?></p>
			<?php else : ?>
				<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><strong><?php esc_html_e( 'Payment confirmations aren’t set up', 'osam-bookings' ); ?></strong><br><?php echo esc_html( $error ? $error : __( 'Click “Check connection” to set them up.', 'osam-bookings' ) ); ?></div></div>
			<?php endif; ?>
			<p>
				<a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $action( 'osb_stripe_check', $mode ) ); ?>"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Check connection', 'osam-bookings' ); ?></a>
				<a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( 'live' === $mode ? 'https://dashboard.stripe.com/payments' : 'https://dashboard.stripe.com/test/payments' ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span><?php esc_html_e( 'Open Stripe', 'osam-bookings' ); ?></a>
				<a class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" href="<?php echo esc_url( $action( 'osb_stripe_disconnect', $mode ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Stripe? Customers won’t be able to pay by card until you connect again.', 'osam-bookings' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'osam-bookings' ); ?></a>
			</p>
			<?php if ( $both ) : ?>
				<div class="osb-field osb-field--half">
					<label for="osb_stripe_mode"><?php esc_html_e( 'Take payments in', 'osam-bookings' ); ?></label>
					<select id="osb_stripe_mode" name="stripe_mode">
						<option value="live" <?php selected( 'live', $mode ); ?>><?php esc_html_e( 'Live — real payments', 'osam-bookings' ); ?></option>
						<option value="test" <?php selected( 'test', $mode ); ?>><?php esc_html_e( 'Test — no real money', 'osam-bookings' ); ?></option>
					</select>
					<p class="osb-help"><?php esc_html_e( 'Both a live and a test key are saved, so you can switch between them here.', 'osam-bookings' ); ?></p>
				</div>
			<?php endif; ?>
			<details class="osb-advanced">
				<summary><?php esc_html_e( 'Use a different key', 'osam-bookings' ); ?></summary>
				<?php
				$this->key_steps( false );
				$this->key_field( true );
				?>
			</details>
			<?php if ( $manual || '' !== $error ) : ?>
			<details class="osb-advanced">
				<summary><?php esc_html_e( 'Set up payment confirmations by hand', 'osam-bookings' ); ?></summary>
				<p class="osb-help">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: 1: URL, 2: event names */
							__( 'Only needed if the automatic setup above didn’t work. In Stripe go to Developers → Webhooks → Add endpoint, use the address %1$s and choose the events %2$s. Stripe then shows a signing secret (starts whsec_) — paste it here.', 'osam-bookings' ),
							'<code>' . esc_html( self::webhook_url() ) . '</code>',
							'<code>' . implode( '</code>, <code>', array_map( 'esc_html', self::EVENTS ) ) . '</code>'
						)
					);
					?>
				</p>
				<div class="osb-field">
					<label for="osb_stripe_whsec"><?php esc_html_e( 'Webhook signing secret', 'osam-bookings' ); ?></label>
					<input type="password" id="osb_stripe_whsec" name="stripe_webhook_secret" class="large-text" autocomplete="new-password" value="<?php echo $manual ? '••••••••' : ''; ?>" placeholder="whsec_…">
				</div>
			</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Settings → Payments: handle what settings_panel() posts.
	 *
	 * @param array $post Unslashed form data.
	 * @return array Extra setting values to store.
	 */
	public function save_settings( array $post ) {
		$v = array();
		if ( isset( $post['stripe_webhook_secret'] ) ) {
			$manual = trim( (string) $post['stripe_webhook_secret'] );
			if ( '••••••••' !== $manual ) {
				$v['stripe_webhook_secret'] = preg_replace( '/[^\x21-\x7e]/', '', $manual );
			}
		}
		if ( isset( $post['stripe_mode'] ) ) {
			$want = 'live' === $post['stripe_mode'] ? 'live' : 'test';
			if ( '' !== $this->secret( $want ) ) {
				$v['stripe_mode'] = $want;
			}
		}
		$key = isset( $post['stripe_key'] ) ? trim( (string) $post['stripe_key'] ) : '';
		if ( '' !== $key ) {
			$r = $this->connect( $key );
			if ( is_wp_error( $r ) ) {
				OSB_Admin::flash_error( $r->get_error_message() );
				$v['stripe_enabled'] = $this->is_configured() && ! empty( $post['stripe_enabled'] ) ? 1 : 0;
			} else {
				$v['stripe_mode']    = $r['mode'];
				$v['stripe_enabled'] = 1;
				if ( $r['warning'] ) {
					OSB_Admin::flash_warnings( array( $r['warning'] ) );
				}
			}
		} elseif ( ! empty( $post['stripe_enabled'] ) && $this->is_configured() && isset( $v['stripe_mode'] ) && $v['stripe_mode'] !== $this->mode() ) {
			// Switched between the saved live and test keys: make sure that mode has its webhook.
			OSB_Settings::update( array( 'stripe_mode' => $v['stripe_mode'] ) );
			$this->ensure_webhook( $v['stripe_mode'] );
		}
		return $v;
	}
}
