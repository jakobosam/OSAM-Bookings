<?php
/**
 * REST routes for restaurant table booking (booking form + admin service screens).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/osb/v1/tables and /wp-json/osb/v1/admin/tables.
 */
class OSB_Restaurant_Rest {

	/**
	 * Register routes.
	 */
	public static function routes() {
		$ns     = OSB_Rest::NS;
		$public = '__return_true';
		$admin  = static function () {
			return current_user_can( 'osb_manage_bookings' );
		};

		register_rest_route( $ns, '/tables/config', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => $public ) );
		register_rest_route( $ns, '/tables/month', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'month' ), 'permission_callback' => $public ) );
		register_rest_route( $ns, '/tables/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day' ), 'permission_callback' => $public ) );

		register_rest_route( $ns, '/admin/tables/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_day' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/suggest', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'suggest' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/bookings/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_booking' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/reservations', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_reservation' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/walkin', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'walk_in' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/charge/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'charge' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/admin/tables/layout', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_layout' ), 'permission_callback' => $admin ) );
	}

	/**
	 * Disabled response.
	 *
	 * @return WP_Error
	 */
	private static function off() {
		return new WP_Error( 'osb_disabled', __( 'Online table booking is not available.', 'osam-bookings' ), array( 'status' => 404 ) );
	}

	/**
	 * Gateways shaped for JS.
	 *
	 * @param string[] $ids Gateway ids.
	 * @return array
	 */
	private static function methods( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$g = osb()->payments->get( $id );
			if ( ! $g ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'title'       => $g->title(),
				'description' => $g->description(),
				'online'      => 'pending_payment' === $g->initial_status(),
			);
		}
		return $out;
	}

	/**
	 * GET /tables/config — what the booking form needs to know.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function config() {
		if ( ! OSB_Restaurant::enabled() ) {
			return self::off();
		}
		list( $min, $max, $request ) = OSB_Restaurant::party_limits();
		$areas                       = array();
		if ( OSB_Settings::get( 'rest_area_choice' ) ) {
			foreach ( OSB_Restaurant::areas( true ) as $a ) {
				if ( $a->online ) {
					$areas[] = array(
						'id'   => (int) $a->id,
						'name' => $a->name,
					);
				}
			}
		}
		$mode = (string) OSB_Settings::get( 'rest_payment_mode' );
		if ( 'deposit' === $mode && ! OSB_Restaurant::deposit_methods() ) {
			$mode = 'none';
		}
		if ( 'card_hold' === $mode && ! OSB_Restaurant::card_hold_ready() ) {
			$mode = 'none';
		}
		return rest_ensure_response(
			array(
				'min'         => $min,
				'max'         => $max,
				'request_max' => $request,
				'large_mode'  => (string) OSB_Settings::get( 'rest_large_party' ),
				'phone'       => (string) OSB_Settings::get( 'rest_phone' ),
				'areas'       => count( $areas ) > 1 ? $areas : array(),
				'payment'     => array(
					'mode'       => $mode,
					'min_party'  => max( 1, (int) OSB_Settings::get( 'rest_payment_min_party' ) ),
					'per'        => 'per_booking' === OSB_Settings::get( 'rest_deposit_type' ) ? 'booking' : 'cover',
					'deposit'    => (float) OSB_Settings::get( 'rest_deposit_amount' ),
					'fee'        => (float) OSB_Settings::get( 'rest_noshow_fee' ),
					'methods'    => self::methods( 'card_hold' === $mode ? array( 'stripe' ) : ( 'deposit' === $mode ? OSB_Restaurant::deposit_methods() : array() ) ),
				),
				'policy'      => wp_kses_post( wpautop( (string) OSB_Settings::get( 'rest_policy_text' ) ) ),
				'confirm'     => (string) OSB_Settings::get( 'rest_confirm' ),
			)
		);
	}

	/**
	 * Common query params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array [ covers, area ]
	 */
	private static function party( WP_REST_Request $request ) {
		return array( max( 1, absint( $request->get_param( 'covers' ) ) ), absint( $request->get_param( 'area_id' ) ) );
	}

	/**
	 * GET /tables/month.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function month( WP_REST_Request $request ) {
		if ( ! OSB_Restaurant::enabled() ) {
			return self::off();
		}
		list( $covers, $area )      = self::party( $request );
		list( $dates, $min, $max ) = OSB_Restaurant::month_dates( sanitize_text_field( (string) $request->get_param( 'month' ) ), $covers, $area );
		return rest_ensure_response(
			array(
				'dates' => $dates,
				'min'   => $min,
				'max'   => $max,
			)
		);
	}

	/**
	 * GET /tables/day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function day( WP_REST_Request $request ) {
		if ( ! OSB_Restaurant::enabled() ) {
			return self::off();
		}
		list( $covers, $area ) = self::party( $request );
		$date                  = sanitize_text_field( (string) $request->get_param( 'date' ) );
		return rest_ensure_response(
			array(
				'slots'   => OSB_Restaurant::day_slots( $date, $covers, $area ),
				'request' => OSB_Restaurant::is_request( $covers ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Valid date param or today.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
	}

	/**
	 * GET /admin/tables/day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_day( WP_REST_Request $request ) {
		return rest_ensure_response( OSB_Restaurant::admin_day( self::date( $request->get_param( 'date' ) ) ) );
	}

	/**
	 * GET /admin/tables/suggest — best table and every free table for a time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function suggest( WP_REST_Request $request ) {
		$date     = self::date( $request->get_param( 'date' ) );
		$time     = preg_match( '/^\d{2}:\d{2}$/', (string) $request->get_param( 'time' ) ) ? (string) $request->get_param( 'time' ) : '19:00';
		$covers   = max( 1, absint( $request->get_param( 'covers' ) ) );
		$exclude  = absint( $request->get_param( 'exclude' ) );
		$duration = absint( $request->get_param( 'duration' ) );
		$duration = $duration ? $duration : OSB_Restaurant::turn_minutes( $covers );
		$fit      = OSB_Restaurant::check(
			$date,
			$time,
			$covers,
			array(
				'online'   => false,
				'exclude'  => $exclude,
				'duration' => $duration,
				'area_id'  => absint( $request->get_param( 'area_id' ) ),
			)
		);
		$start  = strtotime( $date . ' ' . $time . ':00' );
		$end    = $start + $duration * 60;
		$taken  = array();
		foreach ( OSB_Restaurant::day_bookings( $date, $exclude ) as $b ) {
			if ( $b['start'] < $end && $b['end'] > $start ) {
				$taken = array_merge( $taken, $b['tables'] );
			}
		}
		$free = array();
		foreach ( OSB_Restaurant::tables( null, true ) as $t ) {
			if ( ! in_array( (int) $t->id, $taken, true ) ) {
				$free[] = (int) $t->id;
			}
		}
		return rest_ensure_response(
			array(
				'fit'      => $fit['ok'] ? array( 'tables' => $fit['tables'], 'label' => $fit['label'] ) : null,
				'free'     => $free,
				'duration' => $duration,
			)
		);
	}

	/**
	 * POST /admin/tables/bookings/{id} — move, resize, reseat or change status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_booking( WP_REST_Request $request ) {
		$booking = OSB_DB::get( 'bookings', absint( $request['id'] ) );
		if ( ! $booking || 'table' !== $booking->type ) {
			return new WP_Error( 'osb_not_found', __( 'Booking not found.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		$p        = (array) $request->get_json_params();
		$warnings = array();
		$moves    = array_intersect_key( $p, array_flip( array( 'tables', 'date', 'time', 'duration', 'covers' ) ) );
		if ( $moves ) {
			$warnings = OSB_Restaurant::move(
				$booking,
				array_key_exists( 'tables', $p ) ? OSB_Restaurant::ids( (array) $p['tables'] ) : null,
				isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '',
				isset( $p['time'] ) ? sanitize_text_field( $p['time'] ) : '',
				isset( $p['duration'] ) ? absint( $p['duration'] ) : 0,
				isset( $p['covers'] ) ? absint( $p['covers'] ) : 0
			);
			if ( ! empty( $p['notify'] ) && isset( $p['time'] ) && $booking->customer_email ) {
				OSB_Bookings::notify_moved( OSB_DB::get( 'bookings', $booking->id ), $booking->start_at, false );
			}
		}
		if ( isset( $p['note'] ) ) {
			OSB_DB::update( 'bookings', $booking->id, array( 'internal_note' => sanitize_textarea_field( (string) $p['note'] ) ) );
		}
		if ( ! empty( $p['status'] ) ) {
			$status = sanitize_key( $p['status'] );
			if ( ! isset( OSB_Bookings::statuses()[ $status ] ) ) {
				return new WP_Error( 'osb_status', __( 'Unknown status.', 'osam-bookings' ), array( 'status' => 400 ) );
			}
			OSB_Bookings::set_status( $booking->id, $status, ! empty( $p['notify'] ) && '' !== (string) $booking->customer_email );
		}
		$fresh = OSB_DB::get( 'bookings', $booking->id );
		return rest_ensure_response(
			array(
				'ok'       => true,
				'warnings' => array_values( array_diff( $warnings, array( __( 'This time is in the past.', 'osam-bookings' ) ) ) ),
				'status'   => $fresh->status,
			)
		);
	}

	/**
	 * POST /admin/tables/reservations — a booking taken by phone or in person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_reservation( WP_REST_Request $request ) {
		$p      = (array) $request->get_json_params();
		$email  = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
		$fields = isset( $p['fields'] ) && is_array( $p['fields'] ) ? $p['fields'] : array();
		$fields = array_merge(
			$fields,
			array(
				'name'  => isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '',
				'email' => $email,
				'phone' => isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '',
				'notes' => isset( $p['notes'] ) ? sanitize_textarea_field( $p['notes'] ) : '',
			)
		);
		$status = isset( $p['status'] ) ? sanitize_key( $p['status'] ) : 'confirmed';
		$data   = array(
			'type'     => 'table',
			'date'     => isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '',
			'time'     => isset( $p['time'] ) ? sanitize_text_field( $p['time'] ) : '',
			'seats'    => max( 1, isset( $p['covers'] ) ? absint( $p['covers'] ) : 2 ),
			'fields'   => $fields,
			'area_id'  => isset( $p['area_id'] ) ? absint( $p['area_id'] ) : 0,
			'duration' => isset( $p['duration'] ) ? absint( $p['duration'] ) : 0,
			'admin'    => true,
			'lenient'  => true,
			'status'   => isset( OSB_Bookings::statuses()[ $status ] ) ? $status : 'confirmed',
			'notify'   => ! empty( $p['notify'] ) && '' !== $email,
		);
		if ( isset( $p['tables'] ) && is_array( $p['tables'] ) && $p['tables'] ) {
			$data['table_ids'] = OSB_Restaurant::ids( $p['tables'] );
		}
		$booking = OSB_Bookings::create( $data );
		if ( is_wp_error( $booking ) ) {
			$booking->add_data( array( 'status' => 400 ) );
			return $booking;
		}
		if ( ! empty( $p['internal'] ) ) {
			OSB_DB::update( 'bookings', $booking->id, array( 'internal_note' => sanitize_textarea_field( (string) $p['internal'] ) ) );
		}
		return rest_ensure_response(
			array(
				'id'       => (int) $booking->id,
				'tables'   => OSB_Restaurant::booking_table_ids( $booking->id ),
				'warnings' => array_values(
					array_diff(
						OSB_Restaurant::conflicts(
							array(
								'date'    => $data['date'],
								'time'    => $data['time'],
								'seats'   => $data['seats'],
								'exclude' => $booking->id,
							)
						),
						array( __( 'This time is in the past.', 'osam-bookings' ) )
					)
				),
			)
		);
	}

	/**
	 * POST /admin/tables/walkin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function walk_in( WP_REST_Request $request ) {
		$p       = (array) $request->get_json_params();
		$booking = OSB_Restaurant::walk_in(
			array(
				'covers'    => isset( $p['covers'] ) ? absint( $p['covers'] ) : 2,
				'table_ids' => isset( $p['tables'] ) ? (array) $p['tables'] : array(),
				'name'      => isset( $p['name'] ) ? (string) $p['name'] : '',
				'phone'     => isset( $p['phone'] ) ? (string) $p['phone'] : '',
				'note'      => isset( $p['note'] ) ? (string) $p['note'] : '',
				'date'      => isset( $p['date'] ) ? (string) $p['date'] : '',
				'time'      => isset( $p['time'] ) ? (string) $p['time'] : '',
				'duration'  => isset( $p['duration'] ) ? absint( $p['duration'] ) : 0,
			)
		);
		if ( is_wp_error( $booking ) ) {
			$booking->add_data( array( 'status' => 400 ) );
			return $booking;
		}
		return rest_ensure_response(
			array(
				'id'       => (int) $booking->id,
				'tables'   => OSB_Restaurant::booking_table_ids( $booking->id ),
				'warnings' => OSB_Restaurant::booking_table_ids( $booking->id ) ? array() : array( __( 'No table was free for that many guests — the walk-in is waiting for a table.', 'osam-bookings' ) ),
			)
		);
	}

	/**
	 * POST /admin/tables/charge/{id} — charge the no-show fee to the saved card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function charge( WP_REST_Request $request ) {
		$booking = OSB_DB::get( 'bookings', absint( $request['id'] ) );
		if ( ! $booking ) {
			return new WP_Error( 'osb_not_found', __( 'Booking not found.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		$p      = (array) $request->get_json_params();
		$result = OSB_Restaurant::charge_noshow( $booking, isset( $p['amount'] ) ? (float) $p['amount'] : 0 );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /admin/tables/layout — save an area's floor plan (add, move, edit and remove tables).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_layout( WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$area = OSB_DB::get( 'rest_areas', isset( $p['area_id'] ) ? absint( $p['area_id'] ) : 0 );
		if ( ! $area ) {
			return new WP_Error( 'osb_area', __( 'Please choose an area.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		OSB_DB::update(
			'rest_areas',
			$area->id,
			array(
				'plan_width'  => max( 300, min( 4000, isset( $p['width'] ) ? absint( $p['width'] ) : (int) $area->plan_width ) ),
				'plan_height' => max( 200, min( 4000, isset( $p['height'] ) ? absint( $p['height'] ) : (int) $area->plan_height ) ),
			)
		);
		$shapes = array( 'square', 'round', 'rect', 'booth' );
		$ids    = array();
		$order  = 0;
		foreach ( isset( $p['tables'] ) && is_array( $p['tables'] ) ? $p['tables'] : array() as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$min = max( 1, isset( $t['min'] ) ? absint( $t['min'] ) : 1 );
			$row = array(
				'area_id'    => (int) $area->id,
				'name'       => mb_substr( sanitize_text_field( isset( $t['name'] ) ? $t['name'] : '' ), 0, 64 ),
				'min_covers' => $min,
				'max_covers' => max( $min, isset( $t['max'] ) ? absint( $t['max'] ) : 2 ),
				'online'     => ! empty( $t['online'] ) ? 1 : 0,
				'priority'   => isset( $t['priority'] ) ? (int) $t['priority'] : 0,
				'shape'      => isset( $t['shape'] ) && in_array( $t['shape'], $shapes, true ) ? $t['shape'] : 'square',
				'plan_x'     => isset( $t['x'] ) ? (int) $t['x'] : 40,
				'plan_y'     => isset( $t['y'] ) ? (int) $t['y'] : 40,
				'plan_w'     => max( 30, min( 600, isset( $t['w'] ) ? absint( $t['w'] ) : 80 ) ),
				'plan_h'     => max( 30, min( 600, isset( $t['h'] ) ? absint( $t['h'] ) : 80 ) ),
				'rotation'   => isset( $t['rotation'] ) ? ( ( (int) $t['rotation'] % 360 ) + 360 ) % 360 : 0,
				'status'     => isset( $t['status'] ) && 'inactive' === $t['status'] ? 'inactive' : 'active',
				'sort_order' => $order++,
			);
			if ( '' === $row['name'] ) {
				/* translators: %d number */
				$row['name'] = sprintf( __( 'T%d', 'osam-bookings' ), $order );
			}
			$id = isset( $t['id'] ) ? absint( $t['id'] ) : 0;
			$ex = $id ? OSB_DB::get( 'rest_tables', $id ) : null;
			if ( $ex ) {
				OSB_DB::update( 'rest_tables', $id, $row );
			} else {
				$row['created_at'] = current_time( 'mysql' );
				$id                = OSB_DB::insert( 'rest_tables', $row );
			}
			$ids[] = $id;
		}
		// Tables removed from this area's plan.
		global $wpdb;
		$tt   = OSB_Install::table( 'rest_tables' );
		$gone = array_diff( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $tt WHERE area_id = %d", $area->id ) ) ), $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kept = array();
		foreach ( $gone as $gid ) {
			if ( self::table_has_future_bookings( $gid ) ) {
				// Keep it (hidden) so upcoming bookings don't lose their table.
				OSB_DB::update( 'rest_tables', $gid, array( 'status' => 'inactive' ) );
				$kept[] = $gid;
				continue;
			}
			OSB_DB::delete( 'rest_tables', $gid );
		}
		OSB_Restaurant::flush();
		$tables = array();
		foreach ( OSB_Restaurant::tables( (int) $area->id ) as $t ) {
			$tables[] = OSB_Restaurant::table_json( $t );
		}
		return rest_ensure_response(
			array(
				'ok'     => true,
				'tables' => $tables,
				'notice' => $kept ? __( 'Some removed tables have upcoming bookings, so they were switched off instead of deleted. Move those bookings, then remove the tables again.', 'osam-bookings' ) : '',
			)
		);
	}

	/**
	 * Does a table have bookings still to come?
	 *
	 * @param int $table_id Table.
	 * @return bool
	 */
	private static function table_has_future_bookings( $table_id ) {
		global $wpdb;
		$bt = OSB_Install::table( 'booking_tables' );
		$b  = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT b.id FROM $bt bt JOIN $b b ON b.id = bt.booking_id WHERE bt.table_id = %d AND b.end_at >= %s AND b.status NOT IN ('cancelled','completed','no_show') LIMIT 1", $table_id, current_time( 'mysql' ) ) );
	}
}
