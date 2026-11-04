<?php
/**
 * Bookable resources: meeting rooms, sports fields, dog fields, halls, studios…
 *
 * A resource has its own opening hours, a capacity (places) and a sharing rule:
 *  - shared:    customers book places until it's full;
 *  - exclusive: every booking takes the whole resource;
 *  - both:      customers choose — places, or book it out (only when nobody else is in).
 * Time can be booked as fixed slots (optionally several in a row), a flexible length between a
 * minimum and maximum, a morning/afternoon half day, or one or more full days.
 *
 * One booking (type "resource") can take several compatible resources at once; each one taken is
 * a row in booking_resources with its places, whether it's booked out, and its share of the price.
 *
 * Times are naive local, like the rest of the plugin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resource data, availability, pricing and booking hooks.
 */
class OSB_Resources {

	/**
	 * Per-request caches.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 10, 2 );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'on_booking_deleted' ) );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'flush' ) );
		add_action( 'osb_booking_rescheduled', array( __CLASS__, 'flush' ) );
		add_action( 'osb_booking_admin_updated', array( __CLASS__, 'flush' ) );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 10, 2 );
	}

	/**
	 * Module switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'resources' );
	}

	/**
	 * Clear caches.
	 */
	public static function flush() {
		self::$cache = array();
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Every resource keyed by id (cached).
	 *
	 * @return array
	 */
	public static function map() {
		if ( ! isset( self::$cache['map'] ) ) {
			global $wpdb;
			$t   = OSB_Install::table( 'resources' );
			$out = array();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, name ASC" ) as $r ) {
				$out[ (int) $r->id ] = $r;
			}
			self::$cache['map'] = $out;
		}
		return self::$cache['map'];
	}

