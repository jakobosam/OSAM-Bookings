<?php
/**
 * Add-ons: extras customers can add to a booking (equipment, refreshments, extra time…).
 *
 * Each add-on has a price per booking, per person (place/guest), per hour or per day; a maximum
 * quantity (1 = a simple tick box); optional "always included" (a compulsory fee); and the items it is
 * offered on (OSB_Items tokens; empty = everything).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add-ons.
 */
class OSB_Addons {

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $all = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 15, 2 );
		add_filter( 'osb_details_card_rows', array( __CLASS__, 'card_rows' ), 5, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 15, 2 );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'on_deleted' ) );
	}

	/**
	 * Feature on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'addons' );
	}

	/**
	 * Price types.
	 *
	 * @return array
	 */
	public static function price_types() {
		return array(
			'booking' => __( 'per booking', 'osam-bookings' ),
			'person'  => __( 'per person / place', 'osam-bookings' ),
			'hour'    => __( 'per hour', 'osam-bookings' ),
			'day'     => __( 'per day', 'osam-bookings' ),
		);
	}

	/**
	 * Every add-on.
	 *
	 * @param bool $active_only Only those switched on.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		if ( null === self::$all ) {
			global $wpdb;
			$t          = OSB_Install::table( 'addons' );
			self::$all  = (array) $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $active_only ? array_values( array_filter( self::$all, static function ( $a ) { return 'active' === $a->status; } ) ) : self::$all;
	}

	/**
	 * Forget cached rows.
	 */
	public static function flush() {
		self::$all = null;
	}

	/**
	 * One.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $a ) {
			if ( (int) $a->id === (int) $id ) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Add-ons offered for these item tokens.
	 *
	 * @param string[] $tokens Tokens.
	 * @return array
	 */
	public static function for_tokens( array $tokens ) {
		if ( ! self::enabled() ) {
			return array();
		}
		return array_values(
			array_filter(
				self::all( true ),
				static function ( $a ) use ( $tokens ) {
					return OSB_Items::covers( OSB_Items::decode( $a->applies ), $tokens );
				}
			)
		);
	}

	/**
	 * How many units a line is charged for.
	 *
	 * @param object $a   Add-on.
	 * @param array  $ctx seats, minutes, days.
	 * @return float
	 */
	public static function factor( $a, array $ctx ) {
		switch ( $a->price_type ) {
			case 'person':
				return max( 1, (int) ( $ctx['seats'] ?? 1 ) );
			case 'hour':
				return max( 0.25, round( (int) ( $ctx['minutes'] ?? 60 ) / 60, 2 ) );
			case 'day':
				return max( 1, (int) ( $ctx['days'] ?? 1 ) );
			default:
				return 1;
		}
	}

	/**
	 * Lines for a booking being made.
	 *
	 * @param array    $chosen  addon_id => qty from the form.
	 * @param string[] $tokens  What's being booked.
	 * @param array    $ctx     seats, minutes, days.
	 * @param bool     $admin   Staff booking (any add-on, no maximums).
	 * @return array|WP_Error [ lines, total ]
	 */
	public static function build( $chosen, array $tokens, array $ctx, $admin = false ) {
		$chosen = is_array( $chosen ) ? $chosen : array();
		$lines  = array();
		$total  = 0.0;
		$pool   = $admin ? self::all() : self::for_tokens( $tokens );
		$ids    = array_map( 'intval', wp_list_pluck( $pool, 'id' ) );
		foreach ( $chosen as $id => $qty ) {
			if ( (int) $qty > 0 && ! in_array( (int) $id, $ids, true ) ) {
				return new WP_Error( 'osb_addon', __( 'One of the extras you chose isn’t available for this booking.', 'osam-bookings' ) );
			}
		}
		foreach ( $pool as $a ) {
			$qty = isset( $chosen[ $a->id ] ) ? (int) $chosen[ $a->id ] : ( isset( $chosen[ (string) $a->id ] ) ? (int) $chosen[ (string) $a->id ] : 0 );
			if ( $a->required && OSB_Items::covers( OSB_Items::decode( $a->applies ), $tokens ) ) {
				$qty = max( 1, $qty ); // Compulsory where it applies (staff bookings too).
			}
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! $admin ) {
				$qty = min( $qty, max( 1, (int) $a->max_qty ) );
			}
			$amount  = round( (float) $a->price * $qty * self::factor( $a, $ctx ), 2 );
			$lines[] = array(
				'addon_id'   => (int) $a->id,
				'name'       => $a->name,
				'qty'        => $qty,
				'unit_price' => (float) $a->price,
				'amount'     => $amount,
			);
			$total  += $amount;
		}
		return array( $lines, round( $total, 2 ) );
	}

	/**
	 * Save lines on a booking (replaces any there).
	 *
	 * @param int   $booking_id Booking.
	 * @param array $lines      From build().
	 */
	public static function attach( $booking_id, array $lines ) {
		global $wpdb;
		$t = OSB_Install::table( 'booking_addons' );
		$wpdb->delete( $t, array( 'booking_id' => (int) $booking_id ) );
		foreach ( $lines as $l ) {
			$wpdb->insert( $t, array_merge( $l, array( 'booking_id' => (int) $booking_id ) ) );
		}
	}

	/**
	 * Lines on a booking.
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function lines( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'booking_addons' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY id ASC", $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Clean up.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function on_deleted( $booking_id ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'booking_addons' ), array( 'booking_id' => (int) $booking_id ) );
	}

	/**
	 * "2 × Dog towel".
	 *
	 * @param object $l Line.
	 * @return string
	 */
	public static function line_label( $l ) {
		return ( (int) $l->qty > 1 ? (int) $l->qty . ' × ' : '' ) . $l->name;
	}

	/**
	 * Details for confirmation screens.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		$d['addons'] = array();
		$sum         = 0;
		foreach ( self::lines( $booking->id ) as $l ) {
			$d['addons'][] = array(
				'name'   => self::line_label( $l ),
				'amount' => (float) $l->amount > 0 ? OSB_Settings::money( $l->amount, $booking->currency ) : OSB_Settings::label( 'free' ),
			);
			$sum          += (float) $l->amount;
		}
		$d['addons_total'] = $sum > 0 ? OSB_Settings::money( $sum, $booking->currency ) : '';
		return $d;
	}

	/**
	 * Email details-box row.
	 *
	 * @param array  $rows    Rows.
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @return array
	 */
	public static function card_rows( $rows, $booking, $d ) {
		if ( empty( $d['addons'] ) ) {
			return $rows;
		}
		$list = array();
		foreach ( $d['addons'] as $a ) {
			$list[] = esc_html( $a['name'] ) . ' <span style="opacity:.7">(' . esc_html( $a['amount'] ) . ')</span>';
		}
		// Before "Total".
		$pos = count( $rows );
		foreach ( $rows as $i => $r ) {
			if ( __( 'Total', 'osam-bookings' ) === $r[0] ) {
				$pos = $i;
			}
		}
		array_splice( $rows, $pos, 0, array( array( __( 'Extras', 'osam-bookings' ), implode( '<br>', $list ), true ) ) );
		return $rows;
	}

	/**
	 * {addons}.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$map['{addons}'] = esc_html( implode( ', ', array_map( array( __CLASS__, 'line_label' ), self::lines( $booking->id ) ) ) );
		return $map;
	}

	/**
	 * "£4.00 per person".
	 *
	 * @param object $a Add-on.
	 * @return string
	 */
	public static function price_label( $a ) {
		if ( (float) $a->price <= 0 ) {
			return OSB_Settings::label( 'free' );
		}
		$money = OSB_Settings::money( $a->price );
		return 'booking' === $a->price_type ? $money : $money . ' ' . self::price_types()[ $a->price_type ];
	}

	/**
	 * Shaped for the booking form.
	 *
	 * @return array
	 */
	public static function json_all() {
		if ( ! self::enabled() ) {
			return array();
		}
		$out = array();
		foreach ( self::all( true ) as $a ) {
			$out[] = array(
				'id'          => (int) $a->id,
				'name'        => $a->name,
				'description' => wp_kses_post( (string) $a->description ),
				'price'       => (float) $a->price,
				'per'         => $a->price_type,
				'max'         => max( 1, (int) $a->max_qty ),
				'required'    => (bool) $a->required,
				'applies'     => OSB_Items::decode( $a->applies ),
				'image'       => $a->image_id ? wp_get_attachment_image_url( $a->image_id, 'thumbnail' ) : '',
				'price_label' => self::price_label( $a ),
			);
		}
		return $out;
	}
}
