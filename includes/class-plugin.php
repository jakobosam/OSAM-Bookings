<?php
/**
 * Main plugin bootstrap.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together.
 */
final class OSB_Plugin {

	/**
	 * Singleton.
	 *
	 * @var OSB_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Payment gateway registry.
	 *
	 * @var OSB_Payments
	 */
	public $payments;

	/**
	 * Get the singleton.
	 *
	 * @return OSB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function boot() {
		load_plugin_textdomain( 'osam-bookings', false, dirname( plugin_basename( OSB_FILE ) ) . '/languages' );

		OSB_Install::maybe_upgrade();
		OSB_Licence::init();

		$this->payments = new OSB_Payments();

		OSB_Emails::init();
		OSB_Courses::init();
		OSB_Restaurant::init();
		OSB_Resources::init();
		OSB_Access::init();
		OSB_Addons::init();
		OSB_Bundles::init();
		OSB_Repeat::init();
		OSB_Portal::init();
		OSB_Resources_Rest::init();
		OSB_Calendar_Sync::init();
		OSB_Coupons::init();
		OSB_Waitlist::init();
		OSB_Meetings::init();
		OSB_Customers::init();
		OSB_Rest::init();
		OSB_Frontend::init();
		OSB_Cron::init();
		OSB_Elementor::init();
		OSB_Updater::init();
		OSB_Custom_Emails::init();
		OSB_Invoices::init();
		OSB_Calendar::init();

		if ( is_admin() ) {
			OSB_Admin::init();
		}

		do_action( 'osb_loaded', $this );
	}
}
