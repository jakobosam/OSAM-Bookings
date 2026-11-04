<?php
/**
 * Slot availability engine.
 *
 * All datetimes are in the site timezone (Settings > General) and stored as local "Y-m-d H:i:s".
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out which start times are bookable.
 */
class OSB_Availability {

	/**
	 * Per-request booking cache.
	 *
	 * @var array
	 */
	private static $bookings_cache = array();

	/**
	 * Services map for buffer lookups.
	 *
	 * @var array|null
	 */
	private static $services_map = null;

	/**
	 * Booking to ignore while working out free times (the one a customer is moving).
	 *
	 * @var int
	 */
	public static $exclude_booking = 0;

	/**
	 * Clear caches (after a booking is written).
	 */
	public static function flush() {
		self::$bookings_cache = array();
		self::$services_map   = null;
	}

	/**
	 * Site timezone.
	 *
	 * @return DateTimeZone
	 */
	public static function tz() {
		return wp_timezone();
	}

	/**
	 * Current local timestamp as DateTimeImmutable.
	 *
	 * @return DateTimeImmutable
	 */
	public static function now() {
		return new DateTimeImmutable( 'now', self::tz() );
	}

	/**
	 * Parse a local datetime string.
	 *
	 * @param string $value Y-m-d H:i[:s].
	 * @return DateTimeImmutable|null
	 */
	public static function parse( $value ) {
		try {
			return new DateTimeImmutable( $value, self::tz() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Map of services keyed by id.
	 *
	 * @return array
	 */
	private static function services_map() {
		if ( null === self::$services_map ) {
			self::$services_map = array();
			foreach ( OSB_DB::services() as $service ) {
				self::$services_map[ (int) $service->id ] = $service;
			}
		}
		return self::$services_map;
	}

	/**
	 * Booking window bounds.
	 *
	 * @return array [ DateTimeImmutable earliest_start, DateTimeImmutable last_day_end ]
	 */
	public static function window() {
		$now      = self::now();
		$earliest = $now->modify( '+' . max( 0, (float) OSB_Settings::get( 'min_notice_hours' ) * 60 ) . ' minutes' );
		$latest   = $now->setTime( 23, 59, 59 )->modify( '+' . max( 0, (int) OSB_Settings::get( 'max_advance_days' ) ) . ' days' );
		return array( $earliest, $latest );
	}

	/**
	 * Candidate staff for a service.
	 *
	 * @param int $service_id Service.
	 * @param int $staff_id   0 = anyone.
	 * @return int[]
	 */
	public static function candidate_staff( $service_id, $staff_id = 0 ) {
		$ids = wp_list_pluck( OSB_DB::staff_for_service( $service_id ), 'id' );
		$ids = array_map( 'intval', $ids );
		if ( $staff_id ) {
			return in_array( (int) $staff_id, $ids, true ) ? array( (int) $staff_id ) : array();
		}
		return $ids;
	}

	/**
	 * Bookings around a date for a staff member (cached).
	 *
	 * @param int    $staff_id Staff.
	 * @param string $date     Y-m-d.
	 * @return array
	 */
	private static function busy_for( $staff_id, $date ) {
		$key = $staff_id . '|' . $date . '|' . self::$exclude_booking;
		if ( ! isset( self::$bookings_cache[ $key ] ) ) {
			$from  = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00 -1 day' ) );
			$to    = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00 +2 days' ) );
			$items = array();
			$map   = self::services_map();

			foreach ( OSB_DB::staff_bookings_between( $staff_id, $from, $to ) as $b ) {
				if ( self::$exclude_booking && (int) $b->id === self::$exclude_booking ) {
					continue;
				}
				$svc     = ( $b->service_id && isset( $map[ (int) $b->service_id ] ) ) ? $map[ (int) $b->service_id ] : null;
				$items[] = array(
					'start'      => strtotime( $b->start_at ),
					'end'        => strtotime( $b->end_at ),
					'bb'         => $svc ? (int) $svc->buffer_before * 60 : 0,
					'ba'         => $svc ? (int) $svc->buffer_after * 60 : 0,
					'service_id' => (int) $b->service_id,
					'seats'      => (int) $b->seats,
				);
			}
			foreach ( OSB_DB::staff_events_between( $staff_id, $from, $to ) as $e ) {
				$items[] = array(
					'start'      => strtotime( $e->start_at ),
					'end'        => strtotime( $e->end_at ),
					'bb'         => 0,
					'ba'         => 0,
					'service_id' => 0,
					'seats'      => 0,
				);
			}
			foreach ( OSB_Courses::staff_sessions_between( $staff_id, $from, $to ) as $cs ) {
				$items[] = array(
					'start'      => strtotime( $cs->start_at ),
					'end'        => strtotime( $cs->end_at ),
					'bb'         => 0,
					'ba'         => 0,
					'service_id' => 0,
					'seats'      => 0,
				);
			}
			// Busy times from their connected Google / Outlook calendar.
			foreach ( OSB_Calendar_Sync::busy_between( $staff_id, $from, $to ) as $busy ) {
				$items[] = array(
					'start'      => strtotime( $busy[0] ),
					'end'        => strtotime( $busy[1] ),
					'bb'         => 0,
					'ba'         => 0,
					'service_id' => 0,
					'seats'      => 0,
				);
			}
			self::$bookings_cache[ $key ] = $items;
		}
		return self::$bookings_cache[ $key ];
	}

	/**
	 * Check one staff member at one start time.
	 *
	 * Timestamps here are "naive local" (strtotime on local strings, UTC-interpreted) so they
	 * compare consistently with stored values; only the "now" check uses real time.
	 *
	 * @param object $service  Service row.
	 * @param int    $staff_id Staff.
	 * @param int    $start    Naive start timestamp.
	 * @param int    $seats    Seats requested.
	 * @return int Seats left after nothing is booked yet (0 = unavailable).
	 */
	private static function seats_left_for( $service, $staff_id, $start, $seats = 1 ) {
		$dur      = (int) $service->duration * 60;
		$bb       = (int) $service->buffer_before * 60;
		$ba       = (int) $service->buffer_after * 60;
		$capacity = max( 1, (int) $service->capacity );
		$end      = $start + $dur;
		$taken    = 0;

		foreach ( self::busy_for( $staff_id, gmdate( 'Y-m-d', $start ) ) as $b ) {
			// Same group session: share capacity instead of conflicting.
			if ( $capacity > 1 && $b['service_id'] === (int) $service->id && $b['start'] === $start ) {
				$taken += $b['seats'];
				continue;
			}
			$overlap_new_buffered = ( $start - $bb ) < $b['end'] && ( $end + $ba ) > $b['start'];
			$overlap_old_buffered = ( $b['start'] - $b['bb'] ) < $end && ( $b['end'] + $b['ba'] ) > $start;
			if ( $overlap_new_buffered || $overlap_old_buffered ) {
				return 0;
			}
		}

		$left = $capacity - $taken;
		return $left >= $seats ? $left : 0;
	}

	/**
	 * Working ranges for a staff member on a date.
	 *
	 * @param int    $staff_id Staff.
	 * @param string $date     Y-m-d.
	 * @return array [ [start_ts, end_ts], ... ] naive timestamps.
	 */
	public static function working_ranges( $staff_id, $date ) {
		if ( OSB_DB::is_day_off( $staff_id, $date ) ) {
			return array();
		}
		static $schedules = array();
		if ( ! isset( $schedules[ $staff_id ] ) ) {
			$schedules[ $staff_id ] = OSB_DB::schedule( $staff_id );
		}
		$weekday = (int) gmdate( 'N', strtotime( $date ) );
		$ranges  = array();
		foreach ( $schedules[ $staff_id ][ $weekday ] as $r ) {
			$ranges[] = array( strtotime( $date . ' ' . $r[0] . ':00' ), strtotime( $date . ' ' . $r[1] . ':00' ) );
		}
		return apply_filters( 'osb_working_ranges', $ranges, $staff_id, $date );
	}

	/**
	 * Available slots on one day.
	 *
	 * @param int    $service_id Service.
	 * @param int    $staff_id   0 = any.
	 * @param string $date       Y-m-d.
	 * @param int    $seats      Seats requested.
	 * @param bool   $first_only Stop at first slot (month view).
	 * @return array [ [ 'time' => 'H:i', 'staff' => int[], 'seats_left' => int ], ... ]
	 */
	public static function day_slots( $service_id, $staff_id, $date, $seats = 1, $first_only = false ) {
		$service = OSB_DB::get( 'services', $service_id );
		if ( ! $service || 'active' !== $service->status || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}

		list( $earliest, $latest ) = self::window();
		if ( $date < $earliest->format( 'Y-m-d' ) || $date > $latest->format( 'Y-m-d' ) ) {
			return array();
		}
		$earliest_naive = strtotime( $earliest->format( 'Y-m-d H:i:s' ) );

		$dur      = (int) $service->duration * 60;
		$interval = (int) OSB_Settings::get( 'slot_interval' );
		$step     = ( $interval > 0 ? $interval : (int) $service->duration ) * 60;
		$step     = max( 300, $step );

		$slots = array();
		foreach ( self::candidate_staff( $service_id, $staff_id ) as $sid ) {
			foreach ( self::working_ranges( $sid, $date ) as $range ) {
				for ( $t = $range[0]; $t + $dur <= $range[1]; $t += $step ) {
					if ( $t < $earliest_naive ) {
						continue;
					}
					$left = self::seats_left_for( $service, $sid, $t, $seats );
					if ( $left < 1 ) {
						continue;
					}
					$key = gmdate( 'H:i', $t );
					if ( ! isset( $slots[ $key ] ) ) {
						$slots[ $key ] = array(
							'time'       => $key,
							'staff'      => array(),
							'seats_left' => 0,
						);
					}
					$slots[ $key ]['staff'][]    = $sid;
					$slots[ $key ]['seats_left'] = max( $slots[ $key ]['seats_left'], $left );
					if ( $first_only ) {
						return array_values( $slots );
					}
				}
			}
		}

		ksort( $slots );
		return apply_filters( 'osb_day_slots', array_values( $slots ), $service, $staff_id, $date );
	}

	/**
	 * Dates in a month with at least one slot.
	 *
	 * @param int    $service_id Service.
	 * @param int    $staff_id   Staff or 0.
	 * @param string $month      Y-m.
	 * @return string[]
	 */
	public static function month_dates( $service_id, $staff_id, $month ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return array();
		}
		$cache_key = 'osb_month_' . md5( $service_id . '|' . $staff_id . '|' . $month . '|' . get_option( 'osb_availability_version', 1 ) . '|' . current_time( 'Y-m-d H' ) . '|' . self::$exclude_booking );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$days  = (int) gmdate( 't', strtotime( $month . '-01' ) );
		$dates = array();
		for ( $d = 1; $d <= $days; $d++ ) {
			$date = sprintf( '%s-%02d', $month, $d );
			if ( self::day_slots( $service_id, $staff_id, $date, 1, true ) ) {
				$dates[] = $date;
			}
		}
		set_transient( $cache_key, $dates, 10 * MINUTE_IN_SECONDS );
		return $dates;
	}

	/**
	 * Invalidate month caches (bump version).
	 */
	public static function bust_cache() {
		update_option( 'osb_availability_version', (int) get_option( 'osb_availability_version', 1 ) + 1, false );
		self::flush();
	}

	/**
	 * Explain why a slot would not normally be bookable. Used for admin overrides,
	 * which are always allowed but shown these warnings.
	 *
	 * @param array $args type, service_id, staff_id, event_id, intake_id, date (Y-m-d), time (H:i), seats, exclude (booking id).
	 * @return string[] Warnings (empty = no problems).
	 */
	public static function conflicts( array $args ) {
		$a = wp_parse_args(
			$args,
			array(
				'type'       => 'service',
				'service_id' => 0,
				'staff_id'   => 0,
				'event_id'   => 0,
				'intake_id'  => 0,
				'date'       => '',
				'time'       => '',
				'seats'      => 1,
				'exclude'    => 0,
			)
		);
		$warnings = array();
		$tf       = get_option( 'time_format' );

		if ( 'resource' === $a['type'] ) {
			$own = $a['exclude'] ? OSB_DB::get( 'bookings', (int) $a['exclude'] ) : null;
			return apply_filters( 'osb_admin_conflicts', $own ? OSB_Resources::conflicts( $own ) : array(), $a );
		}
		if ( 'table' === $a['type'] ) {
			return apply_filters( 'osb_admin_conflicts', OSB_Restaurant::conflicts( $a ), $a );
		}

		if ( 'course' === $a['type'] ) {
			$intake = OSB_DB::get( 'course_intakes', (int) $a['intake_id'] );
			$course = $intake ? OSB_DB::get( 'courses', (int) $intake->course_id ) : null;
			if ( ! $intake || ! $course ) {
				return $warnings;
			}
			$taken    = OSB_Courses::seats_taken( $intake->id, (int) $a['exclude'] );
			$capacity = OSB_Courses::capacity( $intake, $course );
			if ( $taken + (int) $a['seats'] > $capacity ) {
				/* translators: 1: total after booking 2: capacity */
				$warnings[] = sprintf( __( 'This takes the course over capacity (%1$d of %2$d places).', 'osam-bookings' ), $taken + (int) $a['seats'], $capacity );
			}
			if ( ! $intake->first_start ) {
				$warnings[] = __( 'This intake has no sessions yet.', 'osam-bookings' );
			} elseif ( $intake->first_start <= current_time( 'mysql' ) ) {
				$warnings[] = __( 'This course has already started — the customer will have missed earlier sessions.', 'osam-bookings' );
			}
			if ( 'active' !== $course->status || 'active' !== $intake->status ) {
				$warnings[] = __( 'This course is not on sale to customers.', 'osam-bookings' );
			}
			return apply_filters( 'osb_admin_conflicts', $warnings, $a );
		}

		if ( 'event' === $a['type'] ) {
			$event = OSB_DB::get( 'events', (int) $a['event_id'] );
			if ( ! $event ) {
				return $warnings;
			}
			$taken = OSB_DB::event_seats_taken( $event->id );
			if ( $a['exclude'] ) {
				$own = OSB_DB::get( 'bookings', (int) $a['exclude'] );
				if ( $own && in_array( $own->status, OSB_DB::blocking_statuses(), true ) && (int) $own->event_id === (int) $event->id ) {
					$taken -= (int) $own->seats;
				}
			}
			if ( $taken + (int) $a['seats'] > (int) $event->capacity ) {
				/* translators: 1: total after booking 2: capacity */
				$warnings[] = sprintf( __( 'This takes the event over capacity (%1$d of %2$d places).', 'osam-bookings' ), $taken + (int) $a['seats'], (int) $event->capacity );
			}
			if ( $event->start_at < current_time( 'mysql' ) ) {
				$warnings[] = __( 'This event has already started or finished.', 'osam-bookings' );
			}
			if ( 'active' !== $event->status ) {
				$warnings[] = __( 'This event is hidden from customers.', 'osam-bookings' );
			}
			return apply_filters( 'osb_admin_conflicts', $warnings, $a );
		}

		$service = OSB_DB::get( 'services', (int) $a['service_id'] );
		if ( ! $service || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['date'] ) || ! preg_match( '/^\d{2}:\d{2}$/', $a['time'] ) ) {
			return $warnings;
		}
		$staff_id = (int) $a['staff_id'] ? (int) $a['staff_id'] : ( OSB_DB::is_solo() ? OSB_DB::solo_staff_id() : 0 );
		$staff    = $staff_id ? OSB_DB::get( 'staff', $staff_id ) : null;
		$start    = strtotime( $a['date'] . ' ' . $a['time'] . ':00' );
		$end      = $start + (int) $service->duration * 60;

		if ( gmdate( 'Y-m-d H:i:s', $start ) < current_time( 'mysql' ) ) {
			$warnings[] = __( 'This time is in the past.', 'osam-bookings' );
		}
		if ( 'active' !== $service->status ) {
			$warnings[] = __( 'This service is hidden from customers.', 'osam-bookings' );
		}
		if ( ! $staff ) {
			return apply_filters( 'osb_admin_conflicts', $warnings, $a );
		}
		if ( ! OSB_DB::is_solo() && ! in_array( $staff_id, OSB_DB::raw_service_staff_ids( $service->id ), true ) ) {
			/* translators: %s staff name */
			$warnings[] = sprintf( __( '%s doesn’t normally provide this service.', 'osam-bookings' ), $staff->name );
		}

		if ( OSB_DB::is_day_off( $staff_id, $a['date'] ) ) {
			$warnings[] = OSB_DB::is_solo()
				? __( 'You are marked as off on this date (day off or closure).', 'osam-bookings' )
				/* translators: %s staff name */
				: sprintf( __( '%s is off on this date (day off or business closure).', 'osam-bookings' ), $staff->name );
		} else {
			$inside = false;
			foreach ( self::working_ranges( $staff_id, $a['date'] ) as $r ) {
				if ( $start >= $r[0] && $end <= $r[1] ) {
					$inside = true;
				}
			}
			if ( ! $inside ) {
				$warnings[] = OSB_DB::is_solo()
					? __( 'This is outside your working hours.', 'osam-bookings' )
					/* translators: %s staff name */
					: sprintf( __( 'This is outside %s’s working hours.', 'osam-bookings' ), $staff->name );
			}
		}

		// Overlaps, including buffers, with other bookings and events in this diary.
		$from     = gmdate( 'Y-m-d H:i:s', $start - DAY_IN_SECONDS );
		$to       = gmdate( 'Y-m-d H:i:s', $end + DAY_IN_SECONDS );
		$bb       = (int) $service->buffer_before * 60;
		$ba       = (int) $service->buffer_after * 60;
		$capacity = max( 1, (int) $service->capacity );
		$shared   = 0;
		$map      = self::services_map();
		foreach ( OSB_DB::staff_bookings_between( $staff_id, $from, $to ) as $b ) {
			if ( (int) $b->id === (int) $a['exclude'] ) {
				continue;
			}
			$bs = strtotime( $b->start_at );
			$be = strtotime( $b->end_at );
			if ( $capacity > 1 && (int) $b->service_id === (int) $service->id && $bs === $start ) {
				$shared += (int) $b->seats;
				continue;
			}
			$svc = isset( $map[ (int) $b->service_id ] ) ? $map[ (int) $b->service_id ] : null;
			$obb = $svc ? (int) $svc->buffer_before * 60 : 0;
			$oba = $svc ? (int) $svc->buffer_after * 60 : 0;
			$hard = $start < $be && $end > $bs;
			$soft = ( $start - $bb ) < $be && ( $end + $ba ) > $bs || ( $bs - $obb ) < $end && ( $be + $oba ) > $start;
			if ( $hard || $soft ) {
				$full     = OSB_DB::get( 'bookings', (int) $b->id );
				$label    = sprintf( '#%d %s, %s–%s', $b->id, $full ? $full->customer_name : '', gmdate( $tf, $bs ), gmdate( $tf, $be ) );
				$warnings[] = $hard
					/* translators: %s booking label */
					? sprintf( __( 'Double booking: overlaps %s.', 'osam-bookings' ), $label )
					/* translators: %s booking label */
					: sprintf( __( 'Cuts into the buffer time around %s.', 'osam-bookings' ), $label );
			}
		}
		foreach ( OSB_DB::staff_events_between( $staff_id, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ) ) as $e ) {
			$ev = OSB_DB::get( 'events', $e->id );
			/* translators: %s event name */
			$warnings[] = sprintf( __( 'Clashes with the event “%s”.', 'osam-bookings' ), $ev ? $ev->name : '#' . $e->id );
		}
		foreach ( OSB_Calendar_Sync::busy_between( $staff_id, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ) ) as $busy ) {
			/* translators: 1: start 2: end */
			$warnings[] = sprintf( __( 'Busy in their connected calendar (%1$s–%2$s).', 'osam-bookings' ), gmdate( $tf, strtotime( $busy[0] ) ), gmdate( $tf, strtotime( $busy[1] ) ) );
		}
		foreach ( OSB_Courses::staff_sessions_between( $staff_id, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ) ) as $cs ) {
			$co = OSB_DB::get( 'courses', $cs->course_id );
			/* translators: 1: course name 2: start time 3: end time */
			$warnings[] = sprintf( __( 'Clashes with a session of the course “%1$s” (%2$s–%3$s).', 'osam-bookings' ), $co ? $co->name : '#' . $cs->course_id, gmdate( $tf, strtotime( $cs->start_at ) ), gmdate( $tf, strtotime( $cs->end_at ) ) );
		}
		if ( $shared + (int) $a['seats'] > $capacity ) {
			/* translators: 1: places 2: capacity */
			$warnings[] = sprintf( __( 'This session will be over capacity (%1$d of %2$d places).', 'osam-bookings' ), $shared + (int) $a['seats'], $capacity );
		}

		return apply_filters( 'osb_admin_conflicts', $warnings, $a );
	}

	/**
	 * Resolve which staff member should take a slot, or 0 if none can.
	 *
	 * @param int    $service_id Service.
	 * @param int    $staff_id   Requested staff, 0 = any.
	 * @param string $start      Y-m-d H:i.
	 * @param int    $seats      Seats.
	 * @return int Staff id or 0.
	 */
	public static function resolve_staff( $service_id, $staff_id, $start, $seats = 1 ) {
		self::flush();
		$date  = substr( $start, 0, 10 );
		$time  = substr( $start, 11, 5 );
		$slots = self::day_slots( $service_id, $staff_id, $date, $seats );
		foreach ( $slots as $slot ) {
			if ( $slot['time'] !== $time ) {
				continue;
			}
			if ( 1 === count( $slot['staff'] ) ) {
				return (int) $slot['staff'][0];
			}
			// Spread work: pick whoever has the fewest bookings that day.
			$best       = 0;
			$best_count = PHP_INT_MAX;
			foreach ( $slot['staff'] as $sid ) {
				$count = count( OSB_DB::staff_bookings_between( $sid, $date . ' 00:00:00', $date . ' 23:59:59' ) );
				if ( $count < $best_count ) {
					$best       = (int) $sid;
					$best_count = $count;
				}
			}
			return (int) apply_filters( 'osb_resolve_any_staff', $best, $slot['staff'], $service_id, $start );
		}
		return 0;
	}
}
