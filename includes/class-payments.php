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

		if ( 'cancelled' === $result ) {
			OSB_Bookings::payment_failed( $booking->id );
			$outcome = 'cancelled';
		} else {
			$outcome = $gateway->handle_return( $booking );
		}

		$url = remove_query_arg( array( 'osb_return', 'osb_result', 'session_id', 'token', 'PayerID' ) );
		$url = add_query_arg(
			array(
				'osb_booking' => $booking->token,
				'osb_outcome' => $outcome,
			),
			$url
		);
		wp_safe_redirect( $url );
		exit;
	}
}