	/**
	 * Resources in order.
	 *
	 * @param bool $active_only Active only.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		return array_values(
			array_filter(
				self::map(),
				static function ( $r ) use ( $active_only ) {
					return ! $active_only || 'active' === $r->status;
				}
			)
		);
	}

	/**
	 * One resource.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		$m = self::map();
		return isset( $m[ (int) $id ] ) ? $m[ (int) $id ] : null;
	}

	/**
	 * Weekly opening hours: weekday (1 Mon – 7 Sun) => [ [ 'H:i', 'H:i' ], … ].
	 *
	 * @param int $rid Resource.
	 * @return array
	 */
	public static function hours( $rid, $season = 0 ) {
		$key = 'hours' . (int) $rid . '|' . (int) $season;
		if ( ! isset( self::$cache[ $key ] ) ) {
			global $wpdb;
			$t   = OSB_Install::table( 'res_hours' );
			$out = array_fill( 1, 7, array() );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE resource_id = %d AND season_id = %d ORDER BY weekday ASC, start_time ASC", $rid, $season ) ) as $h ) {
				$out[ (int) $h->weekday ][] = array( substr( $h->start_time, 0, 5 ), substr( $h->end_time, 0, 5 ) );
			}
			self::$cache[ $key ] = $out;
		}
		return self::$cache[ $key ];
	}

	/**
	 * Replace a resource's weekly hours.
	 *
	 * @param int   $rid      Resource.
	 * @param array $schedule weekday => ranges.
	 * @param int   $season   Seasonal hours id (0 = standard hours).
	 */
	public static function set_hours( $rid, array $schedule, $season = 0 ) {
		global $wpdb;
		$t = OSB_Install::table( 'res_hours' );
		$wpdb->delete( $t, array( 'resource_id' => (int) $rid, 'season_id' => (int) $season ) );
		foreach ( $schedule as $day => $ranges ) {
			foreach ( (array) $ranges as $r ) {
				$wpdb->insert(
					$t,
					array(
						'resource_id' => (int) $rid,
						'season_id'   => (int) $season,
						'weekday'     => (int) $day,
						'start_time'  => $r[0] . ':00',
						'end_time'    => $r[1] . ':00',
					)
				);
			}
		}
		self::flush();
	}

	/**
	 * Closed dates for a resource (0 = closures that apply to every resource).
	 *
	 * @param int $rid Resource.
	 * @return array
	 */
	public static function closures( $rid ) {
		global $wpdb;
		$t = OSB_Install::table( 'res_closures' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE resource_id = %d ORDER BY date_from ASC", $rid ) );
	}

	/**
	 * Replace a resource's closed dates.
	 *
	 * @param int   $rid  Resource (0 = all resources).
	 * @param array $rows [ [ from, to, note ] ].
	 */
	public static function set_closures( $rid, array $rows ) {
		global $wpdb;
		$t = OSB_Install::table( 'res_closures' );
		$wpdb->delete( $t, array( 'resource_id' => (int) $rid ) );
		foreach ( $rows as $r ) {
			$from = isset( $r['from'] ) ? sanitize_text_field( $r['from'] ) : '';
			$to   = isset( $r['to'] ) && '' !== $r['to'] ? sanitize_text_field( $r['to'] ) : $from;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
				continue;
			}
			$to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $to >= $from ? $to : $from;
			$wpdb->insert(
				$t,
				array(
					'resource_id' => (int) $rid,
					'date_from'   => $from,
					'date_to'     => $to,
					'note'        => isset( $r['note'] ) ? sanitize_text_field( $r['note'] ) : '',
				)
			);
		}
		self::flush();
	}

	/**
	 * Is a resource closed on a date (its own closures or the all-resources ones)?
	 *
	 * @param int    $rid  Resource.
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public static function is_closed( $rid, $date ) {
		$key = 'closed' . $date;
		if ( ! isset( self::$cache[ $key ] ) ) {
			global $wpdb;
			$t                   = OSB_Install::table( 'res_closures' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$cache[ $key ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT resource_id FROM $t WHERE date_from <= %s AND date_to >= %s", $date, $date ) ) );
		}
		return in_array( 0, self::$cache[ $key ], true ) || in_array( (int) $rid, self::$cache[ $key ], true );
	}

	/**
	 * Opening ranges on a date as naive timestamps.
	 *
	 * @param object $r    Resource.
	 * @param string $date Y-m-d.
	 * @return array [ [ start, end ] ]
	 */
	public static function ranges( $r, $date ) {
		if ( self::is_closed( $r->id, $date ) ) {
			return array();
		}
		$out    = array();
		$season = self::season_for( $r->id, $date );
		foreach ( self::hours( $r->id, $season ? (int) $season->id : 0 )[ (int) gmdate( 'N', strtotime( $date . ' 12:00:00' ) ) ] as $h ) {
			$out[] = array( strtotime( $date . ' ' . $h[0] . ':00' ), strtotime( $date . ' ' . $h[1] . ':00' ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Seasonal opening hours
	 * ------------------------------------------------------------------ */

	/**
	 * How a season's dates are worked out.
	 *
	 * @return array mode => label
	 */
	public static function season_modes() {
		return array(
			'bst'    => __( 'British Summer Time — from when the clocks go forward (last Sunday in March) until they go back (last Sunday in October)', 'osam-bookings' ),
			'gmt'    => __( 'Winter time — from when the clocks go back (last Sunday in October) until they go forward (last Sunday in March)', 'osam-bookings' ),
			'yearly' => __( 'The same dates every year', 'osam-bookings' ),
			'dates'  => __( 'Specific dates (once)', 'osam-bookings' ),
		);
	}

	/**
	 * Seasonal hours set up for a resource.
	 *
	 * @param int $rid Resource.
	 * @return array rows (id, resource_id, name, mode, date_from, date_to, sort_order)
	 */
	public static function seasons( $rid ) {
		$key = 'seasons' . (int) $rid;
		if ( ! isset( self::$cache[ $key ] ) ) {
			global $wpdb;
			$t = OSB_Install::table( 'res_seasons' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$cache[ $key ] = self::schema_ready() ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE resource_id = %d ORDER BY sort_order ASC, id ASC", $rid ) ) : array();
		}
		return self::$cache[ $key ];
	}

	/**
	 * Tables from 2.14 exist (first request after an update)?
	 *
	 * @return bool
	 */
	public static function schema_ready() {
		return version_compare( (string) get_option( 'osb_db_version' ), '2.14.0', '>=' );
	}

	/**
	 * Last Sunday of a month.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return string Y-m-d
	 */
	public static function last_sunday( $year, $month ) {
		$last = strtotime( sprintf( '%04d-%02d-01 12:00:00', $year, $month ) . ' +1 month -1 day' );
		return gmdate( 'Y-m-d', $last - ( (int) gmdate( 'w', $last ) ) * DAY_IN_SECONDS );
	}

	/**
	 * Is a date inside a season?
	 *
	 * @param object $s    Season.
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public static function season_covers( $s, $date ) {
		$year = (int) substr( $date, 0, 4 );
		switch ( $s->mode ) {
			case 'bst':
				return $date >= self::last_sunday( $year, 3 ) && $date < self::last_sunday( $year, 10 );
			case 'gmt':
				return $date < self::last_sunday( $year, 3 ) || $date >= self::last_sunday( $year, 10 );
			case 'yearly':
				$a  = substr( (string) $s->date_from, 5, 5 );
				$b  = substr( (string) $s->date_to, 5, 5 );
				$md = substr( $date, 5, 5 );
				if ( ! $a || ! $b ) {
					return false;
				}
				// "1 Nov – 28 Feb" wraps over the new year.
				return $a <= $b ? ( $md >= $a && $md <= $b ) : ( $md >= $a || $md <= $b );
			default:
				return $s->date_from && $date >= $s->date_from && $date <= ( $s->date_to ? $s->date_to : $s->date_from );
		}
	}

	/**
	 * Seasonal hours in force on a date, or null for the standard hours. Specific dates beat yearly
	 * dates, which beat summer/winter time; among equals the one lower in the list wins.
	 *
	 * @param int    $rid  Resource.
	 * @param string $date Y-m-d.
	 * @return object|null
	 */
	public static function season_for( $rid, $date ) {
		$rank = array( 'dates' => 3, 'yearly' => 2, 'bst' => 1, 'gmt' => 1 );
		$best = null;
		foreach ( self::seasons( $rid ) as $s ) {
			if ( self::season_covers( $s, $date ) && ( ! $best || $rank[ $s->mode ] >= $rank[ $best->mode ] ) ) {
				$best = $s;
			}
		}
		return $best;
	}

	/**
	 * Short description of when a season applies, e.g. "Sun 29 Mar – Sat 24 Oct 2026".
	 *
	 * @param object $s Season.
	 * @return string
	 */
	public static function season_label( $s ) {
		$fmt  = static function ( $date, $f ) {
			return date_i18n( $f, strtotime( $date . ' 12:00:00' ) );
		};
		$today = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
		$year  = (int) substr( $today, 0, 4 );
		switch ( $s->mode ) {
			case 'bst':
				$from = self::last_sunday( $year, 3 );
				$to   = gmdate( 'Y-m-d', strtotime( self::last_sunday( $year, 10 ) . ' 12:00:00' ) - DAY_IN_SECONDS );
				if ( $to < $today ) {
					$from = self::last_sunday( $year + 1, 3 );
					$to   = gmdate( 'Y-m-d', strtotime( self::last_sunday( $year + 1, 10 ) . ' 12:00:00' ) - DAY_IN_SECONDS );
				}
				/* translators: 1: from 2: to */
				return sprintf( __( 'Clocks forward to clocks back · %1$s – %2$s', 'osam-bookings' ), $fmt( $from, 'D j M' ), $fmt( $to, 'D j M Y' ) );
			case 'gmt':
				$from = self::last_sunday( $year, 10 );
				$to   = gmdate( 'Y-m-d', strtotime( self::last_sunday( $year + 1, 3 ) . ' 12:00:00' ) - DAY_IN_SECONDS );
				if ( $today < self::last_sunday( $year, 3 ) ) {
					$from = self::last_sunday( $year - 1, 10 );
					$to   = gmdate( 'Y-m-d', strtotime( self::last_sunday( $year, 3 ) . ' 12:00:00' ) - DAY_IN_SECONDS );
				}
				/* translators: 1: from 2: to */
				return sprintf( __( 'Clocks back to clocks forward · %1$s – %2$s', 'osam-bookings' ), $fmt( $from, 'D j M Y' ), $fmt( $to, 'D j M Y' ) );
			case 'yearly':
				/* translators: 1: from 2: to */
				return sprintf( __( 'Every year · %1$s – %2$s', 'osam-bookings' ), $fmt( $s->date_from, 'j F' ), $fmt( $s->date_to, 'j F' ) );
			default:
				return $s->date_to && $s->date_to !== $s->date_from ? $fmt( $s->date_from, 'j M Y' ) . ' – ' . $fmt( $s->date_to, 'j M Y' ) : $fmt( $s->date_from, 'D j M Y' );
		}
	}

	/**
	 * Replace a resource's seasonal hours.
	 *
	 * @param int   $rid  Resource.
	 * @param array $list [ [ name, mode, date_from, date_to, schedule ] ] (already cleaned).
	 */
	public static function set_seasons( $rid, array $list ) {
		global $wpdb;
		$t = OSB_Install::table( 'res_seasons' );
		$h = OSB_Install::table( 'res_hours' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $h WHERE resource_id = %d AND season_id <> 0", $rid ) );
		$wpdb->delete( $t, array( 'resource_id' => (int) $rid ) );
		foreach ( array_values( $list ) as $i => $s ) {
			$wpdb->insert(
				$t,
				array(
					'resource_id' => (int) $rid,
					'name'        => substr( (string) $s['name'], 0, 100 ),
					'mode'        => $s['mode'],
					'date_from'   => $s['date_from'] ? $s['date_from'] : null,
					'date_to'     => $s['date_to'] ? $s['date_to'] : null,
					'sort_order'  => $i,
				)
			);
			if ( $wpdb->insert_id ) {
				self::set_hours( $rid, (array) $s['schedule'], (int) $wpdb->insert_id );
			}
		}
		self::flush();
	}

	/**
	 * Which hours apply from today: [ [ from date, season|null ] ] for each change in the next year.
	 *
	 * @param int $rid  Resource.
	 * @param int $days How far ahead.
	 * @return array
	 */
	public static function season_timeline( $rid, $days = 366 ) {
		$out  = array();
		$prev = false;
		$day  = strtotime( gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ) . ' 12:00:00' );
		for ( $i = 0; $i < $days; $i++ ) {
			$date = gmdate( 'Y-m-d', $day + $i * DAY_IN_SECONDS );
			$s    = self::season_for( $rid, $date );
			$id   = $s ? (int) $s->id : 0;
			if ( $id !== $prev ) {
				$out[] = array( $date, $s );
				$prev  = $id;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Maintenance
	 * ------------------------------------------------------------------ */

	/**
	 * Maintenance set up for a resource.
	 *
	 * @param int $rid Resource.
	 * @return array rows (id, resource_id, repeat_mode, date_from, date_to, weekdays, start_time, end_time, note)
	 */
	public static function maintenance( $rid ) {
		$key = 'maint' . (int) $rid;
		if ( ! isset( self::$cache[ $key ] ) ) {
			global $wpdb;
			$t = OSB_Install::table( 'res_maintenance' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$cache[ $key ] = self::schema_ready() ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE resource_id = %d ORDER BY date_from ASC, start_time ASC, id ASC", $rid ) ) : array();
		}
		return self::$cache[ $key ];
	}

	/**
	 * Replace a resource's maintenance times.
	 *
	 * @param int   $rid  Resource.
	 * @param array $rows Cleaned rows: repeat_mode, date_from, date_to, weekdays, start_time, end_time, note.
	 */
	public static function set_maintenance( $rid, array $rows ) {
		global $wpdb;
		$t = OSB_Install::table( 'res_maintenance' );
		$wpdb->delete( $t, array( 'resource_id' => (int) $rid ) );
		foreach ( $rows as $m ) {
			$wpdb->insert( $t, array_merge( $m, array( 'resource_id' => (int) $rid ) ) );
		}
		self::flush();
	}

	/**
	 * Clean posted maintenance rows.
	 *
	 * @param array $posted Rows: repeat, from, to, days[], start, end, note.
	 * @return array [ rows, errors ]
	 */
	public static function parse_maintenance( $posted ) {
		$out  = array();
		$errs = array();
		foreach ( (array) $posted as $p ) {
			$from = sanitize_text_field( $p['from'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
				continue;
			}
			$weekly = 'weekly' === ( $p['repeat'] ?? '' );
			$to     = sanitize_text_field( $p['to'] ?? '' );
			$to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $to >= $from ? $to : ( $weekly ? null : $from );
			$st     = substr( sanitize_text_field( $p['start'] ?? '' ), 0, 5 );
			$en     = substr( sanitize_text_field( $p['end'] ?? '' ), 0, 5 );
			$st     = preg_match( '/^\d{2}:\d{2}$/', $st ) ? $st : '';
			$en     = preg_match( '/^\d{2}:\d{2}$/', $en ) ? $en : '';
			$days   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $p['days'] ?? array() ) ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) ) );
			sort( $days );
			$note   = substr( sanitize_text_field( $p['note'] ?? '' ), 0, 191 );
			if ( $weekly && ! $days ) {
				$days = array( (int) gmdate( 'N', strtotime( $from . ' 12:00:00' ) ) );
			}
			// Same-day block (or each day of a weekly one) must end after it starts.
			if ( $st && $en && $en <= $st && ( $weekly || $to === $from ) ) {
				/* translators: %s date */
				$errs[] = sprintf( __( 'Maintenance on %s ends before it starts, so it wasn’t saved.', 'osam-bookings' ), date_i18n( 'j M Y', strtotime( $from . ' 12:00:00' ) ) );
				continue;
			}
			$out[] = array(
				'repeat_mode' => $weekly ? 'weekly' : 'once',
				'date_from'   => $from,
				'date_to'     => $to,
				'weekdays'    => $weekly ? implode( ',', $days ) : '',
				'start_time'  => $st ? $st . ':00' : null,
				'end_time'    => $en ? $en . ':00' : null,
				'note'        => $note,
			);
		}
		return array( $out, $errs );
	}

	/**
	 * Maintenance periods for a resource overlapping a span.
	 *
	 * @param int $rid  Resource.
	 * @param int $from Naive ts.
	 * @param int $to   Naive ts.
	 * @return array [ [ start, end, note ] ] naive
	 */
	public static function blocks( $rid, $from, $to ) {
		$out = array();
		foreach ( self::maintenance( $rid ) as $m ) {
			$st = $m->start_time ? substr( $m->start_time, 0, 5 ) : '00:00';
			$en = $m->end_time ? substr( $m->end_time, 0, 5 ) : '';
			if ( 'weekly' === $m->repeat_mode ) {
				$days = array_map( 'intval', explode( ',', (string) $m->weekdays ) );
				$d0   = max( $m->date_from, gmdate( 'Y-m-d', $from - DAY_IN_SECONDS ) );
				$d1   = $m->date_to ? min( $m->date_to, gmdate( 'Y-m-d', $to ) ) : gmdate( 'Y-m-d', $to );
				for ( $ts = strtotime( $d0 . ' 12:00:00' ); gmdate( 'Y-m-d', $ts ) <= $d1; $ts += DAY_IN_SECONDS ) {
					if ( ! in_array( (int) gmdate( 'N', $ts ), $days, true ) ) {
						continue;
					}
					$date = gmdate( 'Y-m-d', $ts );
					$s    = strtotime( $date . ' ' . $st . ':00' );
					$e    = $en ? strtotime( $date . ' ' . $en . ':00' ) : strtotime( $date . ' 00:00:00' ) + DAY_IN_SECONDS;
					if ( $s < $to && $e > $from ) {
						$out[] = array( $s, $e, (string) $m->note );
					}
				}
				continue;
			}
			$s = strtotime( $m->date_from . ' ' . $st . ':00' );
			$e = $en ? strtotime( ( $m->date_to ? $m->date_to : $m->date_from ) . ' ' . $en . ':00' ) : strtotime( ( $m->date_to ? $m->date_to : $m->date_from ) . ' 00:00:00' ) + DAY_IN_SECONDS;
			if ( $s < $to && $e > $from ) {
				$out[] = array( $s, $e, (string) $m->note );
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		return $out;
	}

	/**
	 * Bookings (still going ahead) that overlap a resource's maintenance, from now on.
	 *
	 * @param object $r Resource.
	 * @return array [ [ booking, block ] ]
	 */
	public static function maintenance_clashes( $r ) {
		$now  = strtotime( current_time( 'mysql' ) );
		$out  = array();
		$busy = self::busy( $r->id, $now, $now + 400 * DAY_IN_SECONDS );
		foreach ( $busy as $b ) {
			if ( $b[1] <= $now ) {
				continue;
			}
			foreach ( self::blocks( $r->id, $b[0], $b[1] ) as $blk ) {
				$out[] = array( $b, $blk );
				break;
			}
		}
		return $out;
	}

	/**
	 * Resources taken by a booking.
	 *
	 * @param int $booking_id Booking.
	 * @return array rows (resource_id, places, exclusive, hire, amount)
	 */
	public static function lines( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'booking_resources' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY resource_id ASC", $booking_id ) );
	}

	/**
	 * Clean up when a booking is deleted.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function on_booking_deleted( $booking_id ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'booking_resources' ), array( 'booking_id' => (int) $booking_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/**
	 * Can this resource take shared places / be booked out?
	 *
	 * @param object $r Resource.
	 * @return array [ shared bool, exclusive bool ]
	 */
	public static function sharing( $r ) {
		$shared = 'exclusive' !== $r->share_mode && (int) $r->capacity > 1;
		$excl   = 'shared' !== $r->share_mode || (int) $r->capacity <= 1;
		return array( $shared, $excl );
	}

	/**
	 * Start-time grid in minutes.
	 *
	 * @param object $r Resource.
	 * @return int
	 */
	public static function grid( $r ) {
		if ( 'slots' !== $r->time_mode ) {
			return max( 5, (int) $r->step );
		}
		if ( (int) $r->slot_step > 0 ) {
			return max( 5, (int) $r->slot_step );
		}
		$opts = self::slot_options( $r );
		return max( 5, $opts ? (int) $opts[0]['minutes'] : (int) $r->slot_length );
	}

	/**
	 * Fixed slot lengths with their prices, shortest first:
	 * [ [ minutes, place (per person), exclusive (book out) ] ].
	 * Resources saved before 2.9.1 (one length, "slots in a row") are read the old way.
	 *
	 * @param object $r Resource.
	 * @return array
	 */
	public static function slot_options( $r ) {
		$key = 'opts' . (int) $r->id . md5( (string) ( $r->slot_options ?? '' ) );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$out = array();
		$raw = isset( $r->slot_options ) ? json_decode( (string) $r->slot_options, true ) : null;
		if ( is_array( $raw ) ) {
			foreach ( $raw as $o ) {
				$m = isset( $o['minutes'] ) ? (int) $o['minutes'] : 0;
				if ( $m >= 5 ) {
					$out[ $m ] = array(
						'minutes'   => $m,
						'place'     => round( (float) ( $o['place'] ?? 0 ), 2 ),
						'exclusive' => round( (float) ( $o['exclusive'] ?? 0 ), 2 ),
					);
				}
			}
		}
		if ( ! $out ) {
			$len = max( 5, (int) $r->slot_length );
			for ( $k = 1; $k <= max( 1, (int) $r->max_slots ); $k++ ) {
				$out[ $len * $k ] = array(
					'minutes'   => $len * $k,
					'place'     => round( (float) $r->price_place * $k, 2 ),
					'exclusive' => round( (float) $r->price_exclusive * $k, 2 ),
				);
			}
		}
		ksort( $out );
		self::$cache[ $key ] = array_values( $out );
		return self::$cache[ $key ];
	}

	/**
	 * Lengths a customer can book, in minutes.
	 *
	 * @param object $r Resource.
	 * @return int[]
	 */
	public static function durations( $r ) {
		$out = array();
		if ( 'slots' === $r->time_mode ) {
			$out = wp_list_pluck( self::slot_options( $r ), 'minutes' );
		} else {
			$step = max( 5, (int) $r->step );
			$min  = max( $step, (int) $r->min_minutes );
			$max  = max( $min, (int) $r->max_minutes );
			for ( $m = $min; $m <= $max && count( $out ) < 96; $m += $step ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * Which kinds of hire a resource offers.
	 *
	 * @param object $r Resource.
	 * @return string[] time|half|day
	 */
	public static function hires( $r ) {
		$out = array();
		if ( $r->hourly ) {
			$out[] = 'time';
		}
		if ( $r->half_day ) {
			$out[] = 'half';
		}
		if ( $r->full_day ) {
			$out[] = 'day';
		}
		return $out ? $out : array( 'time' );
	}

	/**
	 * Booking window for a resource.
	 *
	 * @param object $r Resource.
	 * @return array [ earliest naive ts, last bookable date ]
	 */
	public static function window( $r ) {
		$now    = strtotime( current_time( 'mysql' ) );
		$notice = null === $r->min_notice_hours ? (float) OSB_Settings::get( 'min_notice_hours' ) : (float) $r->min_notice_hours;
		$days   = (int) $r->max_advance_days > 0 ? (int) $r->max_advance_days : (int) OSB_Settings::get( 'max_advance_days' );
		return array( $now + (int) round( $notice * HOUR_IN_SECONDS ), gmdate( 'Y-m-d', $now + max( 1, $days ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Price for one resource.
	 *
	 * @param object $r         Resource.
	 * @param string $hire      time|half|day.
	 * @param int    $minutes   Length (time).
	 * @param int    $days      Days (day).
	 * @param int    $places    Places.
	 * @param bool   $exclusive Booked out.
	 * @return float
	 */
	public static function price( $r, $hire, $minutes, $days, $places, $exclusive ) {
		$places = max( 1, (int) $places );
		if ( 'half' === $hire ) {
			$p = $exclusive ? (float) $r->price_half_exclusive : (float) $r->price_half_place * $places;
		} elseif ( 'day' === $hire ) {
			$p = ( $exclusive ? (float) $r->price_day_exclusive : (float) $r->price_day_place * $places ) * max( 1, (int) $days );
		} else {
			if ( 'slots' === $r->time_mode ) {
				// Each fixed length has its own price. A length that isn't one of them (staff
				// bookings) is priced pro rata from the shortest.
				$opts  = self::slot_options( $r );
				$match = null;
				foreach ( $opts as $o ) {
					if ( (int) $o['minutes'] === (int) $minutes ) {
						$match = $o;
					}
				}
				$base  = $match ? $match : $opts[0];
				$ratio = $match ? 1 : (int) $minutes / max( 5, (int) $base['minutes'] );
				$p     = ( $exclusive ? $base['exclusive'] : $base['place'] * $places ) * $ratio;
			} else {
				$p = ( $exclusive ? (float) $r->price_exclusive : (float) $r->price_place * $places ) * (int) $minutes / 60;
			}
		}
		return (float) apply_filters( 'osb_resource_price', round( $p, 2 ), $r, $hire, $minutes, $days, $places, $exclusive );
	}

	/* ---------------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------------ */

	/**
	 * Bookings holding a resource around a span (cached by resource and day span).
	 *
	 * @param int $rid     Resource.
	 * @param int $from    Naive ts.
	 * @param int $to      Naive ts.
	 * @param int $exclude Booking to leave out.
	 * @return array [ [ s, e, places, exclusive, id ] ] (buffers already added)
	 */
	public static function busy( $rid, $from, $to, $exclude = 0 ) {
		$a   = gmdate( 'Y-m-d', $from - DAY_IN_SECONDS );
		$b   = gmdate( 'Y-m-d', $to + DAY_IN_SECONDS );
		$key = 'busy' . $rid . '|' . $a . '|' . $b . '|' . $exclude;
		if ( ! isset( self::$cache[ $key ] ) ) {
			global $wpdb;
			$br       = OSB_Install::table( 'booking_resources' );
			$bk       = OSB_Install::table( 'bookings' );
			$statuses = OSB_DB::blocking_statuses();
			$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.start_at, b.end_at, r.places, r.exclusive FROM $br r JOIN $bk b ON b.id = r.booking_id WHERE r.resource_id = %d AND b.id <> %d AND b.start_at < %s AND b.end_at > %s AND b.status IN ($in)", array_merge( array( (int) $rid, (int) $exclude, $b . ' 23:59:59', $a . ' 00:00:00' ), $statuses ) ) );
			$res  = self::get( $rid );
			$bb   = $res ? (int) $res->buffer_before * 60 : 0;
			$ba   = $res ? (int) $res->buffer_after * 60 : 0;
			$out  = array();
			foreach ( $rows as $row ) {
				$out[] = array( strtotime( $row->start_at ) - $bb, strtotime( $row->end_at ) + $ba, (int) $row->places, (int) $row->exclusive, (int) $row->id );
			}
			self::$cache[ $key ] = $out;
		}
		return self::$cache[ $key ];
	}

	/**
	 * Most places in use at any moment during a span, and whether anything / anything booked out overlaps.
	 *
	 * @param array $busy  From busy().
	 * @param int   $start Naive.
	 * @param int   $end   Naive.
	 * @return array [ peak places, any overlap, booked out overlap ]
	 */
	private static function usage( array $busy, $start, $end ) {
		$events = array();
		$any    = false;
		$excl   = false;
		foreach ( $busy as $b ) {
			if ( $b[0] < $end && $b[1] > $start ) {
				$any = true;
				if ( $b[3] ) {
					$excl = true;
				}
				$events[] = array( max( $b[0], $start ), $b[2] );
				$events[] = array( min( $b[1], $end ), -$b[2] );
			}
		}
		usort(
			$events,
			static function ( $x, $y ) {
				return $x[0] <=> $y[0] ?: $x[1] <=> $y[1]; // Ends before starts at the same moment.
			}
		);
		$peak = 0;
		$now  = 0;
		foreach ( $events as $e ) {
			$now += $e[1];
			$peak = max( $peak, $now );
		}
		return array( $peak, $any, $excl );
	}

	/**
	 * Places still free for the whole of a span.
	 *
	 * @param object $r       Resource.
	 * @param int    $start   Naive.
	 * @param int    $end     Naive.
	 * @param int    $exclude Booking.
	 * @return int
	 */
	public static function places_left( $r, $start, $end, $exclude = 0 ) {
		$bb = (int) $r->buffer_before * 60;
		$ba = (int) $r->buffer_after * 60;
		if ( self::blocks( $r->id, $start - $bb, $end + $ba ) ) {
			return 0;
		}
		list( $peak, , $excl ) = self::usage( self::busy( $r->id, $start, $end, $exclude ), $start - $bb, $end + $ba );
		return $excl ? 0 : max( 0, (int) $r->capacity - $peak );
	}

	/**
	 * The span a hire covers for one resource.
	 *
	 * @param object $r    Resource.
	 * @param string $hire time|half|day.
	 * @param string $date Y-m-d (first day).
	 * @param array  $opt  time (H:i), minutes, half (am|pm), days.
	 * @return array|null [ start, end ] naive, or null when it isn't possible that day.
	 */
	public static function span( $r, $hire, $date, array $opt ) {
		if ( 'time' === $hire ) {
			$time = isset( $opt['time'] ) ? (string) $opt['time'] : '';
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				return null;
			}
			$s = strtotime( $date . ' ' . $time . ':00' );
			return array( $s, $s + max( 5, (int) ( $opt['minutes'] ?? 0 ) ) * 60 );
		}
		if ( 'half' === $hire ) {
			$ranges = self::ranges( $r, $date );
			if ( ! $ranges ) {
				return null;
			}
			$open  = $ranges[0][0];
			$close = end( $ranges )[1];
			$split = strtotime( $date . ' ' . substr( $r->half_split, 0, 5 ) . ':00' );
			if ( $split <= $open || $split >= $close ) {
				return null;
			}
			return 'pm' === ( $opt['half'] ?? 'am' ) ? array( $split, $close ) : array( $open, $split );
		}
		// Whole days: from opening on the first day to closing on the last; every day must be open.
		$days  = max( 1, min( max( 1, (int) $r->max_days ), (int) ( $opt['days'] ?? 1 ) ) );
		$first = null;
		$last  = null;
		for ( $i = 0; $i < $days; $i++ ) {
			$d      = gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00' ) + $i * DAY_IN_SECONDS );
			$ranges = self::ranges( $r, $d );
			if ( ! $ranges ) {
				return null;
			}
			if ( null === $first ) {
				$first = $ranges[0][0];
			}
			$last = end( $ranges )[1];
		}
		return array( $first, $last );
	}

	/**
	 * Can one resource take this booking?
	 *
	 * @param object $r         Resource.
	 * @param string $hire      time|half|day.
	 * @param int    $start     Naive.
	 * @param int    $end       Naive.
	 * @param int    $places    Places.
	 * @param bool   $exclusive Book it out.
	 * @param array  $args      online (window, hours, status checks), exclude.
	 * @return string '' when it can, else a reason code.
	 */
	public static function why_not( $r, $hire, $start, $end, $places, $exclusive, array $args = array() ) {
		$online  = ! isset( $args['online'] ) || $args['online'];
		$exclude = (int) ( $args['exclude'] ?? 0 );
		list( $can_share, $can_excl ) = self::sharing( $r );
		if ( $online ) {
			if ( 'active' !== $r->status ) {
				return 'hidden';
			}
			if ( ! in_array( $hire, self::hires( $r ), true ) ) {
				return 'hire';
			}
			list( $earliest, $last ) = self::window( $r );
			if ( $start < $earliest || gmdate( 'Y-m-d', $start ) > $last ) {
				return 'window';
			}
			if ( 'time' === $hire ) {
				$date = gmdate( 'Y-m-d', $start );
				$ok   = false;
				foreach ( self::ranges( $r, $date ) as $rg ) {
					if ( $start >= $rg[0] && $end <= $rg[1] ) {
						$ok = true;
					}
				}
				if ( ! $ok ) {
					return 'hours';
				}
			}
			if ( $exclusive && ! $can_excl ) {
				return 'no_exclusive';
			}
			if ( ! $exclusive && ! $can_share ) {
				return 'no_shared';
			}
			$max = (int) $r->max_per_booking > 0 ? min( (int) $r->max_per_booking, (int) $r->capacity ) : (int) $r->capacity;
			if ( ! $exclusive && $places > $max ) {
				return 'too_many';
			}
		}
		$bb = (int) $r->buffer_before * 60;
		$ba = (int) $r->buffer_after * 60;
		if ( self::blocks( $r->id, $start - $bb, $end + $ba ) ) {
			return 'maintenance';
		}
		list( $peak, $any, $excl ) = self::usage( self::busy( $r->id, $start, $end, $exclude ), $start - $bb, $end + $ba );
		if ( $exclusive ) {
			return $any ? 'taken' : '';
		}
		if ( $excl ) {
			return 'taken';
		}
		return $peak + max( 1, (int) $places ) > (int) $r->capacity ? 'full' : '';
	}

	/**
	 * Resources that can be booked together in one go (same kind of hire and time grid).
	 *
	 * @param array  $list Resources.
	 * @param string $hire time|half|day.
	 * @return bool
	 */
	public static function compatible( array $list, $hire ) {
		$key = null;
		foreach ( $list as $r ) {
			if ( ! in_array( $hire, self::hires( $r ), true ) ) {
				return false;
			}
			$k = 'time' === $hire ? $r->time_mode . '|' . self::grid( $r ) : $hire;
			if ( null !== $key && $k !== $key ) {
				return false;
			}
			$key = $k;
		}
		return true;
	}

	/**
	 * Lengths every chosen resource offers.
	 *
	 * @param array $list Resources.
	 * @return int[]
	 */
	public static function common_durations( array $list ) {
		$out = null;
		foreach ( $list as $r ) {
			$out = null === $out ? self::durations( $r ) : array_values( array_intersect( $out, self::durations( $r ) ) );
		}
		return $out ? $out : array();
	}

	/**
	 * Check a whole request (one or more resources).
	 *
	 * @param int[]  $ids       Resources.
	 * @param string $hire      time|half|day.
	 * @param string $date      Y-m-d.
	 * @param array  $opt       time, minutes, half, days.
	 * @param int    $places    Places.
	 * @param bool   $exclusive Book out.
	 * @param array  $args      online, exclude.
	 * @return array [ ok, reason, start, end, lines [ [ rid, places, exclusive, hire, amount ] ], amount ]
	 */
	public static function check( array $ids, $hire, $date, array $opt, $places, $exclusive, array $args = array() ) {
		$fail = static function ( $reason ) {
			return array(
				'ok'     => false,
				'reason' => $reason,
				'start'  => 0,
				'end'    => 0,
				'lines'  => array(),
				'amount' => 0,
			);
		};
		$list = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$r = self::get( $id );
			if ( ! $r ) {
				return $fail( 'unknown' );
			}
			$list[] = $r;
		}
		if ( ! $list ) {
			return $fail( 'none' );
		}
		$online = ! isset( $args['online'] ) || $args['online'];
		if ( $online && ( ! self::compatible( $list, $hire ) || ( count( $list ) > 1 && ! OSB_Settings::get( 'res_multi', 1 ) ) ) ) {
			return $fail( 'mix' );
		}
		if ( $online && 'time' === $hire && ! in_array( (int) ( $opt['minutes'] ?? 0 ), self::common_durations( $list ), true ) ) {
			return $fail( 'length' );
		}
		$lines = array();
		$start = PHP_INT_MAX;
		$end   = 0;
		$total = 0.0;
		foreach ( $list as $r ) {
			$span = self::span( $r, $hire, $date, $opt );
			if ( ! $span ) {
				return $fail( 'closed' );
			}
			if ( $online && 'time' === $hire && ( ( (int) gmdate( 'G', $span[0] ) * 60 + (int) gmdate( 'i', $span[0] ) ) - self::grid_origin( $r, $date, $span[0] ) ) % self::grid( $r ) ) {
				return $fail( 'grid' );
			}
			// A resource that can only be booked out is always booked out.
			$ex  = $exclusive || ! self::sharing( $r )[0];
			$why = self::why_not( $r, $hire, $span[0], $span[1], $places, $ex, $args );
			if ( '' !== $why ) {
				return $fail( $why );
			}
			$amount  = self::price( $r, $hire, (int) round( ( $span[1] - $span[0] ) / 60 ), (int) ( $opt['days'] ?? 1 ), $places, $ex );
			$lines[] = array( (int) $r->id, max( 1, (int) $places ), $ex ? 1 : 0, $hire, $amount );
			$start   = min( $start, $span[0] );
			$end     = max( $end, $span[1] );
			$total  += $amount;
		}
		return array(
			'ok'     => true,
			'reason' => '',
			'start'  => $start,
			'end'    => $end,
			'lines'  => $lines,
			'amount' => round( $total, 2 ),
		);
	}

	/**
	 * Minutes past midnight the start grid is counted from (the opening time of the range the
	 * start falls in), so a field opening at 08:30 with hourly slots offers 08:30, 09:30…
	 *
	 * @param object $r    Resource.
	 * @param string $date Y-m-d.
	 * @param int    $ts   Start.
	 * @return int
	 */
	private static function grid_origin( $r, $date, $ts ) {
		foreach ( self::ranges( $r, $date ) as $rg ) {
			if ( $ts >= $rg[0] && $ts < $rg[1] ) {
				return (int) gmdate( 'G', $rg[0] ) * 60 + (int) gmdate( 'i', $rg[0] );
			}
		}
		return 0;
	}

	/**
	 * What can be booked on a day.
	 *
	 * @param int[]  $ids        Resources.
	 * @param string $hire       time|half|day.
	 * @param string $date       Y-m-d.
	 * @param array  $opt        minutes, days.
	 * @param int    $places     Places.
	 * @param bool   $exclusive  Book out.
	 * @param bool   $first_only Stop at the first (month view).
	 * @return array time: [ [ time, label, end_label, left ] ]; half: [ [ half, label, … ] ]; day: [ [ days, … ] ]
	 */
	public static function day_options( array $ids, $hire, $date, array $opt, $places, $exclusive, $first_only = false ) {
		$list = array_filter( array_map( array( __CLASS__, 'get' ), $ids ) );
		if ( ! $list || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}
		$tf  = get_option( 'time_format' );
		$out = array();
		if ( 'time' === $hire ) {
			$first   = reset( $list );
			$minutes = (int) ( $opt['minutes'] ?? 0 );
			$grid    = self::grid( $first );
			$starts  = array();
			foreach ( self::ranges( $first, $date ) as $rg ) {
				for ( $t = $rg[0]; $t + $minutes * 60 <= $rg[1]; $t += $grid * 60 ) {
					$starts[] = $t;
				}
			}
			foreach ( $starts as $t ) {
				$time = gmdate( 'H:i', $t );
				$c    = self::check( $ids, 'time', $date, array( 'time' => $time, 'minutes' => $minutes ), $places, $exclusive );
				if ( ! $c['ok'] ) {
					continue;
				}
				$left = PHP_INT_MAX;
				foreach ( $list as $r ) {
					$left = min( $left, self::sharing( $r )[0] ? self::places_left( $r, $t, $t + $minutes * 60 ) : 0 );
				}
				$out[] = array(
					'time'      => $time,
					'label'     => date_i18n( $tf, $t ),
					'end_label' => date_i18n( $tf, $t + $minutes * 60 ),
					'left'      => $exclusive ? 0 : $left,
					'amount'    => $c['amount'],
				);
				if ( $first_only ) {
					break;
				}
			}
			return $out;
		}
		if ( 'half' === $hire ) {
			foreach ( array( 'am', 'pm' ) as $half ) {
				$c = self::check( $ids, 'half', $date, array( 'half' => $half ), $places, $exclusive );
				if ( $c['ok'] ) {
					$out[] = array(
						'half'      => $half,
						'label'     => 'am' === $half ? OSB_Settings::label( 'res_morning' ) : OSB_Settings::label( 'res_afternoon' ),
						'time'      => gmdate( 'H:i', $c['start'] ),
						'range'     => date_i18n( $tf, $c['start'] ) . ' – ' . date_i18n( $tf, $c['end'] ),
						'amount'    => $c['amount'],
					);
					if ( $first_only ) {
						break;
					}
				}
			}
			return $out;
		}
		$days = max( 1, (int) ( $opt['days'] ?? 1 ) );
		$c    = self::check( $ids, 'day', $date, array( 'days' => $days ), $places, $exclusive );
		if ( $c['ok'] ) {
			$out[] = array(
				'days'   => $days,
				'time'   => gmdate( 'H:i', $c['start'] ),
				'range'  => date_i18n( 'D j M ' . $tf, $c['start'] ) . ' – ' . date_i18n( ( $days > 1 ? 'D j M ' : '' ) . $tf, $c['end'] ),
				'amount' => $c['amount'],
			);
		}
		return $out;
	}

	/**
	 * Dates in a month with something bookable.
	 *
	 * @param int[]  $ids       Resources.
	 * @param string $hire      Hire.
	 * @param string $month     Y-m.
	 * @param array  $opt       minutes, days.
	 * @param int    $places    Places.
	 * @param bool   $exclusive Book out.
	 * @return array [ dates, min, max ]
	 */
	public static function month_dates( array $ids, $hire, $month, array $opt, $places, $exclusive ) {
		$list = array_filter( array_map( array( __CLASS__, 'get' ), $ids ) );
		$min  = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
		$max  = $min;
		foreach ( $list as $r ) {
			$w   = self::window( $r );
			$min = max( $min, gmdate( 'Y-m-d', $w[0] ) );
			$max = max( $max, $w[1] );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = substr( $min, 0, 7 );
		}
		$dates = array();
		$n     = (int) gmdate( 't', strtotime( $month . '-01' ) );
		for ( $d = 1; $d <= $n; $d++ ) {
			$date = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			if ( $date < $min || $date > $max ) {
				continue;
			}
			if ( self::day_options( $ids, $hire, $date, $opt, $places, $exclusive, true ) ) {
				$dates[] = $date;
			}
		}
		return array( $dates, $min, $max );
	}

	/* ---------------------------------------------------------------------
	 * Booking lifecycle (called from OSB_Bookings::create inside the booking lock)
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a resource booking and fill in its row.
	 *
	 * @param array $data  Request: resource_ids, hire, date, time, minutes, half, days, exclusive, admin, end_time.
	 * @param int   $seats Places.
	 * @return array|WP_Error [ row, item, lines ]
	 */
	public static function booking_row( array $data, $seats ) {
		$admin = ! empty( $data['admin'] );
		if ( ! $admin && ! self::enabled() ) {
			return new WP_Error( 'osb_disabled', __( 'Online booking for this is not available.', 'osam-bookings' ) );
		}
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['resource_ids'] ?? array() ) ) ) ) );
		$hire = in_array( $data['hire'] ?? '', array( 'time', 'half', 'day' ), true ) ? $data['hire'] : 'time';
		$date = (string) ( $data['date'] ?? '' );
		if ( ! $ids ) {
			return new WP_Error( 'osb_resource', __( 'Please choose what you’d like to book.', 'osam-bookings' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'osb_datetime', __( 'Please choose a date.', 'osam-bookings' ) );
		}
		$exclusive = ! empty( $data['exclusive'] );
		$opt       = array(
			'time'    => (string) ( $data['time'] ?? '' ),
			'minutes' => (int) ( $data['minutes'] ?? 0 ),
			'half'    => 'pm' === ( $data['half'] ?? '' ) ? 'pm' : 'am',
			'days'    => max( 1, (int) ( $data['days'] ?? 1 ) ),
		);
		if ( $admin && 'time' === $hire && ! empty( $data['end_time'] ) && preg_match( '/^\d{2}:\d{2}$/', $data['end_time'] ) && preg_match( '/^\d{2}:\d{2}$/', $opt['time'] ) ) {
			$opt['minutes'] = max( 5, (int) ( ( strtotime( '2000-01-01 ' . $data['end_time'] ) - strtotime( '2000-01-01 ' . $opt['time'] ) ) / 60 ) );
		}
		$c = self::check( $ids, $hire, $date, $opt, $seats, $exclusive, array( 'online' => ! $admin ) );
		if ( ! $c['ok'] ) {
			if ( $admin && ! in_array( $c['reason'], array( 'unknown', 'none', 'closed' ), true ) ) {
				// Staff can override clashes; work the booking out without the checks.
				$c = self::forced( $ids, $hire, $date, $opt, $seats, $exclusive );
			}
			if ( ! $c['ok'] ) {
				return new WP_Error( 'osb_unavailable', self::reason_text( $c['reason'] ) );
			}
		}
		$names = array();
		$item  = null;
		foreach ( $c['lines'] as $l ) {
			$r       = self::get( $l[0] );
			$names[] = $r->name;
			$item    = $item ? $item : $r;
		}
		return array(
			array(
				'staff_id' => 0,
				'start_at' => gmdate( 'Y-m-d H:i:s', $c['start'] ),
				'end_at'   => gmdate( 'Y-m-d H:i:s', $c['end'] ),
				'amount'   => $c['amount'],
			),
			$item,
			$c['lines'],
		);
	}

	/**
	 * Admin override: price and span without availability checks.
	 *
	 * @param int[]  $ids       Resources.
	 * @param string $hire      Hire.
	 * @param string $date      Date.
	 * @param array  $opt       Options.
	 * @param int    $places    Places.
	 * @param bool   $exclusive Book out.
	 * @return array Like check().
	 */
	private static function forced( array $ids, $hire, $date, array $opt, $places, $exclusive ) {
		$lines = array();
		$start = PHP_INT_MAX;
		$end   = 0;
		$total = 0.0;
		foreach ( $ids as $id ) {
			$r    = self::get( $id );
			$span = $r ? self::span( $r, $hire, $date, $opt ) : null;
			if ( ! $span ) {
				return array( 'ok' => false, 'reason' => 'closed' );
			}
			$ex      = $exclusive || ! self::sharing( $r )[0];
			$amount  = self::price( $r, $hire, (int) round( ( $span[1] - $span[0] ) / 60 ), $opt['days'], $places, $ex );
			$lines[] = array( (int) $r->id, max( 1, (int) $places ), $ex ? 1 : 0, $hire, $amount );
			$start   = min( $start, $span[0] );
			$end     = max( $end, $span[1] );
			$total  += $amount;
		}
		return array( 'ok' => true, 'reason' => '', 'start' => $start, 'end' => $end, 'lines' => $lines, 'amount' => round( $total, 2 ) );
	}

	/**
	 * Words for a reason code.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	public static function reason_text( $reason ) {
		$map = array(
			'taken'        => __( 'Sorry, that time has just been taken. Please choose another.', 'osam-bookings' ),
			'full'         => __( 'Sorry, there aren’t enough places left at that time.', 'osam-bookings' ),
			'too_many'     => __( 'Too many places requested.', 'osam-bookings' ),
			'no_exclusive' => __( 'This can’t be booked out on its own.', 'osam-bookings' ),
			'no_shared'    => __( 'This can only be booked out as a whole.', 'osam-bookings' ),
			'mix'          => __( 'Those can’t be booked together — please book them separately.', 'osam-bookings' ),
			'closed'       => __( 'Sorry, it’s closed then. Please choose another time.', 'osam-bookings' ),
			'maintenance'  => __( 'Sorry, that time is set aside for maintenance. Please choose another.', 'osam-bookings' ),
			'hours'        => __( 'That time is outside the opening hours.', 'osam-bookings' ),
			'window'       => __( 'That time can’t be booked online. Please choose another.', 'osam-bookings' ),
			'length'       => __( 'Please choose how long you’d like it for.', 'osam-bookings' ),
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : __( 'Sorry, that can’t be booked. Please choose another time.', 'osam-bookings' );
	}

	/**
	 * Save the resources a booking takes (inside the lock).
	 *
	 * @param int   $booking_id Booking.
	 * @param array $lines      [ [ rid, places, exclusive, hire, amount ] ].
	 */
	public static function attach( $booking_id, array $lines ) {
		global $wpdb;
		$t   = OSB_Install::table( 'booking_resources' );
		$old = array();
		foreach ( self::lines( $booking_id ) as $o ) {
			$old[ (int) $o->resource_id ] = $o;
		}
		$wpdb->delete( $t, array( 'booking_id' => (int) $booking_id ) );
		foreach ( $lines as $l ) {
			$prev = isset( $old[ (int) $l[0] ] ) ? $old[ (int) $l[0] ] : null;
			$wpdb->insert(
				$t,
				array(
					'booking_id'  => (int) $booking_id,
					'resource_id' => (int) $l[0],
					'places'      => (int) $l[1],
					'exclusive'   => (int) $l[2],
					'hire'        => (string) $l[3],
					'amount'      => (float) $l[4],
					'access_code' => OSB_Access::code_for_line( self::get( (int) $l[0] ), $prev ? $prev->access_code : '' ),
					'access_sent' => $prev ? (int) $prev->access_sent : 0,
				)
			);
		}
		self::flush();
	}

	/**
	 * Admin warnings for a resource booking.
	 *
	 * @param object $booking Booking.
	 * @return string[]
	 */
	public static function conflicts( $booking ) {
		$out   = array();
		$start = strtotime( $booking->start_at );
		$end   = strtotime( $booking->end_at );
		foreach ( self::lines( $booking->id ) as $l ) {
			$r = self::get( $l->resource_id );
			if ( ! $r ) {
				continue;
			}
			$why = self::why_not( $r, $l->hire, $start, $end, (int) $l->places, (bool) $l->exclusive, array( 'online' => false, 'exclude' => $booking->id ) );
			if ( 'taken' === $why ) {
				/* translators: %s resource */
				$out[] = sprintf( __( '%s is already booked at this time.', 'osam-bookings' ), $r->name );
			} elseif ( 'full' === $why ) {
				/* translators: %s resource */
				$out[] = sprintf( __( '%s is over capacity at this time.', 'osam-bookings' ), $r->name );
			} elseif ( 'maintenance' === $why ) {
				$blk = self::blocks( $r->id, $start - (int) $r->buffer_before * 60, $end + (int) $r->buffer_after * 60 );
				/* translators: 1: resource 2: maintenance note */
				$out[] = $blk && '' !== $blk[0][2] ? sprintf( __( '%1$s is set aside for maintenance at this time (%2$s).', 'osam-bookings' ), $r->name, $blk[0][2] ) : sprintf( __( '%s is set aside for maintenance at this time.', 'osam-bookings' ), $r->name );
			}
		}
		return $out;
	}

	/**
	 * "Morning", "2 days", "1h 30m" — how a line is hired.
	 *
	 * @param object $booking Booking.
	 * @param string $hire    Hire.
	 * @return string
	 */
	public static function hire_label( $booking, $hire ) {
		$start = strtotime( $booking->start_at );
		$end   = strtotime( $booking->end_at );
		if ( 'half' === $hire ) {
			return (int) gmdate( 'G', $start ) < 12 ? OSB_Settings::label( 'res_morning' ) : OSB_Settings::label( 'res_afternoon' );
		}
		if ( 'day' === $hire ) {
			$days = 1 + (int) round( ( strtotime( gmdate( 'Y-m-d', $end ) ) - strtotime( gmdate( 'Y-m-d', $start ) ) ) / DAY_IN_SECONDS );
			/* translators: %d days */
			return sprintf( _n( '%d day', '%d days', $days, 'osam-bookings' ), $days );
		}
		$m = (int) round( ( $end - $start ) / 60 );
		return $m >= 60 ? floor( $m / 60 ) . 'h' . ( $m % 60 ? ' ' . ( $m % 60 ) . 'm' : '' ) : $m . 'm';
	}

	/**
	 * Booking details for resource bookings.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		if ( 'resource' !== $booking->type ) {
			return $d;
		}
		$lines = self::lines( $booking->id );
		$names = array();
		$loc   = '';
		$excl  = false;
		$hire  = 'time';
		foreach ( $lines as $l ) {
			$r       = self::get( $l->resource_id );
			$names[] = $r ? $r->name : __( '(deleted)', 'osam-bookings' );
			$loc     = $loc ? $loc : ( $r ? $r->location : '' );
			$excl    = $excl || (int) $l->exclusive;
			$hire    = $l->hire;
		}
		$d['item_name']    = implode( ' + ', $names );
		$d['resources']    = $names;
		$d['location']     = $loc;
		$d['exclusive']    = $excl;
		$d['hire']         = $hire;
		$d['hire_label']   = self::hire_label( $booking, $hire );
		$d['attendees']    = array();
		if ( 'day' === $hire ) {
			$d['end_time'] = date_i18n( 'D j M, ' . get_option( 'time_format' ), strtotime( $booking->end_at ) );
		}
		return $d;
	}

	/**
	 * {resources}, {booked_out} and {hire} in emails.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$is = 'resource' === $booking->type;
		$d  = $is ? self::details( array(), $booking ) : array();
		$map['{resources}']  = $is ? esc_html( implode( ', ', $d['resources'] ) ) : '';
		$map['{booked_out}'] = $is && $d['exclusive'] ? esc_html__( 'Booked out (sole use)', 'osam-bookings' ) : '';
		$map['{hire}']       = $is ? esc_html( $d['hire_label'] ) : '';
		return $map;
	}

	/**
	 * A resource shaped for the booking form.
	 *
	 * @param object $r Resource.
	 * @return array
	 */
	public static function json( $r ) {
		list( $shared, $excl ) = self::sharing( $r );
		$methods = array();
		foreach ( OSB_Bookings::allowed_methods( $r ) as $id ) {
			$g = osb()->payments->get( $id );
			if ( $g ) {
				$methods[] = array(
					'id'          => $id,
					'title'       => $g->title(),
					'description' => $g->description(),
					'online'      => 'pending_payment' === $g->initial_status(),
				);
			}
		}
		return array(
			'id'          => (int) $r->id,
			'name'        => $r->name,
			'description' => wp_kses_post( wpautop( (string) $r->description ) ),
			'category'    => $r->category,
			'location'    => $r->location,
			'image'       => $r->image_id ? wp_get_attachment_image_url( $r->image_id, 'medium' ) : '',
			'color'       => $r->color,
			'capacity'    => (int) $r->capacity,
			'max'         => (int) $r->max_per_booking > 0 ? min( (int) $r->max_per_booking, (int) $r->capacity ) : (int) $r->capacity,
			'shared'      => $shared,
			'exclusive'   => $excl,
			'mode'        => $r->time_mode,
			'grid'        => self::grid( $r ),
			'durations'   => self::durations( $r ),
			'slot_options' => 'slots' === $r->time_mode ? self::slot_options( $r ) : array(),
			'hires'       => self::hires( $r ),
			'max_days'    => max( 1, (int) $r->max_days ),
			'prices'      => array(
				'place'          => (float) $r->price_place,
				'exclusive'      => (float) $r->price_exclusive,
				'half_place'     => (float) $r->price_half_place,
				'half_exclusive' => (float) $r->price_half_exclusive,
				'day_place'      => (float) $r->price_day_place,
				'day_exclusive'  => (float) $r->price_day_exclusive,
			),
			'price_label' => self::price_label( $r ),
			'methods'     => $methods,
		);
	}

	/**
	 * "From £12 / hour".
	 *
	 * @param object $r Resource.
	 * @return string
	 */
	public static function price_label( $r ) {
		list( $shared, $excl ) = self::sharing( $r );
		$unit = __( 'hour', 'osam-bookings' );
		$cands = array();
		if ( $r->hourly && 'slots' === $r->time_mode ) {
			// Cheapest slot: "From £8 per person / 30 min".
			foreach ( self::slot_options( $r ) as $o ) {
				$len = OSB_Admin_Resources_Helper::duration( (int) $o['minutes'] );
				if ( $shared && $o['place'] > 0 ) {
					/* translators: 1: price 2: length */
					$cands[] = array( $o['place'], sprintf( __( '%1$s per person / %2$s', 'osam-bookings' ), OSB_Settings::money( $o['place'] ), $len ) );
				}
				if ( $excl && $o['exclusive'] > 0 ) {
					/* translators: 1: price 2: length */
					$cands[] = array( $o['exclusive'], sprintf( __( '%1$s / %2$s', 'osam-bookings' ), OSB_Settings::money( $o['exclusive'] ), $len ) );
				}
			}
		} elseif ( $r->hourly ) {
			if ( $shared && (float) $r->price_place > 0 ) {
				/* translators: 1: price 2: unit */
				$cands[] = array( (float) $r->price_place, sprintf( __( '%1$s per person / %2$s', 'osam-bookings' ), OSB_Settings::money( $r->price_place ), $unit ) );
			}
			if ( $excl && (float) $r->price_exclusive > 0 ) {
				/* translators: 1: price 2: unit */
				$cands[] = array( (float) $r->price_exclusive, sprintf( __( '%1$s / %2$s', 'osam-bookings' ), OSB_Settings::money( $r->price_exclusive ), $unit ) );
			}
		}
		if ( $r->full_day && $excl && (float) $r->price_day_exclusive > 0 ) {
			/* translators: %s price */
			$cands[] = array( (float) $r->price_day_exclusive, sprintf( __( '%s / day', 'osam-bookings' ), OSB_Settings::money( $r->price_day_exclusive ) ) );
		}
		usort(
			$cands,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		if ( ! $cands ) {
			$any = (float) $r->price_place + (float) $r->price_exclusive + (float) $r->price_half_place + (float) $r->price_half_exclusive + (float) $r->price_day_place + (float) $r->price_day_exclusive;
			return $any > 0 ? '' : OSB_Settings::label( 'free' );
		}
		/* translators: %s price */
		return sprintf( __( 'From %s', 'osam-bookings' ), $cands[0][1] );
	}
}

/**
 * Small formatting helper shared by the booking form and admin.
 */
class OSB_Admin_Resources_Helper {

	/**
	 * "30 min", "1 hour", "1½ hours".
	 *
	 * @param int $m Minutes.
	 * @return string
	 */
	public static function duration( $m ) {
		if ( $m < 60 ) {
			/* translators: %d minutes */
			return sprintf( __( '%d min', 'osam-bookings' ), $m );
		}
		if ( 0 === $m % 60 ) {
			/* translators: %d hours */
			return sprintf( _n( '%d hour', '%d hours', $m / 60, 'osam-bookings' ), $m / 60 );
		}
		/* translators: 1: hours 2: minutes */
		return sprintf( __( '%1$dh %2$dm', 'osam-bookings' ), floor( $m / 60 ), $m % 60 );
	}
}
