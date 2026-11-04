<?php
/**
 * Base payment gateway.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extend this to add a gateway, then register it with the `osb_payment_gateways` filter.
 */
abstract class OSB_Gateway {

	/**
	 * Unique id (used in settings keys: {id}_enabled, {id}_title).
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Admin-facing name.
	 *
	 * @return string
	 */
	abstract public function admin_name();

	/**
	 * Is the gateway switched on and configured?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) OSB_Settings::get( $this->id . '_enabled' ) && $this->is_configured();
	}

	/**
	 * Has the admin supplied the credentials it needs?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Customer-facing title.
	 *
	 * @return string
	 */
	public function title() {
		$title = OSB_Settings::get( $this->id . '_title' );
		return $title ? $title : $this->admin_name();
	}

	/**
	 * Customer-facing description.
	 *
	 * @return string
	 */
	public function description() {
		return (string) OSB_Settings::get( $this->id . '_description', '' );
	}

	/**
	 * Status a booking starts in for this method.
	 *
	 * @return string
	 */
	public function initial_status() {
		return 'pending_payment';
	}

	/**
	 * Start payment for a freshly created booking.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Page the customer booked on.
	 * @return array|WP_Error [ 'redirect' => url ] or [ 'complete' => true ].
	 */
	abstract public function process( $booking, $return_url );

	/**
	 * Handle the customer coming back from the provider.
	 *
	 * @param object $booking Booking.
	 * @return string success|pending|cancelled|failed
	 */
	public function handle_return( $booking ) {
		return 'pending';
	}

	/**
	 * Admin settings fields: key => [ label, type, options?, help? ].
	 *
	 * @return array
	 */
	public function settings_fields() {
		return array();
	}

	/**
	 * Build the return URL for this gateway.
	 *
	 * @param string $base    Base page URL.
	 * @param object $booking Booking.
	 * @param string $result  Result flag.
	 * @return string
	 */
	protected function return_url( $base, $booking, $result = 'return' ) {
		return add_query_arg(
			array(
				'osb_return'  => $this->id,
				'osb_result'  => $result,
				'osb_booking' => $booking->token,
			),
			$base
		);
	}

	/**
	 * Description line used on statements / provider pages.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	protected function item_description( $booking ) {
		$d = OSB_Bookings::details( $booking );
		return sprintf( '%s — %s %s', $d['item_name'], $d['date'], $d['time'] );
	}

	/**
	 * Log helper (writes to the PHP error log when WP_DEBUG_LOG is on).
	 *
	 * @param string $message Message.
	 * @param mixed  $context Context.
	 */
	protected function log( $message, $context = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( '[OSAM Bookings][' . $this->id . '] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
		}
	}
}
