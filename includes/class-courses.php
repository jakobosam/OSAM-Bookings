<?php
/**
 * Courses: a single fee that books the customer onto every session of an intake.
 *
 * Structure: course (what is sold) → intakes (each run of it, with its own places) → sessions (the dates).
 * A course booking is one row in the bookings table (type "course") pointing at an intake; its
 * start_at/end_at span the first to last session. Per-session attendance lives in the attendance table.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Course data and lifecycle helpers.
 */
class OSB_Courses {

	/**
	 * Attendance states.
	 *
	 * @return array
	 */
	public static function attendance_statuses() {
		return apply_filters(
			'osb_attendance_statuses',
			array(
				'booked'   => __( 'Booked', 'osam-bookings' ),
				'attended' => __( 'Attended', 'osam-bookings' ),
				'absent'   => __( 'Absent', 'osam-bookings' ),
			)
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_created', array( __CLASS__, 'on_booking_created' ) );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'on_booking_deleted' ) );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * All courses.
	 *
	 * @param bool $active_only Only those on sale.
	 * @return array
	 */
	public static function courses( $active_only = false ) {
		global $wpdb;
		$t     = OSB_Install::table( 'courses' );
		$where = $active_only ? "WHERE status = 'active'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t $where ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * Intakes of a course, soonest first (intakes without sessions last).
	 *
	 * @param int $course_id Course.
	 * @return array
	 */
	public static function intakes( $course_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'course_intakes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE course_id = %d ORDER BY first_start IS NULL, first_start ASC, id ASC", $course_id ) );
	}

	/**
	 * Every intake that finishes in the future (or has no dates yet), across courses.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function upcoming_intakes( $limit = 50 ) {
		global $wpdb;
		$t = OSB_Install::table( 'course_intakes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE last_end IS NOT NULL AND last_end >= %s ORDER BY first_start ASC LIMIT %d", current_time( 'mysql' ), $limit ) );
	}

	/**
	 * Sessions of an intake in date order.
	 *
	 * @param int $intake_id Intake.
	 * @return array
	 */
	public static function sessions( $intake_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'course_sessions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE intake_id = %d ORDER BY start_at ASC, id ASC", $intake_id ) );
	}

	/**
	 * Places on an intake (0 on the intake = use the course's number).
	 *
	 * @param object $intake Intake.
	 * @param object $course Course.
	 * @return int
	 */
	public static function capacity( $intake, $course ) {
		return max( 1, (int) $intake->capacity > 0 ? (int) $intake->capacity : (int) $course->capacity );
	}

	/**
	 * Price for an intake (NULL on the intake = use the course price).
	 *
	 * @param object $intake Intake.
	 * @param object $course Course.
	 * @return float
	 */
	public static function price( $intake, $course ) {
		$price = null === $intake->price ? (float) $course->price : (float) $intake->price;
		return (float) apply_filters( 'osb_course_price', $price, $intake, $course );
	}

	/**
	 * Places already taken on an intake.
	 *
	 * @param int  $intake_id  Intake.
	 * @param int  $exclude    Booking id to leave out.
	 * @param bool $with_holds Also count places held for people on the waitlist.
	 * @return int
	 */
	public static function seats_taken( $intake_id, $exclude = 0, $with_holds = false ) {
		if ( $with_holds ) {
			return self::seats_taken( $intake_id, $exclude ) + OSB_Waitlist::held( 'course', (int) $intake_id );
		}
		global $wpdb;
		$t        = OSB_Install::table( 'bookings' );
		$statuses = OSB_DB::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE intake_id = %d AND id <> %d AND status IN ($in)", array_merge( array( $intake_id, $exclude ), $statuses ) ) );
	}

	/**
	 * Bookings on an intake that hold a place (the class list).
	 *
	 * @param int $intake_id Intake.
	 * @return array
	 */
	public static function enrolments( $intake_id ) {
		global $wpdb;
		$t        = OSB_Install::table( 'bookings' );
		$statuses = OSB_DB::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE intake_id = %d AND status IN ($in) ORDER BY customer_name ASC, id ASC", array_merge( array( $intake_id ), $statuses ) ) );
	}

	/**
	 * Count of every booking (any status) on a course or intake — used to stop deletes.
	 *
	 * @param string $column course_id|intake_id.
	 * @param int    $id     Id.
	 * @return int
	 */
	public static function booking_count( $column, $id ) {
		global $wpdb;
		$t      = OSB_Install::table( 'bookings' );
		$column = 'course_id' === $column ? 'course_id' : 'intake_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $column = %d", $id ) );
	}

	/**
	 * Why customers can't enrol right now ('' = they can).
	 *
	 * @param object $intake Intake.
	 * @param object $course Course.
	 * @return string closed|started|hidden|no_sessions|full|''
	 */
	public static function closed_reason( $intake, $course ) {
		if ( ! $course || 'active' !== $course->status || 'active' !== $intake->status ) {
			return 'hidden';
		}
		if ( ! $intake->first_start ) {
			return 'no_sessions';
		}
		// Enrolment closes once the first session has started — no late joining.
		if ( $intake->first_start <= current_time( 'mysql' ) ) {
			return 'started';
		}
		if ( self::seats_taken( $intake->id, 0, true ) >= self::capacity( $intake, $course ) ) {
			return 'full';
		}
		return (string) apply_filters( 'osb_course_closed_reason', '', $intake, $course );
	}

	/**
	 * Display name for an intake.
	 *
	 * @param object $intake Intake.
	 * @return string
	 */
	public static function intake_label( $intake ) {
		if ( '' !== trim( (string) $intake->name ) ) {
			return $intake->name;
		}
		if ( $intake->first_start ) {
			/* translators: %s date */
			return sprintf( __( 'Starts %s', 'osam-bookings' ), date_i18n( 'j M Y', strtotime( $intake->first_start ) ) );
		}
		return __( 'New intake', 'osam-bookings' );
	}

	/**
	 * "13 Jan – 17 Feb 2026".
	 *
	 * @param object $intake Intake.
	 * @return string
	 */
	public static function range_label( $intake ) {
		if ( ! $intake->first_start ) {
			return __( 'No dates yet', 'osam-bookings' );
		}
		$a = strtotime( $intake->first_start );
		$b = strtotime( $intake->last_end );
		if ( gmdate( 'Y-m-d', $a ) === gmdate( 'Y-m-d', $b ) ) {
			return date_i18n( 'j M Y', $a );
		}
		return date_i18n( gmdate( 'Y', $a ) === gmdate( 'Y', $b ) ? 'j M' : 'j M Y', $a ) . ' – ' . date_i18n( 'j M Y', $b );
	}

	/**
	 * One session shaped for display.
	 *
	 * @param object $s      Session.
	 * @param int    $number 1-based position.
	 * @param object $course Course (for the default location).
	 * @return array
	 */
	public static function session_view( $s, $number, $course = null ) {
		$start = strtotime( $s->start_at );
		$end   = strtotime( $s->end_at );
		$tf    = get_option( 'time_format' );
		return array(
			'id'         => (int) $s->id,
			'number'     => (int) $number,
			'start'      => $s->start_at,
			'end'        => $s->end_at,
			'date_label' => date_i18n( 'D ' . get_option( 'date_format' ), $start ),
			'short_date' => date_i18n( 'D j M', $start ),
			'time_label' => date_i18n( $tf, $start ) . ' – ' . date_i18n( $tf, $end ),
			'time'       => date_i18n( $tf, $start ),
			'end_time'   => date_i18n( $tf, $end ),
			'location'   => '' !== $s->location ? $s->location : ( $course ? $course->location : '' ),
			'note'       => $s->note,
		);
	}

	/**
	 * Sessions of an intake shaped for display.
	 *
	 * @param int    $intake_id Intake.
	 * @param object $course    Course.
	 * @return array
	 */
	public static function schedule( $intake_id, $course = null ) {
		$out = array();
		$n   = 0;
		foreach ( self::sessions( $intake_id ) as $s ) {
			$out[] = self::session_view( $s, ++$n, $course );
		}
		return $out;
	}

	/**
	 * Sessions a staff member teaches in a window. They block that diary when the intake is on
	 * sale, or whenever someone is enrolled (so taking a course off sale never frees a running class).
	 *
	 * @param int    $staff_id Staff.
	 * @param string $from     Datetime.
	 * @param string $to       Datetime.
	 * @return array
	 */
	public static function staff_sessions_between( $staff_id, $from, $to ) {
		global $wpdb;
		$s        = OSB_Install::table( 'course_sessions' );
		$i        = OSB_Install::table( 'course_intakes' );
		$c        = OSB_Install::table( 'courses' );
		$b        = OSB_Install::table( 'bookings' );
		$statuses = OSB_DB::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $staff_id, $to, $from ), $statuses );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.intake_id, s.course_id, s.start_at, s.end_at FROM $s s
				INNER JOIN $i i ON i.id = s.intake_id
				INNER JOIN $c c ON c.id = i.course_id
				WHERE s.staff_id = %d AND s.start_at < %s AND s.end_at > %s
				AND ( ( i.status = 'active' AND c.status = 'active' ) OR EXISTS ( SELECT 1 FROM $b b WHERE b.intake_id = i.id AND b.status IN ($in) ) )",
				$args
			)
		);
		// phpcs:enable
	}

	/**
	 * Sessions in a date window for the admin calendar.
	 *
	 * @param string $from     Y-m-d.
	 * @param string $to       Y-m-d.
	 * @param int    $staff_id Optional staff filter.
	 * @return array
	 */
	public static function sessions_between( $from, $to, $staff_id = 0 ) {
		global $wpdb;
		$t     = OSB_Install::table( 'course_sessions' );
		$sql   = "SELECT * FROM $t WHERE start_at >= %s AND start_at <= %s";
		$args  = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		if ( $staff_id ) {
			$sql   .= ' AND staff_id = %d';
			$args[] = $staff_id;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY start_at ASC', $args ) );
	}

	/* ---------------------------------------------------------------------
	 * Bookings
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a course booking and fill in its row. Called inside the booking lock.
	 *
	 * @param array $data  Booking request (intake_id, seats, admin).
	 * @param int   $seats Places requested.
	 * @return array|WP_Error [ row fields, course ].
	 */
	public static function booking_row( array $data, $seats ) {
		$intake = OSB_DB::get( 'course_intakes', isset( $data['intake_id'] ) ? (int) $data['intake_id'] : 0 );
		$course = $intake ? OSB_DB::get( 'courses', (int) $intake->course_id ) : null;
		if ( ! $intake || ! $course ) {
			return new WP_Error( 'osb_course', __( 'Please choose a course.', 'osam-bookings' ) );
		}
		if ( ! $intake->first_start ) {
			return new WP_Error( 'osb_course', __( 'This course has no dates yet.', 'osam-bookings' ) );
		}
		if ( empty( $data['admin'] ) ) {
			$reason = self::closed_reason( $intake, $course );
			if ( 'full' === $reason ) {
				return new WP_Error( 'osb_full', __( 'Sorry, this course is fully booked.', 'osam-bookings' ) );
			}
			if ( $reason ) {
				return new WP_Error( 'osb_course_closed', __( 'Enrolment for this course has closed.', 'osam-bookings' ) );
			}
			if ( $seats > max( 1, (int) $course->max_per_booking ) ) {
				return new WP_Error( 'osb_seats', __( 'Too many places requested.', 'osam-bookings' ) );
			}
			$left = self::capacity( $intake, $course ) - self::seats_taken( $intake->id, 0, true );
			if ( $seats > $left ) {
				/* translators: %d seats */
				return new WP_Error( 'osb_full', sprintf( __( 'Only %d places are left.', 'osam-bookings' ), $left ) );
			}
		}
		return array(
			array(
				'course_id' => (int) $course->id,
				'intake_id' => (int) $intake->id,
				'staff_id'  => (int) $course->staff_id,
				'start_at'  => $intake->first_start,
				'end_at'    => $intake->last_end,
				'amount'    => round( self::price( $intake, $course ) * $seats, 2 ),
			),
			$course,
		);
	}

	/**
	 * Put a new course booking onto every session.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_booking_created( $booking ) {
		if ( 'course' === $booking->type && $booking->intake_id ) {
			self::sync_attendance( (int) $booking->intake_id, (int) $booking->id );
		}
	}

	/**
	 * Clean up attendance when a booking is deleted.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function on_booking_deleted( $booking_id ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'attendance' ), array( 'booking_id' => (int) $booking_id ) );
	}

	/**
	 * Make sure bookings on an intake have exactly one attendance row per current session.
	 *
	 * @param int $intake_id  Intake.
	 * @param int $booking_id Limit to one booking (0 = all on the intake).
	 */
	public static function sync_attendance( $intake_id, $booking_id = 0 ) {
		global $wpdb;
		$a        = OSB_Install::table( 'attendance' );
		$s        = OSB_Install::table( 'course_sessions' );
		$b        = OSB_Install::table( 'bookings' );
		$sessions = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $s WHERE intake_id = %d", $intake_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bookings = $booking_id
			? array( (int) $booking_id )
			: array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $b WHERE intake_id = %d", $intake_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $bookings as $bid ) {
			$have = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM $a WHERE booking_id = %d", $bid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array_diff( $sessions, $have ) as $sid ) {
				$wpdb->insert(
					$a,
					array(
						'booking_id' => $bid,
						'session_id' => $sid,
						'status'     => 'booked',
						'updated_at' => current_time( 'mysql' ),
					)
				);
			}
			foreach ( array_diff( $have, $sessions ) as $sid ) {
				$wpdb->delete(
					$a,
					array(
						'booking_id' => $bid,
						'session_id' => $sid,
					)
				);
			}
		}
	}

	/**
	 * Attendance rows for a booking keyed by session id.
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function attendance_for_booking( $booking_id ) {
		global $wpdb;
		$t   = OSB_Install::table( 'attendance' );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d", $booking_id ) ) as $row ) {
			$out[ (int) $row->session_id ] = $row;
		}
		return $out;
	}

	/**
	 * Attendance for an intake: [ booking_id ][ session_id ] => status.
	 *
	 * @param int $intake_id Intake.
	 * @return array
	 */
	public static function register( $intake_id ) {
		global $wpdb;
		$a   = OSB_Install::table( 'attendance' );
		$s   = OSB_Install::table( 'course_sessions' );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT a.booking_id, a.session_id, a.status FROM $a a INNER JOIN $s s ON s.id = a.session_id WHERE s.intake_id = %d", $intake_id ) ) as $row ) {
			$out[ (int) $row->booking_id ][ (int) $row->session_id ] = $row->status;
		}
		return $out;
	}

	/**
	 * Set one attendance mark.
	 *
	 * @param int    $booking_id Booking.
	 * @param int    $session_id Session.
	 * @param string $status     booked|attended|absent.
	 */
	public static function mark( $booking_id, $session_id, $status ) {
		global $wpdb;
		if ( ! isset( self::attendance_statuses()[ $status ] ) ) {
			return;
		}
		$wpdb->update(
			OSB_Install::table( 'attendance' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'booking_id' => (int) $booking_id,
				'session_id' => (int) $session_id,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Recalculate an intake's first/last dates and move its bookings to match.
	 *
	 * @param int $intake_id Intake.
	 */
	public static function refresh_intake( $intake_id ) {
		global $wpdb;
		$s     = OSB_Install::table( 'course_sessions' );
		$range = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(start_at) a, MAX(end_at) b FROM $s WHERE intake_id = %d", $intake_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first = $range && $range->a ? $range->a : null;
		$last  = $range && $range->b ? $range->b : null;
		$wpdb->update(
			OSB_Install::table( 'course_intakes' ),
			array(
				'first_start' => $first,
				'last_end'    => $last,
			),
			array( 'id' => (int) $intake_id )
		);
		if ( $first ) {
			$wpdb->update(
				OSB_Install::table( 'bookings' ),
				array(
					'start_at' => $first,
					'end_at'   => $last,
				),
				array( 'intake_id' => (int) $intake_id )
			);
		}
	}

	/**
	 * Replace an intake's sessions from the schedule builder.
	 *
	 * @param int   $intake_id Intake.
	 * @param array $rows      [ [ id, date, start, end, location, staff_id, note ] ].
	 * @return array [ 'changed' => bool, 'count' => int ]
	 */
	public static function save_sessions( $intake_id, array $rows ) {
		global $wpdb;
		$intake   = OSB_DB::get( 'course_intakes', $intake_id );
		$t        = OSB_Install::table( 'course_sessions' );
		$existing = array();
		foreach ( self::sessions( $intake_id ) as $s ) {
			$existing[ (int) $s->id ] = $s;
		}
		$keep    = array();
		$changed = false;

		foreach ( $rows as $r ) {
			$date  = isset( $r['date'] ) ? sanitize_text_field( $r['date'] ) : '';
			$start = isset( $r['start'] ) ? substr( sanitize_text_field( $r['start'] ), 0, 5 ) : '';
			$end   = isset( $r['end'] ) ? substr( sanitize_text_field( $r['end'] ), 0, 5 ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
				continue; // Blank rows are ignored.
			}
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) || $end <= $start ) {
				$end = gmdate( 'H:i', strtotime( $date . ' ' . $start . ':00' ) + HOUR_IN_SECONDS );
				$end = $end <= $start ? '23:59' : $end;
			}
			$row = array(
				'intake_id' => (int) $intake_id,
				'course_id' => (int) $intake->course_id,
				'start_at'  => $date . ' ' . $start . ':00',
				'end_at'    => $date . ' ' . $end . ':00',
				'location'  => sanitize_text_field( isset( $r['location'] ) ? $r['location'] : '' ),
				'staff_id'  => absint( isset( $r['staff_id'] ) ? $r['staff_id'] : 0 ),
				'note'      => sanitize_text_field( isset( $r['note'] ) ? $r['note'] : '' ),
			);
			$id = isset( $r['id'] ) ? absint( $r['id'] ) : 0;
			if ( $id && isset( $existing[ $id ] ) ) {
				$old = $existing[ $id ];
				if ( $old->start_at !== $row['start_at'] || $old->end_at !== $row['end_at'] || $old->location !== $row['location'] ) {
					$changed = true;
				}
				$wpdb->update( $t, $row, array( 'id' => $id ) );
				if ( $old->start_at !== $row['start_at'] ) {
					// A moved session deserves a fresh reminder.
					$wpdb->update( OSB_Install::table( 'attendance' ), array( 'reminder_sent' => 0 ), array( 'session_id' => $id ) );
				}
				$keep[] = $id;
			} else {
				$wpdb->insert( $t, $row );
				$keep[]  = (int) $wpdb->insert_id;
				$changed = true;
			}
		}

		foreach ( array_diff( array_keys( $existing ), $keep ) as $gone ) {
			$wpdb->delete( $t, array( 'id' => (int) $gone ) );
			$wpdb->delete( OSB_Install::table( 'attendance' ), array( 'session_id' => (int) $gone ) );
			$changed = true;
		}

		self::refresh_intake( $intake_id );
		self::sync_attendance( $intake_id );
		OSB_Availability::bust_cache();

		return array(
			'changed' => $changed,
			'count'   => count( $keep ),
		);
	}

	/**
	 * Copy an intake, moving its dates on so it starts the week after the original ends.
	 *
	 * @param int $intake_id Intake.
	 * @return int New intake id.
	 */
	public static function duplicate_intake( $intake_id ) {
		$intake = OSB_DB::get( 'course_intakes', $intake_id );
		if ( ! $intake ) {
			return 0;
		}
		$sessions = self::sessions( $intake_id );
		$weeks    = 0;
		if ( $sessions ) {
			$first = strtotime( $sessions[0]->start_at );
			$last  = strtotime( end( $sessions )->start_at );
			$weeks = (int) floor( ( $last - $first ) / WEEK_IN_SECONDS ) + 1;
		}
		$new = OSB_DB::insert(
			'course_intakes',
			array(
				'course_id'  => (int) $intake->course_id,
				/* translators: %s intake name */
				'name'       => sprintf( __( '%s (copy)', 'osam-bookings' ), self::intake_label( $intake ) ),
				'capacity'   => (int) $intake->capacity,
				'price'      => $intake->price,
				'status'     => 'hidden',
				'created_at' => current_time( 'mysql' ),
			)
		);
		if ( ! $new ) {
			return 0;
		}
		foreach ( $sessions as $s ) {
			$row = (array) $s;
			unset( $row['id'] );
			$row['intake_id'] = $new;
			$row['start_at']  = gmdate( 'Y-m-d H:i:s', strtotime( $s->start_at . ' +' . $weeks . ' weeks' ) );
			$row['end_at']    = gmdate( 'Y-m-d H:i:s', strtotime( $s->end_at . ' +' . $weeks . ' weeks' ) );
			OSB_DB::insert( 'course_sessions', $row );
		}
		self::refresh_intake( $new );
		OSB_Availability::bust_cache();
		return $new;
	}

	/**
	 * Delete an intake and its sessions (callers check there are no bookings first).
	 *
	 * @param int $intake_id Intake.
	 */
	public static function delete_intake( $intake_id ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'course_sessions' ), array( 'intake_id' => (int) $intake_id ) );
		OSB_DB::delete( 'course_intakes', $intake_id );
		OSB_Availability::bust_cache();
	}

	/**
	 * Email everyone enrolled on an intake that the dates have changed.
	 *
	 * @param int $intake_id Intake.
	 * @return int Emails sent.
	 */
	public static function notify_schedule_change( $intake_id ) {
		$sent = 0;
		foreach ( self::enrolments( $intake_id ) as $booking ) {
			if ( in_array( $booking->status, array( 'pending', 'confirmed' ), true ) && OSB_Emails::send( 'customer_schedule_changed', $booking, $booking->customer_email ) ) {
				++$sent;
			}
		}
		do_action( 'osb_course_schedule_changed', $intake_id, $sent );
		return $sent;
	}

	/**
	 * Per-session reminder emails (course bookings get one before every session).
	 */
	public static function send_session_reminders() {
		$hours = (float) OSB_Settings::get( 'reminder_hours' );
		if ( $hours <= 0 ) {
			return;
		}
		global $wpdb;
		$a     = OSB_Install::table( 'attendance' );
		$s     = OSB_Install::table( 'course_sessions' );
		$b     = OSB_Install::table( 'bookings' );
		$now   = current_time( 'mysql' );
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + (int) ( $hours * HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.id attendance_id, a.booking_id, a.session_id FROM $a a INNER JOIN $s s ON s.id = a.session_id INNER JOIN $b b ON b.id = a.booking_id WHERE b.status = 'confirmed' AND a.reminder_sent = 0 AND s.start_at > %s AND s.start_at <= %s LIMIT 100", $now, $until ) );
		foreach ( $rows as $row ) {
			$wpdb->update( $a, array( 'reminder_sent' => 1 ), array( 'id' => (int) $row->attendance_id ) );
			$booking = OSB_DB::get( 'bookings', $row->booking_id );
			$session = OSB_DB::get( 'course_sessions', $row->session_id );
			if ( ! $booking || ! $session ) {
				continue;
			}
			// Skip if they enrolled inside the reminder window — they have just had a confirmation.
			if ( strtotime( $session->start_at ) - strtotime( $booking->created_at ) < $hours * HOUR_IN_SECONDS ) {
				continue;
			}
			OSB_Emails::send( 'customer_session_reminder', $booking, $booking->customer_email, false, self::session_placeholders( $booking, $session ) );
		}
	}

	/**
	 * Email placeholders for one session.
	 *
	 * @param object $booking Booking.
	 * @param object $session Session.
	 * @return array
	 */
	public static function session_placeholders( $booking, $session ) {
		$course = OSB_DB::get( 'courses', $booking->course_id );
		$number = 0;
		$total  = 0;
		foreach ( self::sessions( $session->intake_id ) as $i => $s ) {
			++$total;
			if ( (int) $s->id === (int) $session->id ) {
				$number = $i + 1;
			}
		}
		$v = self::session_view( $session, $number, $course );
		return array(
			'{session_number}'   => $number,
			'{session_count}'    => $total,
			'{session_date}'     => esc_html( $v['date_label'] ),
			'{session_time}'     => esc_html( $v['time'] ),
			'{session_end_time}' => esc_html( $v['end_time'] ),
			'{session_location}' => esc_html( $v['location'] ),
			'{session_note}'     => esc_html( $v['note'] ),
		);
	}

	/**
	 * Schedule as an email-safe HTML table.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function schedule_html( $booking ) {
		if ( 'course' !== $booking->type ) {
			return '';
		}
		$course   = OSB_DB::get( 'courses', $booking->course_id );
		$schedule = self::schedule( (int) $booking->intake_id, $course );
		if ( ! $schedule ) {
			return '';
		}
		$default = $course ? $course->location : '';
		$ds      = OSB_Emails::design();
		$rows    = '';
		foreach ( $schedule as $s ) {
			$where = $s['location'] && $s['location'] !== $default ? ' · ' . esc_html( $s['location'] ) : '';
			$note  = $s['note'] ? '<div style="color:' . $ds['muted'] . ';font-size:13px;font-style:italic">' . esc_html( $s['note'] ) . '</div>' : '';
			$rows .= '<tr><td style="padding:8px 12px 8px 0;vertical-align:top;width:30px;border-top:1px solid ' . $ds['panel_bg'] . '"><span style="display:inline-block;width:24px;height:24px;line-height:24px;border-radius:50%;background:' . $ds['panel_bg'] . ';color:' . $ds['accent'] . ';font-size:12px;font-weight:700;text-align:center">' . (int) $s['number'] . '</span></td>'
				. '<td style="padding:8px 0;vertical-align:top;font-size:14px;color:' . $ds['text'] . ';border-top:1px solid ' . $ds['panel_bg'] . '"><strong>' . esc_html( $s['date_label'] ) . '</strong><br><span style="color:' . $ds['muted'] . '">' . esc_html( $s['time_label'] ) . $where . '</span>' . $note . '</td></tr>';
		}
		return '<div style="margin:0 0 6px;font-size:15px;font-weight:700;color:' . $ds['text'] . '">' . esc_html( OSB_Settings::label( 'schedule' ) ) . '</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px">' . $rows . '</table>';
	}
}
