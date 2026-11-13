<?php
/**
 * Payment gateway registry and return handling.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds gateway instances.
 */
class OSB_Payments {

	/**
	 * Gateways keyed by id.
	 *
	 * @var OSB_Gateway[]
	 */
	private $gateways = array();

	/**
	 * Register built-in and third-party gateways.
	 */
	public function __construct() {
		$classes = apply_filters(
			'osb_payment_gateways',
			array( 'OSB_Gateway_Onsite', 'OSB_Gateway_Stripe', 'OSB_Gateway_Paypal', 'OSB_Gateway_Woocommerce' )
		);
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				$gateway                        = new $class();
				$this->gateways[ $gateway->id ] = $gateway;
			}
		}
		add_action( 'template_redirect', array( $this, 'handle_return' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'pay_page' ), 6 );
	}

	/**
	 * All gateways.
	 *
	 * @return OSB_Gateway[]
	 */
	public function all() {
		return $this->gateways;
	}

	/**
	 * Enabled gateways.
	 *
	 * @return OSB_Gateway[]
	 */
	public function enabled() {
		return array_filter(
			$this->gateways,
			static function ( $g ) {
				return $g->is_enabled();
			}
		);
	}

	/**
	 * Get one.
	 *
	 * @param string $id Id.
	 * @return OSB_Gateway|null
	 */
	public function get( $id ) {
		return isset( $this->gateways[ $id ] ) ? $this->gateways[ $id ] : null;
	}

	/**
	 * Customer returning from Stripe/PayPal: verify, then redirect to a clean result URL.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['osb_return'] ) || empty( $_GET['osb_booking'] ) ) {
			return;
		}
		$gateway = $this->get( sanitize_key( wp_unslash( $_GET['osb_return'] ) ) );
		$booking = OSB_DB::booking_by_token( sanitize_text_field( wp_unslash( $_GET['osb_booking'] ) ) );
		$result  = isset( $_GET['osb_result'] ) ? sanitize_key( wp_unslash( $_GET['osb_result'] ) ) : '';
		// phpcs:enable

		if ( ! $gateway || ! $booking ) {
			return;
		}

		// Paying an existing booking from its "Pay now" link: giving up must not release or cancel anything,
		// and the customer goes back to the payment page rather than the booking form.
		$paying = isset( $_GET['osb_pay'] ) && hash_equals( (string) $booking->token, sanitize_text_field( wp_unslash( $_GET['osb_pay'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'cancelled' === $result ) {
			if ( ! $paying ) {
				OSB_Bookings::payment_failed( $booking->id );
			}
			$outcome = 'cancelled';
		} else {
			$outcome = $gateway->handle_return( $booking );
		}

		$url = remove_query_arg( array( 'osb_return', 'osb_result', 'session_id', 'token', 'PayerID', 'osb_pay', 'osb_booking', 'osb_outcome' ) );
		$url = add_query_arg(
			array(
				( $paying ? 'osb_pay' : 'osb_booking' ) => $booking->token,
				'osb_outcome'                           => $outcome,
			),
			$url
		);
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Paying for a booking that already exists ("Pay now" on invoices, the booking page and My bookings)
	 * ------------------------------------------------------------------ */

	/**
	 * Is the "Pay now" button switched on (Settings → Invoices)? On unless switched off.
	 *
	 * @return bool
	 */
	public static function pay_button_on() {
		return (bool) apply_filters( 'osb_pay_button_enabled', (bool) OSB_Settings::get( 'inv_pay_button', 1 ) );
	}

	/**
	 * What is still owed on a booking: its price (plus the later dates a repeat booking's first payment
	 * covers) less the payments already received. Nothing when it is paid, free, refunded or cancelled.
	 *
	 * @param object $booking Booking.
	 * @return float
	 */
	public static function outstanding( $booking ) {
		if ( ! $booking || empty( $booking->id ) || (float) $booking->amount <= 0 || 'unpaid' !== $booking->payment_status ) {
			return 0.0;
		}
		$statuses = (array) apply_filters( 'osb_pay_statuses', array( 'pending_payment', 'confirmed', 'completed', 'no_show' ), $booking );
		if ( ! in_array( $booking->status, $statuses, true ) ) {
			return 0.0; // Cancelled, payment given up on, or still waiting to be approved.
		}
		if ( 'pending_payment' === $booking->status && OSB_Repeat::is_follower( $booking ) ) {
			return 0.0; // Paid for with the first date of the series.
		}
		$paid = 0;
		foreach ( (array) OSB_DB::payments( $booking->id ) as $p ) {
			if ( 'completed' === $p->status && (float) $p->amount > 0 && false === strpos( (string) $p->data, 'no_show_fee' ) ) {
				$paid += (float) $p->amount;
			}
		}
		return (float) apply_filters( 'osb_booking_outstanding', max( 0, round( OSB_Repeat::charge_total( $booking ) - $paid, 2 ) ), $booking );
	}

	/**
	 * Online payment methods this booking can be paid with now.
	 *
	 * @param object $booking Booking.
	 * @return OSB_Gateway[] Keyed by id.
	 */
	public static function pay_gateways( $booking ) {
		$item = null;
		if ( 'course' === $booking->type && ! empty( $booking->course_id ) ) {
			$item = OSB_DB::get( 'courses', (int) $booking->course_id );
		} elseif ( ! empty( $booking->event_id ) ) {
			$item = OSB_DB::get( 'events', (int) $booking->event_id );
		} elseif ( ! empty( $booking->service_id ) ) {
			$item = OSB_DB::get( 'services', (int) $booking->service_id );
		}
		$limit = $item && isset( $item->payment_methods ) ? array_filter( array_map( 'trim', explode( ',', (string) $item->payment_methods ) ) ) : array();
		$out   = array();
		foreach ( osb()->payments->enabled() as $id => $gateway ) {
			if ( 'pending_payment' !== $gateway->initial_status() ) {
				continue; // Pay on arrival and the like.
			}
			if ( $limit && ! in_array( $id, $limit, true ) ) {
				continue;
			}
			if ( 'woo' === $id && 'pending_payment' !== $booking->status ) {
				// The WooCommerce checkout only accepts bookings still awaiting payment, and a failed or
				// cancelled order there cancels a confirmed booking.
				continue;
			}
			$out[ $id ] = $gateway;
		}
		return (array) apply_filters( 'osb_pay_gateways', $out, $booking );
	}

	/**
	 * Amount the customer can pay online right now (0 = no button).
	 *
	 * @param object $booking Booking.
	 * @return float
	 */
	public static function payable( $booking ) {
		if ( ! self::pay_button_on() ) {
			return 0.0;
		}
		$due = self::outstanding( $booking );
		return $due > 0 && self::pay_gateways( $booking ) ? $due : 0.0;
	}

	/**
	 * Private "pay for this booking" link (uses the booking's secret token). Opens on the booking page's
	 * address, or the home page when none is set.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function pay_url( $booking ) {
		$page = (int) OSB_Settings::get( 'booking_page' );
		$base = $page ? get_permalink( $page ) : '';
		return add_query_arg( 'osb_pay', $booking->token, $base ? $base : home_url( '/' ) );
	}

	/**
	 * Button wording.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function pay_label( $booking ) {
		return 'pending_payment' === $booking->status ? __( 'Complete payment', 'osam-bookings' ) : __( 'Pay now', 'osam-bookings' );
	}

	/**
	 * Mark Stripe sessions started from a payment link.
	 *
	 * @param array $params Session parameters.
	 * @return array
	 */
	public static function stripe_pay_params( $params ) {
		$params['metadata[osb_pay]'] = '1';
		return $params;
	}

	/**
	 * Start checkout for what is owed. The booking is read again here, so a link opened twice (or an
	 * old email) can't start a payment for something already paid or cancelled.
	 *
	 * @param object $booking    Booking.
	 * @param string $gateway_id Chosen method.
	 * @return array|WP_Error [ 'redirect' => url ] or [ 'complete' => true ].
	 */
	public static function start_payment( $booking, $gateway_id ) {
		$booking = $booking ? OSB_DB::get( 'bookings', (int) $booking->id ) : null;
		$due     = $booking ? self::payable( $booking ) : 0;
		if ( $due <= 0 ) {
			return new WP_Error( 'osb_nothing_due', __( 'There is nothing to pay on this booking.', 'osam-bookings' ) );
		}
		$gateways = self::pay_gateways( $booking );
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			return new WP_Error( 'osb_payment', __( 'Please choose how you would like to pay.', 'osam-bookings' ) );
		}
		// Gateways charge OSB_Repeat::charge_total(): hand them a copy that comes to exactly what is owed.
		$charge            = clone $booking;
		$charge->amount    = $due;
		$charge->series_id = 0;
		add_filter( 'osb_stripe_session_params', array( __CLASS__, 'stripe_pay_params' ) );
		try {
			$result = $gateways[ $gateway_id ]->process( $charge, self::pay_url( $booking ) );
		} finally {
			remove_filter( 'osb_stripe_session_params', array( __CLASS__, 'stripe_pay_params' ) );
		}
		if ( ! is_wp_error( $result ) ) {
			do_action( 'osb_pay_started', $booking, $gateway_id, $due );
		}
		return $result;
	}

	/**
	 * The payment page (?osb_pay=token): shows what is owed, lets the customer pick a method and sends
	 * them to checkout. Drawn on its own so it works whatever is on the page the link points at.
	 */
	public static function pay_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if ( empty( $_GET['osb_pay'] ) || ! empty( $_GET['osb_return'] ) ) {
			return;
		}
		$token   = preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['osb_pay'] ) ) );
		$booking = strlen( $token ) >= 20 ? OSB_DB::booking_by_token( $token ) : null;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! $booking ) {
			wp_die( esc_html__( 'This payment link isn’t valid.', 'osam-bookings' ), '', array( 'response' => 404 ) );
		}
		$outcome = isset( $_GET['osb_outcome'] ) ? sanitize_key( wp_unslash( $_GET['osb_outcome'] ) ) : '';
		$error   = '';
		// The secret token in the link is what authorises this; starting a checkout changes nothing by itself.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['osb_method'] ) ) {
			$result = self::start_payment( $booking, sanitize_key( wp_unslash( $_POST['osb_method'] ) ) );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} elseif ( ! empty( $result['redirect'] ) ) {
				nocache_headers();
				wp_redirect( esc_url_raw( $result['redirect'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- the payment provider's checkout.
				exit;
			}
			$outcome = '';
			$booking = OSB_DB::get( 'bookings', (int) $booking->id );
		}
		// phpcs:enable
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo OSB_Frontend::pay_page_html( $booking, $outcome, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
