<?php
/**
 * Reporting queries.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Numbers for the Reports screen. Everything is worked out from the bookings table for a
 * date range, either by the date of the appointment or the date it was booked.
 */
class OSB_Reports {

	/**
	 * Statuses that count as a real booking.
	 *
	 * @return string[]
	 */
	public static function counted() {
		return array( 'pending', 'confirmed', 'seated', 'completed', 'no_show' );
	}

	/**
	 * Date column for a basis.
	 *
	 * @param string $basis visit|booked.
	 * @return string
	 */
	private static function col( $basis ) {
		return 'booked' === $basis ? 'created_at' : 'start_at';
	}

	/**
	 * WHERE for a range.
	 *
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d (inclusive).
	 * @param string $basis Basis.
	 * @return string
	 */
	private static function range_where( $from, $to, $basis ) {
		global $wpdb;
		$col = self::col( $basis );
		return $wpdb->prepare( "$col >= %s AND $col < %s", $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Headline numbers.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array
	 */
	public static function summary( $from, $to, $basis = 'visit' ) {
		global $wpdb;
		$b     = OSB_Install::table( 'bookings' );
		$where = self::range_where( $from, $to, $basis );
		$in    = "'" . implode( "','", self::counted() ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END) AS bookings,
				SUM(CASE WHEN status IN ($in) THEN seats ELSE 0 END) AS places,
				SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) AS revenue,
				SUM(CASE WHEN payment_status = 'unpaid' AND status IN ('pending','confirmed','completed') THEN amount ELSE 0 END) AS outstanding,
				SUM(CASE WHEN payment_status = 'refunded' THEN amount ELSE 0 END) AS refunded,
				SUM(CASE WHEN status IN ($in) THEN discount ELSE 0 END) AS discounts,
				SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
				SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_shows,
				SUM(CASE WHEN status = 'completed' OR ( status = 'confirmed' AND end_at < '" . esc_sql( current_time( 'mysql' ) ) . "' ) THEN 1 ELSE 0 END) AS attended,
				COUNT(DISTINCT CASE WHEN status IN ($in) THEN LOWER(customer_email) END) AS customers
			FROM $b WHERE $where AND status <> 'pending_payment'"
		);
		// New customers: first ever booking falls in the range.
		$new = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM ( SELECT LOWER(customer_email) e, MIN(" . self::col( $basis ) . ") f FROM $b WHERE customer_email <> '' AND status IN ($in) GROUP BY LOWER(customer_email) ) x WHERE " . str_replace( self::col( $basis ), 'f', $where )
		);
		// phpcs:enable
		$bookings = (int) $row->bookings;
		$all      = $bookings + (int) $row->cancelled;
		$finished = (int) $row->attended + (int) $row->no_shows;
		return array(
			'bookings'      => $bookings,
			'places'        => (int) $row->places,
			'revenue'       => (float) $row->revenue,
			'outstanding'   => (float) $row->outstanding,
			'refunded'      => (float) $row->refunded,
			'discounts'     => (float) $row->discounts,
			'cancelled'     => (int) $row->cancelled,
			'no_shows'      => (int) $row->no_shows,
			'customers'     => (int) $row->customers,
			'new_customers' => $new,
			'cancel_rate'   => $all ? round( 100 * (int) $row->cancelled / $all, 1 ) : 0,
			'no_show_rate'  => $finished ? round( 100 * (int) $row->no_shows / $finished, 1 ) : 0,
			'avg_value'     => $bookings ? (float) $row->revenue / max( 1, $bookings ) : 0,
		);
	}

	/**
	 * Bookings and revenue per day / week / month.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array [ unit, buckets: [ key => [ label, bookings, revenue ] ] ]
	 */
	public static function timeline( $from, $to, $basis = 'visit' ) {
		global $wpdb;
		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		$unit = $days > 400 ? 'month' : ( $days > 62 ? 'week' : 'day' );
		$col  = self::col( $basis );
		$fmt  = array(
			'day'   => '%Y-%m-%d',
			'week'  => '%x-%v',
			'month' => '%Y-%m',
		);
		$b    = OSB_Install::table( 'bookings' );
		$in   = "'" . implode( "','", self::counted() ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT($col, %s) k, SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END) n, SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) r FROM $b WHERE " . self::range_where( $from, $to, $basis ) . " AND status <> 'pending_payment' GROUP BY k", $fmt[ $unit ] ), OBJECT_K );

		$buckets = array();
		$cursor  = strtotime( $from );
		$end     = strtotime( $to );
		while ( $cursor <= $end ) {
			if ( 'day' === $unit ) {
				$key   = gmdate( 'Y-m-d', $cursor );
				$label = date_i18n( 'j M', $cursor );
				$next  = strtotime( '+1 day', $cursor );
			} elseif ( 'week' === $unit ) {
				$key   = gmdate( 'o-W', $cursor );
				$label = date_i18n( 'j M', $cursor );
				$next  = strtotime( 'monday next week', $cursor );
			} else {
				$key   = gmdate( 'Y-m', $cursor );
				$label = date_i18n( 'M Y', $cursor );
				$next  = strtotime( 'first day of next month', $cursor );
			}
			$buckets[ $key ] = array(
				'label'    => $label,
				'bookings' => isset( $rows[ $key ] ) ? (int) $rows[ $key ]->n : 0,
				'revenue'  => isset( $rows[ $key ] ) ? (float) $rows[ $key ]->r : 0,
				'weekend'  => 'day' === $unit && (int) gmdate( 'N', $cursor ) >= 6,
			);
			$cursor = $next;
		}
		return array(
			'unit'    => $unit,
			'buckets' => $buckets,
		);
	}

	/**
	 * Per service / event / course.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array rows sorted by revenue: [ type, id, name, color, bookings, places, revenue, cancelled, no_shows ]
	 */
	public static function by_item( $from, $to, $basis = 'visit' ) {
		global $wpdb;
		$b     = OSB_Install::table( 'bookings' );
		$in    = "'" . implode( "','", self::counted() ) . "'";
		$names = OSB_Admin_Dashboard::name_maps();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT type, CASE type WHEN 'event' THEN event_id WHEN 'course' THEN course_id ELSE service_id END AS item_id, SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END) bookings, SUM(CASE WHEN status IN ($in) THEN seats ELSE 0 END) places, SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) revenue, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) cancelled, SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) no_shows FROM $b WHERE " . self::range_where( $from, $to, $basis ) . " AND status <> 'pending_payment' GROUP BY type, item_id" );
		$out  = array();
		foreach ( $rows as $r ) {
			$map  = 'event' === $r->type ? 'events' : ( 'course' === $r->type ? 'courses' : 'services' );
			$item = isset( $names[ $map ][ (int) $r->item_id ] ) ? $names[ $map ][ (int) $r->item_id ] : null;
			$name = $item ? $item->name : __( '(deleted)', 'osam-bookings' );
			if ( 'table' === $r->type ) {
				$item = (object) array( 'color' => '#c2255c' );
				$name = __( 'Table bookings', 'osam-bookings' );
			}
			if ( 'resource' === $r->type ) {
				$item = (object) array( 'color' => '#0c8599' );
				$name = __( 'Resource bookings', 'osam-bookings' );
			}
			if ( 'event' === $r->type && $item ) {
				$name .= ' — ' . mysql2date( 'j M', $item->start_at );
			}
			$out[] = array(
				'type'      => $r->type,
				'id'        => (int) $r->item_id,
				'name'      => $name,
				'color'     => $item && ! empty( $item->color ) ? $item->color : '#868e96',
				'bookings'  => (int) $r->bookings,
				'places'    => (int) $r->places,
				'revenue'   => (float) $r->revenue,
				'cancelled' => (int) $r->cancelled,
				'no_shows'  => (int) $r->no_shows,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'] ?: $b['bookings'] <=> $a['bookings'];
			}
		);
		return $out;
	}

	/**
	 * Per staff member, with how full their diary was.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array [ staff_id => [ name, bookings, revenue, booked_min, open_min, occupancy ] ]
	 */
	public static function by_staff( $from, $to, $basis = 'visit' ) {
		global $wpdb;
		$b    = OSB_Install::table( 'bookings' );
		$in   = "'" . implode( "','", self::counted() ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT staff_id, SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END) bookings, SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) revenue FROM $b WHERE " . self::range_where( $from, $to, $basis ) . " AND status <> 'pending_payment' AND staff_id > 0 GROUP BY staff_id", OBJECT_K );
		$occ  = self::occupancy( $from, $to );
		$out  = array();
		foreach ( OSB_DB::staff() as $st ) {
			$id = (int) $st->id;
			$o  = isset( $occ['staff'][ $id ] ) ? $occ['staff'][ $id ] : array( 0, 0 );
			if ( empty( $rows[ $id ] ) && ! $o[1] ) {
				continue;
			}
			$out[ $id ] = array(
				'name'       => $st->name,
				'color'      => isset( $st->color ) ? $st->color : '',
				'bookings'   => isset( $rows[ $id ] ) ? (int) $rows[ $id ]->bookings : 0,
				'revenue'    => isset( $rows[ $id ] ) ? (float) $rows[ $id ]->revenue : 0,
				'booked_min' => $o[0],
				'open_min'   => $o[1],
				'occupancy'  => $o[1] ? min( 100, round( 100 * $o[0] / $o[1] ) ) : null,
			);
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		return $out;
	}

	/**
	 * How much of the working hours were booked (appointments only), and how full events
	 * and course intakes were. Always by appointment date.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array [ overall %, staff => [ id => [ booked, open ] ], events %, courses % ]
	 */
	public static function occupancy( $from, $to ) {
		static $cache = array();
		$ck = $from . '|' . $to;
		if ( isset( $cache[ $ck ] ) ) {
			return $cache[ $ck ];
		}
		global $wpdb;
		$b     = OSB_Install::table( 'bookings' );
		$staff = array();
		$end   = strtotime( $to );
		foreach ( OSB_DB::staff( true ) as $st ) {
			$open = 0;
			for ( $d = strtotime( $from ); $d <= $end; $d = strtotime( '+1 day', $d ) ) {
				foreach ( OSB_Availability::working_ranges( (int) $st->id, gmdate( 'Y-m-d', $d ) ) as $r ) {
					$open += max( 0, ( $r[1] - $r[0] ) / 60 );
				}
			}
			$staff[ (int) $st->id ] = array( 0, (int) $open );
		}
		$range = self::range_where( $from, $to, 'visit' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT staff_id, SUM(TIMESTAMPDIFF(MINUTE, start_at, end_at)) m FROM $b WHERE type = 'service' AND status IN ('pending','confirmed','completed','no_show') AND $range GROUP BY staff_id" ) as $r ) {
			if ( isset( $staff[ (int) $r->staff_id ] ) ) {
				$staff[ (int) $r->staff_id ][0] = (int) $r->m;
			}
		}
		$booked = array_sum( wp_list_pluck( $staff, 0 ) );
		$open   = array_sum( wp_list_pluck( $staff, 1 ) );

		// Events in the range: places sold / places available.
		$e   = OSB_Install::table( 'events' );
		$cap = 0;
		$tak = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT id, capacity FROM $e WHERE $range" ) as $ev ) {
			$cap += (int) $ev->capacity;
			$tak += min( (int) $ev->capacity, OSB_DB::event_seats_taken( (int) $ev->id ) );
		}
		// Course intakes starting in the range.
		$ccap = 0;
		$ctak = 0;
		$i    = OSB_Install::table( 'course_intakes' );
		$c    = OSB_Install::table( 'courses' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT i.id, IF(i.capacity > 0, i.capacity, c.capacity) cap FROM $i i JOIN $c c ON c.id = i.course_id WHERE " . str_replace( 'start_at', 'i.first_start', $range ) ) as $in ) {
			$ccap += (int) $in->cap;
			$ctak += min( (int) $in->cap, OSB_Courses::seats_taken( (int) $in->id ) );
		}
		$cache[ $ck ] = array(
			'overall' => $open ? min( 100, round( 100 * $booked / $open ) ) : null,
			'booked'  => $booked,
			'open'    => $open,
			'staff'   => $staff,
			'events'  => $cap ? round( 100 * $tak / $cap ) : null,
			'courses' => $ccap ? round( 100 * $ctak / $ccap ) : null,
		);
		return $cache[ $ck ];
	}

	/**
	 * Busiest weekdays.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @return array 1..7 => count
	 */
	public static function by_weekday( $from, $to ) {
		global $wpdb;
		$b   = OSB_Install::table( 'bookings' );
		$in  = "'" . implode( "','", self::counted() ) . "'";
		$out = array_fill( 1, 7, 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT WEEKDAY(start_at) + 1 d, COUNT(*) n FROM $b WHERE status IN ($in) AND " . self::range_where( $from, $to, 'visit' ) . ' GROUP BY d' ) as $r ) {
			$out[ (int) $r->d ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Busiest start hours.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array hour => count
	 */
	public static function by_hour( $from, $to ) {
		global $wpdb;
		$b   = OSB_Install::table( 'bookings' );
		$in  = "'" . implode( "','", self::counted() ) . "'";
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( "SELECT HOUR(start_at) h, COUNT(*) n FROM $b WHERE type = 'service' AND status IN ($in) AND " . self::range_where( $from, $to, 'visit' ) . ' GROUP BY h ORDER BY h' ) as $r ) {
			$out[ (int) $r->h ] = (int) $r->n;
		}
		if ( $out ) {
			$out = array_replace( array_fill( min( array_keys( $out ) ), max( array_keys( $out ) ) - min( array_keys( $out ) ) + 1, 0 ), $out );
		}
		return $out;
	}

	/**
	 * Top customers by spend.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @param int    $limit Max.
	 * @return array
	 */
	public static function top_customers( $from, $to, $basis = 'visit', $limit = 10 ) {
		global $wpdb;
		$b  = OSB_Install::table( 'bookings' );
		$in = "'" . implode( "','", self::counted() ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(customer_email) email, MAX(customer_name) name, COUNT(*) bookings, SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) spent FROM $b WHERE customer_email <> '' AND customer_email NOT LIKE %s AND status IN ($in) AND " . self::range_where( $from, $to, $basis ) . ' GROUP BY LOWER(customer_email) ORDER BY spent DESC, bookings DESC LIMIT %d', '%@' . OSB_Customers::ERASED_DOMAIN, $limit ) );
	}

	/**
	 * Discount code / voucher use.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array
	 */
	public static function codes( $from, $to, $basis = 'visit' ) {
		global $wpdb;
		$b  = OSB_Install::table( 'bookings' );
		$in = "'" . implode( "','", self::counted() ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT UPPER(coupon_code) code, COUNT(*) uses, SUM(discount) discount, SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) revenue FROM $b WHERE coupon_code <> '' AND status IN ($in) AND " . self::range_where( $from, $to, $basis ) . ' GROUP BY UPPER(coupon_code) ORDER BY uses DESC, discount DESC LIMIT 20' );
	}

	/**
	 * CSV of the per-item table.
	 *
	 * @param string $from  From.
	 * @param string $to    To.
	 * @param string $basis Basis.
	 * @return array
	 */
	public static function csv_rows( $from, $to, $basis = 'visit' ) {
		$types = array(
			'service' => __( 'Service', 'osam-bookings' ),
			'event'   => __( 'Event', 'osam-bookings' ),
			'course'  => __( 'Course', 'osam-bookings' ),
		);
		$out   = array( array( __( 'Type', 'osam-bookings' ), __( 'Name', 'osam-bookings' ), __( 'Bookings', 'osam-bookings' ), __( 'Places', 'osam-bookings' ), __( 'Revenue (paid)', 'osam-bookings' ), __( 'Cancelled', 'osam-bookings' ), __( 'No-shows', 'osam-bookings' ) ) );
		foreach ( self::by_item( $from, $to, $basis ) as $r ) {
			$out[] = array( $types[ $r['type'] ] ?? $r['type'], $r['name'], $r['bookings'], $r['places'], number_format( $r['revenue'], 2, '.', '' ), $r['cancelled'], $r['no_shows'] );
		}
		return $out;
	}
}
