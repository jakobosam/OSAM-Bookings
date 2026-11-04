<?php
/**
 * REST routes for booking resources.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * /wp-json/osb/v1/resources…
 */
class OSB_Resources_Rest {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$ns = OSB_Rest::NS;
		register_rest_route( $ns, '/resources', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/resources/month', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'month' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/resources/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * Off response.
	 *
	 * @return WP_Error
	 */
	private static function off() {
		return new WP_Error( 'osb_disabled', __( 'Online booking for this is not available.', 'osam-bookings' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /resources?ids=&category=.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		if ( ! OSB_Resources::enabled() ) {
			return self::off();
		}
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$cat = sanitize_text_field( (string) $request->get_param( 'category' ) );
		$out = array();
		foreach ( OSB_Resources::all( true ) as $r ) {
			if ( ( $ids && ! in_array( (int) $r->id, $ids, true ) ) || ( $cat && 0 !== strcasecmp( $cat, $r->category ) ) ) {
				continue;
			}
			$out[] = OSB_Resources::json( $r );
		}
		return rest_ensure_response(
			array(
				'resources' => $out,
				'multi'     => (bool) OSB_Settings::get( 'res_multi', 1 ),
			)
		);
	}

	/**
	 * Shared query params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array [ ids, hire, opt, places, exclusive ]
	 */
	private static function params( WP_REST_Request $request ) {
		$ids  = array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) ), 0, 20 );
		$hire = in_array( $request->get_param( 'hire' ), array( 'time', 'half', 'day' ), true ) ? $request->get_param( 'hire' ) : 'time';
		return array(
			$ids,
			$hire,
			array(
				'minutes' => absint( $request->get_param( 'minutes' ) ),
				'days'    => max( 1, absint( $request->get_param( 'days' ) ) ),
			),
			max( 1, absint( $request->get_param( 'places' ) ) ),
			(bool) $request->get_param( 'exclusive' ),
		);
	}

	/**
	 * GET /resources/month.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function month( WP_REST_Request $request ) {
		if ( ! OSB_Resources::enabled() ) {
			return self::off();
		}
		list( $ids, $hire, $opt, $places, $excl ) = self::params( $request );
		list( $dates, $min, $max )                = OSB_Resources::month_dates( $ids, $hire, sanitize_text_field( (string) $request->get_param( 'month' ) ), $opt, $places, $excl );
		return rest_ensure_response(
			array(
				'dates' => $dates,
				'min'   => $min,
				'max'   => $max,
			)
		);
	}

	/**
	 * GET /resources/day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function day( WP_REST_Request $request ) {
		if ( ! OSB_Resources::enabled() ) {
			return self::off();
		}
		list( $ids, $hire, $opt, $places, $excl ) = self::params( $request );
		return rest_ensure_response( OSB_Resources::day_options( $ids, $hire, sanitize_text_field( (string) $request->get_param( 'date' ) ), $opt, $places, $excl ) );
	}
}
