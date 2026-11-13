<?php
/**
 * Moving in from another booking system (Amelia, Wix Bookings): the shared engine.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * A reader (OSB_Import_Amelia, OSB_Import_Wix) turns the other system's data into one plain
 * "dataset". This class checks it against what is already here (the plan), keeps it in short-lived
 * chunks, and then writes it a chunk at a time so large imports never time out.
 *
 * Dataset:
 *   source    amelia|wix
 *   staff     [ source id => [ name, email, phone, description ] ]
 *   services  [ source id => [ name, description, category, duration, price, capacity, buffer_before,
 *                              buffer_after, color, status, staff => [ source staff id => price|'' ] ] ]
 *   events    [ source id => [ name, description, location, start, end, capacity, price, staff, status, color ] ]
 *   customers [ [ name, email, phone, notes, tags ] ]
 *   bookings  [ [ id, kind (service|event), item, staff, start, end, seats, name, email, phone, status,
 *                 skip, pay, method, amount, currency, note, customer_note, created ] ]
 *   emails    [ [ label, target (standard email key, or 'custom'), subject, body, unknown[], custom[] ] ]
 *   warnings  string[]
 *   columns   Optional: which spreadsheet columns were recognised (shown in the preview).
 * All dates are site-local "Y-m-d H:i:s" — the same as the bookings table.
 *
 * Rules that keep an import quiet and repeatable:
 * - Bookings are written straight to the table. OSB_Bookings::create() is never called and no
 *   osb_booking_* action fires, so nothing is emailed, no meeting is created and no availability,
 *   opening-hours or double-booking check can refuse a booking that already happened.
 * - Confirmed bookings in the past arrive as "completed", so the 5-minute job never has to
 *   complete them (which would fire the status-changed hooks).
 * - Past bookings are flagged as already reminded / followed up, and scheduled custom emails are
 *   marked as dealt with in the email log, so the 5-minute job has nothing to send about them.
 * - Source ids are remembered (osb_migrate_map_{source}) and bookings are also matched on
 *   who + what + when, so running the same import again adds nothing twice.
 */
class OSB_Migrate {

	const CHUNK = 250;
	const TTL   = 21600; // A checked import waits six hours, then it is thrown away.

	/**
	 * Remembered source id → local id, per source.
	 *
	 * @var array
	 */
	private static $maps = array();

	/**
	 * What is already on this site (built once per request).
	 *
	 * @var array|null
	 */
	private static $ctx = null;

	/**
	 * Systems we can import from.
	 *
	 * @return array id => name
	 */
	public static function sources() {
		return array(
			'amelia' => 'Amelia',
			'wix'    => 'Wix Bookings',
		);
	}

	/* ---------------------------------------------------------------------
	 * Dates
	 * ------------------------------------------------------------------ */

	/**
	 * A UTC "Y-m-d H:i:s" → site-local.
	 *
	 * @param string $utc UTC datetime.
	 * @return string '' when it isn't a real date.
	 */
	public static function utc_to_local( $utc ) {
		$utc = trim( (string) $utc );
		if ( ! preg_match( '/^(\d{4})-\d{2}-\d{2}/', $utc, $m ) || (int) $m[1] < 1990 ) {
			return '';
		}
		try {
			$d = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		return $d->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * An ISO 8601 date → site-local. With a "Z" or an offset it is an exact moment; without one it
	 * is read as a clock time in $tz (or, failing that, as it stands).
	 *
	 * @param string $iso ISO date.
	 * @param string $tz  Timezone name for dates with no offset.
	 * @return string '' when it isn't a real date.
	 */
	public static function iso_to_local( $iso, $tz = '' ) {
		$iso = trim( (string) $iso );
		if ( ! preg_match( '/^(\d{4})-\d{2}-\d{2}/', $iso, $m ) || (int) $m[1] < 1990 ) {
			return '';
		}
		$exact = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $iso );
		try {
			$zone = new DateTimeZone( 'UTC' );
			if ( ! $exact && '' !== (string) $tz ) {
				$zone  = new DateTimeZone( (string) $tz );
				$exact = true;
			}
			$d = new DateTimeImmutable( $iso, $zone );
		} catch ( Exception $e ) {
			return '';
		}
		return $exact ? $d->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ) : $d->format( 'Y-m-d H:i:s' );
	}

	/* ---------------------------------------------------------------------
	 * Remembered ids
	 * ------------------------------------------------------------------ */

	/**
	 * Read a remembered local id.
	 *
	 * @param string $source Source.
	 * @param string $kind   staff|service|event|booking.
	 * @param string $id     Source id.
	 * @return int 0 when unknown.
	 */
	private static function mapped( $source, $kind, $id ) {
		if ( ! isset( self::$maps[ $source ] ) ) {
			$m                     = get_option( 'osb_migrate_map_' . $source, array() );
			self::$maps[ $source ] = is_array( $m ) ? $m : array();
		}
		return isset( self::$maps[ $source ][ $kind ][ (string) $id ] ) ? (int) self::$maps[ $source ][ $kind ][ (string) $id ] : 0;
	}

	/**
	 * Remember a local id.
	 *
	 * @param string $source Source.
	 * @param string $kind   Kind.
	 * @param string $id     Source id.
	 * @param int    $local  Local id.
	 */
	private static function remember( $source, $kind, $id, $local ) {
		self::mapped( $source, $kind, $id );
		self::$maps[ $source ][ $kind ][ (string) $id ] = (int) $local;
	}

	/**
	 * Save the remembered ids.
	 *
	 * @param string $source Source.
	 */
	private static function save_map( $source ) {
		if ( isset( self::$maps[ $source ] ) ) {
			update_option( 'osb_migrate_map_' . $source, self::$maps[ $source ], false );
		}
	}

	/* ---------------------------------------------------------------------
	 * What is already here
	 * ------------------------------------------------------------------ */

