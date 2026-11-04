<?php
/**
 * Pay on site / on arrival.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * No online payment — booking is confirmed (or held for approval) immediately.
 */
class OSB_Gateway_Onsite extends OSB_Gateway {

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = 'onsite';

	/**
	 * Admin name.
	 *
	 * @return string
	 */
	public function admin_name() {
		return __( 'Pay on site', 'osam-bookings' );
	}

	/**
	 * Starting status from settings.
	 *
	 * @return string
	 */
	public function initial_status() {
		return 'pending' === OSB_Settings::get( 'onsite_status' ) ? 'pending' : 'confirmed';
	}

	/**
	 * Nothing to collect.
	 *
	 * @param object $booking    Booking.
	 * @param string $return_url Return URL.
	 * @return array
	 */
	public function process( $booking, $return_url ) {
		return array( 'complete' => true );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function settings_fields() {
		return array(
			'onsite_title'       => array( __( 'Title shown to customers', 'osam-bookings' ), 'text' ),
			'onsite_description' => array( __( 'Description', 'osam-bookings' ), 'text' ),
			'onsite_status'      => array(
				__( 'New bookings are', 'osam-bookings' ),
				'select',
				array(
					'confirmed' => __( 'Confirmed automatically', 'osam-bookings' ),
					'pending'   => __( 'Pending until approved by staff', 'osam-bookings' ),
				),
			),
		);
	}
}
