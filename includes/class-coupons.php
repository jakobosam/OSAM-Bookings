<?php
/**
 * Discount codes and gift vouchers.
 *
 * - Discount code: percentage or fixed amount off, with optional limits (dates, uses, minimum
 *   spend, once per customer, first visit only, specific services/events/courses).
 * - Gift voucher: a code with a money balance that is used up across one or more bookings.
 *
 * A booking stores the code and the discount it received; each redemption is logged so it can
 * be given back (use count / voucher balance) if the booking is cancelled or deleted.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Codes engine.
 */
class OSB_Coupons {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_created', array( __CLASS__, 'record' ) );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'release_booking' ) );
	}

	/**
	 * Normalise a code as typed.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalise( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $code ) );
	}

	/**
	 * Find a code.
	 *
	 * @param string $code Code.
	 * @return object|null
	 */
	public static function find( $code ) {
		global $wpdb;
		$code = self::normalise( $code );
		if ( '' === $code ) {
			return null;
		}
		$t = OSB_Install::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE code = %s", $code ) );
	}

	/**
	 * All codes (admin).
	 *
	 * @param string $kind discount|voucher|''.
	 * @return array
	 */
	public static function all( $kind = '' ) {
		global $wpdb;
		$t = OSB_Install::table( 'coupons' );
		if ( $kind ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE kind = %s ORDER BY id DESC", $kind ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC" );
	}

	/**
	 * Are there any codes customers could use? (Hides the code box when there are none.)
	 *
	 * @return bool
	 */
	public static function any_active() {
		if ( ! OSB_Settings::feature( 'coupons' ) ) {
			return false;
		}
		global $wpdb;
		$t = OSB_Install::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = 'active' AND ( expires_at IS NULL OR expires_at >= %s )", current_time( 'Y-m-d' ) ) );
	}

	/**
	 * Redemptions of a code.
	 *
	 * @param int $coupon_id Code.
	 * @return array
	 */
	public static function uses( $coupon_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'coupon_uses' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE coupon_id = %d ORDER BY id DESC", $coupon_id ) );
	}

	/**
	 * A fresh, unused random code.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function generate( $prefix = '' ) {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/1/I.
		do {
			$code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$code = self::normalise( $prefix ) . ( $prefix ? '-' : '' ) . substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
		} while ( self::find( $code ) );
		return $code;
	}

	/**
	 * Label for what a code gives.
	 *
	 * @param object $c Code.
	 * @return string
	 */
	public static function value_label( $c ) {
		if ( 'voucher' === $c->kind ) {
			/* translators: 1: balance 2: original value */
			return sprintf( __( '%1$s left of %2$s', 'osam-bookings' ), OSB_Settings::money( $c->balance ), OSB_Settings::money( $c->amount ) );
		}
		/* translators: %s amount */
		return 'percent' === $c->type ? sprintf( __( '%s%% off', 'osam-bookings' ), rtrim( rtrim( number_format( (float) $c->amount, 2, '.', '' ), '0' ), '.' ) ) : sprintf( __( '%s off', 'osam-bookings' ), OSB_Settings::money( $c->amount ) );
	}

	/**
	 * Has this email booked before (any visit that wasn't cancelled)?
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	private static function has_visited( $email ) {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE customer_email = %s AND status IN ('pending','confirmed','completed','no_show')", $email ) );
	}

	/**
	 * Work out the discount a code gives in a context.
	 *
	 * @param object $c   Code row.
	 * @param array  $ctx type (service|event|course), item_id, subtotal, email.
	 * @return float|WP_Error Discount amount.
	 */
	public static function evaluate( $c, array $ctx ) {
		$ctx   = wp_parse_args(
			$ctx,
			array(
				'type'     => 'service',
				'item_id'  => 0,
				'subtotal' => 0,
				'email'    => '',
			)
		);
		$today = current_time( 'Y-m-d' );
		$no    = static function ( $msg ) {
			return new WP_Error( 'osb_coupon', $msg );
		};
		if ( ! $c || 'active' !== $c->status ) {
			return $no( __( 'That code isn’t valid.', 'osam-bookings' ) );
		}
		if ( $c->starts_at && $today < $c->starts_at ) {
			return $no( __( 'That code isn’t active yet.', 'osam-bookings' ) );
		}
		if ( $c->expires_at && $today > $c->expires_at ) {
			return $no( __( 'That code has expired.', 'osam-bookings' ) );
		}
		if ( 'voucher' === $c->kind ) {
			if ( (float) $c->balance <= 0 ) {
				return $no( __( 'This gift voucher has been used up.', 'osam-bookings' ) );
			}
		} elseif ( (int) $c->max_uses > 0 && (int) $c->uses >= (int) $c->max_uses ) {
			return $no( __( 'That code has reached its limit.', 'osam-bookings' ) );
		}
		$kinds = array(
			'service' => 'services',
			'event'   => 'events',
			'course'  => 'courses',
		);
		if ( 'all' !== $c->applies_to && $c->applies_to !== $kinds[ $ctx['type'] ] ) {
			return $no( __( 'That code can’t be used for this booking.', 'osam-bookings' ) );
		}
		$items = array_filter( array_map( 'absint', explode( ',', (string) $c->item_ids ) ) );
		if ( $items && 'all' !== $c->applies_to && ! in_array( (int) $ctx['item_id'], $items, true ) ) {
			return $no( __( 'That code can’t be used for this booking.', 'osam-bookings' ) );
		}
		if ( (float) $c->min_spend > 0 && (float) $ctx['subtotal'] < (float) $c->min_spend ) {
			/* translators: %s amount */
			return $no( sprintf( __( 'That code needs a minimum spend of %s.', 'osam-bookings' ), OSB_Settings::money( $c->min_spend ) ) );
		}
		$email = strtolower( trim( (string) $ctx['email'] ) );
		if ( 'voucher' !== $c->kind && ( $c->once_per_customer || $c->first_visit ) && $email ) {
			global $wpdb;
			$u = OSB_Install::table( 'coupon_uses' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $c->once_per_customer && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $u WHERE coupon_id = %d AND email = %s AND status = 'applied'", $c->id, $email ) ) ) {
				return $no( __( 'You’ve already used this code.', 'osam-bookings' ) );
			}
			if ( $c->first_visit && self::has_visited( $email ) ) {
				return $no( __( 'This code is for first visits only.', 'osam-bookings' ) );
			}
		}

		$sub = max( 0, (float) $ctx['subtotal'] );
		if ( 'voucher' === $c->kind ) {
			$off = min( (float) $c->balance, $sub );
		} elseif ( 'percent' === $c->type ) {
			$off = round( $sub * min( 100, (float) $c->amount ) / 100, 2 );
		} else {
			$off = min( (float) $c->amount, $sub );
		}
		return (float) apply_filters( 'osb_coupon_discount', round( $off, 2 ), $c, $ctx );
	}

	/**
	 * Apply a code to a booking row being created (inside the booking lock).
	 *
	 * @param array  $row  Booking row (amount already worked out).
	 * @param string $code Code typed.
	 * @return array|WP_Error Row with discount applied.
	 */
	public static function apply_to_row( array $row, $code ) {
		$c = self::find( $code );
		if ( ! $c ) {
			return new WP_Error( 'osb_coupon', __( 'That code isn’t valid.', 'osam-bookings' ) );
		}
		$type = $row['type'];
		$id   = 'event' === $type ? ( $row['event_id'] ?? 0 ) : ( 'course' === $type ? ( $row['course_id'] ?? 0 ) : ( $row['service_id'] ?? 0 ) );
		$off  = self::evaluate(
			$c,
			array(
				'type'     => $type,
				'item_id'  => $id,
				'subtotal' => $row['amount'],
				'email'    => $row['customer_email'],
			)
		);
		if ( is_wp_error( $off ) ) {
			return $off;
		}
		$row['discount']    = $off;
		$row['coupon_code'] = $c->code;
		$row['amount']      = max( 0, round( (float) $row['amount'] - $off, 2 ) );
		return $row;
	}

	/**
	 * Log the redemption once the booking exists.
	 *
	 * @param object $booking Booking.
	 */
	public static function record( $booking ) {
		if ( empty( $booking->coupon_code ) || (float) $booking->discount <= 0 ) {
			return;
		}
		$c = self::find( $booking->coupon_code );
		if ( ! $c ) {
			return;
		}
		global $wpdb;
		OSB_DB::insert(
			'coupon_uses',
			array(
				'coupon_id'  => (int) $c->id,
				'booking_id' => (int) $booking->id,
				'email'      => strtolower( (string) $booking->customer_email ),
				'amount'     => (float) $booking->discount,
				'status'     => 'applied',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$t = OSB_Install::table( 'coupons' );
		if ( 'voucher' === $c->kind ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = uses + 1, balance = GREATEST(0, balance - %f) WHERE id = %d", (float) $booking->discount, $c->id ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = uses + 1 WHERE id = %d", $c->id ) );
		}
	}

	/**
	 * Give the code back when a booking is cancelled.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $status  New status.
	 */
	public static function on_status( $booking, $old, $status ) {
		if ( 'cancelled' === $status ) {
			self::release_booking( (int) $booking->id );
		}
	}

	/**
	 * Release a booking's redemption (restores use count / voucher balance).
	 *
	 * @param int $booking_id Booking.
	 */
	public static function release_booking( $booking_id ) {
		global $wpdb;
		$u   = OSB_Install::table( 'coupon_uses' );
		$t   = OSB_Install::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$use = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $u WHERE booking_id = %d AND status = 'applied' LIMIT 1", $booking_id ) );
		if ( ! $use ) {
			return;
		}
		$wpdb->update( $u, array( 'status' => 'released' ), array( 'id' => (int) $use->id ) );
		$c = OSB_DB::get( 'coupons', $use->coupon_id );
		if ( ! $c ) {
			return;
		}
		if ( 'voucher' === $c->kind ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = GREATEST(0, uses - 1), balance = LEAST(amount, balance + %f) WHERE id = %d", (float) $use->amount, $c->id ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = GREATEST(0, uses - 1) WHERE id = %d", $c->id ) );
		}
	}
}
