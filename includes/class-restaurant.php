<?php
/**
 * Restaurant table booking.
 *
 * Structure: dining areas → tables (each seats min–max guests) and table combinations
 * (two or more tables pushed together). Service periods (lunch, dinner…) say which days and
 * times can be booked. A table booking is one row in the bookings table (type "table",
 * seats = number of guests) and sits at one or more tables (booking_tables).
 *
 * Tables are either picked automatically (best fit: the smallest table or combination that
 * seats the party) or left for staff to assign from the service timeline. Either way the
 * system only offers a time online when every overlapping booking can still be seated.
 *
 * All times are naive local, like the rest of the plugin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restaurant data, availability and allocation.
 */
class OSB_Restaurant {

	/**
	 * Per-request cache of a day's table bookings.
	 *
	 * @var array
	 */
	private static $day_cache = array();

	/**
	 * Per-request cache of rows.
	 *
	 * @var array
	 */
	private static $rows = array();

	/**
	 * Per-request cache of closures by date.
	 *
	 * @var array
	 */
	private static $closed = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_statuses', array( __CLASS__, 'statuses' ) );
		add_filter( 'osb_blocking_statuses', array( __CLASS__, 'blocking' ) );
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 10, 2 );
		add_filter( 'osb_paid_status', array( __CLASS__, 'paid_status' ), 10, 2 );
		add_action( 'osb_booking_deleted', array( __CLASS__, 'on_booking_deleted' ) );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 10, 2 );
		add_action( OSB_Cron::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'rest_api_init', array( 'OSB_Restaurant_Rest', 'routes' ) );
	}

	/**
	 * Is the module switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'restaurant' );
	}

	/* ---------------------------------------------------------------------
	 * Statuses
	 * ------------------------------------------------------------------ */

	/**
	 * Add "Seated" after "Confirmed".
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'confirmed' === $k ) {
				$out['seated'] = __( 'Seated', 'osam-bookings' );
			}
		}
		if ( ! isset( $out['seated'] ) ) {
			$out['seated'] = __( 'Seated', 'osam-bookings' );
		}
		return $out;
	}

	/**
	 * Seated parties hold their table.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function blocking( $statuses ) {
		if ( ! in_array( 'seated', $statuses, true ) ) {
			$statuses[] = 'seated';
		}
		return $statuses;
	}

	/**
	 * Online-paid table bookings follow the approval setting.
	 *
	 * @param string $status  Target status.
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function paid_status( $status, $booking ) {
		if ( 'table' === $booking->type && 'approval' === OSB_Settings::get( 'rest_confirm' ) ) {
			return 'pending';
		}
		return $status;
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * All rows of a restaurant table (cached per request).
	 *
	 * @param string $table rest_areas|rest_tables|rest_combos|rest_periods.
	 * @return array
	 */
	private static function rows( $table ) {
		if ( ! isset( self::$rows[ $table ] ) ) {
			global $wpdb;
			$t     = OSB_Install::table( $table );
			$order = 'rest_periods' === $table ? 'sort_order ASC, start_time ASC, id ASC' : ( 'rest_combos' === $table ? 'name ASC, id ASC' : 'sort_order ASC, id ASC' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$rows[ $table ] = (array) $wpdb->get_results( "SELECT * FROM $t ORDER BY $order" );
		}
		return self::$rows[ $table ];
	}

	/**
	 * Clear request caches (after writes).
	 */
	public static function flush() {
		self::$rows      = array();
		self::$day_cache = array();
		self::$closed    = array();
	}

	/**
	 * Dining areas.
	 *
	 * @param bool $active_only Active only.
	 * @return array
	 */
	public static function areas( $active_only = false ) {
		return array_values(
			array_filter(
				self::rows( 'rest_areas' ),
				static function ( $a ) use ( $active_only ) {
					return ! $active_only || 'active' === $a->status;
				}
			)
		);
	}

	/**
	 * Tables, optionally in one area.
	 *
	 * @param int|null $area_id     Area (null = all).
	 * @param bool     $active_only Active only (in active areas).
	 * @return array
	 */
	public static function tables( $area_id = null, $active_only = false ) {
		$areas = array();
		foreach ( self::areas() as $a ) {
			$areas[ (int) $a->id ] = $a;
		}
		$list = array_filter(
			self::rows( 'rest_tables' ),
			static function ( $t ) use ( $area_id, $active_only, $areas ) {
				if ( null !== $area_id && (int) $t->area_id !== (int) $area_id ) {
					return false;
				}
				if ( $active_only && ( 'active' !== $t->status || ! isset( $areas[ (int) $t->area_id ] ) || 'active' !== $areas[ (int) $t->area_id ]->status ) ) {
					return false;
				}
				return true;
			}
		);
		$list = array_values( $list );
		usort(
			$list,
			static function ( $a, $b ) use ( $areas ) {
				$ao = isset( $areas[ (int) $a->area_id ] ) ? (int) $areas[ (int) $a->area_id ]->sort_order : 0;
				$bo = isset( $areas[ (int) $b->area_id ] ) ? (int) $areas[ (int) $b->area_id ]->sort_order : 0;
				return array( $ao, (int) $a->area_id, (int) $a->sort_order, strnatcasecmp( $a->name, $b->name ) ) <=> array( $bo, (int) $b->area_id, (int) $b->sort_order, 0 );
			}
		);
		return $list;
	}

	/**
	 * Tables keyed by id.
	 *
	 * @return array
	 */
	public static function table_map() {
		$out = array();
		foreach ( self::tables() as $t ) {
			$out[ (int) $t->id ] = $t;
		}
		return $out;
	}

	/**
	 * Areas keyed by id.
	 *
	 * @return array
	 */
	public static function area_map() {
		$out = array();
		foreach ( self::areas() as $a ) {
			$out[ (int) $a->id ] = $a;
		}
		return $out;
	}

	/**
	 * Table combinations.
	 *
	 * @param bool $active_only Active only.
	 * @return array Each with ->ids (int[]).
	 */
	public static function combos( $active_only = false ) {
		$out = array();
		foreach ( self::rows( 'rest_combos' ) as $c ) {
			if ( $active_only && 'active' !== $c->status ) {
				continue;
			}
			$c->ids = self::ids( $c->table_ids );
			$out[]  = $c;
		}
		return $out;
	}

	/**
	 * Service periods.
	 *
	 * @param bool $active_only Active only.
	 * @return array
	 */
	public static function periods( $active_only = false ) {
		return array_values(
			array_filter(
				self::rows( 'rest_periods' ),
				static function ( $p ) use ( $active_only ) {
					return ! $active_only || 'active' === $p->status;
				}
			)
		);
	}

	/**
	 * Closures from today on (plus recent ones for the admin list).
	 *
	 * @return array
	 */
	public static function closures() {
		global $wpdb;
		$t = OSB_Install::table( 'rest_closures' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE date_to >= %s ORDER BY date_from ASC", gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - 30 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Comma list → unique positive ints.
	 *
	 * @param string|array $list List.
	 * @return int[]
	 */
	public static function ids( $list ) {
		$list = is_array( $list ) ? $list : explode( ',', (string) $list );
		return array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) );
	}

	/**
	 * Tables a booking sits at.
	 *
	 * @param int $booking_id Booking.
	 * @return int[]
	 */
	public static function booking_table_ids( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'booking_tables' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT table_id FROM $t WHERE booking_id = %d ORDER BY table_id ASC", $booking_id ) ) );
	}

	/**
	 * Tables for many bookings at once.
	 *
	 * @param int[] $ids Booking ids.
	 * @return array booking_id => int[]
	 */
	public static function tables_for_bookings( array $ids ) {
		$ids = self::ids( $ids );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		global $wpdb;
		$t = OSB_Install::table( 'booking_tables' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( 'SELECT booking_id, table_id FROM ' . $t . ' WHERE booking_id IN (' . implode( ',', $ids ) . ')' ) as $r ) {
			$out[ (int) $r->booking_id ][] = (int) $r->table_id;
		}
		return $out;
	}

	/**
	 * Seat a booking at tables (replaces any previous assignment).
	 *
	 * @param int   $booking_id Booking.
	 * @param int[] $table_ids  Tables (empty = unassigned).
	 */
	public static function set_booking_tables( $booking_id, array $table_ids ) {
		global $wpdb;
		$t = OSB_Install::table( 'booking_tables' );
		$wpdb->delete( $t, array( 'booking_id' => (int) $booking_id ) );
		foreach ( self::ids( $table_ids ) as $tid ) {
			$wpdb->insert(
				$t,
				array(
					'booking_id' => (int) $booking_id,
					'table_id'   => $tid,
				)
			);
		}
		self::$day_cache = array();
	}

	/**
	 * Clean up when a booking is deleted.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function on_booking_deleted( $booking_id ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'booking_tables' ), array( 'booking_id' => (int) $booking_id ) );
	}

	/**
	 * "T4 + T5" for a list of tables.
	 *
	 * @param int[] $ids Tables.
	 * @return string
	 */
	public static function tables_label( array $ids ) {
		$map   = self::table_map();
		$names = array();
		foreach ( $ids as $id ) {
			$names[] = isset( $map[ (int) $id ] ) ? $map[ (int) $id ]->name : '#' . (int) $id;
		}
		return implode( ' + ', $names );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/**
	 * How long a party keeps its table.
	 *
	 * @param int $covers Guests.
	 * @return int Minutes.
	 */
	public static function turn_minutes( $covers ) {
		$bands = (array) OSB_Settings::get( 'rest_turn_times', array() );
		usort(
			$bands,
			static function ( $a, $b ) {
				return (int) $a[0] <=> (int) $b[0];
			}
		);
		$minutes = 120;
		foreach ( $bands as $band ) {
			if ( (int) $covers <= (int) $band[0] ) {
				$minutes = (int) $band[1];
				break;
			}
			$minutes = (int) $band[1];
		}
		return (int) apply_filters( 'osb_table_turn_minutes', max( 15, $minutes ), $covers );
	}

	/**
	 * Party size limits for online booking.
	 *
	 * @return array [ min, max online, max by request (0 = no requests) ]
	 */
	public static function party_limits() {
		$min     = max( 1, (int) OSB_Settings::get( 'rest_min_party' ) );
		$max     = max( $min, (int) OSB_Settings::get( 'rest_max_party_online' ) );
		$request = 'request' === OSB_Settings::get( 'rest_large_party' ) ? max( $max, (int) OSB_Settings::get( 'rest_max_party_request' ) ) : 0;
		return array( $min, $max, $request > $max ? $request : 0 );
	}

	/**
	 * Gateways offered for table deposits (online ones only — a deposit paid on the night is no deposit).
	 *
	 * @return string[]
	 */
	public static function deposit_methods() {
		$limit = array_filter( array_map( 'trim', explode( ',', (string) OSB_Settings::get( 'rest_payment_methods' ) ) ) );
		$out   = array();
		foreach ( osb()->payments->enabled() as $id => $g ) {
			if ( 'pending_payment' !== $g->initial_status() ) {
				continue;
			}
			if ( $limit && ! in_array( $id, $limit, true ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * Can card holds actually be taken? (Needs Stripe switched on and set up.)
	 *
	 * @return bool
	 */
	public static function card_hold_ready() {
		$stripe = osb()->payments->get( 'stripe' );
		return $stripe && $stripe->is_enabled();
	}

	/**
	 * Which payment applies to a party.
	 *
	 * @param int $covers Guests.
	 * @return array [ mode none|deposit|card_hold, amount (charged now), fee (no-show fee for holds) ]
	 */
	public static function payment_rule( $covers ) {
		$mode = (string) OSB_Settings::get( 'rest_payment_mode' );
		$rule = array(
			'mode'   => 'none',
			'amount' => 0.0,
			'fee'    => 0.0,
		);
		if ( (int) $covers < max( 1, (int) OSB_Settings::get( 'rest_payment_min_party' ) ) ) {
			return $rule;
		}
		if ( 'deposit' === $mode && self::deposit_methods() ) {
			$each           = (float) OSB_Settings::get( 'rest_deposit_amount' );
			$rule['mode']   = 'deposit';
			$rule['amount'] = round( 'per_booking' === OSB_Settings::get( 'rest_deposit_type' ) ? $each : $each * (int) $covers, 2 );
		} elseif ( 'card_hold' === $mode && self::card_hold_ready() ) {
			$rule['mode'] = 'card_hold';
			$rule['fee']  = round( (float) OSB_Settings::get( 'rest_noshow_fee' ) * (int) $covers, 2 );
		}
		if ( $rule['amount'] <= 0 && 'deposit' === $rule['mode'] ) {
			$rule['mode'] = 'none';
		}
		if ( $rule['fee'] <= 0 && 'card_hold' === $rule['mode'] ) {
			$rule['mode'] = 'none';
		}
		return apply_filters( 'osb_table_payment_rule', $rule, $covers );
	}

	/**
	 * Is this booking waiting for a card to be saved (rather than a payment)?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function needs_card_hold( $booking ) {
		return 'table' === $booking->type && 'pending_payment' === $booking->status && (float) $booking->amount <= 0 && 'stripe' === $booking->payment_method;
	}

	/**
	 * The saved-card record for a booking, if any.
	 *
	 * @param int $booking_id Booking.
	 * @return object|null Payment row with ->data decoded (customer, payment_method).
	 */
	public static function held_card( $booking_id ) {
		foreach ( OSB_DB::payments( $booking_id ) as $p ) {
			if ( 'card_held' === $p->status ) {
				$p->info = json_decode( (string) $p->data, true );
				$p->info = is_array( $p->info ) ? $p->info : array();
				return $p;
			}
		}
		return null;
	}

	/**
	 * Charge the no-show fee to the card saved at booking.
	 *
	 * @param object $booking Booking.
	 * @param float  $amount  Amount (0 = the fee agreed at booking).
	 * @return true|WP_Error
	 */
	public static function charge_noshow( $booking, $amount = 0.0 ) {
		$card = self::held_card( $booking->id );
		if ( ! $card || empty( $card->info['customer'] ) || empty( $card->info['payment_method'] ) ) {
			return new WP_Error( 'osb_no_card', __( 'There is no saved card on this booking.', 'osam-bookings' ) );
		}
		if ( 'paid' === $booking->payment_status ) {
			return new WP_Error( 'osb_paid', __( 'This booking has already been charged.', 'osam-bookings' ) );
		}
		$amount = $amount > 0 ? round( (float) $amount, 2 ) : (float) $card->amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'osb_amount', __( 'Enter an amount to charge.', 'osam-bookings' ) );
		}
		$stripe = osb()->payments->get( 'stripe' );
		if ( ! $stripe || ! method_exists( $stripe, 'charge_saved_card' ) ) {
			return new WP_Error( 'osb_stripe', __( 'Stripe is not available.', 'osam-bookings' ) );
		}
		$result = $stripe->charge_saved_card( $booking, $card->info['customer'], $card->info['payment_method'], $amount );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		OSB_DB::add_payment( $booking->id, 'stripe', $result['id'], $amount, $booking->currency, 'completed', array( 'no_show_fee' => true ) );
		OSB_DB::update(
			'bookings',
			$booking->id,
			array(
				'amount'         => $amount,
				'payment_status' => 'paid',
				'updated_at'     => current_time( 'mysql' ),
			)
		);
		if ( 'no_show' !== $booking->status ) {
			OSB_Bookings::set_status( $booking->id, 'no_show', false );
		}
		do_action( 'osb_table_noshow_charged', OSB_DB::get( 'bookings', $booking->id ), $amount );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Days and periods
	 * ------------------------------------------------------------------ */

	/**
	 * Is the whole day (or one period) closed?
	 *
	 * @param string $date      Y-m-d.
	 * @param int    $period_id Period (0 = check whole-day closures only).
	 * @return bool
	 */
	public static function is_closed( $date, $period_id = 0 ) {
		if ( ! isset( self::$closed[ $date ] ) ) {
			global $wpdb;
			$t                     = OSB_Install::table( 'rest_closures' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$closed[ $date ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT period_id FROM $t WHERE date_from <= %s AND date_to >= %s", $date, $date ) ) );
		}
		return in_array( 0, self::$closed[ $date ], true ) || ( $period_id && in_array( (int) $period_id, self::$closed[ $date ], true ) );
	}

	/**
	 * Service periods running on a date (closures removed).
	 *
	 * @param string $date Y-m-d.
	 * @return array
	 */
	public static function periods_on( $date ) {
		if ( self::is_closed( $date ) ) {
			return array();
		}
		$w   = (int) gmdate( 'w', strtotime( $date . ' 12:00:00' ) );
		$out = array();
		foreach ( self::periods( true ) as $p ) {
			if ( ! in_array( $w, array_map( 'intval', explode( ',', (string) $p->weekdays ) ), true ) ) {
				continue;
			}
			if ( self::is_closed( $date, (int) $p->id ) ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * Seating times of a period.
	 *
	 * @param object $p Period.
	 * @return string[] H:i
	 */
	public static function period_times( $p ) {
		$step  = max( 5, (int) $p->slot_interval ) * 60;
		$start = strtotime( '2000-01-01 ' . $p->start_time );
		$last  = strtotime( '2000-01-01 ' . $p->last_seating );
		$out   = array();
		for ( $t = $start; $t <= $last && count( $out ) < 300; $t += $step ) {
			$out[] = gmdate( 'H:i', $t );
		}
		return $out;
	}

	/**
	 * The period a seating time on a date belongs to.
	 *
	 * @param string $date Y-m-d.
	 * @param string $time H:i.
	 * @return object|null
	 */
	public static function period_for( $date, $time ) {
		foreach ( self::periods_on( $date ) as $p ) {
			if ( in_array( $time, self::period_times( $p ), true ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Areas a period serves (empty list on the period = all).
	 *
	 * @param object|null $p Period.
	 * @return int[]|null null = every area.
	 */
	private static function period_areas( $p ) {
		$ids = $p ? self::ids( $p->areas ) : array();
		return $ids ? $ids : null;
	}

	/**
	 * Table bookings that could overlap a date (cached per request).
	 *
	 * @param string $date    Y-m-d.
	 * @param int    $exclude Booking to leave out.
	 * @return array [ id, covers, start, end (with turn buffer), tables[], area_id, status ]
	 */
	public static function day_bookings( $date, $exclude = 0 ) {
		$key = $date . '|' . (int) $exclude;
		if ( isset( self::$day_cache[ $key ] ) ) {
			return self::$day_cache[ $key ];
		}
		global $wpdb;
		$t        = OSB_Install::table( 'bookings' );
		$statuses = OSB_DB::blocking_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$from     = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00' ) - DAY_IN_SECONDS );
		$to       = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00' ) + 2 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, seats, start_at, end_at, area_id, status FROM $t WHERE type = 'table' AND id <> %d AND start_at >= %s AND start_at < %s AND status IN ($in)", array_merge( array( (int) $exclude, $from, $to ), $statuses ) ) );
		$tables = self::tables_for_bookings( wp_list_pluck( $rows, 'id' ) );
		$buffer = max( 0, (int) OSB_Settings::get( 'rest_turn_buffer' ) ) * 60;
		$out    = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'      => (int) $r->id,
				'covers'  => (int) $r->seats,
				'start'   => strtotime( $r->start_at ),
				'end'     => strtotime( $r->end_at ) + $buffer,
				'tables'  => isset( $tables[ (int) $r->id ] ) ? $tables[ (int) $r->id ] : array(),
				'area_id' => (int) $r->area_id,
				'status'  => $r->status,
			);
		}
		self::$day_cache[ $key ] = $out;
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Allocation
	 * ------------------------------------------------------------------ */

	/**
	 * Every way to seat a party: single tables and combinations, best fit first.
	 *
	 * @param int        $covers  Guests.
	 * @param int        $area_id Preferred area (0 = any).
	 * @param bool       $online  Only tables/combinations customers can book.
	 * @param int[]|null $areas   Areas the period serves (null = all).
	 * @return array [ [ tables => int[], min, max, combo => bool, priority, label ] ]
	 */
	public static function options( $covers, $area_id = 0, $online = true, $areas = null ) {
		$tables = array();
		foreach ( self::tables( null, true ) as $t ) {
			$tables[ (int) $t->id ] = $t;
		}
		$area_map = self::area_map();
		$area_ok  = static function ( $aid ) use ( $area_id, $online, $areas, $area_map ) {
			if ( $area_id && (int) $aid !== (int) $area_id ) {
				return false;
			}
			if ( null !== $areas && ! in_array( (int) $aid, $areas, true ) ) {
				return false;
			}
			if ( $online && ( ! isset( $area_map[ (int) $aid ] ) || ! $area_map[ (int) $aid ]->online ) ) {
				return false;
			}
			return true;
		};
		$out = array();
		foreach ( $tables as $t ) {
			if ( (int) $covers < (int) $t->min_covers || (int) $covers > (int) $t->max_covers ) {
				continue;
			}
			if ( ( $online && ! $t->online ) || ! $area_ok( $t->area_id ) ) {
				continue;
			}
			$out[] = array(
				'tables'   => array( (int) $t->id ),
				'min'      => (int) $t->min_covers,
				'max'      => (int) $t->max_covers,
				'combo'    => false,
				'priority' => (int) $t->priority,
				'label'    => $t->name,
			);
		}
		foreach ( self::combos( true ) as $c ) {
			if ( count( $c->ids ) < 2 || (int) $covers < (int) $c->min_covers || (int) $covers > (int) $c->max_covers ) {
				continue;
			}
			if ( $online && ! $c->online ) {
				continue;
			}
			$ok    = true;
			$names = array();
			$prio  = 0;
			foreach ( $c->ids as $tid ) {
				if ( ! isset( $tables[ $tid ] ) || ! $area_ok( $tables[ $tid ]->area_id ) ) {
					$ok = false;
					break;
				}
				$names[] = $tables[ $tid ]->name;
				$prio   += (int) $tables[ $tid ]->priority;
			}
			if ( ! $ok ) {
				continue;
			}
			$out[] = array(
				'tables'   => $c->ids,
				'min'      => (int) $c->min_covers,
				'max'      => (int) $c->max_covers,
				'combo'    => true,
				'priority' => $prio,
				'label'    => $c->name ? $c->name : implode( ' + ', $names ),
			);
		}
		// Best fit: fewest empty seats, then a single table over a combination, then priority.
		usort(
			$out,
			static function ( $a, $b ) use ( $covers ) {
				return array( $a['max'] - $covers, (int) $a['combo'], count( $a['tables'] ), $a['priority'] ) <=> array( $b['max'] - $covers, (int) $b['combo'], count( $b['tables'] ), $b['priority'] );
			}
		);
		return $out;
	}

	/**
	 * Tables already taken during a window by bookings that have tables.
	 *
	 * @param array $day   Day bookings.
	 * @param int   $start Naive start.
	 * @param int   $end   Naive end.
	 * @return int[]
	 */
	private static function busy_tables( array $day, $start, $end ) {
		$busy = array();
		foreach ( $day as $b ) {
			if ( $b['tables'] && $b['start'] < $end && $b['end'] > $start ) {
				$busy = array_merge( $busy, $b['tables'] );
			}
		}
		return array_values( array_unique( $busy ) );
	}

	/**
	 * First option whose tables are all free.
	 *
	 * @param array $options Options.
	 * @param int[] $busy    Taken tables.
	 * @return array|null
	 */
	private static function first_free( array $options, array $busy ) {
		foreach ( $options as $o ) {
			if ( ! array_intersect( $o['tables'], $busy ) ) {
				return $o;
			}
		}
		return null;
	}

	/**
	 * Can a party be seated at a time? Works out tables for it too.
	 *
	 * Bookings that don't have tables yet (manual assignment, or large-party requests not yet
	 * confirmed) are seated "on paper" first, biggest party first, so a new booking is only
	 * offered when everyone overlapping can still sit down.
	 *
	 * @param string $date   Y-m-d.
	 * @param string $time   H:i.
	 * @param int    $covers Guests.
	 * @param array  $args {
	 *     @type int  $area_id  Preferred area (0 = any).
	 *     @type bool $online   Customer booking: online tables, periods, pacing and caps apply.
	 *     @type int  $exclude  Booking to leave out (the one being moved).
	 *     @type int  $duration Minutes (0 = turn time for the party).
	 * }
	 * @return array [ ok => bool, tables => int[], label => string, reason => string, period => object|null ]
	 */
	public static function check( $date, $time, $covers, array $args = array() ) {
		$args   = wp_parse_args(
			$args,
			array(
				'area_id'  => 0,
				'online'   => true,
				'exclude'  => 0,
				'duration' => 0,
			)
		);
		$covers = max( 1, (int) $covers );
		$fail   = static function ( $reason, $period = null ) {
			return array(
				'ok'     => false,
				'tables' => array(),
				'label'  => '',
				'reason' => $reason,
				'period' => $period,
			);
		};
		$period = self::period_for( $date, $time );
		if ( $args['online'] && ! $period ) {
			return $fail( 'closed' );
		}
		$start = strtotime( $date . ' ' . $time . ':00' );
		$end   = $start + ( $args['duration'] ? (int) $args['duration'] : self::turn_minutes( $covers ) ) * 60 + max( 0, (int) OSB_Settings::get( 'rest_turn_buffer' ) ) * 60;
		$day   = self::day_bookings( $date, (int) $args['exclude'] );

		if ( $args['online'] && $period ) {
			// Pacing: how many guests can arrive in one slot, and in the whole service.
			$slot_end = $start + max( 5, (int) $period->slot_interval ) * 60;
			$p_start  = strtotime( $date . ' ' . $period->start_time );
			$p_end    = strtotime( $date . ' ' . $period->last_seating ) + 60;
			$in_slot  = 0;
			$in_per   = 0;
			foreach ( $day as $b ) {
				if ( $b['start'] >= $start && $b['start'] < $slot_end ) {
					$in_slot += $b['covers'];
				}
				if ( $b['start'] >= $p_start && $b['start'] < $p_end ) {
					$in_per += $b['covers'];
				}
			}
			if ( (int) $period->max_covers_slot > 0 && $in_slot + $covers > (int) $period->max_covers_slot ) {
				return $fail( 'pacing', $period );
			}
			if ( (int) $period->max_covers_total > 0 && $in_per + $covers > (int) $period->max_covers_total ) {
				return $fail( 'full', $period );
			}
		}

		$areas = $args['online'] ? self::period_areas( $period ) : null;
		$busy  = self::busy_tables( $day, $start, $end );

		// Seat overlapping bookings that have no tables yet, biggest first.
		$waiting = array_filter(
			$day,
			static function ( $b ) use ( $start, $end ) {
				return ! $b['tables'] && $b['start'] < $end && $b['end'] > $start;
			}
		);
		usort(
			$waiting,
			static function ( $a, $b ) {
				return $b['covers'] <=> $a['covers'];
			}
		);
		foreach ( $waiting as $w ) {
			$fit = self::first_free( self::options( $w['covers'], $w['area_id'], false, null ), $busy );
			if ( ! $fit && $w['area_id'] ) {
				$fit = self::first_free( self::options( $w['covers'], 0, false, null ), $busy );
			}
			if ( $fit ) {
				$busy = array_merge( $busy, $fit['tables'] );
			}
		}

		$fit = self::first_free( self::options( $covers, (int) $args['area_id'], (bool) $args['online'], $areas ), $busy );
		if ( ! $fit ) {
			return $fail( 'no_table', $period );
		}
		return array(
			'ok'     => true,
			'tables' => $fit['tables'],
			'label'  => $fit['label'],
			'reason' => '',
			'period' => $period,
		);
	}

	/**
	 * Booking window for customers.
	 *
	 * @return array [ earliest naive ts, last bookable date Y-m-d ]
	 */
	public static function window() {
		$now = strtotime( current_time( 'mysql' ) );
		return array(
			$now + max( 0, (int) OSB_Settings::get( 'rest_min_notice_minutes' ) ) * 60,
			gmdate( 'Y-m-d', $now + max( 0, (int) OSB_Settings::get( 'rest_max_advance_days' ) ) * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Is this a large-party request rather than an instant booking?
	 *
	 * @param int $covers Guests.
	 * @return bool
	 */
	public static function is_request( $covers ) {
		list( , $max, $request ) = self::party_limits();
		return $request && (int) $covers > $max && (int) $covers <= $request;
	}

	/**
	 * Bookable seating times on a day for customers.
	 *
	 * @param string $date       Y-m-d.
	 * @param int    $covers     Guests.
	 * @param int    $area_id    Preferred area.
	 * @param bool   $first_only Stop at the first one (month view).
	 * @return array [ [ time, label, period_id, period ] ]
	 */
	public static function day_slots( $date, $covers, $area_id = 0, $first_only = false ) {
		list( $earliest, $last ) = self::window();
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date > $last || $date < gmdate( 'Y-m-d', $earliest - DAY_IN_SECONDS ) ) {
			return array();
		}
		list( $min, $max, $request ) = self::party_limits();
		if ( $covers < $min || $covers > max( $max, $request ) ) {
			return array();
		}
		$is_request = self::is_request( $covers );
		$out        = array();
		foreach ( self::periods_on( $date ) as $p ) {
			foreach ( self::period_times( $p ) as $time ) {
				if ( strtotime( $date . ' ' . $time . ':00' ) < $earliest ) {
					continue;
				}
				// Large-party requests are checked by staff, so every time in the service is offered.
				$ok = $is_request ? true : self::check( $date, $time, $covers, array( 'area_id' => $area_id ) )['ok'];
				if ( ! $ok ) {
					continue;
				}
				$out[] = array(
					'time'      => $time,
					'label'     => date_i18n( get_option( 'time_format' ), strtotime( '2000-01-01 ' . $time . ':00' ) ),
					'period_id' => (int) $p->id,
					'period'    => $p->name,
				);
				if ( $first_only ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Dates in a month with at least one bookable time.
	 *
	 * @param string $month   Y-m.
	 * @param int    $covers  Guests.
	 * @param int    $area_id Area.
	 * @return array [ dates[], min, max ]
	 */
	public static function month_dates( $month, $covers, $area_id = 0 ) {
		list( $earliest, $last ) = self::window();
		$min = gmdate( 'Y-m-d', $earliest );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = substr( $min, 0, 7 );
		}
		$dates = array();
		$days  = (int) gmdate( 't', strtotime( $month . '-01' ) );
		for ( $d = 1; $d <= $days; $d++ ) {
			$date = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			if ( $date < $min || $date > $last ) {
				continue;
			}
			if ( self::day_slots( $date, $covers, $area_id, true ) ) {
				$dates[] = $date;
			}
		}
		return array( $dates, $min, $last );
	}

	/* ---------------------------------------------------------------------
	 * Booking lifecycle (called from OSB_Bookings::create inside the booking lock)
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a table booking and fill in its row.
	 *
	 * @param array $data  Booking request (date, time, seats, area_id, table_ids, duration, admin).
	 * @param int   $seats Guests.
	 * @return array|WP_Error [ row fields, item (for payment methods), meta [ tables, request, hold, fee ] ].
	 */
	public static function booking_row( array $data, $seats ) {
		$admin = ! empty( $data['admin'] );
		if ( ! $admin && ! self::enabled() ) {
			return new WP_Error( 'osb_disabled', __( 'Online table booking is not available.', 'osam-bookings' ) );
		}
		$date = isset( $data['date'] ) ? (string) $data['date'] : '';
		$time = isset( $data['time'] ) ? (string) $data['time'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new WP_Error( 'osb_datetime', __( 'Please choose a date and time.', 'osam-bookings' ) );
		}
		$area_id = isset( $data['area_id'] ) ? absint( $data['area_id'] ) : 0;
		$areas   = self::area_map();
		if ( $area_id && ( ! isset( $areas[ $area_id ] ) || ( ! $admin && ( ! $areas[ $area_id ]->online || 'active' !== $areas[ $area_id ]->status ) ) ) ) {
			$area_id = 0;
		}
		if ( ! $admin && ! OSB_Settings::get( 'rest_area_choice' ) ) {
			$area_id = 0;
		}
		$duration = ! empty( $data['duration'] ) ? max( 15, (int) $data['duration'] ) : self::turn_minutes( $seats );
		$tables   = array();
		$request  = false;

		if ( $admin ) {
			if ( isset( $data['table_ids'] ) && is_array( $data['table_ids'] ) ) {
				$tables = array_values( array_intersect( self::ids( $data['table_ids'] ), array_keys( self::table_map() ) ) );
			} elseif ( 'auto' === OSB_Settings::get( 'rest_assignment' ) ) {
				$fit    = self::check( $date, $time, $seats, array( 'area_id' => $area_id, 'online' => false, 'duration' => $duration ) );
				$tables = $fit['ok'] ? $fit['tables'] : array();
			}
		} else {
			list( $min, $max, $req_max ) = self::party_limits();
			if ( $seats < $min ) {
				/* translators: %d guests */
				return new WP_Error( 'osb_seats', sprintf( __( 'Online bookings are for at least %d guests.', 'osam-bookings' ), $min ) );
			}
			list( $earliest, $last ) = self::window();
			if ( strtotime( $date . ' ' . $time . ':00' ) < $earliest || $date > $last ) {
				return new WP_Error( 'osb_unavailable', __( 'Sorry, that time can’t be booked online. Please choose another.', 'osam-bookings' ) );
			}
			if ( $seats > $max ) {
				if ( ! $req_max || $seats > $req_max ) {
					return new WP_Error( 'osb_seats', sprintf( OSB_Settings::label( 'large_party' ), $req_max ? $req_max : $max ) );
				}
				if ( ! self::period_for( $date, $time ) ) {
					return new WP_Error( 'osb_unavailable', __( 'Sorry, we’re not open at that time. Please choose another.', 'osam-bookings' ) );
				}
				$request = true;
			} else {
				$fit = self::check( $date, $time, $seats, array( 'area_id' => $area_id ) );
				if ( ! $fit['ok'] ) {
					return new WP_Error( 'osb_unavailable', __( 'Sorry, that time has just been taken. Please choose another.', 'osam-bookings' ) );
				}
				if ( 'auto' === OSB_Settings::get( 'rest_assignment' ) ) {
					$tables = $fit['tables'];
				}
			}
		}

		$rule  = $admin || $request ? array( 'mode' => 'none', 'amount' => 0, 'fee' => 0 ) : self::payment_rule( $seats );
		$start = strtotime( $date . ' ' . $time . ':00' );
		$item  = (object) array(
			'name'            => sprintf( OSB_Settings::label( 'table_item' ), $seats ),
			'payment_methods' => implode( ',', 'deposit' === $rule['mode'] ? self::deposit_methods() : ( 'card_hold' === $rule['mode'] ? array( 'stripe' ) : array() ) ),
		);
		return array(
			array(
				'staff_id' => 0,
				'area_id'  => $area_id,
				'start_at' => gmdate( 'Y-m-d H:i:s', $start ),
				'end_at'   => gmdate( 'Y-m-d H:i:s', $start + $duration * 60 ),
				'amount'   => 'deposit' === $rule['mode'] ? (float) $rule['amount'] : 0.0,
			),
			$item,
			array(
				'tables'  => $tables,
				'request' => $request,
				'hold'    => 'card_hold' === $rule['mode'],
				'fee'     => (float) $rule['fee'],
			),
		);
	}

	/**
	 * Starting status for a table booking that isn't waiting for an online payment.
	 *
	 * @param array $meta  From booking_row().
	 * @param array $row   Row about to be saved.
	 * @param bool  $admin Admin-created.
	 * @return array Row.
	 */
	public static function finalise_row( array $meta, array $row, $admin ) {
		if ( $admin ) {
			return $row;
		}
		if ( $meta['hold'] ) {
			// Nothing is charged now; the customer saves a card with Stripe first.
			$row['payment_method'] = 'stripe';
			$row['payment_status'] = 'unpaid';
			$row['status']         = 'pending_payment';
			return $row;
		}
		if ( 'pending_payment' !== $row['status'] ) {
			$row['status'] = ( $meta['request'] || 'approval' === OSB_Settings::get( 'rest_confirm' ) ) ? 'pending' : 'confirmed';
		}
		return $row;
	}

	/**
	 * After the row is saved (still inside the lock): seat it.
	 *
	 * @param int   $booking_id Booking.
	 * @param array $meta       From booking_row().
	 */
	public static function attach( $booking_id, array $meta ) {
		if ( $meta['tables'] ) {
			self::set_booking_tables( $booking_id, $meta['tables'] );
		}
		self::$day_cache = array();
	}

	/**
	 * Admin warnings for a table booking (shown, never blocking).
	 *
	 * @param array $a date, time, seats, exclude, table_ids (null = current), duration.
	 * @return string[]
	 */
	public static function conflicts( array $a ) {
		$warnings = array();
		$date     = isset( $a['date'] ) ? (string) $a['date'] : '';
		$time     = isset( $a['time'] ) ? (string) $a['time'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return $warnings;
		}
		$covers   = max( 1, (int) ( isset( $a['seats'] ) ? $a['seats'] : 1 ) );
		$exclude  = (int) ( isset( $a['exclude'] ) ? $a['exclude'] : 0 );
		$duration = ! empty( $a['duration'] ) ? (int) $a['duration'] : self::turn_minutes( $covers );
		$tables   = isset( $a['table_ids'] ) && is_array( $a['table_ids'] ) ? self::ids( $a['table_ids'] ) : ( $exclude ? self::booking_table_ids( $exclude ) : array() );

		if ( ! self::period_for( $date, $time ) ) {
			$warnings[] = __( 'This is outside your service times.', 'osam-bookings' );
		}
		if ( strtotime( $date . ' ' . $time . ':00' ) < strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS ) {
			$warnings[] = __( 'This time is in the past.', 'osam-bookings' );
		}
		if ( $tables ) {
			$map  = self::table_map();
			$seat = 0;
			foreach ( $tables as $tid ) {
				$seat += isset( $map[ $tid ] ) ? (int) $map[ $tid ]->max_covers : 0;
			}
			if ( $covers > $seat ) {
				/* translators: 1: guests 2: seats */
				$warnings[] = sprintf( __( '%1$d guests at a table for %2$d.', 'osam-bookings' ), $covers, $seat );
			}
			$start = strtotime( $date . ' ' . $time . ':00' );
			$end   = $start + $duration * 60;
			foreach ( self::day_bookings( $date, $exclude ) as $b ) {
				$shared = array_intersect( $b['tables'], $tables );
				if ( $shared && $b['start'] < $end && $b['end'] > $start ) {
					$other = OSB_DB::get( 'bookings', $b['id'] );
					/* translators: 1: tables 2: customer 3: time */
					$warnings[] = sprintf( __( '%1$s is also booked for %2$s at %3$s.', 'osam-bookings' ), self::tables_label( array_values( $shared ) ), $other ? $other->customer_name : '#' . $b['id'], gmdate( 'H:i', $b['start'] ) );
				}
			}
		} else {
			$warnings[] = __( 'No table assigned yet.', 'osam-bookings' );
		}
		return apply_filters( 'osb_table_conflicts', $warnings, $a );
	}

	/**
	 * Move a table booking (time, length, tables). Admin action: always allowed, returns warnings.
	 *
	 * @param object     $booking   Booking.
	 * @param array|null $table_ids New tables (null = keep).
	 * @param string     $date      Y-m-d ('' = keep).
	 * @param string     $time      H:i ('' = keep).
	 * @param int        $duration  Minutes (0 = keep).
	 * @param int        $seats     Guests (0 = keep).
	 * @return string[] Warnings.
	 */
	public static function move( $booking, $table_ids = null, $date = '', $time = '', $duration = 0, $seats = 0 ) {
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : substr( $booking->start_at, 0, 10 );
		$time     = preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : substr( $booking->start_at, 11, 5 );
		$duration = $duration > 0 ? (int) $duration : (int) round( ( strtotime( $booking->end_at ) - strtotime( $booking->start_at ) ) / 60 );
		$seats    = $seats > 0 ? (int) $seats : (int) $booking->seats;
		$start    = strtotime( $date . ' ' . $time . ':00' );
		$previous = $booking->start_at;
		$update   = array(
			'start_at'   => gmdate( 'Y-m-d H:i:s', $start ),
			'end_at'     => gmdate( 'Y-m-d H:i:s', $start + max( 15, $duration ) * 60 ),
			'seats'      => $seats,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $update['start_at'] !== $booking->start_at ) {
			$update['reminder_sent'] = 0;
		}
		OSB_DB::update( 'bookings', $booking->id, $update );
		if ( null !== $table_ids ) {
			$table_ids = array_values( array_intersect( self::ids( $table_ids ), array_keys( self::table_map() ) ) );
			self::set_booking_tables( $booking->id, $table_ids );
			$map = self::table_map();
			if ( $table_ids && isset( $map[ $table_ids[0] ] ) ) {
				OSB_DB::update( 'bookings', $booking->id, array( 'area_id' => (int) $map[ $table_ids[0] ]->area_id ) );
			}
		}
		self::$day_cache = array();
		OSB_Availability::bust_cache();
		$fresh = OSB_DB::get( 'bookings', $booking->id );
		if ( $fresh->start_at !== $previous ) {
			do_action( 'osb_booking_rescheduled', $fresh, $previous, 'admin' );
		}
		do_action( 'osb_table_booking_moved', $fresh );
		return self::conflicts(
			array(
				'date'     => $date,
				'time'     => $time,
				'seats'    => $seats,
				'exclude'  => $booking->id,
				'duration' => $duration,
			)
		);
	}

	/**
	 * Seat a walk-in now (no emails, no customer details needed).
	 *
	 * @param array $args covers, table_ids, name, phone, note, duration, time (H:i, default now), date.
	 * @return object|WP_Error Booking.
	 */
	public static function walk_in( array $args ) {
		if ( OSB_Licence::bookings_off() ) {
			return OSB_Licence::booking_error();
		}
		$covers = max( 1, (int) ( isset( $args['covers'] ) ? $args['covers'] : 2 ) );
		$now    = strtotime( current_time( 'mysql' ) );
		$date   = ! empty( $args['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['date'] ) ? $args['date'] : gmdate( 'Y-m-d', $now );
		$time   = ! empty( $args['time'] ) && preg_match( '/^\d{2}:\d{2}$/', $args['time'] ) ? $args['time'] : gmdate( 'H:i', $now - ( $now % 300 ) );
		$dur    = ! empty( $args['duration'] ) ? max( 15, (int) $args['duration'] ) : self::turn_minutes( $covers );
		$given  = isset( $args['table_ids'] ) && is_array( $args['table_ids'] ) && $args['table_ids'];
		$tables = $given ? self::ids( $args['table_ids'] ) : array();
		if ( ! $given ) {
			$fit    = self::check( $date, $time, $covers, array( 'online' => false, 'duration' => $dur ) );
			$tables = $fit['ok'] ? $fit['tables'] : array();
		}
		$start  = strtotime( $date . ' ' . $time . ':00' );
		$name   = isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ? sanitize_text_field( $args['name'] ) : __( 'Walk-in', 'osam-bookings' );
		$fields = array(
			'name'  => $name,
			'email' => '',
			'phone' => isset( $args['phone'] ) ? sanitize_text_field( $args['phone'] ) : '',
		);
		$id     = OSB_DB::insert(
			'bookings',
			array(
				'type'           => 'table',
				'start_at'       => gmdate( 'Y-m-d H:i:s', $start ),
				'end_at'         => gmdate( 'Y-m-d H:i:s', $start + $dur * 60 ),
				'seats'          => $covers,
				'customer_name'  => $name,
				'customer_email' => '',
				'customer_phone' => $fields['phone'],
				'fields'         => wp_json_encode( $fields ),
				'internal_note'  => isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '',
				'status'         => 'seated',
				'payment_method' => 'free',
				'payment_status' => 'free',
				'amount'         => 0,
				'currency'       => OSB_Settings::get( 'currency' ),
				'token'          => wp_generate_password( 32, false, false ),
				'area_id'        => 0,
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'osb_db', __( 'Could not save the walk-in.', 'osam-bookings' ) );
		}
		if ( $tables ) {
			self::set_booking_tables( $id, $tables );
			$map = self::table_map();
			if ( isset( $map[ $tables[0] ] ) ) {
				OSB_DB::update( 'bookings', $id, array( 'area_id' => (int) $map[ $tables[0] ]->area_id ) );
			}
		}
		OSB_Availability::bust_cache();
		$booking = OSB_DB::get( 'bookings', $id );
		do_action( 'osb_booking_created', $booking, array( 'type' => 'table', 'walk_in' => true, 'admin' => true ) );
		return $booking;
	}

	/**
	 * Extra booking details for tables.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		if ( 'table' !== $booking->type ) {
			return $d;
		}
		$areas          = self::area_map();
		$tables         = self::booking_table_ids( $booking->id );
		$d['item_name'] = sprintf( OSB_Settings::label( 'table_item' ), (int) $booking->seats );
		$d['covers']    = (int) $booking->seats;
		$d['area_name'] = isset( $areas[ (int) $booking->area_id ] ) ? $areas[ (int) $booking->area_id ]->name : '';
		$d['location']  = $d['area_name'];
		$d['table_ids'] = $tables;
		$d['tables']    = $tables ? self::tables_label( $tables ) : '';
		$d['attendees'] = array();
		$d['card_held'] = 'card_held' === $booking->payment_status;
		$d['payment_status_key'] = $booking->payment_status;
		if ( $d['card_held'] ) {
			$card              = self::held_card( $booking->id );
			$d['noshow_fee']   = $card ? OSB_Settings::money( $card->amount, $booking->currency ) : '';
			$d['payment_method'] = __( 'Card saved (not charged)', 'osam-bookings' );
		}
		return $d;
	}

	/**
	 * {covers}, {tables} and {noshow_fee} in emails.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$is   = 'table' === $booking->type;
		$card = $is && 'card_held' === $booking->payment_status ? self::held_card( $booking->id ) : null;
		$map['{covers}']     = $is ? (int) $booking->seats : '';
		$map['{tables}']     = $is ? esc_html( self::tables_label( self::booking_table_ids( $booking->id ) ) ) : '';
		$map['{noshow_fee}'] = $card ? esc_html( OSB_Settings::money( $card->amount, $booking->currency ) ) : '';
		return $map;
	}

	/**
	 * Housekeeping: seated parties whose time is up are completed.
	 */
	public static function cron() {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE type = 'table' AND status = 'seated' AND end_at < %s LIMIT 200", gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			OSB_Bookings::set_status( (int) $id, 'completed', false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin data for the service screens
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the timeline and floor plan need for one date.
	 *
	 * @param string $date Y-m-d.
	 * @return array
	 */
	public static function admin_day( $date ) {
		global $wpdb;
		$t    = OSB_Install::table( 'bookings' );
		$from = $date . ' 00:00:00';
		$to   = $date . ' 23:59:59';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE type = 'table' AND start_at >= %s AND start_at <= %s AND status NOT IN ('pending_payment','payment_failed') ORDER BY start_at ASC, id ASC", $from, $to ) );
		$tables = self::tables_for_bookings( wp_list_pluck( $rows, 'id' ) );

		$bookings = array();
		foreach ( $rows as $b ) {
			$fields     = OSB_Bookings::fields( $b );
			$bookings[] = array(
				'id'        => (int) $b->id,
				'name'      => $b->customer_name,
				'phone'     => $b->customer_phone,
				'email'     => $b->customer_email,
				'covers'    => (int) $b->seats,
				'start'     => substr( $b->start_at, 11, 5 ),
				'end'       => substr( $b->end_at, 0, 10 ) > $date ? '23:59' : substr( $b->end_at, 11, 5 ),
				'minutes'   => (int) round( ( strtotime( $b->end_at ) - strtotime( $b->start_at ) ) / 60 ),
				'status'    => $b->status,
				'tables'    => isset( $tables[ (int) $b->id ] ) ? $tables[ (int) $b->id ] : array(),
				'area_id'   => (int) $b->area_id,
				'notes'     => isset( $fields['notes'] ) ? (string) $fields['notes'] : '',
				'internal'  => (string) $b->internal_note,
				'payment'   => $b->payment_status,
				'amount'    => (float) $b->amount > 0 ? OSB_Settings::money( $b->amount, $b->currency ) : '',
				'card_held' => 'card_held' === $b->payment_status,
				'fee'       => 'card_held' === $b->payment_status && ( $card = self::held_card( $b->id ) ) ? OSB_Settings::money( $card->amount, $b->currency ) : '',
				'url'       => admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $b->id ),
			);
		}

		$periods = array();
		foreach ( self::periods_on( $date ) as $p ) {
			$periods[] = array(
				'id'    => (int) $p->id,
				'name'  => $p->name,
				'start' => substr( $p->start_time, 0, 5 ),
				'last'  => substr( $p->last_seating, 0, 5 ),
			);
		}

		$areas = array();
		foreach ( self::areas( true ) as $a ) {
			$areas[] = array(
				'id'     => (int) $a->id,
				'name'   => $a->name,
				'width'  => (int) $a->plan_width,
				'height' => (int) $a->plan_height,
			);
		}
		$tlist = array();
		foreach ( self::tables( null, true ) as $tb ) {
			$tlist[] = self::table_json( $tb );
		}
		$combos = array();
		foreach ( self::combos( true ) as $c ) {
			$combos[] = array(
				'id'     => (int) $c->id,
				'name'   => $c->name ? $c->name : self::tables_label( $c->ids ),
				'tables' => $c->ids,
				'min'    => (int) $c->min_covers,
				'max'    => (int) $c->max_covers,
			);
		}

		return array(
			'date'     => $date,
			'label'    => date_i18n( 'l j F Y', strtotime( $date . ' 12:00:00' ) ),
			'closed'   => self::is_closed( $date ),
			'periods'  => $periods,
			'areas'    => $areas,
			'tables'   => $tlist,
			'combos'   => $combos,
			'bookings' => $bookings,
			'now'      => gmdate( 'Y-m-d H:i', strtotime( current_time( 'mysql' ) ) ),
		);
	}

	/**
	 * A table shaped for JS.
	 *
	 * @param object $t Table.
	 * @return array
	 */
	public static function table_json( $t ) {
		return array(
			'id'       => (int) $t->id,
			'area_id'  => (int) $t->area_id,
			'name'     => $t->name,
			'min'      => (int) $t->min_covers,
			'max'      => (int) $t->max_covers,
			'online'   => (bool) $t->online,
			'priority' => (int) $t->priority,
			'shape'    => $t->shape,
			'x'        => (int) $t->plan_x,
			'y'        => (int) $t->plan_y,
			'w'        => (int) $t->plan_w,
			'h'        => (int) $t->plan_h,
			'rotation' => (int) $t->rotation,
			'status'   => $t->status,
		);
	}
}
