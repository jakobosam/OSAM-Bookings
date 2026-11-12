<?php
/**
 * Plugin Name:       OSAM Bookings
 * Plugin URI:        https://osamweb.com/
 * Description:       Booking system for services, events, multi-session courses, restaurant tables and bookable resources with staff calendars, Google & Outlook calendar sync, Stripe, PayPal, WooCommerce and pay-on-site payments, automatic PDF invoices and credit notes, and fully templated emails.
 * Version:           2.17.0-beta.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            OSAM
 * Author URI:        https://osamweb.com/
 * License:           GPL-2.0-or-later
 * Update URI:        https://updates.osamweb.com/osam-bookings
 * Text Domain:       osam-bookings
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'OSB_VERSION', '2.17.0-beta.2' );
define( 'OSB_DB_VERSION', '2.15.0' );
define( 'OSB_FILE', __FILE__ );
define( 'OSB_PATH', plugin_dir_path( __FILE__ ) );
define( 'OSB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'OSB_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		$dirs = array( 'includes/', 'includes/gateways/', 'includes/admin/', 'includes/elementor/' );
		foreach ( $dirs as $dir ) {
			$file = OSB_PATH . $dir . 'class-' . $slug . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'OSB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OSB_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'OSB_Plugin', 'instance' ) );

/**
 * Convenience accessor.
 *
 * @return OSB_Plugin
 */
function osb() {
	return OSB_Plugin::instance();
}
