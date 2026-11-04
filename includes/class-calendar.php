<?php
/**
 * Central calendar: one feed for every kind of booking (appointments, events, course sessions,
 * resources, restaurant tables) plus the things around them (maintenance, closures and days off,
 * busy time from synced Google/Outlook calendars), and a detail panel with quick actions.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feed, detail panel and actions for Bookings → Calendar.
 */
class OSB_Calendar {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * REST routes (staff only).
	 */
	public static function routes() {
		$admin = static function () {
			return current_user_can( 'osb_manage_bookings' );
		};
		register_rest_route( OSB_Rest::NS, '/admin/cal/feed', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'feed' ), 'permission_callback' => $admin ) );
		register_rest_route( OSB_Rest::NS, '/admin/cal/item', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'item' ), 'permission_callback' => $admin ) );
		register_rest_route( OSB_Rest::NS, '/admin/cal/action', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'action' ), 'permission_callback' => $admin ) );
	}

	/**
	 * Kinds of booking the calendar can show (only switched-on features).
	 *
	 * @return array key => [ label, colour ]
	 */
	public static function types() {
		$t = array( 'service' => array( __( 'Appointments', 'osam-bookings' ), '#0273b0' ) );
		if ( OSB_Settings::feature( 'events' ) ) {
			$t['event'] = array( __( 'Events', 'osam-bookings' ), '#e8590c' );
		}
		if ( OSB_Settings::feature( 'courses' ) ) {
			$t['course'] = array( __( 'Courses', 'osam-bookings' ), '#7048e8' );
		}
		if ( OSB_Settings::feature( 'resources' ) ) {
			$t['resource'] = array( __( 'Resources', 'osam-bookings' ), '#0c8599' );
		}
		if ( OSB_Settings::feature( 'restaurant' ) ) {
			$t['table'] = array( __( 'Tables', 'osam-bookings' ), '#c2255c' );
		}
		return $t;
	}

	/**
	 * Extra information layers (only those that apply to this site).
	 *
	 * @return array key => [ label, help ]
	 */
	public static function layers() {
		$l = array();
		if ( OSB_Settings::feature( 'resources' ) ) {
			$l['maintenance'] = array( __( 'Maintenance', 'osam-bookings' ), __( 'Resource maintenance times', 'osam-bookings' ) );
		}
		$l['closures'] = array( OSB_DB::is_solo() ? __( 'Closures & days off', 'osam-bookings' ) : __( 'Closures & staff days off', 'osam-bookings' ), __( 'Business closures, days off, closed resources and restaurant closures', 'osam-bookings' ) );
		if ( OSB_Settings::feature( 'calendar_sync' ) && OSB_Calendar_Sync::has_connections() ) {
			$l['busy'] = array( __( 'Google / Outlook busy', 'osam-bookings' ), __( 'Busy times from connected calendars', 'osam-bookings' ) );
		}
		return $l;
	}

	/**
	 * Local datetime from a FullCalendar ISO string.
	 *
	 * @param string $iso ISO date or datetime.
	 * @return string Y-m-d H:i:s
	 */
	private static function local( $iso ) {
		$iso = sanitize_text_field( (string) $iso );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}(?::\d{2})?))?/', $iso, $m ) ) {
			return $m[1] . ' ' . ( isset( $m[2] ) ? substr( $m[2] . ':00', 0, 8 ) : '00:00:00' );
		}
		return current_time( 'mysql' );
	}

	/**
	 * ISO for FullCalendar.
	 *
	 * @param string|int $v Local datetime or naive timestamp.
	 * @return string
	 */
	private static function iso( $v ) {
		return is_int( $v ) ? gmdate( 'Y-m-d\TH:i:s', $v ) : str_replace( ' ', 'T', (string) $v );
	}

	/**
	 * Lighter text colour choice for a background.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	private static function text_on( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			return '#ffffff';
		}
		$l = ( 0.299 * hexdec( substr( $hex, 0, 2 ) ) + 0.587 * hexdec( substr( $hex, 2, 2 ) ) + 0.114 * hexdec( substr( $hex, 4, 2 ) ) ) / 255;
		return $l > 0.68 ? '#1f2933' : '#ffffff';
	}

	/**
	 * GET /admin/cal/feed — everything between start and end.
	 *
	 * Params: start, end, types (comma list), layers (comma list), who (staff:ID | resource:ID | ''),
	 * cancelled (1 to include), q (customer search: shows matching bookings only).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function feed( WP_REST_Request $req ) {
		global $wpdb;
		$from      = self::local( $req->get_param( 'start' ) );
		$to        = self::local( $req->get_param( 'end' ) );
		$types     = array_intersect( array_map( 'sanitize_key', explode( ',', (string) $req->get_param( 'types' ) ) ), array_keys( self::types() ) );
		$layers    = array_intersect( array_map( 'sanitize_key', explode( ',', (string) $req->get_param( 'layers' ) ) ), array_keys( self::layers() ) );
		$who       = sanitize_text_field( (string) $req->get_param( 'who' ) );
		$staff_id  = 0 === strpos( $who, 'staff:' ) ? absint( substr( $who, 6 ) ) : 0;
		$res_id    = 0 === strpos( $who, 'resource:' ) ? absint( substr( $who, 9 ) ) : 0;
		$cancelled = (bool) $req->get_param( 'cancelled' );
		$q         = trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) );
		$names     = OSB_Admin_Dashboard::name_maps();
		$out       = array();

		// A person's diary has no resources or tables; a resource's has nothing else.
		if ( $staff_id ) {
			$types = array_diff( $types, array( 'resource', 'table' ) );
		}
		if ( $res_id ) {
			$types = array_intersect( $types, array( 'resource' ) );
		}

		/* ---------- Bookings ---------- */
		$btypes = $types;
		if ( '' === $q ) {
			// Events and courses show as one block each (below), not one entry per booking.
			$btypes = array_diff( $btypes, array( 'event', 'course' ) );
		}
		if ( $btypes ) {
			$t      = OSB_Install::table( 'bookings' );
			$hidden = OSB_Bookings::hidden_statuses();
			if ( ! $cancelled ) {
				$hidden[] = 'cancelled';
			}
			$where = array( 'start_at < %s', 'end_at > %s', 'type IN (' . implode( ',', array_fill( 0, count( $btypes ), '%s' ) ) . ')', 'status NOT IN (' . implode( ',', array_fill( 0, count( $hidden ), '%s' ) ) . ')' );
			$args  = array_merge( array( $to, $from ), array_values( $btypes ), $hidden );
			if ( $staff_id ) {
				$where[] = 'staff_id = %d';
				$args[]  = $staff_id;
			}
			if ( $res_id ) {
				$where[] = 'id IN (SELECT booking_id FROM ' . OSB_Install::table( 'booking_resources' ) . ' WHERE resource_id = %d)';
				$args[]  = $res_id;
			}
			if ( '' !== $q ) {
				$like    = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = '( customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR id = %d )';
				array_push( $args, $like, $like, $like, (int) preg_replace( '/\D/', '', $q ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_at ASC LIMIT 3000', $args ) );
			foreach ( (array) $rows as $b ) {
				$out[] = self::booking_event( $b, $names );
			}
		}

		if ( '' === $q ) {
			/* ---------- Events: one block each ---------- */
			if ( in_array( 'event', $types, true ) ) {
				$et = OSB_Install::table( 'events' );
				$sql  = "SELECT * FROM $et WHERE start_at < %s AND end_at > %s";
				$args = array( $to, $from );
				if ( $staff_id ) {
					$sql   .= ' AND staff_id = %d';
					$args[] = $staff_id;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY start_at ASC', $args ) ) as $e ) {
					$taken = OSB_DB::event_seats_taken( $e->id );
					$full  = $taken >= (int) $e->capacity;
					$out[] = array(
						'id'              => 'event-' . (int) $e->id,
						/* translators: 1: event 2: places taken 3: capacity */
						'title'           => sprintf( __( '%1$s · %2$d/%3$d', 'osam-bookings' ), $e->name, $taken, (int) $e->capacity ),
						'start'           => self::iso( $e->start_at ),
						'end'             => self::iso( $e->end_at ),
						'backgroundColor' => $e->color,
						'borderColor'     => $e->color,
						'textColor'       => self::text_on( $e->color ),
						'classNames'      => array_filter( array( 'osb-ev-block', 'active' !== $e->status ? 'osb-ev-hidden' : '', $full ? 'osb-ev-full' : '' ) ),
						'extendedProps'   => array(
							'kind'  => 'event',
							'ref'   => (int) $e->id,
							'type'  => 'event',
							'sub'   => $full ? __( 'Full', 'osam-bookings' ) : sprintf( /* translators: %d places */ _n( '%d place left', '%d places left', (int) $e->capacity - $taken, 'osam-bookings' ), (int) $e->capacity - $taken ),
							'staff' => isset( $names['staff'][ (int) $e->staff_id ] ) ? $names['staff'][ (int) $e->staff_id ] : '',
						),
					);
				}
			}

			/* ---------- Course sessions ---------- */
			if ( in_array( 'course', $types, true ) ) {
				$counts = array();
				$totals = array();
				foreach ( OSB_Courses::sessions_between( substr( $from, 0, 10 ), substr( $to, 0, 10 ), $staff_id ) as $s ) {
					$c = isset( $names['courses'][ (int) $s->course_id ] ) ? $names['courses'][ (int) $s->course_id ] : null;
					if ( ! $c ) {
						continue;
					}
					if ( ! isset( $counts[ (int) $s->intake_id ] ) ) {
						$counts[ (int) $s->intake_id ] = OSB_Courses::seats_taken( (int) $s->intake_id );
						$totals[ (int) $s->intake_id ] = count( OSB_Courses::sessions( (int) $s->intake_id ) );
					}
					$out[] = array(
						'id'              => 'session-' . (int) $s->id,
						/* translators: 1: course 2: enrolled */
						'title'           => sprintf( __( '%1$s · %2$d enrolled', 'osam-bookings' ), $c->name, $counts[ (int) $s->intake_id ] ),
						'start'           => self::iso( $s->start_at ),
						'end'             => self::iso( $s->end_at ),
						'backgroundColor' => $c->color,
						'borderColor'     => $c->color,
						'textColor'       => self::text_on( $c->color ),
						'classNames'      => array( 'osb-ev-block' ),
						'extendedProps'   => array(
							'kind'  => 'session',
							'ref'   => (int) $s->id,
							'type'  => 'course',
							'staff' => isset( $names['staff'][ (int) $s->staff_id ] ) ? $names['staff'][ (int) $s->staff_id ] : '',
						),
					);
				}
			}

			/* ---------- Layers ---------- */
			$from_ts = strtotime( $from );
			$to_ts   = strtotime( $to );
			$resources = OSB_Settings::feature( 'resources' ) ? OSB_Resources::all() : array();
			if ( $res_id ) {
				$resources = array_filter(
					$resources,
					static function ( $r ) use ( $res_id ) {
						return (int) $r->id === $res_id;
					}
				);
			}

			if ( in_array( 'maintenance', $layers, true ) && ! $staff_id ) {
				foreach ( $resources as $r ) {
					foreach ( OSB_Resources::blocks( $r->id, $from_ts, $to_ts ) as $i => $blk ) {
						$out[] = array(
							'id'            => 'maint-' . (int) $r->id . '-' . $blk[0],
							/* translators: 1: resource 2: note */
							'title'         => '' !== $blk[2] ? sprintf( __( '%1$s — %2$s', 'osam-bookings' ), $r->name, $blk[2] ) : sprintf( /* translators: %s resource */ __( '%s — maintenance', 'osam-bookings' ), $r->name ),
							'start'         => self::iso( (int) $blk[0] ),
							'end'           => self::iso( (int) $blk[1] ),
							// Whole days sit in the all-day row rather than filling the time grid.
							'allDay'        => '00:00' === gmdate( 'H:i', (int) $blk[0] ) && '00:00' === gmdate( 'H:i', (int) $blk[1] ),
							'classNames'    => array( 'osb-ev-maint' ),
							'extendedProps' => array(
								'kind' => 'maintenance',
								'info' => array(
									'title' => __( 'Maintenance', 'osam-bookings' ),
									'rows'  => array(
										array( __( 'Resource', 'osam-bookings' ), $r->name ),
										array( __( 'When', 'osam-bookings' ), self::span_label( (int) $blk[0], (int) $blk[1] ) ),
										array( __( 'Note', 'osam-bookings' ), '' !== $blk[2] ? $blk[2] : '—' ),
									),
									'link'  => array( __( 'Edit maintenance', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources&action=edit&id=' . (int) $r->id ) ),
								),
							),
						);
					}
				}
			}

			if ( in_array( 'closures', $layers, true ) ) {
				foreach ( self::closures( substr( $from, 0, 10 ), substr( $to, 0, 10 ), $staff_id, $res_id, $types, $resources, $names ) as $c ) {
					$out[] = $c;
				}
			}

			if ( in_array( 'busy', $layers, true ) && ! $res_id ) {
				$staff = $staff_id ? array( $staff_id ) : array_keys( $names['staff'] );
				foreach ( $staff as $sid ) {
					$conn = OSB_Calendar_Sync::connection_for( $sid );
					if ( ! $conn ) {
						continue;
					}
					$prov = OSB_Calendar_Sync::providers()[ $conn->provider ] ?? $conn->provider;
					foreach ( OSB_Calendar_Sync::busy_between( $sid, $from, $to ) as $k => $bz ) {
						$who_name = OSB_DB::is_solo() ? '' : ( $names['staff'][ $sid ] ?? '' );
						$out[]    = array(
							'id'            => 'busy-' . $sid . '-' . $k,
							'title'         => $who_name ? sprintf( /* translators: %s name */ __( '%s — busy', 'osam-bookings' ), $who_name ) : __( 'Busy', 'osam-bookings' ),
							'start'         => self::iso( $bz[0] ),
							'end'           => self::iso( $bz[1] ),
							'classNames'    => array( 'osb-ev-busy' ),
							'extendedProps' => array(
								'kind' => 'busy',
								'info' => array(
									'title' => __( 'Busy in their own calendar', 'osam-bookings' ),
									'rows'  => array_values(
										array_filter(
											array(
												$who_name ? array( __( 'Who', 'osam-bookings' ), $who_name ) : null,
												array( __( 'When', 'osam-bookings' ), self::span_label( strtotime( $bz[0] ), strtotime( $bz[1] ) ) ),
												array( __( 'From', 'osam-bookings' ), $prov . ( $conn->account ? ' (' . $conn->account . ')' : '' ) ),
											)
										)
									),
									'note'  => __( 'Online booking isn’t offered at this time. Details of the entry stay private in their calendar.', 'osam-bookings' ),
								),
							),
						);
					}
				}
			}
		}

		return rest_ensure_response( apply_filters( 'osb_calendar_feed', $out, $req ) );
	}

	/**
	 * One booking as a calendar entry.
	 *
	 * @param object $b     Booking.
	 * @param array  $names Name maps.
	 * @return array
	 */
	private static function booking_event( $b, array $names ) {
		$color = OSB_Admin_Dashboard::item_color( $b, $names );
		$item  = OSB_Admin_Dashboard::item_name( $b, $names );
		if ( 'table' === $b->type ) {
			$tables = OSB_Restaurant::booking_table_ids( $b->id );
			$title  = $b->customer_name . ' — ' . $item . ( $tables ? ' · ' . OSB_Restaurant::tables_label( $tables ) : '' );
		} else {
			$title = $b->customer_name . ' — ' . $item . ( (int) $b->seats > 1 && 'resource' !== $b->type ? ' ×' . (int) $b->seats : '' );
		}
		$classes = array( 'osb-ev-' . $b->status );
		if ( in_array( $b->status, array( 'pending', 'pending_payment' ), true ) ) {
			$classes[] = 'osb-ev-pending';
		}
		if ( 'unpaid' === $b->payment_status && (float) $b->amount > 0 && ! in_array( $b->status, array( 'cancelled' ), true ) ) {
			$classes[] = 'osb-ev-unpaid';
		}
		return array(
			'id'              => 'b-' . (int) $b->id,
			'title'           => $title,
			'start'           => self::iso( $b->start_at ),
			'end'             => self::iso( $b->end_at ),
			'backgroundColor' => $color,
			'borderColor'     => $color,
			'textColor'       => self::text_on( $color ),
			'classNames'      => $classes,
			'extendedProps'   => array(
				'kind'   => 'booking',
				'ref'    => (int) $b->id,
				'type'   => $b->type,
				'status' => $b->status,
				'staff'  => isset( $names['staff'][ (int) $b->staff_id ] ) && ! OSB_DB::is_solo() ? $names['staff'][ (int) $b->staff_id ] : '',
			),
		);
	}

	/**
	 * Closures and days off as all-day entries.
	 *
	 * @param string $from      Y-m-d.
	 * @param string $to        Y-m-d.
	 * @param int    $staff_id  Only this person (and whole-business closures).
	 * @param int    $res_id    Only this resource.
	 * @param array  $types     Types shown.
	 * @param array  $resources Resources in view.
	 * @param array  $names     Name maps.
	 * @return array
	 */
	private static function closures( $from, $to, $staff_id, $res_id, array $types, array $resources, array $names ) {
		global $wpdb;
		$out  = array();
		$add  = static function ( $id, $title, $date_from, $date_to, $class, array $rows, $link ) use ( &$out ) {
			$out[] = array(
				'id'            => $id,
				'title'         => $title,
				'start'         => $date_from,
				'end'           => gmdate( 'Y-m-d', strtotime( $date_to . ' 12:00:00' ) + DAY_IN_SECONDS ),
				'allDay'        => true,
				'classNames'    => array( 'osb-ev-closed', $class ),
				'extendedProps' => array(
					'kind' => 'closure',
					'info' => array(
						'title' => $title,
						'rows'  => $rows,
						'link'  => $link,
					),
				),
			);
		};
		$dates = static function ( $a, $b ) {
			$fa = date_i18n( 'D j M Y', strtotime( $a . ' 12:00:00' ) );
			return $a === $b ? $fa : $fa . ' – ' . date_i18n( 'D j M Y', strtotime( $b . ' 12:00:00' ) );
		};

		// Business closures and staff days off.
		if ( ! $res_id ) {
			$t    = OSB_Install::table( 'days_off' );
			$sql  = "SELECT * FROM $t WHERE date_from <= %s AND date_to >= %s";
			$args = array( $to, $from );
			if ( $staff_id ) {
				$sql   .= ' AND staff_id IN (0, %d)';
				$args[] = $staff_id;
			}
			$solo = OSB_DB::is_solo();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $d ) {
				$all  = 0 === (int) $d->staff_id || $solo;
				$name = $names['staff'][ (int) $d->staff_id ] ?? '';
				if ( $all ) {
					$title = '' !== $d->note ? sprintf( /* translators: %s note */ __( 'Closed — %s', 'osam-bookings' ), $d->note ) : __( 'Closed', 'osam-bookings' );
				} else {
					$title = '' !== $d->note ? sprintf( /* translators: 1: name 2: note */ __( '%1$s off — %2$s', 'osam-bookings' ), $name, $d->note ) : sprintf( /* translators: %s name */ __( '%s off', 'osam-bookings' ), $name );
				}
				$add(
					'off-' . (int) $d->id,
					$title,
					$d->date_from,
					$d->date_to,
					$all ? 'osb-ev-closed--all' : 'osb-ev-closed--staff',
					array_values( array_filter( array( array( __( 'Dates', 'osam-bookings' ), $dates( $d->date_from, $d->date_to ) ), $all ? null : array( __( 'Who', 'osam-bookings' ), $name ), array( __( 'Note', 'osam-bookings' ), '' !== $d->note ? $d->note : '—' ) ) ) ),
					array( __( 'Edit', 'osam-bookings' ), admin_url( 'admin.php?page=osb-staff' . ( $all || $solo ? '' : '&action=edit&id=' . (int) $d->staff_id ) ) )
				);
			}
		}

		// Resource closed dates.
		if ( OSB_Settings::feature( 'resources' ) && ! $staff_id && in_array( 'resource', $types, true ) ) {
			$t   = OSB_Install::table( 'res_closures' );
			$ids = array_map( 'intval', wp_list_pluck( $resources, 'id' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE date_from <= %s AND date_to >= %s", $to, $from ) ) as $c ) {
				$rid = (int) $c->resource_id;
				if ( $rid && ! in_array( $rid, $ids, true ) ) {
					continue;
				}
				$r     = $rid ? OSB_Resources::get( $rid ) : null;
				$label = $r ? $r->name : __( 'All resources', 'osam-bookings' );
				$add(
					'resclosed-' . (int) $c->id,
					sprintf( /* translators: %s resource */ __( '%s closed', 'osam-bookings' ), $label ) . ( '' !== $c->note ? ' — ' . $c->note : '' ),
					$c->date_from,
					$c->date_to,
					'osb-ev-closed--res',
					array( array( __( 'Resource', 'osam-bookings' ), $label ), array( __( 'Dates', 'osam-bookings' ), $dates( $c->date_from, $c->date_to ) ), array( __( 'Note', 'osam-bookings' ), '' !== $c->note ? $c->note : '—' ) ),
					array( __( 'Edit', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources' . ( $rid ? '&action=edit&id=' . $rid : '' ) ) )
				);
			}
		}

		// Restaurant closures.
		if ( OSB_Settings::feature( 'restaurant' ) && ! $staff_id && ! $res_id && in_array( 'table', $types, true ) ) {
			$t       = OSB_Install::table( 'rest_closures' );
			$periods = array();
			foreach ( OSB_Restaurant::periods() as $p ) {
				$periods[ (int) $p->id ] = $p->name;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE date_from <= %s AND date_to >= %s", $to, $from ) ) as $c ) {
				$what = (int) $c->period_id && isset( $periods[ (int) $c->period_id ] ) ? sprintf( /* translators: %s service period */ __( 'Restaurant %s closed', 'osam-bookings' ), $periods[ (int) $c->period_id ] ) : __( 'Restaurant closed', 'osam-bookings' );
				$add(
					'restclosed-' . (int) $c->id,
					$what . ( '' !== $c->note ? ' — ' . $c->note : '' ),
					$c->date_from,
					$c->date_to,
					'osb-ev-closed--rest',
					array( array( __( 'Dates', 'osam-bookings' ), $dates( $c->date_from, $c->date_to ) ), array( __( 'Note', 'osam-bookings' ), '' !== $c->note ? $c->note : '—' ) ),
					array( __( 'Edit', 'osam-bookings' ), admin_url( 'admin.php?page=osb-rest-hours' ) )
				);
			}
		}
		return $out;
	}

	/**
	 * "Mon 3 Nov, 08:00 – 10:00".
	 *
	 * @param int $s Naive start.
	 * @param int $e Naive end.
	 * @return string
	 */
	private static function span_label( $s, $e ) {
		$tf = get_option( 'time_format' );
		if ( gmdate( 'Y-m-d', $s ) === gmdate( 'Y-m-d', $e - 1 ) ) {
			return date_i18n( 'D j M Y', $s ) . ', ' . date_i18n( $tf, $s ) . ' – ' . date_i18n( $tf, $e );
		}
		return date_i18n( 'D j M, ' . $tf, $s ) . ' – ' . date_i18n( 'D j M Y, ' . $tf, $e );
	}

	/* ---------------------------------------------------------------------
	 * Detail panel
	 * ------------------------------------------------------------------ */

	/**
	 * GET /admin/cal/item?kind=booking|event|session&id=
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function item( WP_REST_Request $req ) {
		$kind = sanitize_key( (string) $req->get_param( 'kind' ) );
		$id   = absint( $req->get_param( 'id' ) );
		ob_start();
		if ( 'event' === $kind ) {
			$ok = self::event_panel( $id );
		} elseif ( 'session' === $kind ) {
			$ok = self::session_panel( $id );
		} else {
			$ok = self::booking_panel( $id );
		}
		$html = ob_get_clean();
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'html' => '<p class="osb-muted">' . esc_html__( 'This has been deleted.', 'osam-bookings' ) . '</p>' ), 404 );
		}
		return rest_ensure_response( array( 'html' => $html ) );
	}

	/**
	 * Definition-list rows.
	 *
	 * @param array $rows [ [ label, value (HTML-escaped already when $raw) ], … ].
	 * @param bool  $raw  Values are HTML.
	 */
	private static function dl( array $rows, $raw = false ) {
		echo '<dl class="osb-cpanel__dl">';
		foreach ( $rows as $r ) {
			if ( null === $r || '' === (string) $r[1] ) {
				continue;
			}
			echo '<dt>' . esc_html( $r[0] ) . '</dt><dd>' . ( $raw ? wp_kses_post( $r[1] ) : esc_html( $r[1] ) ) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Booking detail.
	 *
	 * @param int $id Booking.
	 * @return bool
	 */
	private static function booking_panel( $id ) {
		$b = OSB_DB::get( 'bookings', $id );
		if ( ! $b ) {
			return false;
		}
		$d      = OSB_Bookings::details( $b );
		$fields = OSB_Bookings::fields( $b );
		$types  = self::types();
		$tlabel = 'bundle' === $b->type ? __( 'Bundle', 'osam-bookings' ) : ( isset( $types[ $b->type ] ) ? $types[ $b->type ][0] : ucfirst( $b->type ) );
		$view   = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $b->id );
		$tf     = get_option( 'time_format' );
		$s      = strtotime( $b->start_at );
		$e      = strtotime( $b->end_at );
		$when   = 'course' === $b->type ? sprintf( /* translators: 1: intake 2: count 3: start date */ __( '%1$s · %2$d sessions from %3$s', 'osam-bookings' ), $d['intake_name'], $d['session_count'], date_i18n( 'D j M Y', $s ) ) : self::span_label( $s, $e );
		?>
		<div class="osb-cpanel__head">
			<span class="osb-cpanel__type" style="--c:<?php echo esc_attr( OSB_Admin_Dashboard::item_color( $b, OSB_Admin_Dashboard::name_maps() ) ); ?>"><?php echo esc_html( $tlabel ); ?></span>
			<?php echo OSB_Admin::status_pill( $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h2><?php echo esc_html( $d['item_name'] ); ?><?php echo (int) $b->seats > 1 && ! in_array( $b->type, array( 'resource' ), true ) ? ' <span class="osb-muted">×' . (int) $b->seats . '</span>' : ''; ?></h2>
			<p class="osb-cpanel__when"><span class="dashicons dashicons-clock"></span><?php echo esc_html( $when ); ?></p>
			<p class="osb-muted">#<?php echo (int) $b->id; ?> · <?php /* translators: %s date */ echo esc_html( sprintf( __( 'booked %s', 'osam-bookings' ), mysql2date( 'j M Y, ' . $tf, $b->created_at ) ) ); ?></p>
		</div>

		<?php
		$what = array(
			array( __( 'With', 'osam-bookings' ), $d['staff_name'] ),
			array( __( 'Hire', 'osam-bookings' ), isset( $d['hire_label'] ) ? $d['hire_label'] . ( ! empty( $d['exclusive'] ) ? ' · ' . __( 'booked out', 'osam-bookings' ) : '' ) : '' ),
			array( __( 'Tables', 'osam-bookings' ), isset( $d['tables'] ) ? (string) $d['tables'] : '' ),
			array( __( 'Area', 'osam-bookings' ), isset( $d['area_name'] ) ? (string) $d['area_name'] : '' ),
			array( __( 'Where', 'osam-bookings' ), 'table' === $b->type ? '' : (string) $d['location'] ),
			array( __( 'Repeats', 'osam-bookings' ), isset( $d['series_label'] ) ? (string) $d['series_label'] : '' ),
		);
		if ( $what = array_values( array_filter( $what, static function ( $r ) { return '' !== (string) $r[1]; } ) ) ) {
			self::dl( $what );
		}
		if ( ! empty( $d['meeting_url'] ) ) {
			echo '<p><a class="osb-btn osb-btn--ghost osb-btn--sm" href="' . esc_url( $d['meeting_url'] ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-video-alt2"></span>' . esc_html__( 'Online meeting link', 'osam-bookings' ) . '</a></p>';
		}
		?>

		<h3><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></h3>
		<?php
		$cust = array(
			array( __( 'Name', 'osam-bookings' ), esc_html( $b->customer_name ) ),
			array( __( 'Email', 'osam-bookings' ), $b->customer_email ? '<a href="mailto:' . esc_attr( $b->customer_email ) . '">' . esc_html( $b->customer_email ) . '</a>' : '' ),
			array( __( 'Phone', 'osam-bookings' ), $b->customer_phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $b->customer_phone ) ) . '">' . esc_html( $b->customer_phone ) . '</a>' : '' ),
		);
		foreach ( OSB_Settings::form_fields() as $f ) {
			if ( in_array( $f['key'], array( 'name', 'email', 'phone' ), true ) || ! isset( $fields[ $f['key'] ] ) || '' === (string) $fields[ $f['key'] ] ) {
				continue;
			}
			$v      = $fields[ $f['key'] ];
			$v      = 'checkbox' === $f['type'] ? ( $v ? __( 'Yes', 'osam-bookings' ) : __( 'No', 'osam-bookings' ) ) : ( is_scalar( $v ) ? (string) $v : '' );
			$cust[] = array( $f['label'], nl2br( esc_html( $v ) ) );
		}
		self::dl( $cust, true );
		if ( OSB_Settings::feature( 'customers' ) && $b->customer_email ) {
			echo '<p class="osb-cpanel__more"><a href="' . esc_url( OSB_Admin_Customers::url( $b->customer_email ) ) . '">' . esc_html__( 'Customer history', 'osam-bookings' ) . ' →</a></p>';
		}
		if ( (int) $b->seats > 1 && ! empty( $d['attendees'] ) ) {
			echo '<h3>' . esc_html__( 'Places', 'osam-bookings' ) . '</h3><ol class="osb-cpanel__list">';
			foreach ( OSB_Bookings::attendees( $b ) as $a ) {
				echo '<li>' . esc_html( $a['same'] ? $b->customer_name . ' (' . __( 'same details', 'osam-bookings' ) . ')' : ( '' !== $a['name'] ? $a['name'] : '—' ) ) . '</li>';
			}
			echo '</ol>';
		}
		if ( 'course' === $b->type && ! empty( $d['schedule'] ) ) {
			echo '<h3>' . esc_html__( 'Course dates', 'osam-bookings' ) . '</h3><ol class="osb-cpanel__list">';
			foreach ( $d['schedule'] as $sess ) {
				echo '<li>' . esc_html( $sess['date_label'] . ', ' . $sess['time_label'] ) . '</li>';
			}
			echo '</ol>';
		}

		// Access codes (resources).
		if ( 'resource' === $b->type && class_exists( 'OSB_Access' ) ) {
			$codes = OSB_Access::codes( $b, true );
			if ( $codes['shown'] ) {
				echo '<h3>' . esc_html__( 'Access', 'osam-bookings' ) . '</h3>';
				$rows = array();
				foreach ( $codes['shown'] as $c ) {
					$rows[] = array( $c[0] . ' · ' . $c[1], '<code>' . esc_html( $c[2] ) . '</code>' );
				}
				self::dl( $rows, true );
			}
		}

		// Payment.
		echo '<h3>' . esc_html__( 'Payment', 'osam-bookings' ) . '</h3>';
		$pay = array(
			array( __( 'Amount', 'osam-bookings' ), esc_html( $d['amount'] ) . ( $d['discount'] ? ' <span class="osb-muted">(' . esc_html( sprintf( /* translators: 1: discount 2: code */ __( '−%1$s %2$s', 'osam-bookings' ), $d['discount'], $d['coupon_code'] ) ) . ')</span>' : '' ) ),
			array( __( 'Method', 'osam-bookings' ), esc_html( $d['payment_method'] ) ),
			array( __( 'Status', 'osam-bookings' ), esc_html( $d['payment_status'] ) ),
		);
		if ( ! empty( $d['addons'] ) ) {
			$pay[] = array( __( 'Add-ons', 'osam-bookings' ), esc_html( implode( ', ', wp_list_pluck( $d['addons'], 'name' ) ) ) );
		}
		if ( ! empty( $d['pass_label'] ) ) {
			$pay[] = array( __( 'Paid with', 'osam-bookings' ), esc_html( $d['pass_label'] ) );
		}
		if ( class_exists( 'OSB_Invoices' ) && OSB_Invoices::enabled() ) {
			$links = array();
			foreach ( OSB_Invoices::for_booking( $b ) as $doc ) {
				$links[] = '<a href="' . esc_url( OSB_Admin_Invoices::url( $doc->id ) ) . '">' . esc_html( $doc->number ) . '</a>';
			}
			$pay[] = array( __( 'Invoices', 'osam-bookings' ), implode( ', ', $links ) );
		}
		self::dl( $pay, true );

		if ( '' !== trim( (string) $b->internal_note ) ) {
			echo '<h3>' . esc_html__( 'Staff note', 'osam-bookings' ) . '</h3><p class="osb-cpanel__note">' . nl2br( esc_html( $b->internal_note ) ) . '</p>';
		}

		// Warnings (clashes).
		$warn = 'resource' === $b->type ? OSB_Resources::conflicts( $b ) : array();
		foreach ( $warn as $w ) {
			echo '<p class="osb-cpanel__warn"><span class="dashicons dashicons-warning"></span>' . esc_html( $w ) . '</p>';
		}

		self::actions( $b, $view );
		return true;
	}

	/**
	 * Quick actions for a booking.
	 *
	 * @param object $b    Booking.
	 * @param string $view Full booking URL.
	 */
	private static function actions( $b, $view ) {
		$acts = array();
		if ( 'pending' === $b->status ) {
			$acts['confirmed'] = array( __( 'Confirm', 'osam-bookings' ), 'primary', 'dashicons-yes' );
		}
		$upcoming = strtotime( $b->end_at ) > strtotime( current_time( 'mysql' ) );
		if ( in_array( $b->status, array( 'confirmed', 'pending' ), true ) && ! $upcoming && 'bundle' !== $b->type ) {
			$acts['completed'] = array( __( 'Completed', 'osam-bookings' ), 'ghost', 'dashicons-saved' );
			$acts['no_show']   = array( __( 'No-show', 'osam-bookings' ), 'ghost', 'dashicons-dismiss' );
		}
		if ( in_array( $b->payment_status, array( 'unpaid' ), true ) && (float) $b->amount > 0 && ! in_array( $b->status, array( 'cancelled', 'payment_failed' ), true ) ) {
			$acts['paid'] = array( __( 'Mark paid', 'osam-bookings' ), 'ghost', 'dashicons-money-alt' );
		}
		if ( in_array( $b->status, array( 'confirmed', 'pending', 'pending_payment' ), true ) ) {
			$acts['cancelled'] = array( __( 'Cancel booking', 'osam-bookings' ), 'danger', 'dashicons-no' );
		}
		?>
		<div class="osb-cpanel__actions" data-booking="<?php echo (int) $b->id; ?>">
			<?php if ( $acts ) : ?>
				<div class="osb-cpanel__btns">
					<?php foreach ( $acts as $k => $a ) : ?>
						<button type="button" class="osb-btn osb-btn--sm osb-btn--<?php echo esc_attr( 'primary' === $a[1] ? 'primary' : 'ghost' ); ?><?php echo 'danger' === $a[1] ? ' osb-btn--danger' : ''; ?>" data-cal-act="<?php echo esc_attr( $k ); ?>"><span class="dashicons <?php echo esc_attr( $a[2] ); ?>"></span><?php echo esc_html( $a[0] ); ?></button>
					<?php endforeach; ?>
				</div>
				<label class="osb-switch osb-cpanel__notify"><input type="checkbox" class="osb-cal-notify" checked><span></span><?php esc_html_e( 'Email the customer', 'osam-bookings' ); ?></label>
			<?php endif; ?>
			<a class="osb-btn osb-btn--ghost osb-cpanel__open" href="<?php echo esc_url( $view ); ?>"><?php esc_html_e( 'Open full booking', 'osam-bookings' ); ?> →</a>
		</div>
		<?php
	}

	/**
	 * Event detail: who's coming.
	 *
	 * @param int $id Event.
	 * @return bool
	 */
	private static function event_panel( $id ) {
		$e = OSB_DB::get( 'events', $id );
		if ( ! $e ) {
			return false;
		}
		$names = OSB_Admin_Dashboard::name_maps();
		list( $rows ) = OSB_DB::query_bookings( array( 'event_id' => $id, 'per_page' => 500, 'order' => 'asc', 'orderby' => 'created_at' ) );
		$taken  = OSB_DB::event_seats_taken( $id );
		$wait   = OSB_Settings::feature( 'waitlist' ) ? count( OSB_Waitlist::entries( 'event', $id ) ) : 0;
		$rev    = 0;
		foreach ( $rows as $b ) {
			if ( 'paid' === $b->payment_status ) {
				$rev += (float) $b->amount;
			}
		}
		?>
		<div class="osb-cpanel__head">
			<span class="osb-cpanel__type" style="--c:<?php echo esc_attr( $e->color ); ?>"><?php esc_html_e( 'Event', 'osam-bookings' ); ?></span>
			<?php if ( 'active' !== $e->status ) : ?><span class="osb-pill osb-pill--neutral"><?php esc_html_e( 'Hidden', 'osam-bookings' ); ?></span><?php endif; ?>
			<h2><?php echo esc_html( $e->name ); ?></h2>
			<p class="osb-cpanel__when"><span class="dashicons dashicons-clock"></span><?php echo esc_html( self::span_label( strtotime( $e->start_at ), strtotime( $e->end_at ) ) ); ?></p>
		</div>
		<div class="osb-cpanel__meter"><span style="width:<?php echo esc_attr( min( 100, (int) $e->capacity ? round( $taken / (int) $e->capacity * 100 ) : 0 ) ); ?>%"></span></div>
		<?php
		self::dl(
			array(
				/* translators: 1: taken 2: capacity */
				array( __( 'Places', 'osam-bookings' ), sprintf( __( '%1$d of %2$d booked', 'osam-bookings' ), $taken, (int) $e->capacity ) ),
				array( __( 'Waitlist', 'osam-bookings' ), $wait ? (string) $wait : '' ),
				array( __( 'Price', 'osam-bookings' ), OSB_Settings::money( $e->price ) ),
				array( __( 'Paid so far', 'osam-bookings' ), OSB_Settings::money( $rev ) ),
				array( __( 'Host', 'osam-bookings' ), OSB_DB::is_solo() ? '' : ( $names['staff'][ (int) $e->staff_id ] ?? '' ) ),
				array( __( 'Where', 'osam-bookings' ), (string) $e->location ),
			)
		);
		?>
		<h3><?php /* translators: %d count */ echo esc_html( sprintf( __( 'Bookings (%d)', 'osam-bookings' ), count( $rows ) ) ); ?></h3>
		<?php self::booking_list( $rows ); ?>
		<div class="osb-cpanel__actions">
			<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=new&type=event&event_id=' . (int) $e->id ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add booking', 'osam-bookings' ); ?></a>
			<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-events&action=edit&id=' . (int) $e->id ) ); ?>"><?php esc_html_e( 'Edit event', 'osam-bookings' ); ?> →</a>
		</div>
		<?php
		return true;
	}

	/**
	 * Course session detail.
	 *
	 * @param int $id Session.
	 * @return bool
	 */
	private static function session_panel( $id ) {
		$s = OSB_DB::get( 'course_sessions', $id );
		if ( ! $s ) {
			return false;
		}
		$intake = OSB_DB::get( 'course_intakes', (int) $s->intake_id );
		$course = OSB_DB::get( 'courses', (int) $s->course_id );
		if ( ! $intake || ! $course ) {
			return false;
		}
		$names    = OSB_Admin_Dashboard::name_maps();
		$sessions = OSB_Courses::sessions( (int) $intake->id );
		$n        = 1;
		foreach ( $sessions as $i => $x ) {
			if ( (int) $x->id === (int) $s->id ) {
				$n = $i + 1;
			}
		}
		$rows = OSB_Courses::enrolments( (int) $intake->id );
		$cap  = OSB_Courses::capacity( $intake, $course );
		?>
		<div class="osb-cpanel__head">
			<span class="osb-cpanel__type" style="--c:<?php echo esc_attr( $course->color ); ?>"><?php esc_html_e( 'Course session', 'osam-bookings' ); ?></span>
			<h2><?php echo esc_html( $course->name ); ?></h2>
			<p class="osb-cpanel__when"><span class="dashicons dashicons-clock"></span><?php echo esc_html( self::span_label( strtotime( $s->start_at ), strtotime( $s->end_at ) ) ); ?></p>
		</div>
		<?php
		self::dl(
			array(
				array( __( 'Intake', 'osam-bookings' ), OSB_Courses::intake_label( $intake ) ),
				/* translators: 1: number 2: total */
				array( __( 'Session', 'osam-bookings' ), sprintf( __( '%1$d of %2$d', 'osam-bookings' ), $n, count( $sessions ) ) ),
				/* translators: 1: taken 2: capacity */
				array( __( 'Enrolled', 'osam-bookings' ), sprintf( __( '%1$d of %2$d places', 'osam-bookings' ), OSB_Courses::seats_taken( (int) $intake->id ), (int) $cap ) ),
				array( __( 'Tutor', 'osam-bookings' ), OSB_DB::is_solo() ? '' : ( $names['staff'][ (int) $s->staff_id ] ?? '' ) ),
				array( __( 'Where', 'osam-bookings' ), ! empty( $s->location ) ? (string) $s->location : (string) $course->location ),
				array( __( 'Note', 'osam-bookings' ), ! empty( $s->note ) ? (string) $s->note : '' ),
			)
		);
		?>
		<h3><?php /* translators: %d count */ echo esc_html( sprintf( __( 'Enrolled (%d)', 'osam-bookings' ), count( $rows ) ) ); ?></h3>
		<?php self::booking_list( $rows ); ?>
		<div class="osb-cpanel__actions">
			<a class="osb-btn osb-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $intake->id ) ); ?>"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Take the register', 'osam-bookings' ); ?></a>
			<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-courses&action=edit&id=' . (int) $course->id ) ); ?>"><?php esc_html_e( 'Edit course', 'osam-bookings' ); ?> →</a>
		</div>
		<?php
		return true;
	}

	/**
	 * Bookings as a clickable list (opens each in the panel).
	 *
	 * @param array $rows Bookings.
	 */
	private static function booking_list( array $rows ) {
		if ( ! $rows ) {
			echo '<p class="osb-muted">' . esc_html__( 'Nobody yet.', 'osam-bookings' ) . '</p>';
			return;
		}
		echo '<ul class="osb-cpanel__people">';
		foreach ( $rows as $b ) {
			if ( in_array( $b->status, OSB_Bookings::hidden_statuses(), true ) ) {
				continue;
			}
			$pay = 'paid' === $b->payment_status ? __( 'Paid', 'osam-bookings' ) : ( 'unpaid' === $b->payment_status && (float) $b->amount > 0 ? __( 'Unpaid', 'osam-bookings' ) : '' );
			printf(
				'<li class="%s"><button type="button" class="osb-cpanel__person" data-cal-open="%d"><strong>%s</strong><small>%s</small></button>%s</li>',
				esc_attr( 'cancelled' === $b->status ? 'is-cancelled' : '' ),
				(int) $b->id,
				esc_html( $b->customer_name ),
				esc_html( implode( ' · ', array_filter( array( (int) $b->seats > 1 ? sprintf( /* translators: %d places */ _n( '%d place', '%d places', (int) $b->seats, 'osam-bookings' ), (int) $b->seats ) : '', $pay, OSB_Bookings::statuses()[ $b->status ] ?? $b->status ) ) ) ),
				OSB_Admin::status_pill( $b->status ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * POST /admin/cal/action { id, act: confirmed|completed|no_show|cancelled|paid, notify }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function action( WP_REST_Request $req ) {
		$id     = absint( $req->get_param( 'id' ) );
		$act    = sanitize_key( (string) $req->get_param( 'act' ) );
		$notify = (bool) $req->get_param( 'notify' );
		$b      = OSB_DB::get( 'bookings', $id );
		if ( ! $b ) {
			return new WP_REST_Response( array( 'message' => __( 'Booking not found.', 'osam-bookings' ) ), 404 );
		}
		$msg = '';
		if ( 'paid' === $act ) {
			if ( 'paid' !== $b->payment_status && (float) $b->amount > 0 ) {
				OSB_DB::update( 'bookings', $b->id, array( 'payment_status' => 'paid', 'updated_at' => current_time( 'mysql' ) ) );
				$pid = OSB_DB::add_payment( $b->id, 'manual', 'admin-' . get_current_user_id() . '-' . time(), $b->amount, $b->currency, 'completed', array( 'by' => get_current_user_id() ) );
				if ( $pid && class_exists( 'OSB_Invoices' ) ) {
					OSB_Invoices::on_manual_payment( OSB_DB::get( 'bookings', $b->id ), (int) $pid, $notify );
				}
				do_action( 'osb_booking_admin_updated', OSB_DB::get( 'bookings', $b->id ) );
			}
			$msg = __( 'Marked as paid.', 'osam-bookings' );
		} elseif ( in_array( $act, array( 'confirmed', 'completed', 'no_show', 'cancelled' ), true ) ) {
			if ( 'cancelled' === $act && class_exists( 'OSB_Repeat' ) && OSB_Repeat::is_lead( $b ) && $req->get_param( 'series' ) ) {
				OSB_Repeat::cancel_from( $b, false );
			} else {
				OSB_Bookings::set_status( $b->id, $act, $notify );
			}
			$msg = array(
				'confirmed' => __( 'Confirmed.', 'osam-bookings' ),
				'completed' => __( 'Marked as completed.', 'osam-bookings' ),
				'no_show'   => __( 'Marked as a no-show.', 'osam-bookings' ),
				'cancelled' => __( 'Booking cancelled.', 'osam-bookings' ),
			)[ $act ];
		} else {
			return new WP_REST_Response( array( 'message' => __( 'Unknown action.', 'osam-bookings' ) ), 400 );
		}
		ob_start();
		self::booking_panel( $b->id );
		return rest_ensure_response(
			array(
				'message' => $msg,
				'html'    => ob_get_clean(),
			)
		);
	}
}
