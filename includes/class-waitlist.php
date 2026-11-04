<?php
/**
 * Waitlists for full events and course intakes.
 *
 * People join with their name and email. When places free up (a cancellation, a bigger room),
 * the next person in line is emailed a private link and the place is held for them for a set
 * number of hours. If they don't book in time, it goes to the next person.
 *
 * Held places count as taken for everyone else (see held()).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Waitlist engine.
 */
class OSB_Waitlist {

	/**
	 * Waitlist entry being booked right now (its held seats don't block that booking).
	 *
	 * @var int
	 */
	public static $using = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'process_all' ) );
		add_action( 'osb_booking_created', array( __CLASS__, 'on_booking_created' ), 10, 2 );
	}

	/**
	 * Switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'waitlist' );
	}

	/**
	 * Statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'waiting' => __( 'Waiting', 'osam-bookings' ),
			'offered' => __( 'Place offered', 'osam-bookings' ),
			'booked'  => __( 'Booked', 'osam-bookings' ),
			'expired' => __( 'Offer expired', 'osam-bookings' ),
			'removed' => __( 'Removed', 'osam-bookings' ),
		);
	}

	/**
	 * Column for an item type.
	 *
	 * @param string $type event|course.
	 * @return string
	 */
	private static function col( $type ) {
		return 'course' === $type ? 'intake_id' : 'event_id';
	}

	/**
	 * Seats held by current offers on an item (minus the one being booked).
	 *
	 * @param string $type event|course.
	 * @param int    $id   Event id or intake id.
	 * @return int
	 */
	public static function held( $type, $id ) {
		global $wpdb;
		$t   = OSB_Install::table( 'waitlist' );
		$col = self::col( $type );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE type = %s AND $col = %d AND status = 'offered' AND expires_at > %s AND id <> %d", $type, $id, current_time( 'mysql' ), (int) self::$using ) );
	}

	/**
	 * Entries for an item, in queue order.
	 *
	 * @param string $type   Type.
	 * @param int    $id     Item.
	 * @param array  $status Statuses.
	 * @return array
	 */
	public static function entries( $type, $id, array $status = array( 'waiting', 'offered' ) ) {
		global $wpdb;
		$t   = OSB_Install::table( 'waitlist' );
		$col = self::col( $type );
		$in  = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE type = %s AND $col = %d AND status IN ($in) ORDER BY id ASC", array_merge( array( $type, $id ), $status ) ) );
	}

	/**
	 * Entry by token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( ! $token ) {
			return null;
		}
		$t = OSB_Install::table( 'waitlist' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token = %s", $token ) );
	}

	/**
	 * Free places on an item for the next person (capacity − bookings − other holds).
	 *
	 * @param string $type Type.
	 * @param int    $id   Item.
	 * @return int
	 */
	public static function free_places( $type, $id ) {
		if ( 'course' === $type ) {
			$in = OSB_DB::get( 'course_intakes', $id );
			$co = $in ? OSB_DB::get( 'courses', $in->course_id ) : null;
			return $co ? OSB_Courses::capacity( $in, $co ) - OSB_Courses::seats_taken( $id, 0, true ) : 0;
		}
		$e = OSB_DB::get( 'events', $id );
		return $e ? (int) $e->capacity - OSB_DB::event_seats_taken( $id, true ) : 0;
	}

	/**
	 * Can people still book this item at all (not started, on sale)?
	 *
	 * @param string $type Type.
	 * @param int    $id   Item.
	 * @return string Start datetime, or '' when closed.
	 */
	private static function open_until( $type, $id ) {
		$now = current_time( 'mysql' );
		if ( 'course' === $type ) {
			$in = OSB_DB::get( 'course_intakes', $id );
			$co = $in ? OSB_DB::get( 'courses', $in->course_id ) : null;
			if ( ! $co || 'active' !== $co->status || 'active' !== $in->status || ! $in->first_start || $in->first_start <= $now ) {
				return '';
			}
			return $in->first_start;
		}
		$e = OSB_DB::get( 'events', $id );
		return $e && 'active' === $e->status && $e->start_at > $now ? $e->start_at : '';
	}

	/**
	 * Add someone to the list.
	 *
	 * @param array $data type, item id, seats, name, email, phone.
	 * @return object|WP_Error Entry.
	 */
	public static function join( array $data ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'osb_waitlist', __( 'The waitlist is not available.', 'osam-bookings' ) );
		}
		$type  = 'course' === ( $data['type'] ?? '' ) ? 'course' : 'event';
		$id    = absint( $data['id'] ?? 0 );
		$email = sanitize_email( $data['email'] ?? '' );
		$name  = sanitize_text_field( $data['name'] ?? '' );
		if ( ! is_email( $email ) || '' === $name ) {
			return new WP_Error( 'osb_waitlist', __( 'Please enter your name and a valid email address.', 'osam-bookings' ) );
		}
		if ( ! self::open_until( $type, $id ) ) {
			return new WP_Error( 'osb_waitlist', __( 'Sorry, this is no longer taking bookings.', 'osam-bookings' ) );
		}
		foreach ( self::entries( $type, $id ) as $e ) {
			if ( strtolower( $e->email ) === strtolower( $email ) ) {
				return $e; // Already on the list.
			}
		}
		$max   = 'course' === $type ? (int) ( OSB_DB::get( 'courses', (int) ( OSB_DB::get( 'course_intakes', $id )->course_id ?? 0 ) )->max_per_booking ?? 1 ) : (int) ( OSB_DB::get( 'events', $id )->max_per_booking ?? 1 );
		$entry = OSB_DB::insert(
			'waitlist',
			array(
				'type'       => $type,
				'event_id'   => 'event' === $type ? $id : 0,
				'intake_id'  => 'course' === $type ? $id : 0,
				'seats'      => max( 1, min( max( 1, $max ), absint( $data['seats'] ?? 1 ) ) ),
				'name'       => $name,
				'email'      => $email,
				'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
				'token'      => wp_generate_password( 32, false, false ),
				'status'     => 'waiting',
				'created_at' => current_time( 'mysql' ),
			)
		);
		if ( ! $entry ) {
			return new WP_Error( 'osb_waitlist', __( 'Could not add you to the waitlist. Please try again.', 'osam-bookings' ) );
		}
		$row = OSB_DB::get( 'waitlist', $entry );
		do_action( 'osb_waitlist_joined', $row );
		// A place may already be free (e.g. someone cancelled a moment ago).
		self::process( $type, $id );
		return OSB_DB::get( 'waitlist', $entry );
	}

	/**
	 * A booking-shaped object for email placeholders.
	 *
	 * @param object $w Entry.
	 * @return object
	 */
	public static function as_booking( $w ) {
		$start = '';
		$end   = '';
		$cid   = 0;
		if ( 'course' === $w->type ) {
			$in    = OSB_DB::get( 'course_intakes', $w->intake_id );
			$start = $in ? $in->first_start : current_time( 'mysql' );
			$end   = $in ? $in->last_end : $start;
			$cid   = $in ? (int) $in->course_id : 0;
		} else {
			$e     = OSB_DB::get( 'events', $w->event_id );
			$start = $e ? $e->start_at : current_time( 'mysql' );
			$end   = $e ? $e->end_at : $start;
		}
		return (object) array(
			'id'             => 0,
			'type'           => $w->type,
			'service_id'     => 0,
			'staff_id'       => 0,
			'event_id'       => (int) $w->event_id,
			'course_id'      => $cid,
			'intake_id'      => (int) $w->intake_id,
			'start_at'       => $start,
			'end_at'         => $end,
			'seats'          => (int) $w->seats,
			'customer_name'  => $w->name,
			'customer_email' => $w->email,
			'customer_phone' => $w->phone,
			'fields'         => '{}',
			'internal_note'  => '',
			'status'         => 'pending',
			'payment_method' => '',
			'payment_status' => 'unpaid',
			'amount'         => 0,
			'discount'       => 0,
			'coupon_code'    => '',
			'currency'       => OSB_Settings::get( 'currency' ),
			'token'          => $w->token,
			'wc_order_id'    => 0,
			'meeting_url'    => '',
			'created_at'     => $w->created_at,
		);
	}

	/**
	 * Link that books the held place.
	 *
	 * @param object $w Entry.
	 * @return string
	 */
	public static function book_url( $w ) {
		$page = (int) OSB_Settings::get( 'booking_page' );
		return add_query_arg( 'osb_waitlist', $w->token, $page ? get_permalink( $page ) : home_url( '/' ) );
	}

	/**
	 * Offer a held place to one person.
	 *
	 * @param object $w Entry.
	 * @return bool
	 */
	public static function offer( $w ) {
		$until = self::open_until( $w->type, $w->type === 'course' ? $w->intake_id : $w->event_id );
		if ( ! $until ) {
			return false;
		}
		$hours   = max( 1, (float) OSB_Settings::get( 'waitlist_hold_hours' ) );
		$expires = gmdate( 'Y-m-d H:i:s', min( strtotime( current_time( 'mysql' ) ) + (int) ( $hours * HOUR_IN_SECONDS ), strtotime( $until ) ) );
		OSB_DB::update(
			'waitlist',
			$w->id,
			array(
				'status'     => 'offered',
				'offered_at' => current_time( 'mysql' ),
				'expires_at' => $expires,
			)
		);
		$w  = OSB_DB::get( 'waitlist', $w->id );
		$ds = OSB_Emails::design();
		OSB_Emails::send(
			'customer_waitlist_offer',
			self::as_booking( $w ),
			$w->email,
			false,
			array(
				'{waitlist_expires}' => esc_html( date_i18n( 'l j F, ' . get_option( 'time_format' ), strtotime( $expires ) ) ),
				'{waitlist_button}'  => OSB_Emails::button( self::book_url( $w ), __( 'Book my place', 'osam-bookings' ), $ds ),
				'{manage_url}'       => esc_url( self::book_url( $w ) ),
				'{manage_button}'    => '',
			)
		);
		do_action( 'osb_waitlist_offered', $w );
		return true;
	}

	/**
	 * Expire old offers and offer free places to the next in line, for one item.
	 *
	 * @param string $type Type.
	 * @param int    $id   Item.
	 */
	public static function process( $type, $id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$now = current_time( 'mysql' );
		foreach ( self::entries( $type, $id, array( 'offered' ) ) as $w ) {
			if ( $w->expires_at <= $now ) {
				OSB_DB::update( 'waitlist', $w->id, array( 'status' => 'expired' ) );
			}
		}
		if ( ! self::open_until( $type, $id ) ) {
			return;
		}
		$free = self::free_places( $type, $id );
		foreach ( self::entries( $type, $id, array( 'waiting' ) ) as $w ) {
			if ( $free < (int) $w->seats ) {
				break; // Keep the queue fair: don't skip ahead to smaller groups.
			}
			if ( self::offer( $w ) ) {
				$free -= (int) $w->seats;
			}
		}
	}

	/**
	 * Run the queue for every item that has people waiting or offers out (cron).
	 */
	public static function process_all() {
		global $wpdb;
		if ( ! self::enabled() ) {
			return;
		}
		$t = OSB_Install::table( 'waitlist' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT DISTINCT type, event_id, intake_id FROM $t WHERE status IN ('waiting','offered')" ) as $r ) {
			self::process( $r->type, 'course' === $r->type ? (int) $r->intake_id : (int) $r->event_id );
		}
	}

	/**
	 * A cancellation frees places straight away.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $status  New status.
	 */
	public static function on_status( $booking, $old, $status ) {
		if ( ! in_array( $booking->type, array( 'event', 'course' ), true ) ) {
			return;
		}
		$blocking = OSB_DB::blocking_statuses();
		if ( in_array( $old, $blocking, true ) && ! in_array( $status, $blocking, true ) ) {
			self::process( $booking->type, 'course' === $booking->type ? (int) $booking->intake_id : (int) $booking->event_id );
		}
	}

	/**
	 * Mark the entry booked once its holder books.
	 *
	 * @param object $booking Booking.
	 * @param array  $data    Request data.
	 */
	public static function on_booking_created( $booking, $data ) {
		if ( empty( $data['waitlist'] ) ) {
			return;
		}
		$w = self::by_token( $data['waitlist'] );
		if ( $w && in_array( $w->status, array( 'offered', 'waiting' ), true ) ) {
			OSB_DB::update(
				'waitlist',
				$w->id,
				array(
					'status'     => 'booked',
					'booking_id' => (int) $booking->id,
				)
			);
		}
		// Someone on the list booking by another route also leaves the queue.
		global $wpdb;
		$t = OSB_Install::table( 'waitlist' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'booked', booking_id = %d WHERE status = 'waiting' AND email = %s AND type = %s AND event_id = %d AND intake_id = %d", $booking->id, $booking->customer_email, $booking->type, (int) $booking->event_id, (int) $booking->intake_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * While booking with a waitlist link, its held places are the customer's own.
	 *
	 * @param string $token Token.
	 * @param string $type  Type.
	 * @param int    $id    Item.
	 */
	public static function use_token( $token, $type, $id ) {
		$w = self::by_token( $token );
		if ( $w && 'offered' === $w->status && $w->type === $type && (int) ( 'course' === $type ? $w->intake_id : $w->event_id ) === (int) $id && $w->expires_at > current_time( 'mysql' ) ) {
			self::$using = (int) $w->id;
		}
	}
}