	/**
	 * Matching key for a name.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function norm( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/**
	 * Who + what + when, for spotting a booking that is already here.
	 *
	 * @param string $kind  service|event.
	 * @param int    $item  Local item id.
	 * @param string $start Start.
	 * @param string $email Email.
	 * @param string $name  Name (used when there is no email).
	 * @return string
	 */
	private static function booking_key( $kind, $item, $start, $email, $name ) {
		$who = '' !== trim( (string) $email ) ? self::norm( $email ) : 'n:' . self::norm( $name );
		return $kind . '|' . (int) $item . '|' . substr( (string) $start, 0, 16 ) . '|' . $who;
	}

	/**
	 * Load what is already on the site.
	 *
	 * @return array
	 */
	private static function &ctx() {
		if ( null !== self::$ctx ) {
			return self::$ctx;
		}
		global $wpdb;
		$c = array(
			'services'  => array(),
			'service_ids' => array(),
			'staff_email' => array(),
			'staff_name' => array(),
			'staff_ids' => array(),
			'events'    => array(),
			'event_ids' => array(),
			'event_staff' => array(),
			'customers' => array(),
			'booking_ids' => array(),
			'booking_keys' => array(),
		);
		foreach ( OSB_DB::services() as $s ) {
			$c['service_ids'][ (int) $s->id ] = 1;
			if ( ! isset( $c['services'][ self::norm( $s->name ) ] ) ) {
				$c['services'][ self::norm( $s->name ) ] = (int) $s->id;
			}
		}
		foreach ( OSB_DB::staff() as $s ) {
			$c['staff_ids'][ (int) $s->id ] = 1;
			if ( '' !== (string) $s->email ) {
				$c['staff_email'][ self::norm( $s->email ) ] = (int) $s->id;
			}
			$c['staff_name'][ self::norm( $s->name ) ] = (int) $s->id;
		}
		foreach ( OSB_DB::events() as $e ) {
			$c['event_ids'][ (int) $e->id ]   = 1;
			$c['event_staff'][ (int) $e->id ] = (int) $e->staff_id;
			$c['events'][ self::norm( $e->name ) . '|' . substr( $e->start_at, 0, 16 ) ] = (int) $e->id;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $wpdb->get_col( 'SELECT email FROM ' . OSB_Install::table( 'customers' ) ) as $e ) {
			$c['customers'][ self::norm( $e ) ] = 1;
		}
		$rows = $wpdb->get_results( 'SELECT id, type, service_id, event_id, start_at, customer_email, customer_name FROM ' . OSB_Install::table( 'bookings' ) . " WHERE type IN ('service','event')", ARRAY_A );
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$kind = 'event' === $r['type'] ? 'event' : 'service';
			$c['booking_keys'][ self::booking_key( $kind, 'event' === $kind ? $r['event_id'] : $r['service_id'], $r['start_at'], $r['customer_email'], $r['customer_name'] ) ] = 1;
		}
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . OSB_Install::table( 'bookings' ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$c['booking_ids'][ (int) $id ] = 1;
		}
		self::$ctx = $c;
		return self::$ctx;
	}

	/**
	 * Where a staff member goes.
	 *
	 * @param string $source Source.
	 * @param string $id     Source id.
	 * @param array  $row    Staff row.
	 * @return array [ create|match|skip, local id ]
	 */
	private static function resolve_staff( $source, $id, array $row ) {
		if ( OSB_DB::is_solo() ) {
			return array( 'skip', 0 );
		}
		$c     = &self::ctx();
		$local = self::mapped( $source, 'staff', $id );
		if ( $local && isset( $c['staff_ids'][ $local ] ) ) {
			return array( 'match', $local );
		}
		$email = self::norm( isset( $row['email'] ) ? $row['email'] : '' );
		if ( '' !== $email && isset( $c['staff_email'][ $email ] ) ) {
			return array( 'match', $c['staff_email'][ $email ] );
		}
		$name = self::norm( $row['name'] );
		if ( isset( $c['staff_name'][ $name ] ) ) {
			return array( 'match', $c['staff_name'][ $name ] );
		}
		return array( 'create', 0 );
	}

	/**
	 * Where a service goes.
	 *
	 * @param string $source Source.
	 * @param string $id     Source id.
	 * @param array  $row    Service row.
	 * @return array [ create|match, local id ]
	 */
	private static function resolve_service( $source, $id, array $row ) {
		$c     = &self::ctx();
		$local = self::mapped( $source, 'service', $id );
		if ( $local && isset( $c['service_ids'][ $local ] ) ) {
			return array( 'match', $local );
		}
		$name = self::norm( $row['name'] );
		if ( isset( $c['services'][ $name ] ) ) {
			return array( 'match', $c['services'][ $name ] );
		}
		return array( 'create', 0 );
	}

	/**
	 * Where an event goes.
	 *
	 * @param string $source Source.
	 * @param string $id     Source id.
	 * @param array  $row    Event row.
	 * @return array [ create|match|skip, local id ]
	 */
	private static function resolve_event( $source, $id, array $row ) {
		if ( ! OSB_Settings::feature( 'events' ) || '' === (string) $row['start'] ) {
			return array( 'skip', 0 );
		}
		$c     = &self::ctx();
		$local = self::mapped( $source, 'event', $id );
		if ( $local && isset( $c['event_ids'][ $local ] ) ) {
			return array( 'match', $local );
		}
		$key = self::norm( $row['name'] ) . '|' . substr( $row['start'], 0, 16 );
		if ( isset( $c['events'][ $key ] ) ) {
			return array( 'match', $c['events'][ $key ] );
		}
		return array( 'create', 0 );
	}

	/**
	 * What happens to one booking.
	 *
	 * @param string   $source Source.
	 * @param array    $b      Booking.
	 * @param int|null $item   Local item id; 0 = the item is about to be created; null = there is no such item.
	 * @return array [ create|match|skip, reason code ]
	 */
	private static function classify_booking( $source, array $b, $item ) {
		if ( ! empty( $b['skip'] ) ) {
			return array( 'skip', (string) $b['skip'] );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $b['start'] ) ) {
			return array( 'skip', 'date' );
		}
		if ( null === $item ) {
			return array( 'skip', 'event' === $b['kind'] && ! OSB_Settings::feature( 'events' ) ? 'event_off' : 'item' );
		}
		if ( '' === trim( (string) $b['name'] ) && '' === trim( (string) $b['email'] ) ) {
			return array( 'skip', 'noname' );
		}
		$c     = &self::ctx();
		$local = self::mapped( $source, 'booking', $b['id'] );
		if ( $local && isset( $c['booking_ids'][ $local ] ) ) {
			return array( 'match', '' );
		}
		if ( $item && isset( $c['booking_keys'][ self::booking_key( $b['kind'], $item, $b['start'], $b['email'], $b['name'] ) ] ) ) {
			return array( 'match', '' );
		}
		return array( 'create', '' );
	}

	/**
	 * Plain-language reasons a booking is left out.
	 *
	 * @return array code => text
	 */
	public static function reasons() {
		return array(
			'waiting'    => __( 'On a waiting list, not a booking', 'osam-bookings' ),
			'incomplete' => __( 'Started but never finished by the customer', 'osam-bookings' ),
			'date'       => __( 'No date and time we could read', 'osam-bookings' ),
			'item'       => __( 'We couldn’t tell which service it was for', 'osam-bookings' ),
			'event_off'  => __( 'For an event, and Events are switched off here (Settings → Features)', 'osam-bookings' ),
			'course'     => __( 'For a course that runs over several dates — set the course up under Courses and add these people by hand', 'osam-bookings' ),
			'noname'     => __( 'No customer name or email', 'osam-bookings' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * The job: a checked import waiting to be run
	 * ------------------------------------------------------------------ */

	/**
	 * Option name of the current user's job.
	 *
	 * @return string
	 */
	private static function job_key() {
		return 'osb_migrate_job_' . get_current_user_id();
	}

	/**
	 * The current user's job (expired ones are thrown away).
	 *
	 * @return array|null
	 */
	public static function job() {
		$job = get_option( self::job_key(), null );
		if ( ! is_array( $job ) || empty( $job['id'] ) ) {
			return null;
		}
		if ( (int) $job['time'] < time() - self::TTL ) {
			self::discard();
			return null;
		}
		return $job;
	}

	/**
	 * Throw the current job and its stored data away.
	 */
	public static function discard() {
		$job = get_option( self::job_key(), null );
		if ( is_array( $job ) && ! empty( $job['id'] ) ) {
			delete_option( 'osb_migrate_' . $job['id'] . '_meta' );
			foreach ( array( 'c' => 'n_customers', 'b' => 'n_bookings' ) as $p => $n ) {
				$chunks = (int) ceil( (int) $job[ $n ] / self::CHUNK );
				for ( $i = 0; $i < $chunks; $i++ ) {
					delete_option( 'osb_migrate_' . $job['id'] . '_' . $p . $i );
				}
			}
		}
		delete_option( self::job_key() );
	}

	/**
	 * Check a dataset against this site and keep it, ready to run.
	 *
	 * @param array $data Dataset.
	 * @return array The job.
	 */
	public static function store( array $data ) {
		self::discard();
		$data = array_merge(
			array(
				'staff'     => array(),
				'services'  => array(),
				'events'    => array(),
				'customers' => array(),
				'bookings'  => array(),
				'emails'    => array(),
				'warnings'  => array(),
				'columns'   => array(),
			),
			$data
		);
		// One customer per email address.
		$seen      = array();
		$customers = array();
		foreach ( $data['customers'] as $cu ) {
			$e = self::norm( isset( $cu['email'] ) ? $cu['email'] : '' );
			if ( '' !== $e && isset( $seen[ $e ] ) ) {
				continue;
			}
			$seen[ $e ]  = 1;
			$customers[] = $cu;
		}
		$data['customers'] = $customers;
		$data['bookings']  = array_values( $data['bookings'] );

		$id  = strtolower( wp_generate_password( 10, false, false ) );
		$job = array(
			'id'          => $id,
			'source'      => $data['source'],
			'route'       => isset( $data['route'] ) ? (string) $data['route'] : '',
			'time'        => time(),
			'n_customers' => count( $data['customers'] ),
			'n_bookings'  => count( $data['bookings'] ),
			'columns'     => $data['columns'],
			'plan'        => self::plan( $data ),
			'state'       => null,
		);
		add_option(
			'osb_migrate_' . $id . '_meta',
			array(
				'staff'    => $data['staff'],
				'services' => $data['services'],
				'events'   => $data['events'],
				'emails'   => $data['emails'],
			),
			'',
			false
		);
		foreach ( array( 'c' => 'customers', 'b' => 'bookings' ) as $p => $k ) {
			foreach ( array_chunk( $data[ $k ], self::CHUNK ) as $i => $chunk ) {
				add_option( 'osb_migrate_' . $id . '_' . $p . $i, $chunk, '', false );
			}
		}
		update_option( self::job_key(), $job, false );
		return $job;
	}

	/**
	 * Work out what an import would do. Writes nothing.
	 *
	 * @param array $data Dataset.
	 * @return array
	 */
	private static function plan( array $data ) {
		$src   = $data['source'];
		$zero  = array( 'create' => 0, 'match' => 0, 'skip' => 0 );
		$plan  = array(
			'counts'   => array( 'staff' => $zero, 'services' => $zero, 'events' => $zero, 'customers' => $zero, 'bookings' => $zero ),
			'lists'    => array( 'staff' => array(), 'services' => array(), 'events' => array() ),
			'emails'   => array(),
			'reasons'  => array(),
			'warnings' => array_values( array_unique( $data['warnings'] ) ),
			'upcoming' => 0,
			'past_done' => 0,
		);
		$c     = &self::ctx();
		$now   = current_time( 'mysql' );
		$local = array( 'service' => array(), 'event' => array() );

		foreach ( $data['staff'] as $id => $row ) {
			list( $act ) = self::resolve_staff( $src, $id, $row );
			++$plan['counts']['staff'][ $act ];
			$plan['lists']['staff'][] = array( $row['name'], $act );
		}
		foreach ( $data['services'] as $id => $row ) {
			list( $act, $lid )       = self::resolve_service( $src, $id, $row );
			$local['service'][ $id ] = $lid;
			++$plan['counts']['services'][ $act ];
			/* translators: 1: minutes 2: price */
			$plan['lists']['services'][] = array( $row['name'], $act, sprintf( __( '%1$d min · %2$s', 'osam-bookings' ), (int) $row['duration'], OSB_Settings::money( (float) $row['price'] ) ) );
		}
		foreach ( $data['events'] as $id => $row ) {
			list( $act, $lid ) = self::resolve_event( $src, $id, $row );
			if ( 'skip' !== $act ) {
				$local['event'][ $id ] = $lid;
			}
			++$plan['counts']['events'][ $act ];
			$plan['lists']['events'][] = array( $row['name'], $act, '' !== (string) $row['start'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['start'] ) : '' );
		}
		if ( $data['staff'] && OSB_DB::is_solo() ) {
			$plan['warnings'][] = __( 'This site is set up for one person (solo), so staff members aren’t brought across and every booking goes into your own diary. To keep them separate, switch to “Team” in Settings before importing.', 'osam-bookings' );
		}
		if ( $plan['counts']['staff']['create'] ) {
			$plan['warnings'][] = __( 'New staff members arrive without working hours. Set their hours under Staff & hours before they can be booked online.', 'osam-bookings' );
		}
		if ( $plan['counts']['events']['skip'] && ! OSB_Settings::feature( 'events' ) ) {
			$plan['warnings'][] = __( 'Events are switched off here, so events and their bookings are left out. Switch Events on in Settings → Features and check again to include them.', 'osam-bookings' );
		}

		foreach ( $data['customers'] as $cu ) {
			$e = self::norm( isset( $cu['email'] ) ? $cu['email'] : '' );
			if ( '' === $e || ! is_email( $e ) ) {
				++$plan['counts']['customers']['skip'];
			} elseif ( isset( $c['customers'][ $e ] ) ) {
				++$plan['counts']['customers']['match'];
			} else {
				++$plan['counts']['customers']['create'];
			}
		}
		if ( $plan['counts']['customers']['skip'] ) {
			/* translators: %d customers */
			$plan['warnings'][] = sprintf( _n( '%d customer has no usable email address and can’t be saved as a customer record. Their bookings still come across.', '%d customers have no usable email address and can’t be saved as customer records. Their bookings still come across.', $plan['counts']['customers']['skip'], 'osam-bookings' ), $plan['counts']['customers']['skip'] );
		}

		$keys = array();
		foreach ( $data['bookings'] as $b ) {
			$item             = isset( $local[ $b['kind'] ][ $b['item'] ] ) ? $local[ $b['kind'] ][ $b['item'] ] : null;
			list( $act, $why ) = self::classify_booking( $src, $b, $item );
			if ( 'create' === $act ) {
				// The same booking twice in one file only counts once.
				$k = $b['kind'] . '|' . $b['item'] . '|' . self::booking_key( '', 0, $b['start'], $b['email'], $b['name'] );
				if ( isset( $keys[ $k ] ) ) {
					$act = 'match';
				}
				$keys[ $k ] = 1;
			}
			++$plan['counts']['bookings'][ $act ];
			if ( 'skip' === $act ) {
				$plan['reasons'][ $why ] = ( isset( $plan['reasons'][ $why ] ) ? $plan['reasons'][ $why ] : 0 ) + 1;
			} elseif ( 'create' === $act ) {
				if ( $b['start'] > $now ) {
					++$plan['upcoming'];
				} elseif ( 'confirmed' === $b['status'] ) {
					++$plan['past_done'];
				}
			}
		}

		foreach ( $data['emails'] as $em ) {
			$plan['emails'][] = array(
				'label'        => $em['label'],
				'target'       => $em['target'],
				'target_label' => isset( $em['target_label'] ) ? $em['target_label'] : $em['target'],
				'enabled'      => ! isset( $em['enabled'] ) || $em['enabled'],
				'unknown'      => array_values( $em['unknown'] ),
			);
			if ( $em['unknown'] ) {
				/* translators: 1: email name 2: list of placeholders */
				$plan['warnings'][] = sprintf( __( 'Email “%1$s” uses placeholders we have no match for: %2$s. They are left in the wording exactly as written so you can spot and replace them.', 'osam-bookings' ), $em['label'], implode( ', ', $em['unknown'] ) );
			}
		}

		$plan['calendars'] = (bool) self::push_connections();
		$plan['warnings'] = array_values( array_unique( $plan['warnings'] ) );
		return $plan;
	}

	/**
	 * Begin running the checked import.
	 *
	 * @param array $opts emails (bring email wording across), reminders (send reminders for upcoming imported bookings).
	 * @return bool
	 */
	public static function start( array $opts ) {
		$job = self::job();
		if ( ! $job ) {
			return false;
		}
		if ( empty( $job['state'] ) ) {
			$zero         = array( 'create' => 0, 'match' => 0, 'skip' => 0 );
			$job['state'] = array(
				'step'   => 'setup',
				'cursor' => 0,
				'opts'   => array(
					'emails'    => ! empty( $opts['emails'] ),
					'reminders' => ! empty( $opts['reminders'] ),
					'calendars' => ! empty( $opts['calendars'] ),
				),
				'res'    => array( 'staff' => $zero, 'services' => $zero, 'events' => $zero, 'customers' => $zero, 'bookings' => $zero, 'emails' => 0 ),
			);
			update_option( self::job_key(), $job, false );
		}
		return true;
	}

	/**
	 * How far along a job is.
	 *
	 * @param array $job Job.
	 * @return array [ done, total ]
	 */
	public static function progress( array $job ) {
		$chunks_c = (int) ceil( $job['n_customers'] / self::CHUNK );
		$chunks_b = (int) ceil( $job['n_bookings'] / self::CHUNK );
		$total    = 1 + $chunks_c + $chunks_b;
		$st       = isset( $job['state'] ) ? $job['state'] : null;
		if ( ! $st || 'setup' === $st['step'] ) {
			return array( 0, $total );
		}
		if ( 'customers' === $st['step'] ) {
			return array( 1 + (int) $st['cursor'], $total );
		}
		return array( 1 + $chunks_c + (int) $st['cursor'], $total );
	}

	/**
	 * Do a few seconds' work on the running import.
	 *
	 * @param int $seconds Time to spend.
	 * @return array|WP_Error [ finished bool, done int, total int ]
	 */
	public static function step( $seconds = 8 ) {
		$job = self::job();
		if ( ! $job || empty( $job['state'] ) ) {
			return new WP_Error( 'osb_migrate_gone', __( 'That import has expired. Please check your data again.', 'osam-bookings' ) );
		}
		$lock = 'osb_migrate_lock_' . get_current_user_id();
		if ( get_transient( $lock ) ) {
			list( $done, $total ) = self::progress( $job );
			return array( false, $done, $total );
		}
		set_transient( $lock, 1, 120 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		}
		$stop     = microtime( true ) + max( 1, (int) $seconds );
		$src      = $job['source'];
		$finished = false;
		$wrote    = false;
		do {
			$st = &$job['state'];
			if ( 'setup' === $st['step'] ) {
				self::run_setup( $job );
				$st['step']   = 'customers';
				$st['cursor'] = 0;
			} elseif ( 'customers' === $st['step'] ) {
				$chunk = get_option( 'osb_migrate_' . $job['id'] . '_c' . (int) $st['cursor'], null );
				if ( ! is_array( $chunk ) ) {
					$st['step']   = 'bookings';
					$st['cursor'] = 0;
				} else {
					self::run_customers( $chunk, $st['res']['customers'] );
					++$st['cursor'];
				}
			} else {
				$chunk = get_option( 'osb_migrate_' . $job['id'] . '_b' . (int) $st['cursor'], null );
				if ( ! is_array( $chunk ) ) {
					$finished = true;
				} else {
					self::run_bookings( $job, $chunk );
					$wrote = true;
					++$st['cursor'];
				}
			}
			unset( $st );
			self::save_map( $src );
			update_option( self::job_key(), $job, false );
		} while ( ! $finished && microtime( true ) < $stop );

		if ( $wrote || $finished ) {
			OSB_Availability::bust_cache();
		}
		list( $done, $total ) = self::progress( $job );
		if ( $finished ) {
			update_option(
				'osb_migrate_last_' . get_current_user_id(),
				array(
					'source' => $src,
					'time'   => time(),
					'res'    => $job['state']['res'],
					'opts'   => $job['state']['opts'],
				),
				false
			);
			self::discard();
			/**
			 * Fires once after a whole import from another system has finished. (No per-booking hooks fire.)
			 *
			 * @param string $src Source.
			 * @param array  $res Counts.
			 */
			do_action( 'osb_migrate_finished', $src, $job['state']['res'] );
		}
		delete_transient( $lock );
		return array( $finished, $done, $total );
	}

	/**
	 * Result of the last finished import.
	 *
	 * @return array|null
	 */
	public static function last_result() {
		$r = get_option( 'osb_migrate_last_' . get_current_user_id(), null );
		return is_array( $r ) ? $r : null;
	}

	/* ---------------------------------------------------------------------
	 * Connected calendars
	 * ------------------------------------------------------------------ */

	/**
	 * Calendar connections that add bookings to a Google / Outlook calendar.
	 *
	 * @return array
	 */
	public static function push_connections() {
		if ( ! class_exists( 'OSB_Calendar_Sync' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) OSB_Calendar_Sync::connections() as $conn ) {
			if ( $conn->push && '' !== (string) $conn->calendar_id ) {
				$out[] = $conn;
			}
		}
		return $out;
	}

	/**
	 * Tell the calendar sync that imported bookings and events are already "in" each connected
	 * calendar, so its regular run never sends them there. (The sync compares what should be in the
	 * calendar with what it has recorded as written; these records say it is there and unchanged.)
	 *
	 * @param string $src Source.
	 */
	private static function seed_calendars( $src ) {
		global $wpdb;
		$conns = self::push_connections();
		if ( ! $conns ) {
			return;
		}
		self::mapped( $src, 'booking', '' );
		$ours = array();
		foreach ( array( 'booking', 'event' ) as $kind ) {
			foreach ( isset( self::$maps[ $src ][ $kind ] ) ? self::$maps[ $src ][ $kind ] : array() as $local ) {
				$ours[ $kind . '-' . (int) $local ] = 1;
			}
		}
		if ( ! $ours ) {
			return;
		}
		$t    = OSB_Install::table( 'calendar_events' );
		$now  = current_time( 'mysql' );
		$from = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + OSB_Calendar_Sync::WINDOW_MAX * DAY_IN_SECONDS );
		foreach ( $conns as $conn ) {
			$have = array();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, object_key, remote_id, hash FROM $t WHERE connection_id = %d", $conn->id ) ) as $row ) {
				$have[ $row->object_key ] = $row;
			}
			foreach ( OSB_Calendar_Sync::desired( (int) $conn->staff_id, $from, $to ) as $key => $item ) {
				if ( ! isset( $ours[ $key ] ) ) {
					continue;
				}
				$hash = md5( wp_json_encode( $item ) . '|' . $conn->calendar_id );
				if ( isset( $have[ $key ] ) ) {
					if ( 0 === strpos( (string) $have[ $key ]->remote_id, 'osb-imported-' ) && $have[ $key ]->hash !== $hash ) {
						$wpdb->update( $t, array( 'hash' => $hash, 'start_at' => $item['start'] ), array( 'id' => (int) $have[ $key ]->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					continue;
				}
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$t,
					array(
						'connection_id' => (int) $conn->id,
						'object_key'    => $key,
						'remote_id'     => 'osb-imported-' . $key,
						'calendar_id'   => $conn->calendar_id,
						'hash'          => $hash,
						'start_at'      => $item['start'],
						'updated_at'    => $now,
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Staff, services, events and email wording (small, done in one go).
	 *
	 * @param array $job Job (state updated).
	 */
	private static function run_setup( array &$job ) {
		$meta = get_option( 'osb_migrate_' . $job['id'] . '_meta', array() );
		$meta = is_array( $meta ) ? $meta : array();
		$src  = $job['source'];
		$res  = &$job['state']['res'];
		$c    = &self::ctx();
		$now  = current_time( 'mysql' );

		$staff_local = array();
		foreach ( isset( $meta['staff'] ) ? $meta['staff'] : array() as $id => $row ) {
			list( $act, $lid ) = self::resolve_staff( $src, $id, $row );
			if ( 'create' === $act ) {
				$lid = OSB_DB::insert(
					'staff',
					array(
						'name'        => substr( sanitize_text_field( $row['name'] ), 0, 191 ),
						'email'       => is_email( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
						'phone'       => substr( sanitize_text_field( $row['phone'] ), 0, 50 ),
						'description' => wp_kses_post( (string) $row['description'] ),
						'status'      => 'active',
						'created_at'  => $now,
					)
				);
				if ( ! $lid ) {
					$act = 'skip';
				} else {
					$c['staff_ids'][ $lid ]                       = 1;
					$c['staff_name'][ self::norm( $row['name'] ) ] = $lid;
					if ( is_email( $row['email'] ) ) {
						$c['staff_email'][ self::norm( $row['email'] ) ] = $lid;
					}
				}
			}
			if ( $lid ) {
				self::remember( $src, 'staff', $id, $lid );
				$staff_local[ (string) $id ] = $lid;
			}
			++$res['staff'][ $act ];
		}

		foreach ( isset( $meta['services'] ) ? $meta['services'] : array() as $id => $row ) {
			list( $act, $lid ) = self::resolve_service( $src, $id, $row );
			if ( 'create' === $act ) {
				$color = sanitize_hex_color( (string) $row['color'] );
				$lid   = OSB_DB::insert(
					'services',
					array(
						'name'            => substr( sanitize_text_field( $row['name'] ), 0, 191 ),
						'description'     => wp_kses_post( (string) $row['description'] ),
						'category'        => substr( sanitize_text_field( (string) $row['category'] ), 0, 191 ),
						'duration'        => max( 5, (int) $row['duration'] ),
						'price'           => max( 0, round( (float) $row['price'], 2 ) ),
						'capacity'        => max( 1, (int) $row['capacity'] ),
						'buffer_before'   => max( 0, (int) $row['buffer_before'] ),
						'buffer_after'    => max( 0, (int) $row['buffer_after'] ),
						'color'           => $color ? $color : '#0273b0',
						'payment_methods' => '',
						'status'          => 'hidden' === $row['status'] ? 'hidden' : 'active',
						'created_at'      => $now,
					)
				);
				if ( ! $lid ) {
					$act = 'skip';
				} else {
					$c['service_ids'][ $lid ]                    = 1;
					$c['services'][ self::norm( $row['name'] ) ] = $lid;
					// Who provides it.
					$who = array();
					foreach ( (array) $row['staff'] as $sid => $price ) {
						if ( isset( $staff_local[ (string) $sid ] ) ) {
							$who[ $staff_local[ (string) $sid ] ] = ( '' !== $price && null !== $price && abs( (float) $price - (float) $row['price'] ) > 0.004 ) ? (float) $price : '';
						}
					}
					if ( ! $who ) {
						$who = array( OSB_DB::solo_staff_id() => '' );
					}
					OSB_DB::set_service_staff( $lid, $who );
				}
			}
			if ( $lid ) {
				self::remember( $src, 'service', $id, $lid );
			}
			++$res['services'][ $act ];
		}

		foreach ( isset( $meta['events'] ) ? $meta['events'] : array() as $id => $row ) {
			list( $act, $lid ) = self::resolve_event( $src, $id, $row );
			if ( 'create' === $act ) {
				$color = sanitize_hex_color( (string) $row['color'] );
				$cap   = max( 1, (int) $row['capacity'] );
				$host  = isset( $staff_local[ (string) $row['staff'] ] ) ? $staff_local[ (string) $row['staff'] ] : 0;
				$lid   = OSB_DB::insert(
					'events',
					array(
						'name'            => substr( sanitize_text_field( $row['name'] ), 0, 191 ),
						'description'     => wp_kses_post( (string) $row['description'] ),
						'location'        => substr( sanitize_text_field( (string) $row['location'] ), 0, 255 ),
						'start_at'        => $row['start'],
						'end_at'          => $row['end'] > $row['start'] ? $row['end'] : gmdate( 'Y-m-d H:i:s', strtotime( $row['start'] ) + HOUR_IN_SECONDS ),
						'capacity'        => $cap,
						'max_per_booking' => min( 10, $cap ),
						'price'           => max( 0, round( (float) $row['price'], 2 ) ),
						'staff_id'        => $host,
						'color'           => $color ? $color : '#e8590c',
						'payment_methods' => '',
						'status'          => 'hidden' === $row['status'] ? 'hidden' : 'active',
						'created_at'      => $now,
					)
				);
				if ( ! $lid ) {
					$act = 'skip';
				} else {
					$c['event_ids'][ $lid ]   = 1;
					$c['event_staff'][ $lid ] = $host;
					$c['events'][ self::norm( $row['name'] ) . '|' . substr( $row['start'], 0, 16 ) ] = $lid;
				}
			}
			if ( $lid ) {
				self::remember( $src, 'event', $id, $lid );
			}
			++$res['events'][ $act ];
		}

		if ( ! empty( $job['state']['opts']['emails'] ) && ! empty( $meta['emails'] ) ) {
			$res['emails'] = self::run_emails( $meta['emails'], self::sources()[ $src ] );
		}
		unset( $res );
		if ( empty( $job['state']['opts']['calendars'] ) ) {
			self::seed_calendars( $src );
		}
	}

	/**
	 * Bring email wording across.
	 *
	 * @param array  $emails Dataset emails.
	 * @param string $from   Source name.
	 * @return int How many were saved.
	 */
	private static function run_emails( array $emails, $from ) {
		$tpls   = OSB_Settings::get( 'emails' );
		$backup = get_option( 'osb_migrate_email_backup', array() );
		$backup = is_array( $backup ) ? $backup : array();
		$custom = array();
		foreach ( OSB_Custom_Emails::all() as $def ) {
			$custom[ self::norm( $def['name'] ) ] = 1;
		}
		$n       = 0;
		$changed = false;
		foreach ( $emails as $em ) {
			$subject = sanitize_text_field( (string) $em['subject'] );
			$body    = wp_kses_post( (string) $em['body'] );
			if ( '' === trim( $subject . wp_strip_all_tags( $body ) ) ) {
				continue;
			}
			if ( 'custom' !== $em['target'] && isset( $tpls[ $em['target'] ] ) ) {
				if ( ! isset( $backup[ $em['target'] ] ) ) {
					// The wording that was here before the first import, in case it is wanted back.
					$backup[ $em['target'] ] = array(
						'subject' => $tpls[ $em['target'] ]['subject'],
						'body'    => $tpls[ $em['target'] ]['body'],
					);
				}
				$tpls[ $em['target'] ]['subject'] = $subject;
				$tpls[ $em['target'] ]['body']    = $body;
				$changed                          = true;
				++$n;
				continue;
			}
			// No standard email does this job: keep the wording as one of the site's own emails, switched off.
			/* translators: 1: other system 2: email name */
			$name = sprintf( __( '%1$s: %2$s', 'osam-bookings' ), $from, $em['label'] );
			if ( isset( $custom[ self::norm( $name ) ] ) ) {
				continue;
			}
			$def            = array_merge(
				array(
					'name'        => $name,
					'trigger'     => 'manual',
					'to_customer' => 1,
					'statuses'    => array(),
					/* translators: %s other system */
					'note'        => sprintf( __( 'Wording brought across from %s. Switched off — check it, choose when it should go out, then switch it on.', 'osam-bookings' ), $from ),
				),
				isset( $em['custom'] ) && is_array( $em['custom'] ) ? $em['custom'] : array()
			);
			$def['subject'] = $subject;
			$def['body']    = $body;
			$def['enabled'] = 0;
			OSB_Custom_Emails::save( OSB_Custom_Emails::sanitize( $def ) );
			++$n;
		}
		if ( $changed ) {
			update_option( 'osb_migrate_email_backup', $backup, false );
			OSB_Settings::update( array( 'emails' => $tpls ) );
		}
		return $n;
	}

	/**
	 * One chunk of customer records.
	 *
	 * @param array $chunk Customers.
	 * @param array $res   Counters.
	 */
	private static function run_customers( array $chunk, array &$res ) {
		global $wpdb;
		$c   = &self::ctx();
		$now = current_time( 'mysql' );
		foreach ( $chunk as $cu ) {
			$e = self::norm( isset( $cu['email'] ) ? $cu['email'] : '' );
			if ( '' === $e || ! is_email( $e ) ) {
				++$res['skip'];
				continue;
			}
			if ( isset( $c['customers'][ $e ] ) ) {
				++$res['match'];
				continue;
			}
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				OSB_Install::table( 'customers' ),
				array(
					'email'      => substr( $e, 0, 191 ),
					'name'       => substr( sanitize_text_field( (string) $cu['name'] ), 0, 191 ),
					'phone'      => substr( sanitize_text_field( (string) $cu['phone'] ), 0, 50 ),
					'notes'      => sanitize_textarea_field( (string) $cu['notes'] ),
					'tags'       => substr( implode( ', ', array_slice( OSB_Customers::tags( sanitize_text_field( (string) $cu['tags'] ) ), 0, 20 ) ), 0, 255 ),
					'created_at' => $now,
				)
			);
			$c['customers'][ $e ] = 1;
			++$res[ $ok ? 'create' : 'skip' ];
		}
	}

	/**
	 * One chunk of bookings, written straight to the table (see the class notes for why).
	 *
	 * @param array $job   Job (state updated).
	 * @param array $chunk Bookings.
	 */
	private static function run_bookings( array &$job, array $chunk ) {
		global $wpdb;
		$src   = $job['source'];
		$from  = self::sources()[ $src ];
		$opts  = $job['state']['opts'];
		$res   = &$job['state']['res']['bookings'];
		$c     = &self::ctx();
		$now   = current_time( 'mysql' );
		$now_t = strtotime( $now );
		$cur   = (string) OSB_Settings::get( 'currency' );
		$solo  = OSB_DB::is_solo() ? (int) OSB_DB::solo_staff_id() : 0;
		$ok_st = OSB_Bookings::statuses();
		$log   = OSB_Install::table( 'email_log' );
		$timed = array();
		foreach ( OSB_Custom_Emails::all() as $def ) {
			if ( OSB_Custom_Emails::is_timed( $def['trigger'] ) ) {
				$timed[] = $def;
			}
		}

		foreach ( $chunk as $b ) {
			$item = self::mapped( $src, 'event' === $b['kind'] ? 'event' : 'service', $b['item'] );
			if ( $item && ! isset( $c[ 'event' === $b['kind'] ? 'event_ids' : 'service_ids' ][ $item ] ) ) {
				$item = 0;
			}
			list( $act ) = self::classify_booking( $src, $b, $item ? $item : null );
			if ( 'create' !== $act ) {
				++$res[ $act ];
				continue;
			}
			$start = substr( $b['start'], 0, 16 ) . ':00';
			$end   = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $b['end'] ) && $b['end'] > $start ? substr( $b['end'], 0, 16 ) . ':00' : gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS );
			$past  = $end <= $now;

			$status = isset( $ok_st[ $b['status'] ] ) && ! in_array( $b['status'], OSB_Bookings::hidden_statuses(), true ) ? $b['status'] : 'confirmed';
			if ( 'confirmed' === $status && $past ) {
				$status = 'completed'; // Otherwise the 5-minute job would complete it and fire the status hooks.
			}
			$amount = max( 0, round( (float) $b['amount'], 2 ) );
			$pay    = in_array( $b['pay'], array( 'paid', 'unpaid', 'refunded', 'free' ), true ) ? $b['pay'] : 'unpaid';
			if ( $amount <= 0 && 'refunded' !== $pay ) {
				$pay = 'free';
			} elseif ( 'free' === $pay ) {
				$amount = 0;
			}
			$method = 'free' === $pay ? 'free' : ( '' !== (string) $b['method'] ? sanitize_key( $b['method'] ) : 'onsite' );

			if ( 'event' === $b['kind'] ) {
				$staff = isset( $c['event_staff'][ $item ] ) ? (int) $c['event_staff'][ $item ] : 0;
			} elseif ( $solo ) {
				$staff = $solo;
			} else {
				$staff = '' !== (string) $b['staff'] ? self::mapped( $src, 'staff', $b['staff'] ) : 0;
				$staff = $staff && isset( $c['staff_ids'][ $staff ] ) ? $staff : 0;
			}

			$name   = substr( sanitize_text_field( (string) $b['name'] ), 0, 191 );
			$email  = is_email( trim( (string) $b['email'] ) ) ? sanitize_email( trim( (string) $b['email'] ) ) : '';
			$phone  = substr( sanitize_text_field( (string) $b['phone'] ), 0, 50 );
			$fields = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
			if ( '' !== trim( (string) $b['customer_note'] ) ) {
				$fields['notes'] = sanitize_textarea_field( (string) $b['customer_note'] );
			}
			/* translators: 1: other system 2: its reference for the booking */
			$note = sprintf( __( 'Imported from %1$s (their reference: %2$s).', 'osam-bookings' ), $from, $b['id'] );
			if ( '' !== trim( (string) $b['note'] ) ) {
				$note .= "\n" . sanitize_textarea_field( (string) $b['note'] );
			}
			$created = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $b['created'] ) ? substr( $b['created'], 0, 16 ) . ':00' : min( $start, $now );
			if ( $created > $now ) {
				$created = $now;
			}

			$row = array(
				'type'           => 'event' === $b['kind'] ? 'event' : 'service',
				'service_id'     => 'event' === $b['kind'] ? 0 : $item,
				'event_id'       => 'event' === $b['kind'] ? $item : 0,
				'staff_id'       => $staff,
				'start_at'       => $start,
				'end_at'         => $end,
				'seats'          => max( 1, (int) $b['seats'] ),
				'customer_name'  => $name,
				'customer_email' => $email,
				'customer_phone' => $phone,
				'fields'         => wp_json_encode( $fields ),
				'internal_note'  => $note,
				'status'         => $status,
				'payment_method' => substr( $method, 0, 30 ),
				'payment_status' => $pay,
				'amount'         => $amount,
				'currency'       => preg_match( '/^[A-Za-z]{3}$/', (string) $b['currency'] ) ? strtoupper( $b['currency'] ) : $cur,
				'token'          => wp_generate_password( 32, false, false ),
				// Nothing to remind or follow up about a visit that has already happened.
				'reminder_sent'  => ( $start <= $now || empty( $opts['reminders'] ) ) ? 1 : 0,
				'followup_sent'  => $past ? 1 : 0,
				'created_at'     => $created,
				'updated_at'     => $now,
			);
			$id  = OSB_DB::insert( 'bookings', $row );
			if ( ! $id ) {
				++$res['skip'];
				continue;
			}
			self::remember( $src, 'booking', $b['id'], $id );
			$c['booking_ids'][ $id ] = 1;
			$c['booking_keys'][ self::booking_key( $row['type'], $item, $start, $email, $name ) ] = 1;
			++$res['create'];

			// Scheduled emails of the site's own: mark them as dealt with for this booking, so the
			// 5-minute job never emails anyone about a visit or a booking made in the old system.
			foreach ( $timed as $def ) {
				$sec    = OSB_Custom_Emails::offset_seconds( $def );
				$dedupe = '';
				if ( 'after_end' === $def['trigger'] && $past && strtotime( $end ) >= $now_t - $sec - 15 * DAY_IN_SECONDS ) {
					$dedupe = 'after_end';
				} elseif ( 'after_booked' === $def['trigger'] && strtotime( $created ) >= $now_t - $sec - 15 * DAY_IN_SECONDS ) {
					$dedupe = 'after_booked';
				} elseif ( 'before_start' === $def['trigger'] && $start > $now && empty( $opts['reminders'] ) ) {
					$dedupe = 'before|' . $start;
				}
				if ( '' !== $dedupe ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$log,
						array(
							'booking_id' => $id,
							'email_key'  => 'custom_' . $def['id'],
							'dedupe'     => $dedupe,
							'recipient'  => __( 'Nobody — held back because this booking was imported', 'osam-bookings' ),
							'subject'    => '',
							'sent'       => 0,
							'created_at' => $now,
						)
					);
				}
			}
		}
		unset( $res );
		if ( empty( $opts['calendars'] ) ) {
			self::seed_calendars( $src );
		}
	}
}
