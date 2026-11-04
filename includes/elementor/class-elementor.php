<?php
/**
 * Elementor integration loader.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the widget only when Elementor is active.
 */
class OSB_Elementor {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( 'OSB_Frontend', 'prepare' ) );
		add_action( 'elementor/frontend/after_register_styles', array( 'OSB_Frontend', 'prepare' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( 'OSB_Frontend', 'enqueue' ) );
	}

	/**
	 * Register widget.
	 *
	 * @param \Elementor\Widgets_Manager $manager Manager.
	 */
	public static function register( $manager ) {
		require_once OSB_PATH . 'includes/elementor/class-elementor-widget.php';
		$manager->register( new OSB_Elementor_Widget() );
	}
}
