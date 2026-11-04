<?php
/**
 * Data access helpers.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin repository layer over the custom tables.
 */
class OSB_DB {

	/**
	 * Statuses that hold a slot / seat.
	 *
	 * @return array
	 */
	public static function blocking_statuses() {
		return apply_filters( 'osb_blocking_statuses', array( 'pending', 'pending_payment', 'confirmed', 'completed' ) );
	}

	/**
	 * Generic fetch by id.
	 *
	 * @param string $table Short table name.
	 * @param int    $id    Row id.
	 * @return object|null
	 */
	public static function get( $table, $id ) {
		global $wpdb;
		$t = OSB_Install::table( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
	}

	/**
	 * Insert a row.
	 *
	 * @param string $table Short name.
	 * @param array  $data  Data.
	 * @return int Insert id (0 on failure).
	 */
	public static function insert( $table, array $data ) {
		global $wpdb;
		$ok = $wpdb->insert( OSB_Install::table( $table ), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row.
	 *
	 * @param string $table Short name.
	 * @param int    $id    Id.
	 * @param array  $data  Data.
	 * @return bool
	 */
	public static function update( $table, $id, array $data ) {
		global $wpdb;
		return false !== $wpdb->update( OSB_Install::table( $table ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a row.
	 *
	 * @param string $table Short name.
	 * @param int    $id    Id.
	 * @return bool
	 */
	public static function delete( $table, $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( OSB_Install::table( $table ), array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Solo mode — one diary for the business owner, no employees.
	 * ------------------------------------------------------------------ */

	/**
	 * Is the site in solo (no employees) mode?
	 *
	 * @return bool
	 */
	public static function is_solo() {
		return 'solo' === OSB_Settings::get( 'staff_mode' );
	}

	/**
	 * The owner's diary (a staff row). Created on demand with Mon–Fri 09:00–17:00.
	 *
	 * @return int
	 */
	public static function solo_staff_id() {
		$id = (int) get_option( 'osb_solo_staff_id' );
		if ( $id && self::get( 'staff', $id ) ) {
			return $id;
		}
		// Re-use the only existing staff member if there is exactly one.
		$all = self::staff();
		if ( 1 === count( $all ) ) {
			$id = (int) $all[0]->id;
		} else {
			$id = self::insert(
				'staff',
				array(
					'name'       => get_bloginfo( 'name' ),
					'email'      => get_option( 'admin_email' ),
					'status'     => 'active',
					'created_at' => current_time( 'mysql' ),
				)
			);
			$week = array();
			for ( $d = 1; $d <= 5; $d++ ) {
				$week[ $d ] = array( array( '09:00', '17:00' ) );
			}
			self::set_schedule( $id, $week );
		}
		update_option( 'osb_solo_staff_id', $id, false );
		return $id;
	}

	/**
	 * Called when the business type changes.
	 *
	 * @param string $mode solo|team.
	 */
	public static function apply_staff_mode( $mode ) {
		$owner = self::solo_staff_id();
		self::update( 'staff', $owner, array( 'status' => 'active' ) );
		// Make sure no service is left without anyone to deliver it.
		foreach ( self::services() as $s ) {
			if ( 'team' === $mode && ! self::raw_service_staff_ids( $s->id ) ) {
				self::set_service_staff( $s->id, array( $owner => '' ) );
			}
		}
		OSB_Availability::bust_cache();
	}

	/* ---------------------------------------------------------------------
	 * Services
	 * ------------------------------------------------------------------ */

	/**
	 * List services.
	 *
	 * @param bool $active_only Only active.
	 * @return array
	 */
	public static function services( $active_only = false ) {
		global $wpdb;
		$t     = OSB_Install::table( 'services' );
		$where = $active_only ? "WHERE status = 'active'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t $where ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * Staff ids assigned to a service.
	 *
	 * @param int $service_id Service.
	 * @return int[]
	 */
	public static function service_staff_ids( $service_id ) {
		if ( self::is_solo() ) {
			return array( self::solo_staff_id() );
		}
		return self::raw_service_staff_ids( $service_id );
	}

	/**
	 * Staff ids assigned in the table (ignores solo mode).
	 *
	 * @param int $service_id Service.
	 * @return int[]
	 */
	public static function raw_service_staff_ids( $service_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'service_staff' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT staff_id FROM $t WHERE service_id = %d", $service_id ) ) );
	}

	/**
	 * Service-staff price overrides.
	 *
	 * @param int $service_id Service.
	 * @return array staff_id => price|null
	 */
	public static function service_staff_prices( $service_id ) {
		global $wpdb;
		$t    = OSB_Install::table( 'service_staff' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, price FROM $t WHERE service_id = %d", $service_id ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->staff_id ] = null === $row->price ? null : (float) $row->price;
		}
		return $out;
	}

	/**
	 * Replace assignments for a service.
	 *
	 * @param int   $service_id Service.
	 * @param array $staff      staff_id => price|'' .
	 */
	public static function set_service_staff( $service_id, array $staff ) {
		global $wpdb;
		$t = OSB_Install::table( 'service_staff' );
		$wpdb->delete( $t, array( 'service_id' => (int) $service_id ) );
		foreach ( $staff as $staff_id => $price ) {
			$row = array(
				'service_id' => (int) $service_id,
				'staff_id'   => (int) $staff_id,
			);
			if ( '' !== $price && null !== $price ) {
				$row['price'] = (float) $price;
			}
			$wpdb->insert( $t, $row );
		}
	}

	/**
	 * Price for a service with a given staff member.
	 *
	 * @param object $service  Service row.
	 * @param int    $staff_id Staff.
	 * @return float
	 */
	public static function service_price( $service, $staff_id = 0 ) {
		$price = (float) $service->price;
		if ( $staff_id ) {
			$prices = self::service_staff_prices( $service->id );
			if ( isset( $prices[ $staff_id ] ) && null !== $prices[ $staff_id ] ) {
				$price = (float) $prices[ $staff_id ];
			}
		}
		return (float) apply_filters( 'osb_service_price', $price, $service, $staff_id );
	}

	/* ---------------------------------------------------------------------
	 * Staff
	 * ------------------------------------------------------------------ */

	/**
	 * List staff.
	 *
	 * @param bool $active_only Only active.
	 * @return array
	 */
	public static function staff( $active_only = false ) {
		global $wpdb;
		$t     = OSB_Install::table( 'staff' );
		$where = $active_only ? "WHERE status = 'active'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t $where ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * Active staff for a service.
	 *
	 * @param int $service_id Service.
	 * @return array
	 */
	public static function staff_for_service( $service_id ) {
		if ( self::is_solo() ) {
			$owner = self::get( 'staff', self::solo_staff_id() );
			return $owner ? array( $owner ) : array();
		}
		global $wpdb;
		$s  = OSB_Install::table( 'staff' );
		$ss = OSB_Install::table( 'service_staff' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM $s s INNER JOIN $ss ss ON ss.staff_id = s.id WHERE ss.service_id = %d AND s.status = 'active' ORDER BY s.sort_order ASC, s.name ASC", $service_id ) );
	}

	/**
	 * Weekly schedule rows for staff.
	 *
	 * @param int $staff_id Staff.
	 * @return array weekday(1-7) => [ [start,end], ... ]
	 */
	public static function schedule( $staff_id ) {
		global $wpdb;
		$t    = OSB_Install::table( 'schedule' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT weekday, start_time, end_time FROM $t WHERE staff_id = %d ORDER BY weekday, start_time", $staff_id ) );
		$out  = array_fill_keys( range( 1, 7 ), array() );
		foreach ( $rows as $row ) {
			$out[ (int) $row->weekday ][] = array( substr( $row->start_time, 0, 5 ), substr( $row->end_time, 0, 5 ) );
		}
		return $out;
	}

	/**
	 * Replace a staff schedule.
	 *
	 * @param int   $staff_id Staff.
	 * @param array $schedule weekday => [ [start,end] ].
	 */
	public static function set_schedule( $staff_id, array $schedule ) {
		global $wpdb;
		$t = OSB_Install::table( 'schedule' );
		$wpdb->delete( $t, array( 'staff_id' => (int) $staff_id ) );
		foreach ( $schedule as $day => $ranges ) {
			foreach ( (array) $ranges as $range ) {
				if ( empty( $range[0] ) || empty( $range[1] ) || $range[0] >= $range[1] ) {
					continue;
				}
				$wpdb->insert(
					$t,
					array(
						'staff_id'   => (int) $staff_id,
						'weekday'    => (int) $day,
						'start_time' => $range[0] . ':00',
						'end_time'   => $range[1] . ':00',
					)
				);
			}
		}
	}

	/**
	 * Days off for a staff member (staff_id 0 = business-wide closures).
	 *
	 * @param int $staff_id Staff, or 0 for global only.
	 * @return array
	 */
	public static function days_off( $staff_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'days_off' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE staff_id = %d ORDER BY date_from DESC", $staff_id ) );
	}

	/**
	 * Is the staff member (or whole business) off on a date?
	 *
	 * @param int    $staff_id Staff.
	 * @param string $date     Y-m-d.
	 * @return bool
	 */
	public static function is_day_off( $staff_id, $date ) {
		static $cache = array();
		$key          = $staff_id . '|' . $date;
		if ( ! isset( $cache[ $key ] ) ) {
			global $wpdb;
			$t = OSB_Install::table( 'days_off' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache[ $key ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE staff_id IN (0, %d) AND date_from <= %s AND date_to >= %s", $staff_id, $date, $date ) );
		}
		return $cache[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * Upcoming active events.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function upcoming_events( $limit = 50 ) {
		global $wpdb;
		$t = OSB_Install::table( 'events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'active' AND start_at > %s ORDER BY start_at ASC LIMIT %d", current_time( 'mysql' ), $limit ) );
	}

	/**
	 * All events (admin).
	 *
	 * @return array
	 */
	public static function events() {
		global $wpdb;
		$t = OSB_Install::table( 'events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY start_at DESC" );
	}

	/**
	 * Seats already taken on an event.
	 *
	 * @param int  $event_id   Event.
	 * @param bool $with_holds Also count places held for people on the waitlist.
	 * @return int
	 */
	public static function event_seats_taken( $event_id, $with_holds = false ) {
		if ( $with_holds ) {
			return self::event_seats_taken( $event_id ) + OSB_Waitlist::held( 'event', (int) $event_id );
		}
		global $wpdb;
		$t        = OSB_Install::table( 'bookings' );
		$statuses = self::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE event_id = %d AND status IN ($in)", array_merge( array( $event_id ), $statuses ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Bookings
	 * ------------------------------------------------------------------ */

	/**
	 * Booking by token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function booking_by_token( $token ) {
		global $wpdb;
		if ( ! $token ) {
			return null;
		}
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token = %s", $token ) );
	}

	/**
	 * Blocking bookings for a staff member overlapping a window.
	 *
	 * Course bookings are left out: they span weeks, so the diary is blocked by the
	 * course's individual sessions instead (see OSB_Courses::staff_sessions_between()).
	 *
	 * @param int    $staff_id Staff.
	 * @param string $from     Datetime.
	 * @param string $to       Datetime.
	 * @return array
	 */
	public static function staff_bookings_between( $staff_id, $from, $to ) {
		global $wpdb;
		$t        = OSB_Install::table( 'bookings' );
		$statuses = self::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $staff_id, $to, $from ), $statuses );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, type, service_id, event_id, start_at, end_at, seats FROM $t WHERE staff_id = %d AND type <> 'course' AND start_at < %s AND end_at > %s AND status IN ($in)", $args ) );
	}

	/**
	 * Events led by a staff member in a window (they block that person's diary).
	 *
	 * @param int    $staff_id Staff.
	 * @param string $from     Datetime.
	 * @param string $to       Datetime.
	 * @return array
	 */
	public static function staff_events_between( $staff_id, $from, $to ) {
		global $wpdb;
		$t = OSB_Install::table( 'events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, start_at, end_at FROM $t WHERE staff_id = %d AND status = 'active' AND start_at < %s AND end_at > %s", $staff_id, $to, $from ) );
	}

	/**
	 * Query bookings for admin.
	 *
	 * @param array $args Filters.
	 * @return array [ rows, total ]
	 */
	public static function query_bookings( array $args ) {
		global $wpdb;
		$t     = OSB_Install::table( 'bookings' );
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		} elseif ( empty( $args['include_unpaid'] ) ) {
			// Unpaid and failed online payments stay out of lists and calendars unless asked for by status.
			$hidden  = OSB_Bookings::hidden_statuses();
			$where[] = 'status NOT IN (' . implode( ',', array_fill( 0, count( $hidden ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $hidden );
		}
		if ( ! empty( $args['staff_id'] ) ) {
			$where[] = 'staff_id = %d';
			$vals[]  = (int) $args['staff_id'];
		}
		if ( ! empty( $args['service_id'] ) ) {
			$where[] = 'service_id = %d';
			$vals[]  = (int) $args['service_id'];
		}
		if ( ! empty( $args['event_id'] ) ) {
			$where[] = 'event_id = %d';
			$vals[]  = (int) $args['event_id'];
		}
		if ( ! empty( $args['course_id'] ) ) {
			$where[] = 'course_id = %d';
			$vals[]  = (int) $args['course_id'];
		}
		if ( ! empty( $args['intake_id'] ) ) {
			$where[] = 'intake_id = %d';
			$vals[]  = (int) $args['intake_id'];
		}
		if ( ! empty( $args['type'] ) ) {
			$where[] = 'type = %s';
			$vals[]  = (string) $args['type'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'start_at >= %s';
			$vals[]  = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'start_at <= %s';
			$vals[]  = $args['to'] . ' 23:59:59';
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR id = %d)';
			array_push( $vals, $like, $like, $like, (int) $args['search'] );
		}

		$orderby  = in_array( isset( $args['orderby'] ) ? $args['orderby'] : '', array( 'start_at', 'created_at', 'id', 'status' ), true ) ? $args['orderby'] : 'start_at';
		$order    = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $t WHERE $sql_where";
		$rows_sql  = "SELECT * FROM $t WHERE $sql_where ORDER BY $orderby $order LIMIT $per_page OFFSET $offset";

		if ( $vals ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $vals ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $rows_sql );
		}

		return array( $rows, $total );
	}

	/**
	 * Record a payment row.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $gateway    Gateway id.
	 * @param string $txn        Transaction id.
	 * @param float  $amount     Amount.
	 * @param string $currency   Currency.
	 * @param string $status     Status.
	 * @param mixed  $data       Raw data.
	 * @return int
	 */
	public static function add_payment( $booking_id, $gateway, $txn, $amount, $currency, $status, $data = null ) {
		return self::insert(
			'payments',
			array(
				'booking_id'     => (int) $booking_id,
				'gateway'        => $gateway,
				'transaction_id' => (string) $txn,
				'amount'         => (float) $amount,
				'currency'       => $currency,
				'status'         => $status,
				'data'           => $data ? wp_json_encode( $data ) : null,
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Payments for a booking.
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function payments( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY id ASC", $booking_id ) );
	}

	/**
	 * Has this transaction already been recorded (idempotency for webhooks)?
	 *
	 * @param string $gateway Gateway.
	 * @param string $txn     Transaction id.
	 * @return bool
	 */
	public static function payment_exists( $gateway, $txn ) {
		global $wpdb;
		$t = OSB_Install::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE gateway = %s AND transaction_id = %s AND status = 'completed' LIMIT 1", $gateway, $txn ) );
	}
}
